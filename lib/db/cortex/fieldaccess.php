<?php

/**
 *  FieldAccessTrait - Field access, getters/setters, events and configuration for Cortex ORM
 *
 *  Extracted from Cortex class.
 *  Methods: fields, applyWhitelist, setFieldConfiguration, getFieldConfiguration,
 *           touch, set, emit, onset, onget, virtual, clearVirtual, get, getRaw,
 *           exists, clear
 *
 *  @package DB
 */

namespace DB;
use DB\SQL\Schema;

trait FieldAccessTrait {

	/**
	 * get fields or set whitelist / blacklist of fields
	 * @param array $fields
	 * @param bool  $exclude
	 * @return array
	 */
	public function fields(array $fields=[], $exclude=false) {
		$addInc=[];
		if ($fields)
			// collect & set restricted fields for related mappers
			foreach($fields as $i=>$val)
				if(str_contains($val,'.')) {
					list($key, $relField) = explode('.',$val,2);
					$this->relWhitelist[$key][(int)$exclude][] = $relField;
					unset($fields[$i]);
					$addInc[] = $key;
				}
		$fields = array_unique($fields);
		$schema = $this->whitelist ?: $this->mapper->fields();
		if (!$schema && $this->dbsType != 'sql' && $this->dry()) {
			$this->load();
			$schema = $this->mapper->fields();
			$this->reset();
		}
		// include relation linkage fields to $fields (if $fields is a whitelist)
		if (!$exclude && !empty($fields) && !empty($addInc))
			$fields=array_unique(array_merge($fields,$addInc));
		// include relation linkage fields to existing whitelist (if $fields is a blacklist or there's nothing else to whitelist)
		elseif (!empty($addInc) && $this->whitelist)
			$this->whitelist=array_unique(array_merge($this->whitelist,$addInc));
		// initially merge configured fields into schema (add virtual/rel fields to schema)
		if (!$this->whitelist && $this->fieldConf)
			$schema=array_unique(array_merge($schema,
				array_keys($this->fieldConf),array_keys($this->vFields?:[])));
		// skip if there's nothing to set for own model
		if (!$fields || empty($fields))
			return $schema;
		elseif ($exclude) {
			$this->whitelist=array_diff($schema,$fields);
		} else
			$this->whitelist=$fields;
		$id=$this->dbsType=='sql'?$this->primary:'_id';
		if (!in_array($id,$this->whitelist) && !($exclude && in_array($id,$fields)))
			$this->whitelist[]=$id;
		$this->applyWhitelist();
		return $this->whitelist;
	}

	/**
	 * apply whitelist to active mapper schema
	 */
	protected function applyWhitelist() {
		if ($this->dbsType == 'sql') {
			// fetch full schema
			if (!$this->fluid && isset(self::$schema_cache[$key=$this->table.'_'.$this->db->uuid()]))
				$schema = self::$schema_cache[$key];
			else {
				$schema = $this->mapper->schema();
				self::$schema_cache[$this->table.'_'.$this->db->uuid()] = $schema;
			}
			// apply reduced fields schema
			if ($this->whitelist)
				$schema = array_intersect_key($schema, array_flip($this->whitelist));
			$this->mapper->schema($schema);
			$this->mapper->reset();
		}
	}

	/**
	 * set model definition
	 * config example:
	 *  array('title' => array(
	 *          'type' => \DB\SQL\Schema::DT_TEXT,
	 *          'default' => 'new record title',
	 *          'nullable' => true
	 *          )
	 *        '...' => ...
	 *  )
	 * @param array $config
	 */
	function setFieldConfiguration(array $config) {
		foreach ($config as $fk => $conf) {
			if (isset($conf['type']) && $conf['type'] === self::DT_SERIALIZED)
				@trigger_error(sprintf('Field "%s": %s', $fk, self::E_DT_SERIALIZED_DEPRECATED), E_USER_DEPRECATED);
		}
		$this->fieldConf = $config;
		$this->reset();
	}

	/**
	 * returns model field conf array
	 * @return array|null
	 */
	public function getFieldConfiguration() {
		return $this->fieldConf;
	}

	/**
	 * update a given date or time field with the current time
	 * @param string $key
	 * @param null $timestamp
	 */
	public function touch($key, $timestamp=NULL) {
		if (isset($this->fieldConf[$key])
			&& isset($this->fieldConf[$key]['type'])) {
			$type = $this->fieldConf[$key]['type'];
			$date = ($this->dbsType=='sql' && preg_match('/mssql|sybase|dblib|odbc|sqlsrv/',
					$this->db->driver())) ? 'Ymd' : 'Y-m-d';
			$timestamp = $timestamp ?: time();
			if ($type == Schema::DT_DATETIME || $type == Schema::DT_TIMESTAMP)
				$this->set($key,date($date.' H:i:s', $timestamp));
			elseif ($type == Schema::DT_DATE)
				$this->set($key,date($date, $timestamp));
			elseif ($type == Schema::DT_INT4)
				$this->set($key,$timestamp);
		}
	}

	/**
	 * Bind value to key
	 * @return mixed
	 * @param $key string
	 * @param $val mixed
	 */
	function set($key, $val) {
		if ($key == '_id' && $this->dbsType == 'sql')
			$key = $this->primary;
		elseif ($key == 'id' && $this->dbsType != 'sql')
			$key = '_id';
		$fields = $this->fieldConf;
		unset($this->fieldsCache[$key]);
		// pre-process if field config available
		if (!empty($fields) && isset($fields[$key]) && is_array($fields[$key])) {
			// handle relations
			if (isset($fields[$key]['belongs-to-one'])) {
				// one-to-many, one-to-one
				if (empty($val))
					$val = NULL;
				elseif (is_object($val) &&
					!($this->dbsType=='mongo' && (
						($this->db->legacy() && $val instanceof \MongoId) ||
						(!$this->db->legacy() && $val instanceof \MongoDB\BSON\ObjectId)))) {
					// fetch fkey from mapper
					if (!$val instanceof Cortex || $val->dry())
                        throw new \Exception(self::E_INVALID_RELATION_OBJECT);
					else {
						$relConf = $fields[$key]['belongs-to-one'];
						$rel_field = (is_array($relConf) ? $relConf[1] : '_id');
						$val = $val->get($rel_field,true);
					}
				} elseif ($this->dbsType == 'mongo' && (($this->db->legacy() && !$val instanceof \MongoId)
						|| (!$this->db->legacy() && !$val instanceof \MongoDB\BSON\ObjectId)))
					$val = $this->db->legacy() ? new \MongoId($val) : new \MongoDB\BSON\ObjectId($val);
			} elseif (isset($fields[$key]['has-one'])){
				$relConf = $fields[$key]['has-one'];
				if (empty($val)) {
					$val = $this->get($key);
					$val->set($relConf[1],NULL);
				} else {
					if (!$val instanceof Cortex) {
						$rel = $this->getRelInstance($relConf[0],null,$key);
						$rel->load(['_id = ?', $val]);
						$val = $rel;
					}
					$val->set($relConf[1], $this->_id);
				}
				$this->saveCsd[$key] = $val;
				return $val;
			} elseif (isset($fields[$key]['belongs-to-many'])) {
				// many-to-many, unidirectional
				$fields[$key]['type'] = self::DT_JSON;
				$relConf = $fields[$key]['belongs-to-many'];
				$rel_field = (is_array($relConf) ? $relConf[1] : '_id');
				$val = $this->getForeignKeysArray($val, $rel_field, $key);
			}
			elseif (isset($fields[$key]['has-many'])) {
				$relConf = $fields[$key]['has-many'];
				// many-to-many, bidirectional
				// many-to-one, inverse
				if ($relConf['hasRel'] == 'has-many'
					|| $relConf['hasRel'] == 'belongs-to-one') {
					// custom setter
					$val = $this->emit('set_'.$key, $val);
					$val = $this->getForeignKeysArray($val,'_id',$key);
					if (empty($val) && is_array($val))
						$val=new CortexCollection();
					$this->saveCsd[$key] = $val; // array of keys
					$this->fieldsCache[$key] = $val;
					return $val;
				}
			}
			// add nullable polyfill
			if ($val === NULL && ($this->dbsType == 'jig' || $this->dbsType == 'mongo')
				&& array_key_exists('nullable', $fields[$key]) && $fields[$key]['nullable'] === false)
                throw new \Exception(sprintf(self::E_NULLABLE_COLLISION,$key));
			// MongoId shorthand
			if ($this->dbsType == 'mongo' && (($this->db->legacy() && !$val instanceof \MongoId)
					|| (!$this->db->legacy() && !$val instanceof \MongoDB\BSON\ObjectId))) {
				if ($key == '_id')
					$val = $this->db->legacy() ? new \MongoId($val) : new \MongoDB\BSON\ObjectId($val);
				elseif (preg_match('/INT/i',$fields[$key]['type'])
					&& !isset($fields[$key]['relType']))
					$val = (int) $val;
			}
			// cast boolean
			if (preg_match('/BOOL/i',$fields[$key]['type']) && !($val===NULL
					&& isset($fields[$key]['nullable']) && $fields[$key]['nullable']==TRUE)) {
				$val = !$val || $val==='false' ? false : (bool) $val;
				if ($this->dbsType == 'sql')
					$val = (int) $val;
			}
			// custom setter
			$val = $this->emit('set_'.$key, $val);
			// clean datetime
			if (isset($fields[$key]['type']) && empty($val) &&
				in_array($fields[$key]['type'], [Schema::DT_DATE,Schema::DT_DATETIME])
			)
				$val=NULL;
			// convert array content
			if (is_array($val) && $this->dbsType == 'sql') {
				if ($fields[$key]['type']==self::DT_SERIALIZED)
					$val=serialize($val);
				elseif ($fields[$key]['type']==self::DT_JSON)
					$val=json_encode($val);
				else
                    throw new \Exception(sprintf(self::E_ARRAY_DATATYPE,$key));
			}
		} else {
			// custom setter
			$val = $this->emit('set_'.$key, $val);
		}
		// fluid SQL
		if ($this->fluid && $this->dbsType == 'sql') {
			$schema = new Schema($this->db);
			$table = $schema->alterTable($this->table);
			// add missing field
			if (!in_array($key,$table->getCols())) {
				// determine data type
				if (isset($this->fieldConf[$key]) && isset($this->fieldConf[$key]['type']))
					$type = $this->fieldConf[$key]['type'];
				elseif (is_int($val)) $type = $schema::DT_INT;
				elseif (is_float($val)) $type = $schema::DT_FLOAT;
				elseif (is_bool($val)) $type = $schema::DT_BOOLEAN;
				elseif (strlen($val)>10 && strtotime($val)) $type = $schema::DT_DATETIME;
				elseif (date('Y-m-d H:i:s', strtotime($val)) == $val) $type = $schema::DT_DATETIME;
				elseif (date('Y-m-d', strtotime($val)) == $val) $type = $schema::DT_DATE;
				elseif (\UTF::instance()->strlen($val)<=255) $type = $schema::DT_VARCHAR256;
				else $type = $schema::DT_TEXT;
				$table->addColumn($key)->type($type);
				$table->build();
				// update mapper fields
				$newField = $table->getCols(true);
				$newField = $newField[$key];
				$fields = $this->mapper->schema();
				$fields[$key] = $newField + ['value'=>NULL,'initial'=>NULL,'changed'=>NULL];
				$this->mapper->schema($fields);
			}
		}
		return $this->mapper->set($key, $val);
	}

	/**
	 * call custom field handlers
	 * @param $event
	 * @param $val
	 * @return mixed
	 */
	protected function emit($event, $val=null) {
		if (isset($this->trigger[$event])) {
			if (preg_match('/^[sg]et_/',$event)) {
				$val = (is_string($f=$this->trigger[$event])
					&& preg_match('/^[sg]et_/',$f))
					? call_user_func([$this,$event],$val)
					: \Base::instance()->call($f,[$this,$val]);
			} else
				$val = \Base::instance()->call($this->trigger[$event],[$this,$val]);
		} elseif (preg_match('/^[sg]et_/',$event) && method_exists($this,$event)) {
			$this->trigger[] = $event;
			$val = call_user_func([$this,$event],$val);
		}
		return $val;
	}

	/**
	 * Define a custom field setter
	 * @param $key
	 * @param $func
	 */
	public function onset($key, $func) {
		$this->trigger['set_'.$key] = $func;
	}

	/**
	 * Define a custom field getter
	 * @param $key
	 * @param $func
	 */
	public function onget($key, $func) {
		$this->trigger['get_'.$key] = $func;
	}

	/**
	 * virtual mapper field setter
	 * @param string $key
	 * @param mixed|callback $val
	 */
	public function virtual($key, $val) {
		$this->vFields[$key]=$val;
		if (!empty($this->whitelist)) {
			$this->whitelist[] = $key;
			$this->whitelist = array_unique($this->whitelist);
		}
	}

	/**
	 * reset virtual fields
	 * @param string $key
	 */
	public function clearVirtual($key=NULL) {
		if ($key)
			unset($this->vFields[$key]);
		else
			$this->vFields=[];
	}

	/**
	 * Retrieve contents of key
	 * @return mixed
	 * @param string $key
	 * @param bool $raw
	 */
	function &get($key, $raw = false) {
		// handle virtual fields
		if (isset($this->vFields[$key])) {
			$out = (is_callable($this->vFields[$key]))
				? call_user_func($this->vFields[$key], $this) : $this->vFields[$key];
			return $out;
		}
		$fields = $this->fieldConf;
		$id = $this->primary;
		if ($key == '_id' && $this->dbsType == 'sql')
			$key = $id;
		elseif ($key == 'id' && $this->dbsType != 'sql')
			$key = '_id';
		if ($this->whitelist && !in_array($key,$this->whitelist)) {
			$out = null;
			return $out;
		}
		if ($raw) {
			$out = $this->exists($key) ? $this->mapper->{$key} : NULL;
			if ($this->dbsType == 'mongo' && !$this->db->legacy() && $out instanceof \MongoDB\Model\BSONArray)
				$out = (array) $out;
			return $out;
		}
		if (!empty($fields) && isset($fields[$key]) && is_array($fields[$key])
			&& !array_key_exists($key,$this->fieldsCache)) {
			// load relations
			if (isset($fields[$key]['belongs-to-one'])) {
				// one-to-X, bidirectional, direct way
				if (!$this->exists($key) || is_null($this->mapper->{$key}))
					$this->fieldsCache[$key] = null;
				else {
					// get config for this field
					$relConf = $fields[$key]['belongs-to-one'];
					// fetch related model
					$rel = $this->getRelFromConf($relConf,$key);
					// am i part of a result collection?
					if ($cx = $this->getCollection()) {
						// does the collection has cached results for this key?
						if (!$cx->hasRelSet($key)) {
							// build the cache, find all values of current key
							$relKeys = array_unique($cx->getAll($key,true));
							// find related models
							$crit = [$relConf[1].' IN ?', $relKeys];
							$relSet = $rel->find($this->mergeWithRelFilter($key, $crit),
								$this->getRelFilterOption($key),$this->_ttl);
							// cache relSet, sorted by ID
							$cx->setRelSet($key, $relSet ? $relSet->getBy($relConf[1]) : NULL);
						}
						// get a subset of the preloaded set
						$result = $cx->getSubset($key,(string) $this->get($key,true));
						$this->fieldsCache[$key] = $result ? $result[0] : NULL;
					} else {
						$crit = [$relConf[1].' = ?', $this->get($key, true)];
						$crit = $this->mergeWithRelFilter($key, $crit);
						$this->fieldsCache[$key] = $rel->findone($crit,
							$this->getRelFilterOption($key),$this->_ttl);
					}
				}
			}
			elseif (($type = isset($fields[$key]['has-one']))
				|| isset($fields[$key]['has-many'])) {
				$type = $type ? 'has-one' : 'has-many';
				$fromConf = $fields[$key][$type];
				if (!is_array($fromConf))
                    throw new \Exception(sprintf(self::E_REL_CONF_INC, $key));
				$rel = $this->getRelInstance($fromConf[0],null,$key,true);
				$relFieldConf = $rel->getFieldConfiguration();
				$relType = isset($relFieldConf[$fromConf[1]]['belongs-to-one']) ?
					'belongs-to-one' : 'has-many';
				// one-to-*, bidirectional, inverse way
				if ($relType == 'belongs-to-one') {
					$toConf = $relFieldConf[$fromConf[1]]['belongs-to-one'];
					if (!is_array($toConf))
						$toConf = [$toConf, $id];
					if ($toConf[1] != $id && (!$this->exists($toConf[1])
							|| is_null($this->mapper->get($toConf[1]))))
						$this->fieldsCache[$key] = null;
					elseif ($cx=$this->getCollection()) {
						// part of a result set
						if (!$cx->hasRelSet($key)) {
							// emit eager loading
							$relKeys = $cx->getAll($toConf[1],true);
							$crit = [$fromConf[1].' IN ?', $relKeys];
							$relSet = $rel->find($this->mergeWithRelFilter($key,$crit),
								$this->getRelFilterOption($key),$this->_ttl);
							$cx->setRelSet($key, $relSet ? $relSet->getBy($fromConf[1],true) : NULL);
						}
						$result = $cx->getSubset($key, [$this->get($toConf[1])]);
						$this->fieldsCache[$key] = $result ? (($type == 'has-one')
							? $result[0][0] : CortexCollection::factory($result[0])) : NULL;
					}	// no collection
					elseif (($val=$this->getRaw($toConf[1]))) {
						$crit=[$fromConf[1].' = ?',$val];
						$crit=$this->mergeWithRelFilter($key,$crit);
						$opt=$this->getRelFilterOption($key);
						$this->fieldsCache[$key]=(($type=='has-one')
							?$rel->findone($crit,$opt,$this->_ttl)
							:$rel->find($crit,$opt,$this->_ttl))?:NULL;
					} else
						$this->fieldsCache[$key] = NULL;
				}
				// many-to-many, bidirectional
				elseif ($relType == 'has-many') {
					$toConf = $relFieldConf[$fromConf[1]]['has-many'];
					$mmTable = $this->mmTable($fromConf,$key,$toConf);
					// create mm table mapper
					if (!$this->get($id,true)) {
						$this->fieldsCache[$key] = null;
						return $this->fieldsCache[$key];
					}
					$id = $toConf['relPK'];
					$rel = $this->getRelInstance(null,['db'=>$this->db,'table'=>$mmTable]);
					if ($cx = $this->getCollection()) {
						if (!$cx->hasRelSet($key)) {
							// get IDs of all results
							$relKeys = $cx->getAll($id,true);
							// get all pivot IDs
							$filter = [$fromConf['relField'].' IN ?',$relKeys];
							if ($fromConf['isSelf']) {
								$filter[0].= ' OR '.$fromConf['selfRefField'].' IN ?';
								$filter[] = $relKeys;
							}
							$mmRes = $rel->find($filter,null,$this->_ttl);
							if (!$mmRes)
								$cx->setRelSet($key, NULL);
							else {
								$pivotRel = [];
								$pivotKeys = [];
								foreach($mmRes as $model) {
									$val = $model->get($toConf['relField'],true);
									if ($fromConf['isSelf']) {
										$refVal = $model->get($fromConf['selfRefField'],true);
										$pivotRel[(string) $refVal][] = $val;
										$pivotRel[(string) $val][] = $refVal;
										$pivotKeys[] = $val;
										$pivotKeys[] = $refVal;
									} else {
										$pivotRel[ (string) $model->get($fromConf['relField'])][] = $val;
										$pivotKeys[] = $val;
									}
								}
								// cache pivot keys
								$cx->setRelSet($key.'_pivot', $pivotRel);
								// preload all rels
								$pivotKeys = array_unique($pivotKeys);
								$fRel = $this->getRelInstance($fromConf[0],null,$key,true);
								$crit = [$id.' IN ?', $pivotKeys];
								$relSet = $fRel->find($this->mergeWithRelFilter($key, $crit),
									$this->getRelFilterOption($key),$this->_ttl);
								$cx->setRelSet($key, $relSet ? $relSet->getBy($id) : NULL);
								unset($fRel);
							}
						}
						// fetch subset from preloaded rels using cached pivot keys
						$fkeys = $cx->getSubset($key.'_pivot', [$this->get($id)]);
						$this->fieldsCache[$key] = $fkeys ?
							CortexCollection::factory($cx->getSubset($key, $fkeys[0])) : NULL;
					} // no collection
					else {
						// find foreign keys
						$fId=$this->get($fromConf['localKey'],true);
						$filter = [$fromConf['relField'].' = ?',$fId];
						if ($fromConf['isSelf']) {
							$filter[0].= ' OR '.$fromConf['selfRefField'].' = ?';
							$filter[] = $filter[1];
						}
						$results = $rel->find($filter,null,$this->_ttl);
						if (!$results)
							$this->fieldsCache[$key] = NULL;
						else {
							$fkeys = $results->getAll($toConf['relField'],true);
							if (empty($fkeys))
                                throw new \Exception(sprintf('Got empty foreign keys from "%s"', $rel->getTable().'.'.$toConf['relField']));
							else {
								if ($fromConf['isSelf']) {
									// merge both rel sides and remove itself
									$fkeys = array_diff(array_merge($fkeys,
										$results->getAll($toConf['selfRefField'],true)),[$fId]);
								}
								// create foreign table mapper
								unset($rel);
								$rel = $this->getRelInstance($fromConf[0],null,$key,true);
								// load foreign models
								$filter = [$id.' IN ?', $fkeys];
								$filter = $this->mergeWithRelFilter($key, $filter);
								$this->fieldsCache[$key] = $rel->find($filter,
									$this->getRelFilterOption($key),$this->_ttl);
							}
						}
					}
				}
			}
			elseif (isset($fields[$key]['belongs-to-many'])) {
				// many-to-many, unidirectional
				$fields[$key]['type'] = self::DT_JSON;
				$result = $this->getRaw($key);
				if ($this->dbsType == 'sql')
					$result = json_decode($result?:'', true);
				if (!is_array($result))
					$this->fieldsCache[$key] = $result;
				else {
					// create foreign table mapper
					$relConf = $fields[$key]['belongs-to-many'];
					$rel = $this->getRelFromConf($relConf,$key);
					$fkeys = [];
					foreach ($result as $el)
						$fkeys[] = (is_int($el)||ctype_digit((string)$el))?(int)$el:(string)$el;
					// if part of a result set
					if ($cx = $this->getCollection()) {
						if (!$cx->hasRelSet($key)) {
							// find all keys
							$relKeys = ($cx->getAll($key,true));
							if ($this->dbsType == 'sql'){
								foreach ($relKeys as &$val) {
									$val = substr($val, 1, -1);
									unset($val);
								}
								$relKeys = json_decode('['.implode(',',$relKeys).']');
							} else
								$relKeys = call_user_func_array('array_merge', $relKeys);
							// get related models
							if (!empty($relKeys)) {
								$crit = [$relConf[1].' IN ?', array_unique($relKeys)];
								$relSet = $rel->find($this->mergeWithRelFilter($key, $crit),
									$this->getRelFilterOption($key),$this->_ttl);
								// cache relSet, sorted by ID
								$cx->setRelSet($key, $relSet ? $relSet->getBy($relConf[1]) : NULL);
							} else
								$cx->setRelSet($key, NULL);
						}
						// get a subset of the preloaded set
						$subset = $cx->getSubset($key, $fkeys);
						$this->fieldsCache[$key] = $subset ? CortexCollection::factory($subset) : NULL;
					} else {
						// load foreign models
						$filter = [$relConf[1].' IN ?', $fkeys];
						$filter = $this->mergeWithRelFilter($key, $filter);
						$this->fieldsCache[$key] = $rel->find($filter,
							$this->getRelFilterOption($key),$this->_ttl);
					}
				}
			}
			// resolve array fields
			elseif (isset($fields[$key]['type'])) {
				if ($this->dbsType == 'sql') {
					if ($fields[$key]['type'] == self::DT_SERIALIZED)
						$this->fieldsCache[$key] = unserialize($this->mapper->{$key}, ['allowed_classes' => false]);
					elseif ($fields[$key]['type'] == self::DT_JSON)
						$this->fieldsCache[$key] = json_decode($this->mapper->{$key}?:'',true);
				}
				if ($this->exists($key) && preg_match('/BOOL/i',$fields[$key]['type'])) {
					$field_val = $this->mapper->{$key};
					if (isset($fields[$key]['nullable']) && $fields[$key]['nullable'] == TRUE
						&& $field_val===NULL)
						$this->fieldsCache[$key] = $field_val;
					else
						$this->fieldsCache[$key] = (bool) $this->mapper->{$key};
				}
			}
		}
		// fetch cached value, if existing
		// TODO: fix array key reference editing, #71
//		if (array_key_exists($key,$this->fieldsCache))
//			$val = $this->fieldsCache[$key];
//		elseif ($this->exists($key)) {
//			$val =& $this->mapper->{$key};
//		} else
//			$val = NULL;
		$val = array_key_exists($key,$this->fieldsCache) ? $this->fieldsCache[$key]
			: (($this->exists($key)) ? $this->mapper->{$key} : null);
		if ($this->dbsType == 'mongo' && (($this->db->legacy() && $val instanceof \MongoId) ||
				(!$this->db->legacy() && $val instanceof \MongoDB\BSON\ObjectId))) {
			// conversion to string makes further processing in template, etc. much easier
			$val = (string) $val;
		}
		// custom getter
		$out = $this->emit('get_'.$key, $val);
		return $out;
	}

	/**
	 * return raw value of a field
	 * @param $key
	 * @return mixed
	 */
	function &getRaw($key) {
		return $this->get($key, true);
	}

	/**
	 * check if a certain field exists in the mapper or
	 * or is a virtual relation field
	 * @param string $key
	 * @param bool $relField
	 * @return bool
	 */
	function exists($key, $relField = false) {
		if (!$this->dry() && ($key == '_id' || ($key == 'id' && $this->dbsType != 'sql')))
			return true;
		return $this->mapper->exists($key) ||
			($relField && isset($this->fieldConf[$key]['relType']));
	}

	/**
	 * clear any mapper field or relation
	 * @param string $key
	 * @return NULL|void
	 */
	function clear($key) {
		unset($this->fieldsCache[$key]);
		if (isset($this->fieldConf[$key]['relType']))
			$this->set($key,null);
		$this->mapper->clear($key);
	}

}
