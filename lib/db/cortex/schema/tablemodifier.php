<?php

/**
 *  Cortex Schema Builder - TableModifier
 *
 *  @package DB\Cortex\Schema
 *  @version 2.0.0
 *  @date 29.03.2026
 */

namespace DB\Cortex\Schema;

class TableModifier extends TableBuilder {

	protected
		$colTypes,$rebuild_cmd,$charset,$collation;

	const
		// error messages
		TEXT_TableNotExisting="Unable to alter table `%s`. It does not exist.",
		TEXT_NotNullFieldNeedsDefault='You cannot add the not nullable column `%s` without specifying a default value',
		TEXT_ENGINE_NOT_SUPPORTED='DB Engine `%s` is not supported for this action.';

	public function setCharset($charset,$collation='unicode') {
		$this->charset=$charset;
		$this->collation=$collation;
	}

	/**
	 * generate SQL queries for altering the table and execute it if $exec is true,
	 * otherwise return the generated query string
	 * @param bool $exec
	 * @return array|FALSE
	 */
	public function build($exec=TRUE) {
		// check if table exists
		if (!in_array($this->name,$this->schema->getTables()))
			trigger_error(sprintf(self::TEXT_TableNotExisting,$this->name),E_USER_ERROR);

		if ($sqlite=strpos($this->db->driver(),'sqlite')!==FALSE) {
			$sqlite_queries=[];
		}
		$rebuild=FALSE;
		$additional_queries=$this->queries;
		$this->queries=[];
		// add new columns
		$table=$this->db->quotekey($this->name);
		foreach ($this->columns as $cname=>$column) {
			/** @var Column $column */
			// not nullable fields should have a default value, when altering a table
			if ($column->default===FALSE && $column->nullable===FALSE) {
				trigger_error(sprintf(self::TEXT_NotNullFieldNeedsDefault,$column->name),
					E_USER_ERROR);
				return FALSE;
			}
			// no defaults for TEXT type
			if ($column->default!==FALSE &&
				is_int(strpos(strtoupper($column->type),'TEXT'))) {
				trigger_error(sprintf(self::TEXT_NoDefaultForTEXT,$column->name),
					E_USER_ERROR);
				return FALSE;
			}
			$col_query=$column->getColumnQuery();
			if ($sqlite) {
				// sqlite: dynamic column default only works when rebuilding the table
				if ($column->default===Schema::DF_CURRENT_TIMESTAMP) {
					$rebuild=TRUE;
					break;
				} else
					$sqlite_queries[]="ALTER TABLE $table ADD $col_query;";
			} else {
				$cmd=[
					'mysql|pgsql|mssql|sybase|dblib|odbc|sqlsrv'=>
						"ALTER TABLE $table ADD $col_query;",
					'ibm'=>
						"ALTER TABLE $table ADD COLUMN $col_query;",
				];
				$this->queries[]=$this->findQuery($cmd);
			}
		}
		if (strpos($this->db->driver(),'mysql')!==FALSE && !empty($this->charset) &&
			!empty($this->collation)) {
			$this->queries[]=
				"ALTER TABLE $table CONVERT TO CHARACTER SET ".$this->charset." COLLATE ".
				$this->charset."_".$this->collation."_ci;";
		}
		if ($sqlite)
			if ($rebuild || !empty($this->rebuild_cmd)) $this->_sqlite_rebuild($exec);
			else $this->queries+=$sqlite_queries;
		$this->queries=array_merge($this->queries,$additional_queries);
		// add new indexes
		foreach ($this->columns as $cname=>$column)
			if ($column->index)
				$this->addIndex($cname,$column->unique);
		if (empty($this->queries))
			return FALSE;
		if (is_array($this->queries) && count($this->queries)==1)
			$this->queries=$this->queries[0];
		if (!$exec) return $this->queries;
		$result=($this->suppress)
			?@$this->db->exec($this->queries):$this->db->exec($this->queries);
		$this->queries=$this->columns=$this->rebuild_cmd=[];
		return $result;
	}

	/**
	 * rebuild a sqlite table with additional schema changes
	 */
	protected function _sqlite_rebuild($exec=TRUE) {
		$new_columns=$this->columns;
		$existing_columns=$this->getCols(TRUE);
		// find after sorts
		$after=[];
		foreach ($new_columns as $cname=>$column)
			if (!empty($column->after))
				$after[$column->after][]=$cname;
		// find rename commands
		$rename=
			(!empty($this->rebuild_cmd) && array_key_exists('rename',$this->rebuild_cmd))
				?$this->rebuild_cmd['rename']:[];
		// get primary-key fields
		foreach ($existing_columns as $key=>$col)
			if ($col['pkey'])
				$pkeys[array_key_exists($key,$rename)?$rename[$key]:$key]=$col;
		foreach ($new_columns as $key=>$col)
			if ($col->pkey)
				$pkeys[$key]=$col;
		// indexes
		$indexes=$this->listIndex();
		// drop fields
		if (!empty($this->rebuild_cmd) && array_key_exists('drop',$this->rebuild_cmd))
			foreach ($this->rebuild_cmd['drop'] as $name)
				if (array_key_exists($name,$existing_columns)) {
					if (array_key_exists($name,$pkeys)) {
						unset($pkeys[$name]);
						// drop composite key
						if (count($pkeys)==1) {
							$incrementTrigger=$this->db->quotekey($this->name.'_insert');
							$this->queries[]='DROP TRIGGER IF EXISTS '.$incrementTrigger;
						}
					}
					unset($existing_columns[$name]);
					// drop index
					foreach (array_keys($indexes) as $col) {
						// new index names
						if ($col==$this->name.'___'.$name)
							unset($indexes[$this->name.'___'.$name]);
						// check if column is part of an existing combined index
						if (is_int(strpos($col,'__'))) {
							if (is_int(strpos($col,'___'))) {
								$col=explode('___',$col);
								$ci=explode('__',$col[1]);
								$col=implode('___',$col);
								// drop combined index
								if (in_array($name,$ci))
									unset($indexes[$col]);
							}
						}
					}
				}
		// create new table
		$oname=$this->name;
		$this->queries[]=$this->rename($oname.'_temp',FALSE);
		$newTable=$this->schema->createTable($oname);
		// add existing fields
		foreach ($existing_columns as $name=>$col) {
			$colName=array_key_exists($name,$rename)?$rename[$name]:$name;
			// update column datatype
			if (array_key_exists('update',$this->rebuild_cmd)
				&& in_array($name,array_keys($this->rebuild_cmd['update']))) {
				$cdat=$this->rebuild_cmd['update'][$name];
				if ($cdat instanceof Column)
					$col=$cdat->getColumnArray();
				else
					$col['type']=$cdat;
			}
			$newTable->addColumn($colName,$col)->passThrough();
			// add new fields with after flag
			if (array_key_exists($name,$after))
				foreach (array_reverse($after[$name]) as $acol) {
					$newTable->addColumn($new_columns[$acol]);
					unset($new_columns[$acol]);
				}
		}
		// add remaining new fields
		foreach ($new_columns as $ncol)
			$newTable->addColumn($ncol);
		$newTable->primary(array_keys($pkeys));
		// add existing indexes
		foreach (array_reverse($indexes) as $name=>$conf) {
			if (is_int(strpos($name,'___')))
				list($tname,$name)=explode('___',$name);
			if (is_int(strpos($name,'__')))
				$name=explode('__',$name);
			if ($exec) {
				$t=$this->schema->alterTable($oname);
				$t->dropIndex($name);
				$t->build();
			}
			$newTable->addIndex($name,$conf['unique']);
		}
		// build new table
		$newTableQueries=$newTable->build(FALSE);
		$this->queries=array_merge($this->queries,$newTableQueries);
		// copy data
		if (!empty($existing_columns)) {
			foreach (array_keys($existing_columns) as $name) {
				$fields_from[]=$this->db->quotekey($name);
				$toName=array_key_exists($name,$rename)?$rename[$name]:$name;
				$fields_to[]=$this->db->quotekey($toName);
			}
			$this->queries[]=
				'INSERT INTO '.$this->db->quotekey($newTable->name).' ('.
				implode(', ',$fields_to).') '.
				'SELECT '.implode(', ',$fields_from).' FROM '.
				$this->db->quotekey($this->name).';';
		}
		$this->queries[]=$this->drop(FALSE);
		$this->name=$oname;
	}

	/**
	 * create an insert trigger to work-a-round auto-incrementation in composite primary keys
	 * @param $pkey
	 * @return array
	 */
	public function _sqlite_increment_trigger($pkey) {
		$table=$this->db->quotekey($this->name);
		$pkey=$this->db->quotekey($pkey);
		$triggerName=$this->db->quotekey($this->name.'_insert');
		$queries[]="DROP TRIGGER IF EXISTS $triggerName;";
		$queries[]='CREATE TRIGGER '.$triggerName.' AFTER INSERT ON '.$table.
			' WHEN (NEW.'.$pkey.' IS NULL) BEGIN'.
			' UPDATE '.$table.' SET '.$pkey.' = ('.
			' select coalesce( max( '.$pkey.' ), 0 ) + 1 from '.$table.
			') WHERE ROWID = NEW.ROWID;'.
			' END;';
		return $queries;
	}

	/**
	 * get columns of a table
	 * @param bool $types
	 * @return array
	 */
	public function getCols($types=FALSE) {
		$schema=$this->db->schema($this->name,NULL,0);
		if (!$types)
			return array_keys($schema);
		else
			foreach ($schema as $name=>&$cols) {
				$default=($cols['default']==='')?NULL:$cols['default'];
				if (!is_null($default) && ((is_int(strpos($curdef=strtolower(
								$this->findQuery($this->schema->getDefaultTypes()['CUR_STAMP'])),
								strtolower($default))) ||
							is_int(strpos(strtolower($default),$curdef)))
						|| $default=="('now'::text)::timestamp(0) without time zone")) {
					$default='CUR_STAMP';
				} elseif (!is_null($default)) {
					// remove single-qoutes
					if (strpos($this->db->driver(),'sqlite')!==FALSE)
						$default=preg_replace('/^\s*([\'"])(.*)\1\s*$/','\2',$default);
					elseif (preg_match('/mssql|sybase|dblib|odbc|sqlsrv/',
						$this->db->driver()))
						$default=preg_replace('/^\s*(\(\')(.*)(\'\))\s*$/','\2',$default);
					// extract value from character_data in postgre
					elseif (strpos($this->db->driver(),'pgsql')!==FALSE)
						if (is_int(strpos($default,'nextval')))
							$default=NULL; // drop autoincrement default
						elseif (preg_match("/^\'*(.*)\'*::(\s*\w)+/",$default,$match))
							$default=$match[1];
				} else
					$default=FALSE;
				$cols['default']=$default;
			}
		return $schema;
	}

	/**
	 * check if a data type is compatible with an existing column type
	 * @param string $colType (i.e: BOOLEAN)
	 * @param string $column (i.e: active)
	 * @return bool
	 */
	public function isCompatible($colType,$column) {
		$cols=$this->getCols(TRUE);
		return $this->schema->isCompatible($colType,$cols[$column]['type']);
	}

	/**
	 * removes a column from a table
	 * @param string $name
	 */
	public function dropColumn($name) {
		$colTypes=$this->getCols(TRUE);
		// check if column exists
		if (!in_array($name,array_keys($colTypes))) return TRUE;
		if (strpos($this->db->driver(),'sqlite')!==FALSE) {
			// SQlite does not support drop column directly
			$this->rebuild_cmd['drop'][]=$name;
		} else {
			$quotedTable=$this->db->quotekey($this->name);
			$quotedColumn=$this->db->quotekey($name);
			$cmd=[
				'mysql'=>
					"ALTER TABLE $quotedTable DROP $quotedColumn;",
				'pgsql|odbc|ibm|mssql|sybase|dblib|sqlsrv'=>
					"ALTER TABLE $quotedTable DROP COLUMN $quotedColumn;",
			];
			if (preg_match('/mssql|sybase|dblib|sqlsrv/',$this->db->driver()))
				$this->suppress=TRUE;
			$this->queries[]=$this->findQuery($cmd);
		}
	}

	/**
	 * rename a column
	 * @param $name
	 * @param $new_name
	 * @return void
	 */
	public function renameColumn($name,$new_name) {
		$existing_columns=$this->getCols(TRUE);
		// check if column is already existing
		if (!in_array($name,array_keys($existing_columns)))
			trigger_error('cannot rename column. it does not exist.',E_USER_ERROR);
		if (in_array($new_name,array_keys($existing_columns)))
			trigger_error('cannot rename column. new column already exist.',E_USER_ERROR);

		if (strpos($this->db->driver(),'sqlite')!==FALSE)
			// SQlite does not support drop or rename column directly
			$this->rebuild_cmd['rename'][$name]=$new_name;
		elseif (strpos($this->db->driver(),'odbc')!==FALSE) {
			// no rename column for odbc, create temp column
			$this->addColumn($new_name,$existing_columns[$name])->passThrough();
			$this->queries[]="UPDATE $this->name SET $new_name = $name";
			$this->dropColumn($name);
		} else {
			$existing_columns=$this->getCols(TRUE);
			$quotedTable=$this->db->quotekey($this->name);
			$quotedColumn=$this->db->quotekey($name);
			$quotedColumnNew=$this->db->quotekey($new_name);
			$cmd=[
				'mysql'=>
					"ALTER TABLE $quotedTable CHANGE $quotedColumn $quotedColumnNew ".
					$existing_columns[$name]['type'].";",
				'pgsql|ibm'=>
					"ALTER TABLE $quotedTable RENAME COLUMN $quotedColumn TO $quotedColumnNew;",
				'mssql|sybase|dblib|sqlsrv'=>
					"sp_rename [$this->name.$name], '$new_name', 'Column'",
			];
			if (preg_match('/mssql|sybase|dblib|sqlsrv/',$this->db->driver()))
				$this->suppress=TRUE;
			$this->queries[]=$this->findQuery($cmd);
		}
	}

	/**
	 * modifies column datatype
	 * @param string $name
	 * @param string|Column $datatype
	 * @param bool $force
	 */
	public function updateColumn($name,$datatype,$force=FALSE) {
		if ($datatype instanceof Column) {
			$col=$datatype;
			$datatype=$col->type;
			$force=$col->passThrough;
		}
		if (!$force)
			$datatype=$this->findQuery($this->schema->getDataTypes()[strtoupper($datatype)]);
		$table=$this->db->quotekey($this->name);
		$column=$this->db->quotekey($name);
		if (strpos($this->db->driver(),'sqlite')!==FALSE) {
			$this->rebuild_cmd['update'][$name]=isset($col)?$col:$datatype;
		} else {
			$dat=isset($col)?$col->getColumnQuery():
				$column.' '.$datatype;
			$cmd=[
				'mysql'=>
					"ALTER TABLE $table MODIFY COLUMN $dat;",
				'pgsql'=>
					"ALTER TABLE $table ALTER COLUMN $column TYPE $datatype;",
				'sqlsrv|mssql|sybase|dblib|odbc|ibm'=>
					"ALTER TABLE $table ALTER COLUMN $column $datatype;",
			];
			if (isset($col)) {
				$cmd['pgsql']=[$cmd['pgsql']];
				$cmd['pgsql'][]="ALTER TABLE $table ALTER COLUMN $column SET DEFAULT ".
					$col->getDefault().";";
				if ($col->nullable)
					$cmd['pgsql'][]=
						"ALTER TABLE $table ALTER COLUMN $column DROP NOT NULL;";
				else
					$cmd['pgsql'][]=
						"ALTER TABLE $table ALTER COLUMN $column SET NOT NULL;";
				$df_key='DF_'.$this->name.'_'.$name;
				$cmd['sqlsrv|mssql|sybase|dblib|odbc|ibm']=[
					"ALTER TABLE $table ALTER COLUMN $column $datatype ".
					$col->getNullable().";",
					"DECLARE @ConstraintName nvarchar(200)
					SELECT @ConstraintName = Name FROM SYS.DEFAULT_CONSTRAINTS WHERE PARENT_OBJECT_ID = OBJECT_ID('$this->name')
					 AND PARENT_COLUMN_ID = (SELECT column_id FROM sys.columns WHERE NAME = N'$name'
					 AND object_id = OBJECT_ID(N'$this->name'))
					IF @ConstraintName IS NOT NULL
					EXEC('ALTER TABLE $this->name DROP CONSTRAINT ' + @ConstraintName)
					",
					"ALTER TABLE $table ADD CONSTRAINT $df_key DEFAULT ".
					$col->getDefault()." FOR $column;",
				];
			}
			$this->queries[]=$this->findQuery($cmd);
		}
	}

	/**
	 * create index on one or more columns
	 * @param string|array $columns Column(s) to be indexed
	 * @param bool $unique Unique index
	 * @param int $length index length for text fields in mysql
	 */
	public function addIndex($columns,$unique=FALSE,$length=20) {
		if (!is_array($columns))
			$columns=[$columns];
		$existingCol=$this->columns;
		foreach ($existingCol as &$col)
			$col=$col->getColumnArray();
		$allCols=array_merge($this->getCols(TRUE),$existingCol);
		parent::_addIndex($columns,$allCols,$unique,$length);
	}

	/**
	 * drop a column index
	 * @param string|array $name
	 */
	public function dropIndex($name) {
		if (is_array($name))
			$name=$this->name.'___'.implode('__',$name);
		elseif (!is_int(strpos($name,'___')))
			$name=$this->name.'___'.$name;
		$name=$this->db->quotekey($name);
		$table=$this->db->quotekey($this->name);
		$cmd=[
			'pgsql|sqlite2?|ibm'=>
				"DROP INDEX $name;",
			'mssql|sybase|dblib|odbc|sqlsrv'=>
				"DROP INDEX $table.$name;",
			'mysql'=>
				"ALTER TABLE $table DROP INDEX $name;",
		];
		$query=$this->findQuery($cmd);
		$this->queries[]=$query;
	}

	/**
	 * returns table indexes as assoc array
	 * @return array
	 */
	public function listIndex() {
		$table=$this->db->quotekey($this->name);
		$cmd=[
			'sqlite2?'=>
				"PRAGMA index_list($table);",
			'mysql'=>
				"SHOW INDEX FROM $table;",
			'mssql|sybase|dblib|sqlsrv|odbc'=>
				"select * from sys.indexes ".
				"where object_id = (select object_id from sys.objects where name = '$this->name')",
			'pgsql'=>
				"select i.relname as name, ix.indisunique as unique ".
				"from pg_class t, pg_class i, pg_index ix ".
				"where t.oid = ix.indrelid and i.oid = ix.indexrelid ".
				"and t.relkind = 'r' and t.relname = '$this->name' ".
				"group by t.relname, i.relname, ix.indisunique;",
			'ibm'=>
				"SELECT INDNAME AS name, CASE UNIQUERULE WHEN 'U' THEN 1 ELSE 0 END AS unique_flag ".
				"FROM SYSCAT.INDEXES WHERE TABNAME = '".strtoupper($this->name)."'",
		];
		$result=$this->db->exec($this->findQuery($cmd));
		$indexes=[];
		if (preg_match('/pgsql|sqlite/',$this->db->driver())) {
			foreach ($result as $row)
				$indexes[$row['name']]=['unique'=>$row['unique']];
		} elseif (preg_match('/mssql|sybase|dblib|sqlsrv|odbc/',$this->db->driver())) {
			foreach ($result as $row)
				$indexes[$row['name']]=['unique'=>$row['is_unique']];
		} elseif (strpos($this->db->driver(),'mysql')!==FALSE) {
			foreach ($result as $row)
				$indexes[$row['Key_name']]=['unique'=>!(bool)$row['Non_unique']];
		} elseif (strpos($this->db->driver(),'ibm')!==FALSE) {
			foreach ($result as $row)
				$indexes[$row['name']]=['unique'=>(bool)$row['unique_flag']];
		} else
			trigger_error(sprintf(self::TEXT_ENGINE_NOT_SUPPORTED,$this->db->driver()),
				E_USER_ERROR);
		return $indexes;
	}

	/**
	 * rename this table
	 * @param string $new_name
	 * @param bool $exec
	 * @return $this|bool
	 */
	public function rename($new_name,$exec=TRUE) {
		$query=$this->schema->renameTable($this->name,$new_name,$exec);
		$this->name=$new_name;
		return ($exec)?$this:$query;
	}

	/**
	 * drop this table
	 * @param bool $exec
	 * @return mixed
	 */
	public function drop($exec=TRUE) {
		return $this->schema->dropTable($this,$exec);
	}

}
