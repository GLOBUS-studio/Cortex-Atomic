<?php

namespace DB\Cortex;

use DB\Cortex;

trait CrudTrait {

	/**
	 * Return an array of result arrays matching criteria
	 * @param null $filter
	 * @param array|null $options
	 * @param int $ttl
	 * @param int $rel_depths
	 * @return array
	 */
	public function afind($filter = NULL, ?array $options = NULL, $ttl = 0, $rel_depths = 1) {
		$result = $this->find($filter, $options, $ttl);
		return $result ? $result->castAll($rel_depths): NULL;
	}

	/**
	 * Return an array of objects matching criteria
	 * @param array|null $filter
	 * @param array|null $options
	 * @param int        $ttl
	 * @return static[]|\Db\CortexCollection|false
	 */
	public function find($filter = NULL, ?array $options = NULL, $ttl = 0) {
		$sort=false;
		if ($this->dbsType!='sql') {
			// see if reordering is needed
			foreach($this->countFields?:[] as $counter) {
				$cKey = is_array($counter) ? $counter[1] : 'count_'.$counter;
				if ($options && isset($options['order']) &&
					preg_match('/'.preg_quote($cKey,'/').'\h+(asc|desc)/i',$options['order'],$match))
					$sort=true;
			}
			if ($sort) {
				// backup slice settings
				if (isset($options['limit'])) {
					$limit = $options['limit'];
					unset($options['limit']);
				}
				if (isset($options['offset'])) {
					$offset = $options['offset'];
					unset($options['offset']);
				}
			}
		}
		$this->_ttl=$ttl?:$this->rel_ttl;
		$result = $this->filteredFind($filter,$options,$ttl);
		if (empty($result)) {
			$this->eagerLoad = [];
			return false;
		}
		foreach($result as &$record) {
			$record = $this->factory($record);
			unset($record);
		}
		// add counter for NoSQL engines
		foreach($this->countFields?:[] as $counter) {
			$cKey = is_array($counter) ? $counter[0] : $counter;
			$cAlias = is_array($counter) ? $counter[1] : 'count_'.$counter;
			foreach($result as &$mapper) {
				$cr=$mapper->get($cKey);
				$mapper->virtual($cAlias,$cr?count($cr):null);
				unset($mapper);
			}
		}
		$cc = new CortexCollection();
		$cc->setModels($result);
		if($sort) {
			$cc->orderBy($options['order']);
			$cc->slice($offset ?? 0, $limit ?? NULL);
		}
		// eager-load declared relations
		if (!empty($this->eagerLoad)) {
			static::resolveEagerLoading($cc, $this->eagerLoad);
			$this->eagerLoad = [];
		}
		$this->clearFilter();
		return $cc;
	}

	/**
	 * wrapper for custom find queries
	 * @param array $filter
	 * @param array|null $options
	 * @param int $ttl
	 * @param bool $count
	 * @return array|int|false array of underlying cursor objects
	 */
	protected function filteredFind($filter = NULL, ?array $options = NULL, $ttl = 0, $count=false) {
		if (empty($filter))
			$filter = NULL;
		if ($this->grp_stack) {
			if ($this->dbsType == 'mongo') {
				$group = [
					'keys' => $this->grp_stack['keys'],
					'reduce' => 'function (obj, prev) {'.$this->grp_stack['reduce'].'}',
					'initial' => $this->grp_stack['initial'],
					'finalize' => $this->grp_stack['finalize'],
				];
				if ($options && isset($options['group'])) {
					if(is_array($options['group']))
						$options['group'] = array_merge($options['group'],$group);
					else {
						$keys = explode(',',$options['group']);
						$keys = array_combine($keys,array_fill(0,count($keys),1));
						$group['keys'] = array_merge($group['keys'],$keys);
						$options['group'] = $group;
					}
				} else
					$options = ['group'=>$group];
			}
			if($this->dbsType == 'sql') {
				if ($options && isset($options['group']))
					$options['group'].= ','.$this->grp_stack;
				else
					$options['group'] = $this->grp_stack;
			}
			// Jig can't group yet, but pending enhancement https://github.com/bcosca/fatfree/pull/616
		}
		if ($this->dbsType == 'sql' && !$count) {
			$m_refl=new \ReflectionObject($this->mapper);
			$m_ad_prop=$m_refl->getProperty('adhoc');
			$m_refl_adhoc=$m_ad_prop->getValue($this->mapper);
			unset($m_ad_prop,$m_refl);
		}
		// sorting by relation helper: @field.field
		if ($this->dbsType == 'sql' && !empty($options['order'])
			&& str_contains($options['order'], '@')) {
			$options['order']=preg_replace_callback(
				'/(?:^|[\h,])+(@([\w\-\d]+)\.)/i', function($match) {
					if (isset($this->fieldConf[ $match[2] ])) {
						$rel = $this->rel($match[2]);
						// having a has filter on that relation is mandatory
						if (!isset($this->hasCond[$match[2]]))
							$this->has($match[2],null,null);
						return $rel->getTable().'__'.$match[2].'.';
					}
					return $match[1];
				}, $options['order']);
		}
		$hasJoin = [];
		if ($this->hasCond) {
			foreach($this->hasCond as $key => $hasCond) {
				$addToFilter = null;
				if ($deep = str_contains($key,'.')) {
					$key = rtrim($key,'.');
					$hasCond = [null,null];
				}
				list($has_filter,$has_options) = $hasCond;
				$isOrCondition = $has_options['OR'] ?? FALSE;
				$type = $this->fieldConf[$key]['relType'];
				$fromConf = $this->fieldConf[$key][$type];
				switch($type) {
					case 'has-one':
					case 'has-many':
						if (!is_array($fromConf))
                            throw new \Exception(sprintf(self::E_REL_CONF_INC, $key));
						$id = $this->dbsType == 'sql' ? $this->primary : '_id';
						if ($type=='has-many' && isset($fromConf['relField'])
							&& $fromConf['hasRel'] == 'belongs-to-one')
							$id=$fromConf['relField'];
						// many-to-many
						if ($type == 'has-many' && $fromConf['hasRel'] == 'has-many') {
							if (!$deep && $this->dbsType == 'sql'
								&& !isset($has_options['limit']) && !isset($has_options['offset'])) {
								$hasJoin = array_merge($hasJoin,
									$this->_hasJoinMM_sql($key,$hasCond,$filter,$options));
								if (!isset($options['group']))
									$options['group'] = '';
								$groupFields = explode(',', preg_replace('/"/','',$options['group']));
								if (!in_array($this->table.'.'.$this->primary,$groupFields)) {
									$options['group'] = ($options['group']?',':'').$this->table.'.'.$this->primary;
									$groupFields[]=$this->table.'.'.$this->primary;
								}
								// all non-aggregated fields need to be present in the GROUP BY clause
								if (isset($m_refl_adhoc) && preg_match('/sybase|dblib|odbc|sqlsrv|ibm/i',$this->db->driver()))
									foreach (array_diff($this->mapper->fields(),array_keys($m_refl_adhoc)) as $field)
										if (!in_array($this->table.'.'.$field,$groupFields))
											$options['group'] .= ', '.$this->table.'.'.$field;
							}
							elseif ($result = $this->_hasRefsInMM($key,$has_filter,$has_options,$ttl))
								$addToFilter = [$id.' IN ?', $result];
						}
						// *-to-one
						elseif (!$deep && $this->dbsType == 'sql') {
							// use sub-query inclusion
							$has_filter=$this->mergeFilter([$has_filter,
								[$this->rel($key)->getTable().'.'.$fromConf[1].'='.$this->getTable().'.'.$id]]);
							$result = $this->_refSubQuery($key,$has_filter,$has_options);
							$addToFilter = array_merge(['exists('.$result[0].')'],$result[1]);
						}
						elseif ($result = $this->_hasRefsIn($key,$has_filter,$has_options,$ttl))
							$addToFilter = [$id.' IN ?', $result];
						break;
					// one-to-*
					case 'belongs-to-one':
						if (!$deep && $this->dbsType == 'sql'
							&& !isset($has_options['limit']) && !isset($has_options['offset'])) {
							if (!is_array($fromConf))
								$fromConf = [$fromConf, '_id'];
							$rel = $fromConf[0]::resolveConfiguration();
							if ($fromConf[1] == '_id')
								$fromConf[1] = $rel['primary'];
							$hasJoin[] = $this->_hasJoin_sql($key,$rel['table'],$hasCond,$filter,$options);
						} elseif ($result = $this->_hasRefsIn($key,$has_filter,$has_options,$ttl))
								$addToFilter = [$key.' IN ?', $result];
						break;
					// unidirectional m:m (JSON-stored IDs)
					case 'belongs-to-many':
						if (!$deep && $this->dbsType == 'sql'
							&& ($btmSql = $this->_hasBTM_sql($key,$hasCond)) !== false) {
							// native JSON query — no full table scan
							$addToFilter = $btmSql;
						} else {
							// Mongo / Jig / unsupported SQL driver: PHP-based scan fallback
							$relConf = $this->fieldConf[$key]['belongs-to-many'];
							$rel = $this->getRelFromConf($relConf,$key);
							$hasSet = $rel->find($has_filter,$has_options,$ttl);
							if ($hasSet) {
								$relField = is_array($relConf) ? $relConf[1] : '_id';
								$matchIDs = array_unique($hasSet->getAll($relField, true));
								// scan own records for JSON arrays containing these IDs
								$allOwn = $this->mapper->find(null,null,$ttl);
								$ownIDs = [];
								if ($allOwn) foreach ($allOwn as $own) {
									$raw = $own->get($key);
									$arr = is_string($raw) ? json_decode($raw ?: '', true) : $raw;
									if (is_array($arr) && array_intersect($arr, $matchIDs))
										$ownIDs[] = $own->get($this->primary);
								}
								if (!empty($ownIDs))
									$addToFilter = [$this->primary.' IN ?', array_unique($ownIDs)];
								else
									$result = true; // mark as resolved but empty
							}
						}
						break;
					default:
                        throw new \Exception(self::E_HAS_COND);
				}
				if (isset($result) && !isset($addToFilter))
					return false;
				elseif (isset($addToFilter)) {
					if (!$filter)
						$filter = [''];
					if (!empty($filter[0]))
						$filter[0] .= $isOrCondition?' or ':' and ';
					$cond = array_shift($addToFilter);
					if ($this->dbsType=='sql')
						$cond = $this->queryParser->sql_prependTableToFields($cond,$this->table);
					$filter[0] .= '('.$cond.')';
					$filter = array_merge($filter, $addToFilter);
				}
			}
			$this->hasCond = null;
		}
		$filter = $this->queryParser->prepareFilter($filter, $this->dbsType, $this->db, $this->fieldConf, $this->primary);
		if ($this->dbsType=='sql') {
			$qtable = $this->db->quotekey($this->table);
			if (isset($options['order']) && $this->db->driver() == 'pgsql')
				// PostgreSQLism: sort NULL values to the end of a table
				$options['order'] = preg_replace('/\h+DESC(?=\s*(?:$|,))/i',' DESC NULLS LAST',$options['order']);
			// assemble full sql query for joined queries
			if ($hasJoin) {
				$adhoc=[];
				// when in count-mode and grouping is active, wrap the query later
				// otherwise add a an adhoc counter field here
				if (!($subquery_mode=($options && !empty($options['group']))) && $count)
					$adhoc[]='(COUNT(*)) as _rows';
				if (!$count)
					// add bind parameters for filters in adhoc fields
					if ($this->preBinds) {
						$crit = array_shift($filter);
						$filter = array_merge($this->preBinds,$filter);
						array_unshift($filter,$crit);
					}
				if (!empty($m_refl_adhoc))
					// add adhoc field expressions
					foreach ($m_refl_adhoc as $key=>$val)
						$adhoc[]=$val['expr'].' AS '.$this->db->quotekey($key);
				$fields=implode(',',$adhoc);
				if ($count && $subquery_mode) {
					if (empty($fields))
						// Select at least one field, ideally the grouping fields or sqlsrv fails
						$fields=preg_replace('/HAVING.+$/i','',$options['group']);
					if (preg_match('/mssql|dblib|sqlsrv/',$this->db->driver()))
						$fields='TOP 100 PERCENT '.$fields;
				}
				if (!$count)
					// add only selected fields to field list
					$fields.=($fields?', ':'').implode(', ',array_map(function($field) use($qtable){
						return $qtable.'.'.$this->db->quotekey($field);
					},array_diff($this->mapper->fields(),array_keys($m_refl_adhoc))));
				// assemble query
				$sql = 'SELECT '.$fields.' FROM '.$qtable.' '
					.implode(' ',$hasJoin).($filter ? ' WHERE '.$filter[0] : '');
				$db=$this->db;
				// add grouping in both, count & selection mode
				if (isset($options['group']))
					$sql.=' GROUP BY '.preg_replace_callback('/\w+[._\-\w]*/i',
						function($match) use($db) {
							return $db->quotekey($match[0]);
						}, $options['group']);
				if (!$count) {
					if (isset($options['order']))
						$sql.=' ORDER BY '.implode(',',array_map(
							function($str) use($db) {
								return preg_match('/^\h*(\w+[._\-\w]*)(?:\h+((?:ASC|DESC)[\w\h]*))?\h*$/i',
									$str,$parts)?
									($db->quotekey($parts[1]).
										(isset($parts[2])?(' '.$parts[2]):'')):$str;
							},
							explode(',',$options['order'])));
					// SQL Server fixes
					if (preg_match('/mssql|sqlsrv|odbc/', $this->db->driver()) &&
						(isset($options['limit']) || isset($options['offset']))) {
						$ofs=isset($options['offset'])?(int)$options['offset']:0;
						$lmt=isset($options['limit'])?(int)$options['limit']:0;
						if (strncmp($this->db->version(),'11',2)>=0) {
							// SQL Server >= 2012
							if (!isset($options['order']))
								$sql.=' ORDER BY '.$this->db->quotekey($this->primary);
							$sql.=' OFFSET '.$ofs.' ROWS'.($lmt?' FETCH NEXT '.$lmt.' ROWS ONLY':'');
						} else {
							// SQL Server 2008
							$order=(!isset($options['order']))
								?($this->db->quotekey($this->table.'.'.$this->primary)):$options['order'];
							$sql=str_replace('SELECT','SELECT '.($lmt>0?'TOP '.($ofs+$lmt):'').' ROW_NUMBER() '.
								'OVER (ORDER BY '.$order.') AS rnum,',$sql);
							$sql='SELECT * FROM ('.$sql.') x WHERE rnum > '.($ofs);
						}
					} else {
						if (isset($options['limit']))
							$sql.=' LIMIT '.(int)$options['limit'];
						if (isset($options['offset']))
							$sql.=' OFFSET '.(int)$options['offset'];
					}
				} elseif ($subquery_mode)
					// wrap count query if necessary
					$sql='SELECT COUNT(*) AS '.$this->db->quotekey('_rows').' '.
						'FROM ('.$sql.') AS '.$this->db->quotekey('_temp');
				unset($filter[0]);
				$result = $this->db->exec($sql, $filter, $ttl);
				if ($count)
					return $result[0]['_rows'];
				foreach ($result as &$record) {
					// factory new mappers
					$record = $this->mapper->factory($record);
					unset($record);
				}
				return $result;
			} elseif (!empty($this->preBinds) && !$count) {
				// bind values to adhoc queries
				if (!$filter)
					// we (PDO) need any filter to bind values
					$filter = ['1=1'];
				$crit = array_shift($filter);
				$filter = array_merge($this->preBinds,$filter);
				array_unshift($filter,$crit);
			}
		}
		if ($options) {
			$options = $this->queryParser->prepareOptions($options,$this->dbsType,$this->db);
			if ($count)
				unset($options['order']);
		}
		return ($count)
			? $this->mapper->count($filter,$options,$ttl)
			: $this->mapper->find($filter,$options,$ttl);
	}

	/**
	 * use a raw sql query to find results and factory them into models
	 * @param $sql
	 * @param array|null $args
	 * @param int $ttl
	 * @return static[]|\DB\CortexCollection
	 */
	public function findByRawSQL($query, $args=NULL, $ttl=0) {
		$result = $this->db->exec($query, $args, $ttl);
		$cx = new CortexCollection();
		foreach($result as $row) {
			$new = $this->factory($row);
			$cx->add($new);
			unset($new);
		}
		return $cx;
	}

	/**
	 * Retrieve first object that satisfies criteria
	 * @param array|null  $filter
	 * @param array|null $options
	 * @param int   $ttl
	 * @return bool
	 */
	public function load($filter = NULL, ?array $options = NULL, $ttl = 0) {
		$this->reset(TRUE, FALSE);
		$this->_ttl=$ttl?:$this->rel_ttl;
		$res = $this->filteredFind($filter, $options, $ttl);
		if ($res) {
			$this->mapper->query = $res;
			$this->reset(FALSE, FALSE);
			$this->mapper->first();
		} else
			$this->mapper->reset();
		$this->emit('load');
		// eager-load declared relations for single record
		if (!empty($this->eagerLoad) && $this->valid()) {
			$cc = new CortexCollection();
			$cc->add($this);
			static::resolveEagerLoading($cc, $this->eagerLoad);
			$this->eagerLoad = [];
		} elseif (!empty($this->eagerLoad)) {
			$this->eagerLoad = [];
		}
		return $this->valid();
	}

	/**
	 * Delete object/s and reset ORM
	 * @param $filter
	 * @return bool
	 */
	public function erase($filter = null) {
		$filter = $this->queryParser->prepareFilter($filter, $this->dbsType, $this->db,null,$this->primary);
		if (!$filter) {
			if ($this->emit('beforeerase')===false)
				return false;
			$needsTx = $this->dbsType == 'sql' && !$this->db->trans();
			if ($needsTx)
				$this->db->begin();
			try {
				if ($this->fieldConf) {
					// clear all m:m references
					foreach($this->fieldConf as $key => $conf)
						if (isset($conf['has-many']) &&
							$conf['has-many']['hasRel']=='has-many') {
							$rel = $this->getRelInstance(null, [
								'db'=>$this->db,
								'table'=>$this->mmTable($conf['has-many'],$key)]);
							$id = $this->get($conf['has-many']['relPK'],true);
							$rel->erase([$conf['has-many']['relField'].' = ?', $id]);
						}
				}
				$this->mapper->erase();
				if ($needsTx)
					$this->db->commit();
			} catch (\Exception $e) {
				if ($needsTx)
					$this->db->rollback();
				throw $e;
			}
			$this->emit('aftererase');
		} else {
			// check if this model has m:m relations that need pivot cleanup
			$hasMMRels = false;
			if ($this->fieldConf) {
				foreach ($this->fieldConf as $conf)
					if (isset($conf['has-many']) &&
						$conf['has-many']['hasRel']=='has-many') {
						$hasMMRels = true;
						break;
					}
			}
			if (!$hasMMRels) {
				// no m:m relations - safe to batch-erase directly
				$this->mapper->erase($filter);
			} else {
				// has m:m relations: load each record and erase individually
				// to ensure pivot cleanup and transaction safety
				$needsTx = $this->dbsType == 'sql' && !$this->db->trans();
				if ($needsTx)
					$this->db->begin();
				try {
					$clone = clone($this);
					while ($clone->load($filter)) {
						$clone->erase();
						$clone->reset();
					}
					if ($needsTx)
						$this->db->commit();
				} catch (\Exception $e) {
					if ($needsTx)
						$this->db->rollback();
					throw $e;
				}
			}
		}
		return true;
	}

	/**
	 * prepare mapper for save operation
	 */
	protected function _beforesave() {
		// update changed collections
		foreach($this->fieldConf?:[] as $key=>$conf)
			if (!empty($this->fieldsCache[$key]) && $this->fieldsCache[$key] instanceof CortexCollection
				&& $this->fieldsCache[$key]->hasChanged())
				$this->set($key,$this->fieldsCache[$key]->getAll('_id',true));
	}

	/**
	 * additional save operations
	 */
	protected function _aftersave() {
		$fields = $this->fieldConf?:[];
		// m:m save cascade
		if (!empty($this->saveCsd)) {
			foreach($this->saveCsd as $key => $val) {
				if ($fields[$key]['relType'] == 'has-many') {
					$relConf = $fields[$key]['has-many'];
					if ($relConf['hasRel'] == 'has-many') {
						$mmTable = $this->mmTable($relConf,$key);
						$mm = $this->getRelInstance(null, ['db'=>$this->db, 'table'=>$mmTable]);
						$id = $this->get($relConf['localKey'],true);
						$filter = [$relConf['relField'].' = ?',$id];
						if ($relConf['isSelf']) {
							$filter[0].= ' OR '.$relConf['selfRefField'].' = ?';
							$filter[] = $id;
						}
						// delete all refs
						if (empty($val) || ($val instanceof CortexCollection && empty($val->getArrayCopy())))
							$mm->erase($filter);
						// update refs
						elseif (is_array($val)) {
							$relFieldConf = $this->getRelInstance($relConf[0],$relConf,$key)
								->getFieldConfiguration();
							$fkey = $relFieldConf[$relConf[1]]['has-many']['relField'];
							// keep existing entries
                            $refs = $mm->find($filter);
                            $existingRefIds = $refs ? $refs->getAll($fkey) : [];
                            $removed_refs = array_diff($existingRefIds, $val);
                            foreach ($removed_refs as $rId) {
                                $mm->erase($mm->mergeFilter([$filter, [$fkey.'= ?', $rId]]));
                            }
							foreach(array_unique($val) as $v) {
								if (($relConf['isSelf'] && $v==$id)
                                    || in_array($v, $existingRefIds))
									continue;
								$mm->set($fkey,$v);
								$mm->set($relConf['isSelf'] ? $relConf['selfRefField']
									: $relConf['relField'], $id);
								$mm->save();
								$mm->reset();
							}
						}
						unset($mm);
					}
					elseif($relConf['hasRel'] == 'belongs-to-one') {
						$rel = $this->getRelInstance($relConf[0],$relConf,$key);
						// find existing relations
						$refs = $rel->find([$relConf[1].' = ?',$this->getRaw($relConf['relField'])]);
						if (empty($val)) {
							foreach ($refs?:[] as $model) {
								$model->set($relConf[1],NULL);
								$model->save();
							}
							$this->fieldsCache[$key] = NULL;
						} else {
							if ($refs) {
								$ref_ids = $refs->getAll('_id');
								// unlink removed relations
								$remove_refs = array_diff($ref_ids,$val);
								foreach ($refs as $model)
									if (in_array($model->getRaw($relConf['relField']),$remove_refs)) {
										$model->set($relConf[1],null);
										$model->save();
									}
								// get new relation keys
								$val = array_diff($val,$ref_ids);
							} else
								$refs = new CortexCollection();
							if (!empty($val)) {
								// find models that need to be linked
								$new_refs = $rel->find([$relConf['relField'].' IN ?',$val]);
								foreach ($new_refs?:[] as $model) {
									// set relation to new models
									$model->set($relConf[1],$this->getRaw($relConf['relField']));
									$model->save();
									$refs->add($model);
								}
							}
							$this->fieldsCache[$key] = $refs;
						}
					}
				} elseif($fields[$key]['relType'] == 'has-one') {
					$val->save();
				}
			}
			$this->saveCsd = [];
		}
	}

	/**
	 * Save mapped record
	 * @return mixed
	 **/
	function save() {
		// perform event & save operations
		return $this->dry() ? $this->insert() : $this->update();
	}

	/**
	 * Count records that match criteria
	 * @param array $filter
	 * @param array|null $options
	 * @param int $ttl
	 * @return mixed
	 */
	public function count($filter=NULL, ?array $options=NULL, $ttl=0) {
		$has=$this->hasCond;
		$count=$this->filteredFind($filter,$options,$ttl,true);
		$this->hasCond=$has;
		return $count;
	}

	/**
	 * perform event & insert operations
	 * @return mixed
	 */
	function insert() {
		$this->_beforesave();
		if ($this->emit('beforeinsert')===false)
			return false;
		$needsTx = $this->dbsType == 'sql' && !$this->db->trans();
		if ($needsTx)
			$this->db->begin();
		try {
			$res = $this->mapper->insert();
			if (is_array($res))
				$res = $this->mapper;
			if (is_object($res))
				$res = $this->factory($res);
			$this->_aftersave();
			if ($needsTx)
				$this->db->commit();
		} catch (\Exception $e) {
			if ($needsTx)
				$this->db->rollback();
			throw $e;
		}
		$this->emit('afterinsert');
		return is_int($res) ? $this : $res;
	}

	/**
	 * perform event & update operations
	 * @return mixed
	 */
	function update() {
		$this->_beforesave();
		if ($this->emit('beforeupdate')===false)
			return false;
		$needsTx = $this->dbsType == 'sql' && !$this->db->trans();
		if ($needsTx)
			$this->db->begin();
		try {
			$res = $this->mapper->update();
			if (is_array($res))
				$res = $this->mapper;
			if (is_object($res))
				$res = $this->factory($res);
			$this->_aftersave();
			if ($needsTx)
				$this->db->commit();
		} catch (\Exception $e) {
			if ($needsTx)
				$this->db->rollback();
			throw $e;
		}
		$this->emit('afterupdate');
		return is_int($res) ? $this : $res;
	}

	/**
	 * Resolve eager loading for a collection.
	 * Supports array of relation paths (dot-notation) or integer depth.
	 * @param CortexCollection $cc
	 * @param array|int $relations
	 */
	static protected function resolveEagerLoading(CortexCollection $cc, $relations) {
		if (!count($cc))
			return;
		if (is_int($relations)) {
			static::eagerLoadByDepth($cc, $relations);
			return;
		}
		// build tree from dot-notation paths
		$tree = [];
		foreach ((array) $relations as $rel) {
			$parts = explode('.', $rel);
			$ref = &$tree;
			foreach ($parts as $part) {
				if (!isset($ref[$part]))
					$ref[$part] = [];
				$ref = &$ref[$part];
			}
			unset($ref);
		}
		static::eagerLoadTree($cc, $tree);
	}

	/**
	 * Eager-load relations from a tree structure.
	 * Each key is a relation name, value is a subtree for nested loading.
	 * @param CortexCollection $cc
	 * @param array $tree e.g. ['news' => ['tags' => []], 'profile' => []]
	 */
	static protected function eagerLoadTree(CortexCollection $cc, array $tree) {
		if (!count($cc) || empty($tree))
			return;
		foreach ($tree as $key => $nested) {
			$allRelated = [];
			foreach ($cc as $model) {
				$val = $model->get($key);
				if (!empty($nested)) {
					if ($val instanceof CortexCollection) {
						foreach ($val as $item)
							$allRelated[spl_object_id($item)] = $item;
					} elseif ($val instanceof Cortex) {
						$allRelated[spl_object_id($val)] = $val;
					}
				}
			}
			if (!empty($nested) && !empty($allRelated)) {
				$megaCC = new CortexCollection();
				$megaCC->setModels(array_values($allRelated));
				static::eagerLoadTree($megaCC, $nested);
			}
		}
	}

	/**
	 * Eager-load all relations by depth.
	 * @param CortexCollection $cc
	 * @param int $depth 1 = load direct relations, 2 = relations of relations, etc.
	 */
	static protected function eagerLoadByDepth(CortexCollection $cc, int $depth) {
		if ($depth <= 0 || !count($cc))
			return;
		$first = $cc[0];
		$fieldConf = $first->getFieldConfiguration();
		if (!$fieldConf)
			return;
		$relKeys = [];
		foreach ($fieldConf as $key => $conf)
			if (is_array($conf)
				&& preg_grep('/(belongs|has)-(to-)*(one|many)/', array_keys($conf)))
				$relKeys[] = $key;
		foreach ($relKeys as $key) {
			$allRelated = [];
			foreach ($cc as $model) {
				$val = $model->get($key);
				if ($depth > 1) {
					if ($val instanceof CortexCollection) {
						foreach ($val as $item)
							$allRelated[spl_object_id($item)] = $item;
					} elseif ($val instanceof Cortex) {
						$allRelated[spl_object_id($val)] = $val;
					}
				}
			}
			if ($depth > 1 && !empty($allRelated)) {
				$megaCC = new CortexCollection();
				$megaCC->setModels(array_values($allRelated));
				static::eagerLoadByDepth($megaCC, $depth - 1);
			}
		}
	}

}
