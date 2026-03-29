<?php

/**
 *  SchemaBuilderTrait - Schema management for Cortex ORM
 *
 *  Extracted from Cortex class.
 *  Methods: resolveConfiguration, setup, setdown, getMMTableName, mmTable, resolveRelationConf
 *
 *  @package DB
 */

namespace DB;
use DB\SQL\Schema;

trait SchemaBuilderTrait {

	/**
	 * kick start to just fetch the config
	 * @return array
	 */
	static public function resolveConfiguration() {
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
							if (!in_array($mmTable,$schema->getTables())) {
								$toConf = $relConf[0]::resolveRelationConf($rel['fieldConf'][$relConf[1]]);
								$mmt = $schema->createTable($mmTable);
								$relField = $relConf['isSelf']?$relConf['selfRefField']:$relConf['relField'];
								$mmt->addColumn($relField)->type($relConf['relFieldType']);
								$mmt->addColumn($toConf['has-many']['relField'])->type($field['type']);
								$index = [$relField,$toConf['has-many']['relField']];
								sort($index);
								$mmt->addIndex($index);
								$mmt->build();
							}
						}
					}
					unset($fields[$key]);
					continue;
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
			if (!in_array($table, $schema->getTables())) {
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
					&& key($rel['fieldConf'][$relConf[1]]) == 'has-many') {
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
			foreach ($deletable as $item)
				if(in_array($item, $tables))
					$schema->dropTable($item);
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
