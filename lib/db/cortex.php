<?php

/**
 *  Cortex-Atomic - a multi-engine ORM / ODM
 *  Part of Atomic Framework: https://github.com/MADEVAL/Atomic-Framework
 *
 *  Developed by GLOBUS.studio
 *  https://github.com/GLOBUS-studio/Cortex-Atomic
 *
 *  Based on Cortex by Christian Knuth (ikkez) for Fat-Free Framework
 *  https://github.com/ikkez/F3-Sugar/
 *
 *  The contents of this file are subject to the terms of the GNU General
 *  Public License Version 3.0. You may not use this file except in
 *  compliance with the license. Any of the license terms and conditions
 *  can be waived if you get permission from the copyright holder.
 *
 *  @package DB
 *  @version 2.0.0
 *  @date 29.03.2026
 *  @since 22.01.2013
 */

namespace DB;
use DB\Cortex\Schema\Schema;

require_once __DIR__.'/cortex/schema/db_utils.php';
require_once __DIR__.'/cortex/schema/schema.php';
require_once __DIR__.'/cortex/schema/tablebuilder.php';
require_once __DIR__.'/cortex/schema/tablecreator.php';
require_once __DIR__.'/cortex/schema/tablemodifier.php';
require_once __DIR__.'/cortex/schema/column.php';
require_once __DIR__.'/cortex/constraintadapter.php';
require_once __DIR__.'/cortex/schemabuilder.php';
require_once __DIR__.'/cortex/cast.php';
require_once __DIR__.'/cortex/fieldaccess.php';
require_once __DIR__.'/cortex/relation.php';
require_once __DIR__.'/cortex/crud.php';

class Cortex extends Cursor {

	use SchemaBuilderTrait;
	use CastTrait;
	use FieldAccessTrait;
	use RelationTrait;
	use CrudTrait;

	protected
		// config
		$db,            // DB object [ \DB\SQL, \DB\Jig, \DB\Mongo ]
		$table,         // selected table, string
		$fluid,         // fluid sql schema mode, boolean
		$fieldConf,     // field configuration, array
		$ttl,           // default mapper schema ttl
		$rel_ttl,       // default mapper rel ttl
		$primary,       // SQL table primary key
		// behaviour
		$smartLoading,  // intelligent lazy eager loading, boolean
		$standardiseID, // return standardized '_id' field for SQL when casting
		// internals
		$dbsType,       // mapper engine type [jig, sql, mongo]
		$fieldsCache,   // relation field cache
		$saveCsd,       // mm rel save cascade
		$collection,    // collection
		$relFilter,     // filter for loading related models
		$hasCond,       // IDs of records the next find should have
		$whitelist,     // restrict to these fields
		$relWhitelist,  // restrict relations to these fields
		$eagerLoad,     // relations to eager-load via with()
		$grp_stack,     // stack of group conditions
		$countFields,   // relational counter buffer
		$preBinds,      // bind values to be prepended to $filter
		$vFields,       // virtual fields buffer
		$_ttl,          // rel_ttl overwrite
		$charset;       // sql collation charset

	/** @var Cursor */
	protected $mapper;

	/** @var CortexQueryParser */
	protected $queryParser;

	/** @var bool initialization flag */
	static $init = false;

	/** @var array sql table schema cache */
	static $schema_cache = [];

	const
		// special datatypes
		/** @deprecated Use DT_JSON instead. DT_SERIALIZED is unsafe and will be removed in a future version. */
		DT_SERIALIZED = 'SERIALIZED',
		DT_JSON = 'JSON',

		// error messages
		E_ARRAY_DATATYPE = 'Unable to save an Array in field %s. Use DT_JSON.',
		E_DT_SERIALIZED_DEPRECATED = 'DT_SERIALIZED is deprecated and will be removed in a future version. Use DT_JSON instead.',
		E_CONNECTION = 'No valid DB Connection given.',
		E_NO_TABLE = 'No table specified.',
		E_UNKNOWN_DB_ENGINE = 'This unknown DB system is not supported: %s',
		E_FIELD_SETUP = 'No field setup defined',
		E_UNKNOWN_FIELD = 'Field %s does not exist in %s.',
		E_INVALID_RELATION_OBJECT = 'You can only save hydrated mapper objects',
		E_NULLABLE_COLLISION = 'Unable to set NULL to the NOT NULLABLE field: %s',
		E_WRONG_RELATION_CLASS = 'Relations only works with Cortex objects',
		E_MM_REL_VALUE = 'Invalid value for many field "%s". Expecting null, split-able string, hydrated mapper object, or array of mapper objects.',
		E_MM_REL_CLASS = 'Mismatching m:m relation config from class `%s` to `%s`.',
		E_MM_REL_FIELD = 'Mismatching m:m relation keys from `%s` to `%s`.',
		E_REL_CONF_INC = 'Incomplete relation config for `%s`. Linked key is missing.',
		E_MISSING_REL_CONF = 'Cannot create related model. Specify a model name or relConf array.',
		E_HAS_COND = 'Cannot use a "has"-filter on a non-bidirectional relation field';

	/**
	 * init the ORM, based on given DBS
	 * @param null|object $db
	 * @param string      $table
	 * @param null|bool   $fluid
	 * @param int         $ttl
	 */
	public function __construct($db = NULL, $table = NULL, $fluid = NULL, $ttl = 0) {
		if (!is_null($fluid))
			$this->fluid = $fluid;
		if (!is_object($this->db=(is_string($db=($db?:$this->db))
				? \Base::instance()->get($db):$db)) && !static::$init)
            throw new \Exception(self::E_CONNECTION);
		if ($this->db instanceof Jig)
			$this->dbsType = 'jig';
		elseif ($this->db instanceof SQL)
			$this->dbsType = 'sql';
		elseif ($this->db instanceof Mongo)
			$this->dbsType = 'mongo';
		if ($table)
			$this->table = $table;
		if ($this->dbsType != 'sql')
			$this->primary = '_id';
		elseif (!$this->primary)
			$this->primary = 'id';
		$this->table = $this->getTable();
		if (!$this->table)
            throw new \Exception(self::E_NO_TABLE);
		$this->ttl = $ttl ?: ($this->ttl ?: 60);
		if (!$this->rel_ttl)
			$this->rel_ttl = 0;
		$this->_ttl = $this->rel_ttl ?: 0;
		if (static::$init == TRUE) return;
		if ($this->fluid)
			static::setup($this->db,$this->table,[]);
		$this->initMapper();
	}

	/**
	 * create mapper instance
	 */
	public function initMapper() {
		switch ($this->dbsType) {
			case 'jig':
				$this->mapper = new Jig\Mapper($this->db, $this->table);
				break;
			case 'sql':
				// ensure to load full table schema, so we can work with it at runtime
				$this->mapper = new SQL\Mapper($this->db, $this->table, null,
					($this->fluid)?0:$this->ttl);
				$this->applyWhitelist();
				break;
			case 'mongo':
				$this->mapper = new Mongo\Mapper($this->db, $this->table);
				break;
			default:
                throw new \Exception(sprintf(self::E_UNKNOWN_DB_ENGINE,$this->dbsType));
		}
		$this->queryParser = CortexQueryParser::instance();
		$this->reset();
		$this->clearFilter();
		$f3 = \Base::instance();
		$this->smartLoading = $f3->exists('CORTEX.smartLoading') ?
			$f3->get('CORTEX.smartLoading') : TRUE;
		$this->standardiseID = $f3->exists('CORTEX.standardiseID') ?
			$f3->get('CORTEX.standardiseID') : TRUE;
		if (!empty($this->fieldConf))
			foreach($this->fieldConf as $fk=>&$conf) {
				$conf=static::resolveRelationConf($conf, $this->primary);
				// emit deprecation notice for DT_SERIALIZED fields
				if (isset($conf['type']) && $conf['type'] === self::DT_SERIALIZED)
					@trigger_error(sprintf('Field "%s": %s', $fk, self::E_DT_SERIALIZED_DEPRECATED), E_USER_DEPRECATED);
				unset($conf);
			}
	}

	/**
	 * return raw mapper instance
	 * @return Cursor
	 */
	public function getMapper() {
		return $this->mapper;
	}

	/**
	 * give this model a reference to the collection it is part of
	 * @param CortexCollection $cx
	 */
	public function addToCollection($cx) {
		$this->collection = $cx;
	}

	/**
	 * returns the collection where this model lives in, or false
	 * @return CortexCollection|bool
	 */
	protected function getCollection() {
		return ($this->collection && $this->smartLoading)
			? $this->collection : false;
	}

	/**
	 * Declare relations to eager-load on next find() or load().
	 * Supports dot-notation for nested relations and integer for depth.
	 *   with(['news', 'news.tags'])  - named relations
	 *   with(2)                      - all relations to depth 2
	 *   with('news')                 - single relation (string shorthand)
	 * @param array|string|int|null $relations
	 * @return static
	 */
	public function with($relations = null): static {
		if (is_null($relations) || $relations === false)
			$this->eagerLoad = [];
		elseif (is_int($relations))
			$this->eagerLoad = $relations;
		elseif (is_string($relations))
			$this->eagerLoad = [$relations];
		elseif (is_array($relations))
			$this->eagerLoad = $relations;
		return $this;
	}

	/**
	 * returns model table name
	 * @return string
	 */
	public function getTable() {
		if (!$this->table && ($this->fluid || static::$init))
			$this->table = strtolower(get_class($this));
		return $this->table;
	}

	public function dry() {
		return $this->mapper->dry();
	}

	function dbtype() {
		return $this->mapper->dbtype();
	}

	public function __clone() {
		$this->mapper = clone($this->mapper);
	}

	function getiterator() {
//		return new \ArrayIterator($this->cast(null,false));
		return new \ArrayIterator([]);
	}
}

require_once __DIR__.'/cortex/queryparser.php';
require_once __DIR__.'/cortex/collection.php';
