<?php

/**
 *  RelationTrait - Relation management, has-conditions and filters for Cortex ORM
 *
 *  Extracted from Cortex class.
 *  Methods: has, orHas, _hasRefsIn, _refSubQuery, _hasRefsInMM,
 *           _hasJoinMM_sql, _hasJoin_sql, _sql_left_join, _sql_mergeRelCondition,
 *           filter, clearFilter, mergeWithRelFilter, mergeFilter, getRelFilterOption,
 *           countRel, _mongo_addGroup, getForeignKeysArray,
 *           getRelInstance, getRelFromConf, rel
 *
 *  @package DB
 */

namespace DB;

trait RelationTrait {

	/**
	 * add has-conditional filter to next find call
	 * @param string $key
	 * @param ?array $filter
	 * @param null $options
	 * @return $this
	 */
	public function has($key, $filter, $options = null) {
		if (is_string($filter))
			$filter=[$filter];
		if (str_contains($key,'.')) {
			list($key,$fkey) = explode('.',$key,2);
			if (!isset($this->hasCond[$key.'.']))
				$this->hasCond[$key.'.'] = [];
			$this->hasCond[$key.'.'][$fkey] = [$filter,$options];
		} else {
			if (!isset($this->fieldConf[$key]))
                throw new \Exception(sprintf(self::E_UNKNOWN_FIELD,$key,get_called_class()));
			if (!isset($this->fieldConf[$key]['relType']))
                throw new \Exception(self::E_HAS_COND);
			// support OR-chaining: append to existing condition instead of overwriting
			if (isset($this->hasCond[$key]) && $options && !empty($options['OR'])) {
				$prev = $this->hasCond[$key];
				$merged = $this->mergeFilter([$prev[0], $filter], 'or');
				$mergedOpts = $prev[1] ?: $options;
				$this->hasCond[$key] = [$merged, $mergedOpts];
			} else {
				$this->hasCond[$key] = [$filter,$options];
			}
		}
		return $this;
	}

	/**
	 * add has-conditional filter with OR operator to previous condition
	 * @param $key
	 * @param $filter
	 * @param null $options
	 */
	public function orHas($key, $filter, $options = null) {
		if (!$options)
			$options=[];
		$options['OR'] = TRUE;
		return $this->has($key, $filter, $options);
	}

	/**
	 * return IDs of records that has a linkage to this mapper
	 * @param string $key     relation field
	 * @param array  $filter  condition for foreign records
	 * @param array  $options filter options for foreign records
	 * @param int    $ttl
	 * @return array|false
	 */
	protected function _hasRefsIn($key, $filter, $options, $ttl = 0) {
		$type = $this->fieldConf[$key]['relType'];
		$fieldConf = $this->fieldConf[$key][$type];
		// one-to-many shortcut
		$rel = $this->getRelFromConf($fieldConf,$key);
		$hasSet = $rel->find($filter, $options, $ttl);
		if (!$hasSet)
			return false;
		$hasSetByRelId = array_unique($hasSet->getAll($fieldConf[1], true));
		return empty($hasSetByRelId) ? false : $hasSetByRelId;
	}

	/**
	 * build sub query on relation
	 * @param $key
	 * @param $filter
	 * @param $options
	 * @return mixed
	 */
	protected function _refSubQuery($key, $filter, $options,$fields=null) {
		$type = $this->fieldConf[$key]['relType'];
		$fieldConf = $this->fieldConf[$key][$type];
		$rel = $this->getRelFromConf($fieldConf,$key);
		$filter[0]=$this->queryParser->sql_quoteCondition($filter[0],$this->db);
		return $rel->mapper->stringify(implode(',',array_map([$this->db,'quotekey'],
			$fields?:[$rel->primary])),$filter,$options);
	}

	/**
	 * return IDs of own mappers that match the given relation filter on pivot tables
	 * @param string $key
	 * @param array $filter
	 * @param array $options
	 * @param int $ttl
	 * @return array|false
	 */
	protected function _hasRefsInMM($key, $filter, $options, $ttl=0) {
		$fieldConf = $this->fieldConf[$key]['has-many'];
		$rel = $this->getRelInstance($fieldConf[0],null,$key,true);
		$hasSet = $rel->find($filter,$options,$ttl);
		$result = false;
		if ($hasSet) {
			$hasIDs = $hasSet->getAll('_id',true);
			$mmTable = $this->mmTable($fieldConf,$key);
			$pivot = $this->getRelInstance(null,['db'=>$this->db,'table'=>$mmTable]);
			$rel = $fieldConf[0]::resolveConfiguration();
			$toConf = $fieldConf[0]::resolveRelationConf($rel['fieldConf'][$fieldConf[1]]);
			$filter = [$toConf['has-many']['relField'].' IN ?',$hasIDs];
			if ($fieldConf['isSelf']) {
				$filter[0].= ' OR '.$fieldConf['selfRefField'].' IN ?';
				$filter[] = $hasIDs;
			}
			$pivotSet = $pivot->find($filter,null,$ttl);
			if ($pivotSet) {
				$result = $pivotSet->getAll($fieldConf['relField'],true);
				if ($fieldConf['isSelf'])
					$result = array_merge($result,
						$pivotSet->getAll($fieldConf['selfRefField'],true));
				$result = array_diff(array_unique($result),$hasIDs);
			}
		}
		return $result;
	}

	/**
	 * build query for SQL pivot table join and merge conditions
	 */
	protected function _hasJoinMM_sql($key, $hasCond, &$filter, &$options) {
		$fieldConf = $this->fieldConf[$key]['has-many'];
		$relTable = $fieldConf['relTable'];
		$hasJoin = [];
		$mmTable = $this->mmTable($fieldConf,$key);
		if ($fieldConf['isSelf']) {
			$relTable .= '_ref';
			$hasJoin[] = $this->_sql_left_join($this->primary,$this->table,$fieldConf['selfRefField'],$mmTable);
			$hasJoin[] = $this->_sql_left_join($fieldConf['relField'],$mmTable,$fieldConf['relPK'],
				[$fieldConf['relTable'],$relTable]);
			// cross-linked
			$hasJoin[] = $this->_sql_left_join($this->primary,$this->table,
				$fieldConf['relField'],[$mmTable,$mmTable.'_c']);
			$hasJoin[] = $this->_sql_left_join($fieldConf['selfRefField'],$mmTable.'_c',$fieldConf['relPK'],
				[$fieldConf['relTable'],$relTable.'_c']);
			$this->_sql_mergeRelCondition($hasCond,$relTable,$filter,$options);
			$this->_sql_mergeRelCondition($hasCond,$relTable.'_c',$filter,$options,'OR');
		} else {
			$hasJoin[] = $this->_sql_left_join($this->primary,$this->table,$fieldConf['relField'],$mmTable);
			$rel = $fieldConf[0]::resolveConfiguration();
			$toConf = $fieldConf[0]::resolveRelationConf($rel['fieldConf'][$fieldConf[1]])['has-many'];
			$hasJoin[] = $this->_sql_left_join($toConf['relField'],$mmTable,$fieldConf['relPK'],$relTable);
			$glue = isset($hasCond[1]['OR']) && $hasCond[1]['OR']===TRUE ? 'OR' : 'AND';
			$this->_sql_mergeRelCondition($hasCond,$relTable,$filter,$options, $glue);
		}
		return $hasJoin;
	}

	/**
	 * build query for single SQL table join and merge conditions
	 */
	protected function _hasJoin_sql($key, $table, $cond, &$filter, &$options) {
		$relConf = $this->fieldConf[$key]['belongs-to-one'];
		$relModel = is_array($relConf)?$relConf[0]:$relConf;
		$rel = $this->getRelInstance($relModel,null,$key);
		$fkey = is_array($this->fieldConf[$key]['belongs-to-one']) ?
			$this->fieldConf[$key]['belongs-to-one'][1] : $rel->primary;
		$alias = $table.'__'.$key;
		$query = $this->_sql_left_join($key,$this->table,$fkey,[$table,$alias]);
		$glue = isset($cond[1]['OR']) && $cond[1]['OR']===TRUE ? 'OR' : 'AND';
		$this->_sql_mergeRelCondition($cond,$alias,$filter,$options, $glue);
		return $query;
	}

	/**
	 * assemble SQL join query string
	 * @param string $skey
	 * @param string $sTable
	 * @param string $fkey
	 * @param string|array $fTable
	 * @return string
	 */
	protected function _sql_left_join($skey, $sTable, $fkey, $fTable) {
		if (is_array($fTable))
			list($fTable,$fTable_alias) = $fTable;
		$skey = $this->db->quotekey($skey);
		$sTable = $this->db->quotekey($sTable);
		$fkey = $this->db->quotekey($fkey);
		$fTable = $this->db->quotekey($fTable);
		if (isset($fTable_alias)) {
			$fTable_alias = $this->db->quotekey($fTable_alias);
			return 'LEFT JOIN '.$fTable.' AS '.$fTable_alias.' ON '.$sTable.'.'.$skey.' = '.$fTable_alias.'.'.$fkey;
		} else
			return 'LEFT JOIN '.$fTable.' ON '.$sTable.'.'.$skey.' = '.$fTable.'.'.$fkey;
	}

	/**
	 * merge condition of relation with current condition
	 * @param array $cond condition of related model
	 * @param string $table table of related model
	 * @param array $filter current filter to merge with
	 * @param array $options current options to merge with
	 * @param string $glue
	 */
	protected function _sql_mergeRelCondition($cond, $table, &$filter, &$options, $glue='AND') {
		if (!empty($cond[0])) {
			$whereClause = '('.array_shift($cond[0]).')';
			$whereClause = $this->queryParser->sql_prependTableToFields($whereClause,$table);
			if (!$filter)
				$filter = [$whereClause];
			elseif (!empty($filter[0]))
				$filter[0] = '('.$this->queryParser->sql_prependTableToFields($filter[0],$this->table)
					.') '.$glue.' '.$whereClause;
			$filter = array_merge($filter, $cond[0]);
		}
		if ($cond[1] && isset($cond[1]['group'])) {
			$hasGroup = preg_replace('/(\w+)/i', $table.'.$1', $cond[1]['group']);
			$options['group'] .= ','.$hasGroup;
		}
	}

	/**
	 * add filter for loading related models
	 * @param string $key
	 * @param array $filter
	 * @param array $option
	 * @return $this
	 */
	public function filter($key, $filter=null, $option=null) {
		if (str_contains($key,'.')) {
			list($key,$fkey) = explode('.',$key,2);
			if (!isset($this->relFilter[$key.'.']))
				$this->relFilter[$key.'.'] = [];
			$this->relFilter[$key.'.'][$fkey] = [$filter,$option];
		} else
			$this->relFilter[$key] = [$filter,$option];
		return $this;
	}

	/**
	 * removes one or all relation filter
	 * @param null|string $key
	 */
	public function clearFilter($key = null) {
		if (!$key)
			$this->relFilter = [];
		elseif(isset($this->relFilter[$key]))
			unset($this->relFilter[$key]);
	}

	/**
	 * merge the relation filter to the query criteria if it exists
	 * @param string $key
	 * @param array $crit
	 * @return array
	 */
	protected function mergeWithRelFilter($key, $crit) {
		if (array_key_exists($key, $this->relFilter) &&
			!empty($this->relFilter[$key][0]))
			$crit=$this->mergeFilter([$this->relFilter[$key][0],$crit]);
		return $crit;
	}

	/**
	 * merge multiple filters
	 * @param array $filters
	 * @param string $glue
	 * @return array
	 */
	public function mergeFilter($filters, $glue='and') {
		$crit = [];
		$params = [];
		if ($filters) {
			foreach($filters as $filter) {
				if (!$filter)
					continue;
				$crit[] = array_shift($filter);
				$params = array_merge($params,$filter);
			}
			array_unshift($params,'( '.implode(' ) '.$glue.' ( ',$crit).' )');
		}
		return $params;
	}

	/**
	 * returns the option condition for a relation filter, if defined
	 * @param string $key
	 * @return array null
	 */
	protected function getRelFilterOption($key) {
		return (array_key_exists($key, $this->relFilter) &&
			!empty($this->relFilter[$key][1]))
			? $this->relFilter[$key][1] : null;
	}

	/**
	 * add a virtual field that counts occurring relations
	 * @param $key
	 */
	public function countRel($key, $alias=null, $filter=null, $option=null) {
		if (str_contains($key,'.')) {
			list($relM,$relF) = explode('.',$key,2);
			$this->rel($relM)->countRel($relF,$alias,$filter,$option);
			return;
		}
		if (!$alias)
			$alias = 'count_'.$key;
		$filter_bak = null;
		if ($filter || $option) {
			$filter_bak = $this->relFilter[$key] ?? false;
			$this->filter($key,$filter,$option);
		}
		if (isset($this->fieldConf[$key])){
			// one-to-one, one-to-many
			if ($this->fieldConf[$key]['relType'] == 'belongs-to-one') {
				if ($this->dbsType == 'sql') {
					$this->mapper->set($alias,'count('.$this->db->quotekey($key).')');
					$this->grp_stack=(!$this->grp_stack)?$key:$this->grp_stack.','.$key;
					if ($this->whitelist && !in_array($alias,$this->whitelist))
						$this->whitelist[] = $alias;
				} elseif ($this->dbsType == 'mongo')
					$this->_mongo_addGroup([
						'keys'=>[$key=>1],
						'reduce' => 'prev.'.$alias.'++;',
						"initial" => [$alias => 0]
					]);
				else
					trigger_error('Cannot add direct relational counter.',E_USER_WARNING);
			} elseif($this->fieldConf[$key]['relType'] == 'has-many') {
				$relConf=$this->fieldConf[$key]['has-many'];
				if ($relConf['hasRel']=='has-many') {
					// many-to-many
					if ($this->dbsType == 'sql') {
						$mmTable = $this->mmTable($relConf,$key);
						$filter = [$mmTable.'.'.$relConf['relField']
							.' = '.$this->table.'.'.$this->primary];
						$from = $this->db->quotekey($mmTable);
						if (array_key_exists($key, $this->relFilter) &&
							!empty($this->relFilter[$key][0])) {
							$options=[];
							$from = $this->db->quotekey($mmTable).' '.
								$this->_sql_left_join($key,$mmTable,$relConf['relPK'],$relConf['relTable']);
							$relFilter = $this->relFilter[$key];
							$this->_sql_mergeRelCondition($relFilter,$relConf['relTable'],
								$filter,$options);
						}
						$filter = $this->queryParser->prepareFilter($filter,
							$this->dbsType, $this->db, $this->fieldConf, $this->primary);
						$crit = array_shift($filter);
						if (count($filter)>0)
							$this->preBinds=array_merge($this->preBinds,$filter);
						$this->mapper->set($alias,
							'(select count('.$this->db->quotekey($mmTable.'.'.$relConf['relField']).')'.
							' from '.$from.' where '.$crit.
							' group by '.$this->db->quotekey($mmTable.'.'.$relConf['relField']).')');
						if ($this->whitelist && !in_array($alias,$this->whitelist))
							$this->whitelist[] = $alias;
					} else {
						// count rel
						$this->countFields[]=$key;
					}
				} elseif($this->fieldConf[$key]['has-many']['hasRel']=='belongs-to-one') {
					// many-to-one
					if ($this->dbsType == 'sql') {
						$fConf=$relConf[0]::resolveConfiguration();
						$fTable=$fConf['table'];
						$fAlias=$fTable.'__count';
						$rKey=$relConf[1];
						$crit = $fAlias.'.'.$rKey.' = '.$this->table.'.'.$relConf['relField'];
						$filter = $this->mergeWithRelFilter($key,[$crit]);
						$filter[0] = $this->queryParser->sql_prependTableToFields($filter[0],$fAlias);
						$filter = $this->queryParser->prepareFilter($filter,
							$this->dbsType, $this->db, $this->fieldConf, $this->primary);
						$crit = array_shift($filter);
						if (count($filter)>0)
							$this->preBinds=array_merge($this->preBinds,$filter);
						$this->mapper->set($alias,
							'(select count('.$this->db->quotekey($fAlias.'.'.$fConf['primary']).') from '.
							$this->db->quotekey($fTable).' AS '.$this->db->quotekey($fAlias).' where '.
							$crit.' group by '.$this->db->quotekey($fAlias.'.'.$rKey).')');
						if ($this->whitelist && !in_array($alias,$this->whitelist))
							$this->whitelist[] = $alias;
					} else {
						// count rel
						$this->countFields[]=$key;
					}
				}
			}
		}
		if ($filter_bak!==null) {
			if ($filter_bak)
				$this->relFilter[$key] = $filter_bak;
			else
				$this->clearFilter($key);
		}
	}

	/**
	 * merge mongo group options array
	 * @param $opt
	 */
	protected function _mongo_addGroup($opt) {
		if (!$this->grp_stack)
			$this->grp_stack = ['keys'=>[],'initial'=>[],'reduce'=>'','finalize'=>''];
		if (isset($opt['keys']))
			$this->grp_stack['keys']+=$opt['keys'];
		if (isset($opt['reduce']))
			$this->grp_stack['reduce'].=$opt['reduce'];
		if (isset($opt['initial']))
			$this->grp_stack['initial']+=$opt['initial'];
		if (isset($opt['finalize']))
			$this->grp_stack['finalize'].=$opt['finalize'];
	}

	/**
	 * find the ID values of given relation collection
	 * @param $val string|array|object|bool
	 * @param $rel_field string
	 * @param $key string
	 * @return array|Cortex|null|object
	 */
	protected function getForeignKeysArray($val, $rel_field, $key) {
		if (is_null($val))
			return NULL;
		if (is_object($val) && $val instanceof CortexCollection)
			$val = $val->getAll($rel_field,true);
		elseif (is_string($val))
			// split-able string of collection IDs
			$val = \Base::instance()->split($val);
		elseif (!is_array($val) && !(is_object($val)
				&& ($val instanceof Cortex && !$val->dry())))
            throw new \Exception(sprintf(self::E_MM_REL_VALUE, $key));
		// hydrated mapper as collection
		if (is_object($val)) {
			$nval = [];
			while (!$val->dry()) {
				$nval[] = $val->get($rel_field,true);
				$val->next();
			}
			$val = $nval;
		}
		elseif (is_array($val)) {
			// array of single hydrated mappers, raw ID value or mixed
			$isMongo = ($this->dbsType == 'mongo');
			foreach ($val as &$item) {
				if (is_object($item) &&
					!($isMongo && (($this->db->legacy() && $item instanceof \MongoId) ||
						(!$this->db->legacy() && $item instanceof \MongoDB\BSON\ObjectId)))) {
					if (!$item instanceof Cortex || $item->dry())
                        throw new \Exception(self::E_INVALID_RELATION_OBJECT);
					else $item = $item->get($rel_field,true);
				}
				if ($isMongo && $rel_field == '_id' && is_string($item))
					$item = $this->db->legacy() ? new \MongoId($item) : new \MongoDB\BSON\ObjectId($item);
				if (is_numeric($item))
					$item = (int) $item;
				unset($item);
			}
		}
		return $val;
	}

	/**
	 * creates and caches related mapper objects
	 * @param string $model
	 * @param array $relConf
	 * @param string $key
	 * @param bool $pushFilter
	 * @return Cortex
	 */
	protected function getRelInstance($model=null, $relConf=null, $key='', $pushFilter=false) {
		if (!$model && !$relConf)
            throw new \Exception(self::E_MISSING_REL_CONF);
		$relConf = $model ? $model::resolveConfiguration() : $relConf;
		$relName = ($model?:'Cortex').'\\'.$relConf['db']->uuid().
			'\\'.$relConf['table'].'\\'.$key;
		if (\Registry::exists($relName)) {
			$rel = \Registry::get($relName);
			// use pre-configured relation object here and dont reset it
			//$rel->reset();
		} else {
			$rel = $model ? new $model : new Cortex($relConf['db'], $relConf['table']);
			if (!$rel instanceof Cortex)
                throw new \Exception(self::E_WRONG_RELATION_CLASS);
			\Registry::set($relName, $rel);
        }
        // restrict fields of related mapper
        if(!empty($key) && isset($this->relWhitelist[$key])) {
            // ensure not to alter cached object
            $rel = clone $rel;
            if (isset($this->relWhitelist[$key][0]))
				$rel->fields($this->relWhitelist[$key][0],false);
			if (isset($this->relWhitelist[$key][1]))
				$rel->fields($this->relWhitelist[$key][1],true);
		}
		if ($pushFilter && !empty($key)) {
			if (isset($this->relFilter[$key.'.'])) {
				foreach($this->relFilter[$key.'.'] as $fkey=>$conf)
					$rel->filter($fkey,$conf[0],$conf[1]);
			}
			if (isset($this->hasCond[$key.'.'])) {
				foreach($this->hasCond[$key.'.'] as $fkey=>$conf)
					$rel->has($fkey,$conf[0],$conf[1]);
			}
		}
		return $rel;
	}

	/**
	 * get relation model from config
	 * @param $relConf
	 * @param $key
	 * @return Cortex
	 */
	protected function getRelFromConf(&$relConf, $key) {
		if (!is_array($relConf))
			$relConf = [$relConf, '_id'];
		$rel = $this->getRelInstance($relConf[0],null,$key,true);
		if($this->dbsType=='sql' && $relConf[1] == '_id')
			$relConf[1] = $rel->primary;
		return $rel;
	}

	/**
	 * returns a clean/dry model from a relation
	 * @param string $key
	 * @return Cortex
	 */
	public function rel($key) {
		$rt = $this->fieldConf[$key]['relType'];
		$rc = $this->fieldConf[$key][$rt];
		if (!is_array($rc))
			$rc = [$rc,'_id'];
		return $this->getRelInstance($rc[0],null,$key);
	}

}
