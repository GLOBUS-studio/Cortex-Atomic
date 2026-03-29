<?php

/**
 *  CastTrait - Data casting, navigation and comparison for Cortex ORM
 *
 *  Extracted from Cortex class.
 *  Methods: cast, castField, factory, copyfrom, copyto, copyto_flat,
 *           compare, reset, resetFields, defaults, skip, first, last, loaded,
 *           changed, cleared, initial
 *
 *  @package DB
 */

namespace DB;

trait CastTrait {

	/**
	 * Count records that are currently loaded
	 * @return int
	 */
	public function loaded() {
		return count($this->mapper->query);
	}

	/**
	 * Return fields of mapper object as an associative array
	 * @return array
	 * @param bool|Cortex $obj
	 * @param int|array $rel_depths depths to resolve relations
	 */
	public function cast($obj = NULL, $rel_depths = 1) {
		$fields = $this->mapper->cast( ($obj) ? $obj->mapper : null );
		if (!empty($this->vFields))
			foreach(array_keys($this->vFields) as $key)
				$fields[$key]=$this->get($key);
		if (is_int($rel_depths))
			$rel_depths = ['*'=>$rel_depths-1];
		elseif (is_array($rel_depths))
			$rel_depths['*'] = isset($rel_depths['*'])?--$rel_depths['*']:-1;
		$mask=[];
		$relMasks=[];
		if ($rel_depths)
			// collect field mask for relations
			foreach($rel_depths as $i=>$val)
				if (is_int($i)) {
					if (str_contains($val,'.')) {
						list($key, $relMask) = explode('.',$val,2);
						$relMasks[$key][] = $relMask;
					} else
						$mask[] = $val;
					unset($rel_depths[$i]);
				}
		if ($this->fieldConf) {
			$fields += array_fill_keys(array_keys($this->fieldConf),NULL);
			if ($this->whitelist)
				$fields = array_intersect_key($fields, array_flip($this->whitelist));
			$mp = $obj ? : $this;
			foreach ($fields as $key => &$val) {
				// post process configured fields
				if (isset($this->fieldConf[$key]) && is_array($this->fieldConf[$key])) {
					// handle relations
					$rd = isset($rel_depths[$key]) ? $rel_depths[$key] : $rel_depths['*'];
					// assemble field mask
					if (isset($relMasks[$key])) {
						if (!is_array($rd))
							$rd = $rd=['*'=>$rd-1];
						$rd = array_merge($rd, $relMasks[$key]);
					}
					// fetch relations
					if ((is_array($rd) || $rd >= 0) && $type=preg_grep('/(belongs|has)-(to-)*(one|many)/',
							array_keys($this->fieldConf[$key]))) {
						$relType=current($type);
						// cast relations
						$val = (($relType == 'belongs-to-one' || $relType == 'belongs-to-many')
							&& !$mp->exists($key)) ? NULL : $mp->get($key);
						if ($val instanceof Cortex)
							$val = $val->cast(null, $rd);
						elseif ($val instanceof CortexCollection)
							$val = $val->castAll($rd);
					}
					// extract array fields
					elseif (isset($this->fieldConf[$key]['type'])) {
						if ($this->dbsType == 'sql') {
							if ($this->fieldConf[$key]['type'] == self::DT_SERIALIZED)
								$val=unserialize($mp->mapper->{$key}?:'', ['allowed_classes' => false]);
							elseif ($this->fieldConf[$key]['type'] == self::DT_JSON)
								$val=json_decode($mp->mapper->{$key}?:'', true);
						}
						if ($this->exists($key)
							&& preg_match('/BOOL/i',$this->fieldConf[$key]['type'])) {
							$field_val = $mp->mapper->{$key};
							if (isset($this->fieldConf[$key]['nullable'])
								&& $this->fieldConf[$key]['nullable']==TRUE && $field_val===NULL)
								$val = $field_val;
							else
								$val = (bool) $field_val;
						}
					}
				}
				if ($this->dbsType == 'mongo' && $key == '_id')
					$val = (string) $val;
				if ($this->dbsType == 'sql' && $key == 'id' && $this->standardiseID) {
					$fields['_id'] = $val;
					unset($fields[$key]);
				}
				unset($val);
			}
		}
		// custom getter
		foreach ($fields as $key => &$val) {
			if ($mask && !in_array($key,$mask) && !array_key_exists($key,$relMasks)) {
				unset($fields[$key]);
				continue;
			}
			$val = $this->emit('get_'.$key, $val);
			unset($val);
		}
		return $fields;
	}

	/**
	 * cast a related collection of mappers
	 * @param string $key field name
	 * @param int $rel_depths  depths to resolve relations
	 * @return array    array of associative arrays
	 */
	function castField($key, $rel_depths=0) {
		if (!$key)
			return NULL;
		$mapper_arr = $this->get($key);
		if(!$mapper_arr)
			return NULL;
		$out = [];
		foreach ($mapper_arr as $mp)
			$out[] = $mp->cast(null,$rel_depths);
		return $out;
	}

	/**
	 * wrap result mapper
	 * @param Cursor|array $mapper
	 * @return Cortex
	 */
	protected function factory($mapper) {
		if (is_array($mapper)) {
			$mp = clone($this->mapper);
			$mp->reset();
			$mp->query=[$mp];
			$cx = $this->factory($mp);
			$cx->copyfrom($mapper);
		} else {
			$cx = clone($this);
			$cx->reset(false);
			$cx->mapper = $mapper;
		}
		$cx->emit('load');
		return $cx;
	}

	/**
	 * hydrate the mapper from hive key or given array
	 * @param string|array $key
	 * @param callback|array|string $fields
	 * @return NULL
	 */
	public function copyfrom($key, $fields = null) {
		$f3 = \Base::instance();
		$srcfields = is_array($key) ? $key : $f3->get($key);
		if ($fields)
			if (is_callable($fields))
				$srcfields = $fields($srcfields);
			else {
				if (is_string($fields))
					$fields = $f3->split($fields);
				$srcfields = array_intersect_key($srcfields, array_flip($fields));
			}
		foreach ($srcfields as $key => $val) {
			if (isset($this->fieldConf[$key]) && isset($this->fieldConf[$key]['type'])) {
				if ($this->fieldConf[$key]['type'] == self::DT_JSON && is_string($val))
					$val = json_decode($val?:'',true);
				elseif ($this->fieldConf[$key]['type'] == self::DT_SERIALIZED && is_string($val))
					$val = unserialize($val?:'', ['allowed_classes' => false]);
			}
			$this->set($key, $val);
		}
	}

	/**
	 * copy mapper values into hive key
	 * @param string $key the hive key to copy into
	 * @param int $relDepth the depth of relations to resolve
	 * @return NULL|void
	 */
	public function copyto($key, $relDepth=0) {
		\Base::instance()->set($key, $this->cast(null,$relDepth));
	}

	/**
	 * copy to hive key with relations being simple arrays of keys
	 * @param $key
	 */
	function copyto_flat($key) {
		/** @var \Base $f3 */
		$f3 = \Base::instance();
		$this->copyto($key);
		foreach ($this->fields() as $field) {
			if (isset($this->fieldConf[$field]) && isset($this->fieldConf[$field]['relType'])
				&& $this->fieldConf[$field]['relType']=='has-many'
				&& $f3->devoid($key.'.'.$field)) {
				$val = $this->get($field);
				if ($val instanceof CortexCollection) {
					$relKey = isset($this->fieldConf[$field]['has-many']['relPK']) ?
						$this->fieldConf[$field]['has-many']['relPK'] : '_id';
					$f3->set($key.'.'.$field,$val->getAll($relKey));
				}
				elseif (is_array($val))
					$f3->set($key.'.'.$field,$val);
				else
					$f3->clear($key.'.'.$field);
			}
		}
	}

	public function skip($ofs = 1) {
		$this->reset(false);
		if ($this->mapper->skip($ofs))
			return $this;
		else
			$this->reset(false);
	}

	public function first() {
		$this->reset(false);
		$this->mapper->first();
		return $this;
	}

	public function last() {
		$this->reset(false);
		$this->mapper->last();
		return $this;
	}

	/**
	 * reset and re-initialize the mapper
	 * @param bool $mapper
	 * @param bool $essentials
	 * @return NULL|void
	 */
	public function reset($mapper = true, $essentials=true) {
		if ($mapper)
			$this->mapper->reset();
		$this->fieldsCache=[];
		$this->saveCsd=[];
		if ($essentials) {
			// used to store filter conditions and parameters
			$this->countFields=[];
			$this->preBinds=[];
			$this->grp_stack=null;
		}
		// set default values
		if (($this->dbsType == 'jig' || $this->dbsType == 'mongo')
			&& !empty($this->fieldConf))
			foreach($this->fieldConf as $field_key => $field_conf)
				if (array_key_exists('default',$field_conf)) {
					$val = ($field_conf['default'] === \DB\SQL\Schema::DF_CURRENT_TIMESTAMP)
						? date('Y-m-d H:i:s') : $field_conf['default'];
					$this->set($field_key, $val);
				}
	}

	/**
	 * reset only specific fields and return to their default values
	 * @param array $fields
	 */
	public function resetFields(array $fields) {
		$defaults = $this->defaults();
		foreach ($fields as $field) {
			unset($this->fieldsCache[$field]);
			unset($this->saveCsd[$field]);
			if (isset($defaults[$field]))
				$this->set($field,$defaults[$field]);
			else {
				$this->set($field,NULL);
			}
		}
	}

	/**
	 * return default values from schema configuration
	 * @param bool $set set default values to mapper
	 * @return array
	 */
	function defaults($set=false) {
		$out = [];
		$fields = $this->fieldConf;
		if ($this->dbsType == 'sql')
			$fields = array_replace_recursive($this->mapper->schema(),$fields);
		foreach($fields as $field_key => $field_conf)
			if (array_key_exists('default',$field_conf)) {
				$val = ($field_conf['default'] === \DB\SQL\Schema::DF_CURRENT_TIMESTAMP)
					? date('Y-m-d H:i:s') : $field_conf['default'];
				if ($val!==NULL) {
					$out[$field_key]=$val;
					if ($set)
						$this->set($field_key, $val);
				}
			}
		return $out;
	}

	/**
	 * return TRUE if any/specified field value has changed
	 * @param string $key
	 * @return mixed
	 */
	public function changed($key=null) {
		if ($key=='_id')
			$key = $this->primary;
		if (method_exists($this->mapper,'changed'))
			return $this->mapper->changed($key);
		else
            throw new \Exception('method does not exist on mapper');
	}

	/**
	 * return initial field value if field was cleared, otherwise FALSE
	 * @param $key
	 * @return bool|mixed
	 */
	function cleared($key) {
		$fields = $this->mapper->schema();
		if (!empty($fields[$key]['initial']) && empty($fields[$key]['value']))
			return $this->initial($key);
		return false;
	}

	/**
	 * return initial field value
	 * @param $key
	 * @return mixed
	 */
	function initial($key) {
		$fields = $this->mapper->schema();
		if (!empty($fields[$key]['initial'])) {
			if ($this->fieldConf[$key]['type'] == self::DT_JSON)
				return json_decode($fields[$key]['initial'], true);
			elseif ($this->fieldConf[$key]['type'] == self::DT_SERIALIZED)
				return unserialize($fields[$key]['initial'], ['allowed_classes' => false]);
		}
		return $fields[$key]['initial'];
	}

	/**
	 * compare new data as an assoc array of [field => value] against the initial field values
	 * callback functions for $new and $old values can be used to prepare new / cleanup old data
	 * updated fields are set, $new callback must return a value
	 * it's also possible to target children fields, i.e. [fieldA.fieldB[2].foo => value]
	 * @param array $fields
	 * @param callback $new
	 * @param callback $old
	 */
	function compare($fields, $new, $old=NULL) {
		/** @var \Base $f3 */
		$f3 = \Base::instance();
		foreach ($fields as $field=>$data) {
			$partial=false;
			$rootKey=false;
			$parts = preg_split('/(\[.*?\]\.|\.)/', $field, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
			if (count($parts) > 1) {
				$rootKey=array_shift($parts);
				$partial=ltrim(implode($parts),'.');
			}
			if (!empty($data)) {
				if ($partial) {
					$init = $this->initial($rootKey);
					$init = $f3->ref($partial,false,$init);
				} else {
					$init = $this->initial($field);
				}
				if (is_array($data)) {
					// cleanup old values
					if ($init && $old) {
						$old_values = array_diff($init,$data);
						if ($old_values)
							foreach ($old_values as $val)
								call_user_func($old,$val);
					}
					if ($new)
						// handle new values
						foreach ($data as &$val) {
							$val = call_user_func($new,$val);
							unset($val);
						}
				}
				else {
					// changed
					if ($init !== $data && $old && $init)
						call_user_func($old,$init);
					if ($init !== $data && $new)
						$data = call_user_func($new,$data);
				}
			} elseif ($old) {
				if ($partial) {
					$old_values = $this->initial($rootKey);
					// cleanup old in array context
					if (($pos=strpos($partial,'['))!==FALSE) {
						$node = substr($partial,0,strpos($partial,'['));
						$field_value = $pos === 0 ? $old_values : $f3->ref($node,false,$old_values);
						$old_values = [];
						if ($field_value) {
							$fkey = $parts[count($parts)-1];
							foreach ($field_value as $v)
								if (!empty($v[$fkey]))
									$old_values[] = $v[$fkey];
 						}
					} else {
						$field_value = $f3->ref($partial,false,$old_values);
						$old_values = !empty($field_value) ? $field_value : null;
					}
				} else {
					$old_values = $this->cleared($field);
				}
				if ($old_values) {
					// cleanup all
					if (!is_array($old_values))
						$old_values=[$old_values];
					foreach ($old_values as $val)
						call_user_func($old,$val);
				}
			}
			if ($data) {
				if ($partial) {
					$rootFieldData = $this->get($rootKey);
					$fieldData = &$f3->ref($partial,true,$rootFieldData);
					$fieldData = $data;
					$this->set($rootKey, $rootFieldData);
					unset($fieldData);
				} else {
					$this->set($field, $data);
				}
			}
		}
	}

}
