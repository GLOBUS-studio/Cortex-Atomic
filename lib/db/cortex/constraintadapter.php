<?php

/**
 *  ConstraintAdapter - Backward-compatible static facade for Schema FK methods
 *
 *  All constraint logic now lives in DB\Cortex\Schema\Schema.
 *  This class delegates to Schema instance methods for backward compatibility
 *  with existing code that uses static ConstraintAdapter::method($db, ...) calls.
 *
 *  @package DB\Cortex
 *  @deprecated Use DB\Cortex\Schema\Schema instance methods directly
 */

namespace DB\Cortex;

use DB\SQL;
use DB\Cortex\Schema\Schema;

class ConstraintAdapter {

	/**
	 * Enable FK enforcement on the connection (required for SQLite)
	 * @param SQL $db
	 */
	static function enableForeignKeys(SQL $db): void {
		Schema::enableForeignKeys($db);
	}

	/**
	 * Add a foreign key constraint to a table
	 * @param SQL $db
	 * @param string $table
	 * @param string $column
	 * @param string $refTable
	 * @param string $refColumn
	 * @param string $onDelete
	 * @return bool
	 */
	static function addForeignKey(SQL $db, string $table, string $column,
		string $refTable, string $refColumn = 'id',
		string $onDelete = 'RESTRICT'): bool
	{
		return (new Schema($db))->addForeignKey($table, $column, $refTable, $refColumn, $onDelete);
	}

	/**
	 * Generate FK clause for use inside CREATE TABLE statement
	 * @param SQL $db
	 * @param string $column
	 * @param string $refTable
	 * @param string $refColumn
	 * @param string $onDelete
	 * @return string
	 */
	static function foreignKeyClause(SQL $db, string $column,
		string $refTable, string $refColumn = 'id',
		string $onDelete = 'RESTRICT'): string
	{
		return (new Schema($db))->foreignKeyClause($column, $refTable, $refColumn, $onDelete);
	}

	/**
	 * Add a UNIQUE composite index on multiple columns
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
	 * Check if a table has a specific index
	 * @param SQL $db
	 * @param string $table
	 * @param array $columns
	 * @param bool $unique
	 * @return bool
	 */
	static function hasIndex(SQL $db, string $table, array $columns, bool $unique = false): bool {
		return (new Schema($db))->hasIndex($table, $columns, $unique);
	}

	/**
	 * Check if FK constraint exists on a column
	 * @param SQL $db
	 * @param string $table
	 * @param string $column
	 * @return bool
	 */
	static function hasForeignKey(SQL $db, string $table, string $column): bool {
		return (new Schema($db))->hasForeignKey($table, $column);
	}

	/**
	 * Create a pivot table with UNIQUE composite index and FK constraints
	 * @param SQL $db
	 * @param string $mmTable
	 * @param string $col1
	 * @param string $type1
	 * @param string $refTable1
	 * @param string $refCol1
	 * @param string $col2
	 * @param string $type2
	 * @param string $refTable2
	 * @param string $refCol2
	 * @param string $onDelete
	 * @return bool
	 */
	static function createPivotTable(SQL $db, string $mmTable,
		string $col1, string $type1, string $refTable1, string $refCol1,
		string $col2, string $type2, string $refTable2, string $refCol2,
		string $onDelete = 'CASCADE'): bool
	{
		return (new Schema($db))->createPivotTable($mmTable,
			$col1, $type1, $refTable1, $refCol1,
			$col2, $type2, $refTable2, $refCol2,
			$onDelete);
	}

	/**
	 * Resolve a schema type constant to actual SQL type for current driver
	 * @param SQL $db
	 * @param string $type
	 * @return string
	 */
	static function resolveColumnType(SQL $db, string $type): string {
		return Schema::resolveColumnType($db, $type);
	}

	/**
	 * Add FK for a belongs-to-one column
	 * @param SQL $db
	 * @param string $table
	 * @param string $column
	 * @param string $refTable
	 * @param string $refColumn
	 * @param string $onDelete
	 * @return bool
	 */
	static function addBelongsToForeignKey(SQL $db, string $table, string $column,
		string $refTable, string $refColumn = 'id',
		string $onDelete = 'SET NULL'): bool
	{
		return (new Schema($db))->addForeignKey($table, $column, $refTable, $refColumn, $onDelete);
	}

	/**
	 * Recreate an existing pivot table with FK constraints
	 * @param SQL $db
	 * @param string $mmTable
	 * @param string $col1
	 * @param string $type1
	 * @param string $refTable1
	 * @param string $refCol1
	 * @param string $col2
	 * @param string $type2
	 * @param string $refTable2
	 * @param string $refCol2
	 * @param string $onDelete
	 * @return bool
	 */
	static function recreatePivotWithFK(SQL $db, string $mmTable,
		string $col1, string $type1, string $refTable1, string $refCol1,
		string $col2, string $type2, string $refTable2, string $refCol2,
		string $onDelete = 'CASCADE'): bool
	{
		return (new Schema($db))->recreatePivotWithFK($mmTable,
			$col1, $type1, $refTable1, $refCol1,
			$col2, $type2, $refTable2, $refCol2,
			$onDelete);
	}
}
