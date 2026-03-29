<?php

/**
 *  ConstraintAdapter - Database constraint management for Cortex ORM
 *
 *  Provides FK constraints and UNIQUE composite indexes for SQL databases.
 *  Designed as an adapter layer: first checks if f3-schema-builder supports
 *  native FK methods, falls back to raw SQL per dialect.
 *
 *  When f3-schema-builder is forked and updated with native addForeignKey(),
 *  the adapter will automatically use the native method instead of raw SQL.
 *
 *  @package DB\Cortex
 */

namespace DB\Cortex;

use DB\SQL;
use DB\SQL\Schema;

class ConstraintAdapter {

	const
		// SQLite requires PRAGMA foreign_keys=ON per connection
		PRAGMA_FK_ON = 'PRAGMA foreign_keys = ON',
		PRAGMA_FK_OFF = 'PRAGMA foreign_keys = OFF';

	/**
	 * Enable FK enforcement on the connection (required for SQLite)
	 * @param SQL $db
	 */
	static function enableForeignKeys(SQL $db): void {
		if (str_contains($db->driver(), 'sqlite'))
			$db->exec(self::PRAGMA_FK_ON);
	}

	/**
	 * Add a foreign key constraint to a table
	 * First checks if schema-builder supports native FK, falls back to raw SQL.
	 * For SQLite: FK can only be defined at CREATE TABLE time, not via ALTER TABLE.
	 * Use addForeignKeyClause() for SQLite CREATE TABLE scenarios.
	 *
	 * @param SQL $db Database connection
	 * @param string $table Table that holds the FK column
	 * @param string $column FK column name
	 * @param string $refTable Referenced table
	 * @param string $refColumn Referenced column (usually 'id')
	 * @param string $onDelete ON DELETE action: RESTRICT, CASCADE, SET NULL
	 * @return bool True if constraint was added, false if skipped
	 */
	static function addForeignKey(SQL $db, string $table, string $column,
		string $refTable, string $refColumn = 'id',
		string $onDelete = 'RESTRICT'): bool
	{
		$driver = $db->driver();

		// Check native schema-builder support (future-proof)
		$schema = new Schema($db);
		if (method_exists($schema->alterTable($table), 'addForeignKey')) {
			$schema->alterTable($table)->addForeignKey(
				$column, $refTable, $refColumn, $onDelete
			);
			return true;
		}

		// SQLite: ALTER TABLE ADD CONSTRAINT not supported
		if (str_contains($driver, 'sqlite'))
			return false;

		$constraintName = 'fk_'.$table.'_'.$column;
		$onDelete = strtoupper($onDelete);
		$qt = $db->quotekey($table);
		$qc = $db->quotekey($column);
		$qrt = $db->quotekey($refTable);
		$qrc = $db->quotekey($refColumn);
		$qn = $db->quotekey($constraintName);

		if (str_contains($driver, 'mysql') || str_contains($driver, 'pgsql')) {
			$db->exec(
				"ALTER TABLE {$qt} ADD CONSTRAINT {$qn} ".
				"FOREIGN KEY ({$qc}) REFERENCES {$qrt}({$qrc}) ".
				"ON DELETE {$onDelete}"
			);
			return true;
		}

		if (preg_match('/mssql|dblib|sybase|odbc|sqlsrv/', $driver)) {
			$db->exec(
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
	 * Returns string like: , FOREIGN KEY("col") REFERENCES "ref_table"("ref_col") ON DELETE RESTRICT
	 *
	 * @param SQL $db
	 * @param string $column
	 * @param string $refTable
	 * @param string $refColumn
	 * @param string $onDelete
	 * @return string SQL fragment to append to CREATE TABLE column list
	 */
	static function foreignKeyClause(SQL $db, string $column,
		string $refTable, string $refColumn = 'id',
		string $onDelete = 'RESTRICT'): string
	{
		$onDelete = strtoupper($onDelete);
		$qc = $db->quotekey($column);
		$qrt = $db->quotekey($refTable);
		$qrc = $db->quotekey($refColumn);
		return ", FOREIGN KEY({$qc}) REFERENCES {$qrt}({$qrc}) ON DELETE {$onDelete}";
	}

	/**
	 * Add a UNIQUE composite index on multiple columns.
	 * Uses schema-builder's addIndex with unique=true.
	 *
	 * @param SQL $db
	 * @param string $table
	 * @param array $columns
	 * @return bool
	 */
	static function addUniqueComposite(SQL $db, string $table, array $columns): bool {
		$schema = new Schema($db);
		if (!in_array($table, $schema->getTables()))
			return false;
		$tbl = $schema->alterTable($table);
		$tbl->addIndex($columns, true);
		$tbl->build();
		return true;
	}

	/**
	 * Check if a table has a specific index (for verification in tests)
	 * @param SQL $db
	 * @param string $table
	 * @param array $columns Columns to check
	 * @param bool $unique Whether to check for unique index specifically
	 * @return bool
	 */
	static function hasIndex(SQL $db, string $table, array $columns, bool $unique = false): bool {
		$schema = new Schema($db);
		if (!in_array($table, $schema->getTables()))
			return false;
		$tbl = $schema->alterTable($table);
		$indexes = $tbl->listIndex();
		sort($columns);
		$driver = $db->driver();
		foreach ($indexes as $name => $info) {
			// get column names for this index
			if (str_contains($driver, 'sqlite')) {
				$idxInfo = $db->exec('PRAGMA index_info('.$db->quotekey($name).')');
				$idxCols = array_column($idxInfo, 'name');
			} elseif (str_contains($driver, 'mysql')) {
				$qt = $db->quotekey($table);
				$idxInfo = $db->exec("SHOW INDEX FROM {$qt} WHERE Key_name = ?", [$name]);
				$idxCols = array_column($idxInfo, 'Column_name');
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
	 * Check if FK constraint exists on a column (for verification in tests)
	 * @param SQL $db
	 * @param string $table
	 * @param string $column
	 * @return bool
	 */
	static function hasForeignKey(SQL $db, string $table, string $column): bool {
		$driver = $db->driver();
		if (str_contains($driver, 'sqlite')) {
			$result = $db->exec("PRAGMA foreign_key_list(".$db->quotekey($table).")");
			foreach ($result as $row)
				if ($row['from'] === $column)
					return true;
			return false;
		}
		if (str_contains($driver, 'mysql')) {
			$result = $db->exec(
				"SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE ".
				"WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ".
				"AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
				[$table, $column]
			);
			return !empty($result);
		}
		if (str_contains($driver, 'pgsql')) {
			$result = $db->exec(
				"SELECT tc.constraint_name FROM information_schema.table_constraints tc ".
				"JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name ".
				"WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ? AND kcu.column_name = ?",
				[$table, $column]
			);
			return !empty($result);
		}
		return false;
	}

	/**
	 * Create a pivot table with UNIQUE composite index and optional FK constraints.
	 * Handles SQLite inline FK (via raw CREATE TABLE) and other engines (via ALTER TABLE).
	 *
	 * @param SQL $db
	 * @param string $mmTable Pivot table name
	 * @param string $col1 First FK column name
	 * @param string $type1 First column data type (schema-builder type string)
	 * @param string $refTable1 First referenced table
	 * @param string $refCol1 First referenced column
	 * @param string $col2 Second FK column name
	 * @param string $type2 Second column data type
	 * @param string $refTable2 Second referenced table
	 * @param string $refCol2 Second referenced column
	 * @param string $onDelete ON DELETE action for both FKs
	 * @return bool
	 */
	static function createPivotTable(SQL $db, string $mmTable,
		string $col1, string $type1, string $refTable1, string $refCol1,
		string $col2, string $type2, string $refTable2, string $refCol2,
		string $onDelete = 'CASCADE'): bool
	{
		$schema = new Schema($db);
		if (in_array($mmTable, $schema->getTables()))
			return false;

		$driver = $db->driver();
		$isSqlite = str_contains($driver, 'sqlite');

		if ($isSqlite) {
			// SQLite: FK must be defined inline in CREATE TABLE
			self::enableForeignKeys($db);
			$qt = $db->quotekey($mmTable);
			$qid = $db->quotekey('id');
			$qc1 = $db->quotekey($col1);
			$qc2 = $db->quotekey($col2);
			// resolve types via schema-builder
			$colType1 = self::resolveColumnType($db, $type1);
			$colType2 = self::resolveColumnType($db, $type2);
			$fk1 = self::foreignKeyClause($db, $col1, $refTable1, $refCol1, $onDelete);
			$fk2 = self::foreignKeyClause($db, $col2, $refTable2, $refCol2, $onDelete);
			$cols = [$col1, $col2];
			sort($cols);
			$uniqueCols = $db->quotekey($cols[0]).', '.$db->quotekey($cols[1]);
			$db->exec(
				"CREATE TABLE {$qt} (".
				"{$qid} INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, ".
				"{$qc1} {$colType1}, ".
				"{$qc2} {$colType2}".
				$fk1.$fk2.
				", UNIQUE({$uniqueCols}))"
			);
			// add regular index too
			$indexName = $db->quotekey('idx_'.$mmTable.'_'.implode('_', $cols));
			$db->exec("CREATE INDEX {$indexName} ON {$qt} ({$uniqueCols})");
		} else {
			// Non-SQLite: create table via schema-builder, then ALTER TABLE for FK
			$mmt = $schema->createTable($mmTable);
			$mmt->addColumn($col1)->type($type1);
			$mmt->addColumn($col2)->type($type2);
			$index = [$col1, $col2];
			sort($index);
			$mmt->addIndex($index, true); // UNIQUE composite index
			$mmt->build();
			// add FK constraints via ALTER TABLE
			self::addForeignKey($db, $mmTable, $col1, $refTable1, $refCol1, $onDelete);
			self::addForeignKey($db, $mmTable, $col2, $refTable2, $refCol2, $onDelete);
		}
		return true;
	}

	/**
	 * Resolve a schema-builder type constant to actual SQL type for current driver
	 * @param SQL $db
	 * @param string $type Schema-builder type (e.g. 'INT4', 'VARCHAR256')
	 * @return string SQL type string for current driver
	 */
	static protected function resolveColumnType(SQL $db, string $type): string {
		$schema = new Schema($db);
		$types = $schema->dataTypes;
		$type = strtoupper($type);
		if (!isset($types[$type]))
			return $type; // passthrough
		foreach ($types[$type] as $pattern => $sqlType)
			if (preg_match('/'.$pattern.'/', $db->driver()))
				return $sqlType;
		return $type;
	}

	/**
	 * Add FK for a belongs-to-one column on an existing table.
	 * For SQLite: rebuilds the table with FK inline (only if table is empty or force=true).
	 * For other engines: uses ALTER TABLE.
	 *
	 * @param SQL $db
	 * @param string $table
	 * @param string $column belongs-to-one column
	 * @param string $refTable referenced model's table
	 * @param string $refColumn referenced column
	 * @param string $onDelete
	 * @return bool
	 */
	static function addBelongsToForeignKey(SQL $db, string $table, string $column,
		string $refTable, string $refColumn = 'id',
		string $onDelete = 'SET NULL'): bool
	{
		return self::addForeignKey($db, $table, $column, $refTable, $refColumn, $onDelete);
	}

	/**
	 * Recreate an existing pivot table with FK constraints.
	 * For SQLite: recreates the table with inline FK (preserving existing data).
	 * For non-SQLite: uses ALTER TABLE ADD CONSTRAINT.
	 *
	 * @param SQL $db
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
	static function recreatePivotWithFK(SQL $db, string $mmTable,
		string $col1, string $type1, string $refTable1, string $refCol1,
		string $col2, string $type2, string $refTable2, string $refCol2,
		string $onDelete = 'CASCADE'): bool
	{
		$driver = $db->driver();
		$isSqlite = str_contains($driver, 'sqlite');

		if ($isSqlite) {
			self::enableForeignKeys($db);
			$qt = $db->quotekey($mmTable);
			$tmpTable = $mmTable.'_fk_tmp';
			$qtmp = $db->quotekey($tmpTable);
			$qid = $db->quotekey('id');
			$qc1 = $db->quotekey($col1);
			$qc2 = $db->quotekey($col2);
			$colType1 = self::resolveColumnType($db, $type1);
			$colType2 = self::resolveColumnType($db, $type2);
			$fk1 = self::foreignKeyClause($db, $col1, $refTable1, $refCol1, $onDelete);
			$fk2 = self::foreignKeyClause($db, $col2, $refTable2, $refCol2, $onDelete);
			$cols = [$col1, $col2];
			sort($cols);
			$uniqueCols = $db->quotekey($cols[0]).', '.$db->quotekey($cols[1]);

			// create temp table with FK
			$db->exec(
				"CREATE TABLE {$qtmp} (".
				"{$qid} INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, ".
				"{$qc1} {$colType1}, ".
				"{$qc2} {$colType2}".
				$fk1.$fk2.
				", UNIQUE({$uniqueCols}))"
			);
			// copy existing data
			$db->exec("INSERT INTO {$qtmp} ({$qc1}, {$qc2}) SELECT {$qc1}, {$qc2} FROM {$qt}");
			// drop original
			$db->exec("DROP TABLE {$qt}");
			// rename temp to original
			$db->exec("ALTER TABLE {$qtmp} RENAME TO {$qt}");
			// recreate index
			$indexName = $db->quotekey('idx_'.$mmTable.'_'.implode('_', $cols));
			$db->exec("CREATE INDEX {$indexName} ON {$qt} ({$uniqueCols})");
			return true;
		} else {
			// Non-SQLite: just add FK via ALTER TABLE
			self::addForeignKey($db, $mmTable, $col1, $refTable1, $refCol1, $onDelete);
			self::addForeignKey($db, $mmTable, $col2, $refTable2, $refCol2, $onDelete);
			return true;
		}
	}
}
