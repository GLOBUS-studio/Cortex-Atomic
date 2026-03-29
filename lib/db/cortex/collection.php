<?php

namespace DB\Cortex;

use DB\Cortex;
use DB\Cursor;

class CortexCollection extends \ArrayIterator {

	protected
		$relSets = [],
		$pointer = 0,
		$changed = false,
		$cid;

	const
		E_UnknownCID = 'This Collection does not exist: %s',
		E_SubsetKeysValue = '$keys must be an array or split-able string, but %s was given.';

	public function __construct() {
		$this->cid = uniqid('cortex_collection_');
		parent::__construct();
	}

	//! Prohibit cloning to ensure an existing relation cache
	private function __clone() { }

	/**
	 * set a collection of models
	 * @param $models
	 */
	function setModels($models, $init=true) {
		array_map([$this,'add'],$models);
		if ($init)
			$this->changed = false;
	}

	/**
	 * add single model to collection
	 * @param $model
	 */
	function add(Cortex $model) {
		$model->addToCollection($this);
		$this->append($model);
	}

	#[\ReturnTypeWillChange]
	public function offsetSet($i, $val) {
		$this->changed=true;
		parent::offsetSet($i,$val);
	}

	public function hasChanged() {
		return $this->changed;
	}

	/**
	 * get a related collection
	 * @param $key
	 * @return null
	 */
	public function getRelSet($key) {
		return (isset($this->relSets[$key])) ? $this->relSets[$key] : null;
	}

	/**
	 * set a related collection for caching it for the lifetime of this collection
	 * @param $key
	 * @param $set
	 */
	public function setRelSet($key,$set) {
		$this->relSets[$key] = $set;
	}

	/**
	 * check if a related collection exists in runtime cache
	 * @param $key
	 * @return bool
	 */
	public function hasRelSet($key) {
		return array_key_exists($key,$this->relSets);
	}

	public function expose() {
		return $this->getArrayCopy();
	}

	/**
	 * get an intersection from a cached relation-set, based on given keys
	 * @param string $prop
	 * @param array|string $keys
	 * @return array
	 */
	public function getSubset($prop, $keys) {
		if (is_string($keys))
			$keys = \Base::instance()->split($keys);
		if (!is_array($keys))
            throw new \Exception(sprintf(self::E_SubsetKeysValue,gettype($keys)));
		if (!$this->hasRelSet($prop) || !($relSet = $this->getRelSet($prop)))
			return null;
		foreach ($keys as &$key) {
			if ($key instanceof \MongoId || $key instanceof \MongoDB\BSON\ObjectId)
				$key = (string) $key;
			unset($key);
		}
		return array_values(array_intersect_key($relSet, array_flip($keys)));
	}

	/**
	 * returns all values of a specified property from all models
	 * @param string $prop
	 * @param bool $raw
	 * @return array
	 */
	public function getAll($prop, $raw = false) {
		$out = [];
		foreach ($this->getArrayCopy() as $model) {
			if ($model instanceof Cortex && $model->exists($prop,true)) {
				$val = $model->get($prop, $raw);
				if (!empty($val))
					$out[] = $val;
			} elseif($raw) {
				if (!is_array($model))
					$out[] = $model;
				elseif (!empty($model[$prop]))
					$out[] = $model[$prop];
			}
		}
		return $out;
	}

	/**
	 * cast all contained mappers to a nested array
	 * @param int|array $rel_depths depths to resolve relations
	 * @return array
	 */
	public function castAll($rel_depths=1) {
		$out = [];
		foreach ($this->getArrayCopy() as $model)
			$out[] = $model->cast(null,$rel_depths);
		return $out;
	}

	/**
	 * return all models keyed by a specified index key
	 * @param string $index
	 * @param bool $nested
	 * @return array
	 */
	public function getBy($index, $nested = false) {
		$out = [];
		foreach ($this->getArrayCopy() as $model)
			if ($model->exists($index)) {
				$val = $model->get($index, true);
				if (!empty($val))
					if($nested) $out[(string) $val][] = $model;
					else        $out[(string) $val] = $model;
			}
		return $out;
	}

	/**
	 * re-assort the current collection using a sql-like syntax
	 * @param $cond
	 */
	public function orderBy($cond) {
		$cols=\Base::instance()->split($cond);
		$this->uasort(function($val1,$val2) use($cols) {
			foreach ($cols as $col) {
				$parts=explode(' ',$col,2);
				$order=empty($parts[1])?'ASC':$parts[1];
				$col=$parts[0];
				list($v1,$v2)=[$val1[$col],$val2[$col]];
				if ($out=strnatcmp($v1?:'',$v2?:'')*
					((strtoupper($order)=='ASC')*2-1))
					return $out;
			}
			return 0;
		});
	}

	/**
	 * slice the collection
	 * @param $offset
	 * @param null $limit
	 */
	public function slice($offset, $limit=null) {
		$this->rewind();
		$i=0;
		$del=[];
		while ($this->valid()) {
			if ($i < $offset)
				$del[]=$this->key();
			elseif ($i >= $offset && $limit && $i >= ($offset+$limit))
				$del[]=$this->key();
			$i++;
			$this->next();
		}
		foreach ($del as $ii)
			unset($this[$ii]);
	}

	/**
	 * compare collection with a given ID stack
	 * @param array|CortexCollection $stack
	 * @param string $cpm_key
	 * @return array
	 */
	public function compare($stack,$cpm_key='_id') {
		if ($stack instanceof CortexCollection)
			$stack = $stack->getAll($cpm_key,true);
		$keys = $this->getAll($cpm_key,true);
		$out = [];
		$new = array_diff($stack,$keys);
		$old = array_diff($keys,$stack);
		if ($new)
			$out['new'] = $new;
		if ($old)
			$out['old'] = $old;
		return $out;
	}

	/**
	 * check if the collection contains a record with the given key-val set
	 * @param mixed $val
	 * @param string $key
	 * @return bool
	 */
	public function contains($val,$key='_id') {
		$rel_ids = $this->getAll($key, true);
		if ($val instanceof Cursor)
			$val = $val->{$key};
		return in_array($val,$rel_ids);
	}

	/**
	 * create a new hydrated collection from the given records
	 * @param $records
	 * @return CortexCollection
	 */
	static public function factory($records) {
		$cc = new self();
		$cc->setModels($records);
		return $cc;
	}

}
