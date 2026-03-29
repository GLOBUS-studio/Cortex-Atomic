<?php

/**
 *  SchemaBuilderTrait - Schema management for Cortex ORM
 *
 *  Extracted from Cortex class.
 *  Methods: resolveConfiguration, setup, setdown, getMMTableName, mmTable, resolveRelationConf
 *
 *  @package DB
 */

namespace DB\Cortex;

use DB\Cortex;
use DB\SQL;
use DB\Jig;
use DB\Mongo;
use DB\Cortex\Schema\Schema;

trait SchemaBuilderTrait {

	/**
	 * kick start to just fetch the config
	 * @return array
	 */
	static public function resolveConfiguration() {
		static $configCache = [];
		$class = static::class;
		if (isset($configCache[$class]))
			return $configCache[$class];
		static::$init=true;
		$self = new static();
		static::$init=false;
		$conf = [
			'table'=>$self->getTable(),
			'fieldConf'=>$self->getFieldConfiguration(),
			'db'=>$self->db,
			'fluid'=>$self->fluid,
			'primary'=>$self->primary,
			'charset'=>$self->charset,
		];
		unset($self);
		$configCache[$class] = $conf;
		return $conf;
	}

	/**
	 * setup / update table schema
	 * @static
	 * @param $db
	 * @param $table
	 * @param $fields
	 * @return bool
	 */
	static public function setup($db=null, $table=null, $fields=null) {
		/** @var Cortex $self */
		$self = get_called_class();
		$self::$schema_cache=[];
		if (is_null($db) || is_null($table) || is_null($fields))
			$df = $self::resolveConfiguration();
		if (!is_object($db=(is_string($db=($db?:$df['db']))?\Base::instance()->get($db):$db)))
            throw new \Exception(self::E_CONNECTION);
		if (strlen($table=$table?:$df['table'])==0)
            throw new \Exception(self::E_NO_TABLE);
		if (is_null($fields))
			if (!empty($df['fieldConf']))
				$fields = $df['fieldConf'];
			elseif(!$df['fluid']) {
                throw new \Exception(self::E_FIELD_SETUP);
				return false;
			} else
				$fields = [];
		if ($db instanceof SQL) {
			$schema = new Schema($db);
			Schema::enableForeignKeys($db);
			$tables = $schema->getTables(); // cache table list to avoid repeated queries
			$belongsToFK = []; // collect belongs-to-one for FK after table build
			$pendingPivots = []; // collect m:m pivot info for deferred creation
			// prepare field configuration
			foreach($fields as $key => &$field) {
				// fetch relation field types
				$field = static::resolveRelationConf($field);
				// check m:m relation
				if (array_key_exists('has-many', $field)) {
					// m:m relation conf [class,to-key,from-key]
					if (is_array($relConf = $field['has-many'])) {
						$rel = $relConf[0]::resolveConfiguration();
						// check if foreign conf matches m:m
						if (!is_null($relConf[1])
							&& array_key_exists($relConf[1],$rel['fieldConf'])
							&& !is_null($rel['fieldConf'][$relConf[1]])
							&& $relConf['hasRel'] == 'has-many') {
							// compute mm table name
							$mmTable = isset($relConf[2]) ? $relConf[2] :
								static::getMMTableName($rel['table'], $relConf['relField'],
									$table, $key, $rel['fieldConf'][$relConf[1]]['has-many']);
							if (!in_array($mmTable,$tables)) {
								$toConf = $relConf[0]::resolveRelationConf($rel['fieldConf'][$relConf[1]]);
								$relField = $relConf['isSelf']?$relConf['selfRefField']:$relConf['relField'];
								$col2Name = $toConf['has-many']['relField'];
								$ownPrimary = isset($df) ? $df['primary'] : 'id';
								// defer pivot creation until after main table build
								$pendingPivots[] = [
									'mmTable' => $mmTable,
									'relField' => $relField,
									'relFieldType' => $relConf['relFieldType'],
									'relTable' => $rel['table'],
									'relPrimary' => $rel['primary'],
									'col2Name' => $col2Name,
									'col2Type' => $field['type'],
									'ownPrimary' => $ownPrimary,
								];
							}
						}
					}
					unset($fields[$key]);
					continue;
				}
				// collect belongs-to-one FK info
				if (isset($field['relType']) && $field['relType'] == 'belongs-to-one') {
					$btoConf = $field['belongs-to-one'];
					if (!is_array($btoConf))
						$btoConf = [$btoConf, '_id'];
					$btoRefConf = $btoConf[0]::resolveConfiguration();
					$btoRefCol = ($btoConf[1] == '_id')
						? $btoRefConf['primary'] : $btoConf[1];
					$belongsToFK[$key] = [
						'table' => $btoRefConf['table'],
						'column' => $btoRefCol,
					];
				}
				// skip virtual fields with no type
				if (!array_key_exists('type', $field)) {
					unset($fields[$key]);
					continue;
				}
				// transform array fields
				if (in_array($field['type'], [self::DT_JSON, self::DT_SERIALIZED]))
					$field['type']=$schema::DT_TEXT;
				// defaults values
				if (!array_key_exists('nullable', $field))
					$field['nullable'] = true;
				unset($field);
			}
			$tableName = $table; // save before reassignment
			if (!in_array($table, $tables)) {
				// create table
				$table = $schema->createTable($table);
				if (isset($df) && $df['charset'])
					$table->setCharset($df['charset']);
				foreach ($fields as $field_key => $field_conf)
					$table->addColumn($field_key, $field_conf);
				if(isset($df) && $df['primary'] != 'id') {
					$table->addColumn($df['primary'])->type_int();
					$table->primary($df['primary']);
				}
				$table->build();
			} else {
				// add missing fields
				$table = $schema->alterTable($table);
				$existingCols = $table->getCols();
				foreach ($fields as $field_key => $field_conf)
					if (!in_array($field_key, $existingCols))
						$table->addColumn($field_key, $field_conf);
				// remove unused fields
				// foreach ($existingCols as $col)
				//     if (!in_array($col, array_keys($fields)) && $col!='id')
				//     $table->dropColumn($col);
				$table->build();
			}
			// refresh table list after main table creation/modification
			$tables = $schema->getTables();
			// create deferred pivot tables (after main table exists)
			foreach ($pendingPivots as $pv) {
				$canAddFK = in_array($pv['relTable'], $tables)
					&& in_array($tableName, $tables);
				if ($canAddFK) {
					$schema->createPivotTable(
						$pv['mmTable'],
						$pv['relField'], $pv['relFieldType'],
						$pv['relTable'], $pv['relPrimary'],
						$pv['col2Name'], $pv['col2Type'],
						$tableName, $pv['ownPrimary'],
						'CASCADE'
					);
				} else {
					// fallback: create without FK, but with UNIQUE
					$mmt = $schema->createTable($pv['mmTable']);
					$mmt->addColumn($pv['relField'])->type($pv['relFieldType']);
					$mmt->addColumn($pv['col2Name'])->type($pv['col2Type']);
					$index = [$pv['relField'], $pv['col2Name']];
					sort($index);
					$mmt->addIndex($index, true);
					$mmt->build();
				}
			}
			// add belongs-to-one FK constraints (skipped on SQLite)
			$tables = $schema->getTables(); // refresh after pivot creation
			foreach ($belongsToFK as $col => $ref) {
				if (in_array($ref['table'], $tables))
					$schema->addForeignKey(
						$tableName, $col,
						$ref['table'], $ref['column'], 'SET NULL'
					);
			}
		}
		return true;
	}

	/**
	 * Add FK constraints for this model's relations.
	 * Call AFTER all related models have been setup().
	 * Handles m:m pivots (recreates on SQLite) and belongs-to-one.
	 * @static
	 * @param SQL|null $db
	 * @param string|null $table
	 * @param array|null $fields
	 * @return bool
	 */
	static public function setupForeignKeys($db=null, $table=null, $fields=null) {
		$self = get_called_class();
		if (is_null($db) || is_null($table) || is_null($fields))
			$df = $self::resolveConfiguration();
		if (!is_object($db=(is_string($db=($db?:$df['db']))?\Base::instance()->get($db):$db)))
			throw new \Exception(self::E_CONNECTION);
		if (strlen($table=$table?:$df['table'])==0)
			throw new \Exception(self::E_NO_TABLE);
		if (is_null($fields))
			if (!empty($df['fieldConf']))
				$fields = $df['fieldConf'];
			else
				$fields = [];
		if (!($db instanceof SQL))
			return false;
		$schema = new Schema($db);
		Schema::enableForeignKeys($db);
		$ownPrimary = isset($df) ? $df['primary'] : 'id';
		foreach ($fields as $key => $field) {
			$field = static::resolveRelationConf($field);
			// m:m pivot FK
			if (array_key_exists('has-many', $field) && is_array($relConf = $field['has-many'])) {
				$rel = $relConf[0]::resolveConfiguration();
				if (!is_null($relConf[1])
					&& array_key_exists($relConf[1],$rel['fieldConf'])
					&& !is_null($rel['fieldConf'][$relConf[1]])
					&& $relConf['hasRel'] == 'has-many') {
					$mmTable = isset($relConf[2]) ? $relConf[2] :
						static::getMMTableName($rel['table'], $relConf['relField'],
							$table, $key, $rel['fieldConf'][$relConf[1]]['has-many']);
					if (in_array($mmTable, $schema->getTables())
						&& in_array($rel['table'], $schema->getTables())
						&& in_array($table, $schema->getTables())) {
						$toConf = $relConf[0]::resolveRelationConf($rel['fieldConf'][$relConf[1]]);
						$relField = $relConf['isSelf']?$relConf['selfRefField']:$relConf['relField'];
						$col2Name = $toConf['has-many']['relField'];
						// check if FK already exists
						if (!$schema->hasForeignKey($mmTable, $relField)) {
							$schema->recreatePivotWithFK(
								$mmTable,
								$relField, $relConf['relFieldType'],
								$rel['table'], $rel['primary'],
								$col2Name, $field['type'] ?? Schema::DT_INT,
								$table, $ownPrimary,
								'CASCADE'
							);
						}
					}
				}
			}
			// belongs-to-one FK
			elseif (array_key_exists('belongs-to-one', $field)) {
				$btoConf = $field['belongs-to-one'];
				if (!is_array($btoConf))
					$btoConf = [$btoConf, '_id'];
				$btoRefConf = $btoConf[0]::resolveConfiguration();
				$btoRefCol = ($btoConf[1] == '_id')
					? $btoRefConf['primary'] : $btoConf[1];
				if (in_array($btoRefConf['table'], $schema->getTables())
					&& in_array($table, $schema->getTables())) {
					if (!$schema->hasForeignKey($table, $key))
						$schema->addForeignKey(
							$table, $key,
							$btoRefConf['table'], $btoRefCol, 'SET NULL'
						);
				}
			}
		}
		return true;
	}

	/**
	 * erase all model data, handle with care
	 * @param null $db
	 * @param null $table
	 */
	static public function setdown($db=null, $table=null) {
		if (is_null($db) || is_null($table))
			$df = static::resolveConfiguration();
		if (!is_object($db=(is_string($db=($db?:$df['db']))?\Base::instance()->get($db):$db)))
            throw new \Exception(self::E_CONNECTION);
		if (strlen($table=($table?:$df['table']))==0)
            throw new \Exception(self::E_NO_TABLE);
		if (isset($df) && !empty($df['fieldConf']))
			$fields = $df['fieldConf'];
		else
			$fields = [];
		$deletable = [];
		$deletable[] = $table;
		foreach ($fields as $key => $field) {
			$field = static::resolveRelationConf($field);
			if (array_key_exists('has-many',$field)) {
				if (!is_array($relConf = $field['has-many']))
					continue;
				$rel = $relConf[0]::resolveConfiguration();
				// check if foreign conf matches m:m
				if (!is_null($relConf[1]) && array_key_exists($relConf[1],$rel['fieldConf'])
				&& array_key_exists('has-many', $rel['fieldConf'][$relConf[1]])) {
					// compute mm table name
					$deletable[] = $relConf[2] ?? static::getMMTableName(
                        $rel['table'], $relConf[1], $table, $key,
                        $rel['fieldConf'][$relConf[1]]['has-many']);
				}
			}
		}

		if($db instanceof Jig) {
			/** @var Jig $db */
			$dir = $db->dir();
			foreach ($deletable as $item)
				if(file_exists($dir.$item))
					unlink($dir.$item);
		} elseif($db instanceof SQL) {
			/** @var SQL $db */
			$schema = new Schema($db);
			$tables = $schema->getTables();
			$driver = $db->driver();
			$isMysql = str_contains($driver, 'mysql');
			$isPgsql = str_contains($driver, 'pgsql');
			// disable FK checks on MySQL to allow dropping referenced tables
			if ($isMysql)
				$db->exec('SET FOREIGN_KEY_CHECKS=0');
			// drop pivot/mm tables first, then the main table
			$deletable = array_reverse($deletable);
			foreach ($deletable as $item)
				if(in_array($item, $tables)) {
					if ($isPgsql)
						$db->exec('DROP TABLE IF EXISTS '.$db->quotekey($item).' CASCADE');
					else
						$schema->dropTable($item);
				}
			if ($isMysql)
				$db->exec('SET FOREIGN_KEY_CHECKS=1');
		} elseif($db instanceof Mongo) {
			/** @var Mongo $db */
			foreach ($deletable as $item)
				$db->selectCollection($item)->drop();
		}
	}

	/**
	 * computes the m:m table name
	 * @param string $ftable foreign table
	 * @param string $fkey   foreign key
	 * @param string $ptable own table
	 * @param string $pkey   own key
	 * @param null|array $fConf  foreign conf [class,key]
	 * @return string
	 */
	static protected function getMMTableName($ftable, $fkey, $ptable, $pkey, $fConf=null) {
		if ($fConf) {
			list($fclass, $pfkey) = $fConf;
			$self = get_called_class();
			// check for a matching config
			if ($pfkey != $pkey)
                throw new \Exception(sprintf(self::E_MM_REL_FIELD,
					$fclass.'.'.$pfkey, $self.'.'.$pkey));
		}
		$mmTable = [$ftable.'__'.$fkey, $ptable.'__'.$pkey];
		natcasesort($mmTable);
		// shortcut for self-referencing mm tables
		if ($mmTable[0] == $mmTable[1] ||
			($fConf && isset($fConf['isSelf']) && $fConf['isSelf']==true))
			return array_shift($mmTable);
		return strtolower(str_replace('\\', '_', implode('_mm_', $mmTable)));
	}

	/**
	 * get mm table name from config
	 * @param array $conf own relation config
	 * @param string $key relation field
	 * @param null|array $fConf optional foreign config
	 * @return string
	 */
	protected function mmTable($conf, $key, $fConf=null) {
		if (!isset($conf['refTable'])) {
			// compute mm table name
			$mmTable = $conf[2] ?? static::getMMTableName($conf['relTable'],
                $conf['relField'], $this->table, $key, $fConf);
			$this->fieldConf[$key]['has-many']['refTable'] = $mmTable;
		} else
			$mmTable = $conf['refTable'];
		return $mmTable;
	}

	/**
	 * resolve relation field types
	 * @param array $field
	 * @param string $pkey
	 * @return array
	 */
	protected static function resolveRelationConf($field,$pkey=NULL) {
		if (array_key_exists('belongs-to-one', $field)) {
			// find primary field definition
			if (!is_array($relConf = $field['belongs-to-one']))
				$relConf = [$relConf, '_id'];
			// set field type
			if ($relConf[1] == '_id')
				$field['type'] = Schema::DT_INT4;
			else {
				// find foreign field type
				$fc = $relConf[0]::resolveConfiguration();
				$field['belongs-to-one']['relPK'] = $fc['primary'];
				$field['type'] = $fc['fieldConf'][$relConf[1]]['type'];
			}
			$field['nullable'] = true;
			$field['relType'] = 'belongs-to-one';
		}
		elseif (array_key_exists('belongs-to-many', $field)){
			$field['type'] = self::DT_JSON;
			$field['nullable'] = true;
			$field['relType'] = 'belongs-to-many';
		}
		elseif (array_key_exists('has-many', $field)){
			$field['relType'] = 'has-many';
			if (!isset($field['type']))
				$field['type'] = Schema::DT_INT;
			$relConf = $field['has-many'];
			if(!is_array($relConf))
				return $field;
			$rel = $relConf[0]::resolveConfiguration();
			if (array_key_exists('has-many',$rel['fieldConf'][$relConf[1]])) {
				// has-many <> has-many (m:m)
				$field['has-many']['hasRel'] = 'has-many';
				$field['has-many']['isSelf'] = (ltrim($relConf[0],'\\')==get_called_class());
				$field['has-many']['relTable'] = $rel['table'];
				$field['has-many']['relField'] = $relField = $relConf['relField'] ?? $relConf[1];
				$field['has-many']['selfRefField'] = $relField.'_ref';
				$field['has-many']['relFieldType'] = $rel['fieldConf'][$relConf[1]]['type'] ?? Schema::DT_INT;
				if (isset($rel['fieldConf'][$relConf[1]]['has-many']['relPK'])) {
					$field['has-many']['relPK'] = $rel['fieldConf'][$relConf[1]]['has-many']['relPK'];
					$field['type'] = $rel['fieldConf'][$rel['fieldConf'][$relConf[1]]['has-many']['relPK']]['type'];
				}
				elseif (isset($relConf['relPK'])) {
					$selfConf = static::resolveConfiguration();
					$field['has-many']['relPK'] = $relConf['relPK'];
					$field['has-many']['relFieldType'] = $selfConf['fieldConf'][$relConf['relPK']]['type'];
				}
				else
					$field['has-many']['relPK'] = $rel['primary'];
				$field['has-many']['localKey'] = $relConf['localKey'] ?? ($pkey ?: '_id');
			} else {
				// has-many <> belongs-to-one (m:1)
				$field['has-many']['hasRel'] = 'belongs-to-one';
				$toConf=$rel['fieldConf'][$relConf[1]]['belongs-to-one'];
				$field['has-many']['relField'] = is_array($toConf) ?
					$toConf[1] : $rel['primary'];
			}
		} elseif(array_key_exists('has-one', $field))
			$field['relType'] = 'has-one';
		return $field;
	}

}
