<?php

/**
 *  Cortex Schema Builder - SQL Table Schema management
 *
 *  Forked from ikkez/f3-schema-builder 2.2.5 and enhanced with
 *  native FK constraint support (integrated from ConstraintAdapter).
 *
 *  Original by Christian Knuth <ikkez0n3@gmail.com>
 *  Enhanced for Cortex-Atomic by GLOBUS.studio
 *
 *  The contents of this file are subject to the terms of the GNU General
 *  Public License Version 3.0. You may not use this file except in
 *  compliance with the license. Any of the license terms and conditions
 *  can be waived if you get permission from the copyright holder.
 *
 *  @package DB\Cortex\Schema
 *  @version 2.0.0
 *  @date 29.03.2026
 */

namespace DB\Cortex\Schema;

use Base;
use DB\SQL;
use PDO;

class Schema {

	use DB_Utils;

	public
		$dataTypes=[
			'BOOLEAN'=>[
				'mysql'=>'tinyint(1)',
				'sqlite2?|pgsql'=>'BOOLEAN',
				'mssql|sybase|dblib|odbc|sqlsrv'=>'bit',
				'ibm'=>'numeric(1,0)',
			],
			'INT1'=>[
				'mysql'=>'tinyint(4)',
				'sqlite2?'=>'integer(4)',
				'mssql|sybase|dblib|odbc|sqlsrv'=>'tinyint',
				'pgsql|ibm'=>'smallint',
			],
			'INT2'=>[
				'mysql'=>'smallint(6)',
				'sqlite2?'=>'integer(6)',
				'pgsql|ibm|mssql|sybase|dblib|odbc|sqlsrv'=>'smallint',
			],
			'INT4'=>[
				'sqlite2?'=>'integer(11)',
				'pgsql|ibm'=>'integer',
				'mysql'=>'int(11)',
				'mssql|dblib|sybase|odbc|sqlsrv'=>'int',
			],
			'INT8'=>[
				'sqlite2?'=>'integer(20)',
				'pgsql|mssql|sybase|dblib|odbc|sqlsrv|ibm'=>'bigint',
				'mysql'=>'bigint(20)',
			],
			'FLOAT'=>[
				'mysql|sqlite2?'=>'FLOAT',
				'pgsql'=>'double precision',
				'mssql|sybase|dblib|odbc|sqlsrv'=>'float',
				'ibm'=>'decfloat'
			],
			'DOUBLE'=>[
				'mysql|ibm'=>'decimal(18,6)',
				'sqlite2?'=>'decimal(15,6)',
				'pgsql'=>'numeric(18,6)',
				'mssql|dblib|sybase|odbc|sqlsrv'=>'decimal(18,6)',
			],
			'VARCHAR128'=>[
				'mysql|sqlite2?|ibm|mssql|sybase|dblib|odbc|sqlsrv'=>'varchar(128)',
				'pgsql'=>'character varying(128)',
			],
			'VARCHAR256'=>[
				'mysql|sqlite2?|ibm|mssql|sybase|dblib|odbc|sqlsrv'=>'varchar(255)',
				'pgsql'=>'character varying(255)',
			],
			'VARCHAR512'=>[
				'mysql|sqlite2?|ibm|mssql|sybase|dblib|odbc|sqlsrv'=>'varchar(512)',
				'pgsql'=>'character varying(512)',
			],
			'TEXT'=>[
				'mysql|sqlite2?|pgsql|mssql'=>'text',
				'sybase|dblib|odbc|sqlsrv'=>'nvarchar(max)',
				'ibm'=>'BLOB SUB_TYPE TEXT',
			],
			'LONGTEXT'=>[
				'mysql'=>'LONGTEXT',
				'sqlite2?|pgsql|mssql'=>'text',
				'sybase|dblib|odbc|sqlsrv'=>'nvarchar(max)',
				'ibm'=>'CLOB(2000000000)',
			],
			'DATE'=>[
				'mysql|sqlite2?|pgsql|mssql|sybase|dblib|odbc|sqlsrv|ibm'=>'date',
			],
			'DATETIME'=>[
				'pgsql'=>'timestamp without time zone',
				'mysql|sqlite2?|mssql|sybase|dblib|odbc|sqlsrv'=>'datetime',
				'ibm'=>'timestamp',
			],
			'TIMESTAMP'=>[
				'mysql|ibm'=>'timestamp',
				'pgsql|odbc'=>'timestamp without time zone',
				'sqlite2?|mssql|sybase|dblib|sqlsrv'=>'DATETIME',
			],
			'BLOB'=>[
				'mysql|odbc|sqlite2?|ibm'=>'blob',
				'pgsql'=>'bytea',
				'mssql|sybase|dblib'=>'image',
				'sqlsrv'=>'varbinary(max)',
			],
		],
		$defaultTypes=[
			'CUR_STAMP'=>[
				'mysql|ibm'=>'CURRENT_TIMESTAMP',
				'mssql|sybase|dblib|odbc|sqlsrv'=>'getdate()',
				'pgsql'=>'LOCALTIMESTAMP(0)',
				'sqlite2?'=>"(datetime('now','localtime'))",
			],
		];

	public
		$name;

	public static
		$strict=FALSE;

	const
		// DataTypes and Aliases
		DT_BOOL='BOOLEAN',
		DT_BOOLEAN='BOOLEAN',
		DT_INT1='INT1',
		DT_TINYINT='INT1',
		DT_INT2='INT2',
		DT_SMALLINT='INT2',
		DT_INT4='INT4',
		DT_INT='INT4',
		DT_INT8='INT8',
		DT_BIGINT='INT8',
		DT_FLOAT='FLOAT',
		DT_DOUBLE='DOUBLE',
		DT_DECIMAL='DOUBLE',
		DT_VARCHAR128='VARCHAR128',
		DT_VARCHAR256='VARCHAR256',
		DT_VARCHAR512='VARCHAR512',
		DT_TEXT='TEXT',
		DT_LONGTEXT='LONGTEXT',
		DT_DATE='DATE',
		DT_DATETIME='DATETIME',
		DT_TIMESTAMP='TIMESTAMP',
		DT_BLOB='BLOB',
		DT_BINARY='BLOB',

		// column default values
		DF_CURRENT_TIMESTAMP='CUR_STAMP',

		// SQLite FK pragma
		PRAGMA_FK_ON = 'PRAGMA foreign_keys = ON',
		PRAGMA_FK_OFF = 'PRAGMA foreign_keys = OFF';


	public function __construct(SQL $db) {
		$this->db=$db;
	}

	/**
	 * Enable FK enforcement on the connection (required for SQLite)
	 * @param SQL $db
	 */
	static function enableForeignKeys(SQL $db): void {
		if (str_contains($db->driver(), 'sqlite'))
			$db->exec(self::PRAGMA_FK_ON);
	}

	/**
	 * Resolve a schema type constant to actual SQL type for current driver
	 * @param SQL $db
	 * @param string $type Schema type (e.g. 'INT4', 'VARCHAR256')
	 * @return string SQL type string for current driver
	 */
	static function resolveColumnType(SQL $db, string $type): string {
		$schema = new static($db);
		$types = $schema->dataTypes;
		$type = strtoupper($type);
		if (!isset($types[$type]))
			return $type;
		foreach ($types[$type] as $pattern => $sqlType)
			if (preg_match('/'.$pattern.'/', $db->driver()))
				return $sqlType;
		return $type;
	}

	/**
	 * get a list of all databases
	 * @return array|bool
	 */
	public function getDatabases() {
		$cmd=[
			'mysql'=>'SHOW DATABASES',
			'pgsql'=>'SELECT datname FROM pg_catalog.pg_database',
			'mssql|sybase|dblib|sqlsrv|odbc'=>'EXEC SP_HELPDB',
			'sqlite2?'=>"SELECT name FROM pragma_database_list",
			'ibm'=>'SELECT SCHEMANAME FROM SYSCAT.SCHEMATA',
		];
		$query=$this->findQuery($cmd);
		if (!$query) return FALSE;
		$result=$this->db->exec($query);
		if (!is_array($result)) return FALSE;
		foreach ($result as &$db)
			if (is_array($db)) $db=array_shift($db);
		return $result;
	}

	/**
	 * get all tables of current DB
	 * @return bool|array list of tables, or false
	 */
	public function getTables() {
		$cmd=[
			'mysql'=>[
				"show tables"],
			'sqlite2?'=>[
				"SELECT name FROM sqlite_master WHERE type='table' AND name!='sqlite_sequence'"],
			'pgsql|sybase|dblib'=>[
				"select table_name from information_schema.tables where table_schema = 'public'"],
			'mssql|sqlsrv|odbc'=>[
				"select table_name from information_schema.tables"],
			'ibm'=>["select TABLE_NAME from sysibm.tables"],
		];
		$query=$this->findQuery($cmd);
		if (!$query[0]) return FALSE;
		$tables=$this->db->exec($query[0]);
		if ($tables && is_array($tables) && count($tables)>0)
			foreach ($tables as &$table)
				$table=array_shift($table);
		return $tables;
	}

	/**
	 * returns a table object for creation
	 * @param string $name
	 * @return TableCreator
	 */
	public function createTable($name) {
		return new TableCreator($name,$this);
	}

	/**
	 * returns a table object for altering operations
	 * @param string $name
	 * @return TableModifier
	 */
	public function alterTable($name) {
		return new TableModifier($name,$this);
	}

	/**
	 * rename a table
	 * @param string $name
	 * @param string $new_name
	 * @param bool $exec
	 * @return bool
	 */
	public function renameTable($name,$new_name,$exec=TRUE) {
		$name=$this->db->quotekey($name);
		$new_name=$this->db->quotekey($new_name);
		if (strpos($this->db->driver(),'odbc')!==FALSE) {
			$queries=[];
			$queries[]="SELECT * INTO $new_name FROM $name;";
			$queries[]=$this->dropTable($name,FALSE);
			return ($exec)?$this->db->exec($queries):implode("\n",$queries);
		} else {
			$cmd=[
				'sqlite2?|pgsql'=>
					"ALTER TABLE $name RENAME TO $new_name;",
				'mysql|ibm'=>
					"RENAME TABLE $name TO $new_name;",
				'mssql|sqlsrv|sybase|dblib|odbc'=>
					"sp_rename {$name}, $new_name"
			];
			$query=$this->findQuery($cmd);
			if (!$exec) return $query;
			return (preg_match('/mssql|sybase|dblib|sqlsrv/',$this->db->driver()))
				?@$this->db->exec($query):$this->db->exec($query);
		}
	}

	/**
	 * drop a table
	 * @param TableBuilder|string $name
	 * @param bool $exec
	 * @return bool
	 */
	public function dropTable($name,$exec=TRUE) {
		if (is_object($name) && $name instanceof TableBuilder)
			$name=$name->name;
		$cmd=[
			'mysql|ibm|sqlite2?|pgsql|sybase|dblib'=>
				'DROP TABLE IF EXISTS '.$this->db->quotekey($name).';',
			'mssql|sqlsrv|odbc'=>
				"IF OBJECT_ID('[$name]', 'U') IS NOT NULL DROP TABLE [$name];"
		];
		$query=$this->findQuery($cmd);
		return ($exec)?$this->db->exec($query):$query;
	}

	/**
	 * clear a table
	 * @param string $name
	 * @param bool $exec
	 * @return array|bool|FALSE|int|string
	 */
	public function truncateTable($name,$exec=TRUE) {
		if (is_object($name) && $name instanceof TableBuilder)
			$name=$name->name;
		$cmd=[
			'mysql|ibm|pgsql|sybase|dblib|mssql|sqlsrv|odbc'=>
				'TRUNCATE TABLE '.$this->db->quotekey($name).';',
			'sqlite2?'=>[
				'DELETE FROM '.$this->db->quotekey($name).';',
			],
		];
		$query=$this->findQuery($cmd);
		return ($exec)?$this->db->exec($query):$query;
	}

	/**
	 * check if a data type is compatible with a given column definition
	 * @param string $colType (i.e: BOOLEAN)
	 * @param string $colDef (i.e: tinyint(1))
	 * @return int
	 */
	public function isCompatible($colType,$colDef) {
		$raw_type=$this->findQuery($this->dataTypes[strtoupper($colType)]);
		preg_match_all('/(?P<type>\w+)($|\((?P<length>(\d+|(.*)))\))/',$raw_type,$match);
		return (bool)preg_match_all('/'.preg_quote($match['type'][0]).'($|\('.
			preg_quote($match['length'][0]).'\))/i',$colDef);
	}

	// ================================================================
	// FK Constraint methods (integrated from ConstraintAdapter)
	// ================================================================

	/**
	 * Add a foreign key constraint to a table.
	 * For SQLite: FK can only be inline at CREATE TABLE time, returns false.
	 * For other engines: uses ALTER TABLE ADD CONSTRAINT.
	 *
	 * @param string $table Table that holds the FK column
	 * @param string $column FK column name
	 * @param string $refTable Referenced table
	 * @param string $refColumn Referenced column (usually 'id')
	 * @param string $onDelete ON DELETE action: RESTRICT, CASCADE, SET NULL
	 * @return bool True if constraint was added, false if skipped
	 */
	public function addForeignKey(string $table, string $column,
		string $refTable, string $refColumn = 'id',
		string $onDelete = 'RESTRICT'): bool
	{
		$driver = $this->db->driver();

		// SQLite: ALTER TABLE ADD CONSTRAINT not supported
		if (str_contains($driver, 'sqlite'))
			return false;

		$constraintName = 'fk_'.$table.'_'.$column;
		$onDelete = strtoupper($onDelete);
		$qt = $this->db->quotekey($table);
		$qc = $this->db->quotekey($column);
		$qrt = $this->db->quotekey($refTable);
		$qrc = $this->db->quotekey($refColumn);
		$qn = $this->db->quotekey($constraintName);

		if (str_contains($driver, 'mysql') || str_contains($driver, 'pgsql')
			|| str_contains($driver, 'ibm')
			|| preg_match('/mssql|dblib|sybase|odbc|sqlsrv/', $driver)) {
			$this->db->exec(
				"ALTER TABLE {$qt} ADD CONSTRAINT {$qn} ".
				"FOREIGN KEY ({$qc}) REFERENCES {$qrt}({$qrc}) ".
				"ON DELETE {$onDelete}"
			);
			return true;
		}

		return false;
	}

	/**
	 * Generate FK clause for use inside CREATE TABLE statement.
	 * @param string $column
	 * @param string $refTable
	 * @param string $refColumn
	 * @param string $onDelete
	 * @return string SQL fragment to append to CREATE TABLE column list
	 */
	public function foreignKeyClause(string $column,
		string $refTable, string $refColumn = 'id',
		string $onDelete = 'RESTRICT'): string
	{
		$onDelete = strtoupper($onDelete);
		$qc = $this->db->quotekey($column);
		$qrt = $this->db->quotekey($refTable);
		$qrc = $this->db->quotekey($refColumn);
		return ", FOREIGN KEY({$qc}) REFERENCES {$qrt}({$qrc}) ON DELETE {$onDelete}";
	}

	/**
	 * Check if FK constraint exists on a column
	 * @param string $table
	 * @param string $column
	 * @return bool
	 */
	public function hasForeignKey(string $table, string $column): bool {
		$driver = $this->db->driver();
		if (str_contains($driver, 'sqlite')) {
			$result = $this->db->exec("PRAGMA foreign_key_list(".$this->db->quotekey($table).")");
			foreach ($result as $row)
				if ($row['from'] === $column)
					return true;
			return false;
		}
		if (str_contains($driver, 'mysql')) {
			$result = $this->db->exec(
				"SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE ".
				"WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ".
				"AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
				[$table, $column]
			);
			return !empty($result);
		}
		if (str_contains($driver, 'pgsql')) {
			$result = $this->db->exec(
				"SELECT tc.constraint_name FROM information_schema.table_constraints tc ".
				"JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name ".
				"WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ? AND kcu.column_name = ?",
				[$table, $column]
			);
			return !empty($result);
		}
		if (preg_match('/mssql|sqlsrv|dblib|sybase|odbc/', $driver)) {
			$result = $this->db->exec(
				"SELECT ccu.CONSTRAINT_NAME FROM INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE ccu ".
				"JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc ".
				"ON ccu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME ".
				"WHERE tc.CONSTRAINT_TYPE = 'FOREIGN KEY' AND ccu.TABLE_NAME = ? AND ccu.COLUMN_NAME = ?",
				[$table, $column]
			);
			return !empty($result);
		}
		if (str_contains($driver, 'ibm')) {
			$result = $this->db->exec(
				"SELECT CONSTNAME FROM SYSCAT.REFERENCES r ".
				"JOIN SYSCAT.KEYCOLUSE k ON r.CONSTNAME = k.CONSTNAME ".
				"WHERE r.TABNAME = ? AND k.COLNAME = ?",
				[strtoupper($table), strtoupper($column)]
			);
			return !empty($result);
		}
		return false;
	}

	/**
	 * Check if a table has a specific composite index
	 * @param string $table
	 * @param array $columns Columns to check
	 * @param bool $unique Whether to check for unique index specifically
	 * @return bool
	 */
	public function hasIndex(string $table, array $columns, bool $unique = false): bool {
		if (!in_array($table, $this->getTables()))
			return false;
		$tbl = $this->alterTable($table);
		$indexes = $tbl->listIndex();
		sort($columns);
		$driver = $this->db->driver();
		foreach ($indexes as $name => $info) {
			if (str_contains($driver, 'sqlite')) {
				$idxInfo = $this->db->exec('PRAGMA index_info('.$this->db->quotekey($name).')');
				$idxCols = array_column($idxInfo, 'name');
			} elseif (str_contains($driver, 'mysql')) {
				$qt = $this->db->quotekey($table);
				$idxInfo = $this->db->exec("SHOW INDEX FROM {$qt} WHERE Key_name = ?", [$name]);
				$idxCols = array_column($idxInfo, 'Column_name');
			} elseif (str_contains($driver, 'pgsql')) {
				$idxInfo = $this->db->exec(
					"SELECT a.attname FROM pg_index ix ".
					"JOIN pg_attribute a ON a.attrelid = ix.indrelid AND a.attnum = ANY(ix.indkey) ".
					"JOIN pg_class i ON i.oid = ix.indexrelid ".
					"WHERE i.relname = ?", [$name]
				);
				$idxCols = array_column($idxInfo, 'attname');
			} elseif (preg_match('/mssql|sqlsrv|dblib|sybase|odbc/', $driver)) {
				$idxInfo = $this->db->exec(
					"SELECT COL_NAME(ic.object_id, ic.column_id) AS column_name ".
					"FROM sys.index_columns ic ".
					"JOIN sys.indexes i ON ic.object_id = i.object_id AND ic.index_id = i.index_id ".
					"WHERE i.name = ? AND OBJECT_NAME(ic.object_id) = ?",
					[$name, $table]
				);
				$idxCols = array_column($idxInfo, 'column_name');
			} elseif (str_contains($driver, 'ibm')) {
				$idxInfo = $this->db->exec(
					"SELECT COLNAME FROM SYSCAT.INDEXCOLUSE WHERE INDNAME = ?",
					[strtoupper($name)]
				);
				$idxCols = array_column($idxInfo, 'COLNAME');
				$idxCols = array_map('strtolower', $idxCols);
			} else {
				continue;
			}
			sort($idxCols);
			if ($idxCols === $columns) {
				if (!$unique)
					return true;
				if (isset($info['unique']) && $info['unique'])
					return true;
			}
		}
		return false;
	}

	/**
	 * Create a pivot table with UNIQUE composite index and FK constraints.
	 * Handles SQLite inline FK and other engines via ALTER TABLE.
	 *
	 * @param string $mmTable Pivot table name
	 * @param string $col1 First FK column name
	 * @param string $type1 First column data type
	 * @param string $refTable1 First referenced table
	 * @param string $refCol1 First referenced column
	 * @param string $col2 Second FK column name
	 * @param string $type2 Second column data type
	 * @param string $refTable2 Second referenced table
	 * @param string $refCol2 Second referenced column
	 * @param string $onDelete ON DELETE action for both FKs
	 * @return bool
	 */
	public function createPivotTable(string $mmTable,
		string $col1, string $type1, string $refTable1, string $refCol1,
		string $col2, string $type2, string $refTable2, string $refCol2,
		string $onDelete = 'CASCADE'): bool
	{
		if (in_array($mmTable, $this->getTables()))
			return false;

		$driver = $this->db->driver();
		$isSqlite = str_contains($driver, 'sqlite');

		if ($isSqlite) {
			self::enableForeignKeys($this->db);
			$qt = $this->db->quotekey($mmTable);
			$qid = $this->db->quotekey('id');
			$qc1 = $this->db->quotekey($col1);
			$qc2 = $this->db->quotekey($col2);
			$colType1 = self::resolveColumnType($this->db, $type1);
			$colType2 = self::resolveColumnType($this->db, $type2);
			$fk1 = $this->foreignKeyClause($col1, $refTable1, $refCol1, $onDelete);
			$fk2 = $this->foreignKeyClause($col2, $refTable2, $refCol2, $onDelete);
			$cols = [$col1, $col2];
			sort($cols);
			$uniqueCols = $this->db->quotekey($cols[0]).', '.$this->db->quotekey($cols[1]);
			$this->db->exec(
				"CREATE TABLE {$qt} (".
				"{$qid} INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, ".
				"{$qc1} {$colType1}, ".
				"{$qc2} {$colType2}".
				$fk1.$fk2.
				", UNIQUE({$uniqueCols}))"
			);
			$indexName = $this->db->quotekey('idx_'.$mmTable.'_'.implode('_', $cols));
			$this->db->exec("CREATE INDEX {$indexName} ON {$qt} ({$uniqueCols})");
		} else {
			$mmt = $this->createTable($mmTable);
			$mmt->addColumn($col1)->type($type1);
			$mmt->addColumn($col2)->type($type2);
			$index = [$col1, $col2];
			sort($index);
			$mmt->addIndex($index, true);
			$mmt->build();
			$this->addForeignKey($mmTable, $col1, $refTable1, $refCol1, $onDelete);
			$this->addForeignKey($mmTable, $col2, $refTable2, $refCol2, $onDelete);
		}
		return true;
	}

	/**
	 * Recreate an existing pivot table with FK constraints.
	 * For SQLite: recreates with inline FK (preserving data).
	 * For non-SQLite: ALTER TABLE ADD CONSTRAINT.
	 *
	 * @param string $mmTable Pivot table name
	 * @param string $col1 First FK column name
	 * @param string $type1 First column data type
	 * @param string $refTable1 First referenced table
	 * @param string $refCol1 First referenced column
	 * @param string $col2 Second FK column name
	 * @param string $type2 Second column data type
	 * @param string $refTable2 Second referenced table
	 * @param string $refCol2 Second referenced column
	 * @param string $onDelete ON DELETE action
	 * @return bool
	 */
	public function recreatePivotWithFK(string $mmTable,
		string $col1, string $type1, string $refTable1, string $refCol1,
		string $col2, string $type2, string $refTable2, string $refCol2,
		string $onDelete = 'CASCADE'): bool
	{
		$driver = $this->db->driver();
		$isSqlite = str_contains($driver, 'sqlite');

		if ($isSqlite) {
			self::enableForeignKeys($this->db);
			$qt = $this->db->quotekey($mmTable);
			$tmpTable = $mmTable.'_fk_tmp';
			$qtmp = $this->db->quotekey($tmpTable);
			$qid = $this->db->quotekey('id');
			$qc1 = $this->db->quotekey($col1);
			$qc2 = $this->db->quotekey($col2);
			$colType1 = self::resolveColumnType($this->db, $type1);
			$colType2 = self::resolveColumnType($this->db, $type2);
			$fk1 = $this->foreignKeyClause($col1, $refTable1, $refCol1, $onDelete);
			$fk2 = $this->foreignKeyClause($col2, $refTable2, $refCol2, $onDelete);
			$cols = [$col1, $col2];
			sort($cols);
			$uniqueCols = $this->db->quotekey($cols[0]).', '.$this->db->quotekey($cols[1]);

			$this->db->exec(
				"CREATE TABLE {$qtmp} (".
				"{$qid} INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, ".
				"{$qc1} {$colType1}, ".
				"{$qc2} {$colType2}".
				$fk1.$fk2.
				", UNIQUE({$uniqueCols}))"
			);
			$this->db->exec("INSERT INTO {$qtmp} ({$qc1}, {$qc2}) SELECT {$qc1}, {$qc2} FROM {$qt}");
			$this->db->exec("DROP TABLE {$qt}");
			$this->db->exec("ALTER TABLE {$qtmp} RENAME TO {$qt}");
			$indexName = $this->db->quotekey('idx_'.$mmTable.'_'.implode('_', $cols));
			$this->db->exec("CREATE INDEX {$indexName} ON {$qt} ({$uniqueCols})");
			return true;
		} else {
			$this->addForeignKey($mmTable, $col1, $refTable1, $refCol1, $onDelete);
			$this->addForeignKey($mmTable, $col2, $refTable2, $refCol2, $onDelete);
			return true;
		}
	}
}

// Backward-compatible alias so existing code using \DB\SQL\Schema still works
class_alias(Schema::class, 'DB\SQL\Schema');
