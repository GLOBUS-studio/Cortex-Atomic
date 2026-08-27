<?php

/**
 * Tests for the forked DB\Cortex\Schema\Schema class.
 * Verifies that the new schema classes work directly,
 * and that the DB\SQL\Schema backward-compatible alias works.
 */
class Test_Schema {

	function run($db, $type)
	{
		$test = new \Test();
		$schema = new \DB\Cortex\Schema\Schema($db);

		// ========================================================================
		// 1. class_alias: \DB\SQL\Schema maps to \DB\Cortex\Schema\Schema
		// ========================================================================
		$aliasSchema = new \DB\SQL\Schema($db);
		$test->expect(
			$aliasSchema instanceof \DB\Cortex\Schema\Schema,
			$type.': class_alias maps DB\SQL\Schema to DB\Cortex\Schema\Schema'
		);

		// ========================================================================
		// 2. DT_* constants accessible from both namespaces
		// ========================================================================
		$test->expect(
			\DB\Cortex\Schema\Schema::DT_INT4 === 'INT4'
			&& \DB\SQL\Schema::DT_INT4 === 'INT4',
			$type.': DT_INT4 constant accessible from both namespaces'
		);
		$test->expect(
			\DB\Cortex\Schema\Schema::DT_VARCHAR256 === 'VARCHAR256'
			&& \DB\SQL\Schema::DT_VARCHAR256 === 'VARCHAR256',
			$type.': DT_VARCHAR256 constant accessible from both namespaces'
		);
		$test->expect(
			\DB\Cortex\Schema\Schema::DF_CURRENT_TIMESTAMP === 'CUR_STAMP'
			&& \DB\SQL\Schema::DF_CURRENT_TIMESTAMP === 'CUR_STAMP',
			$type.': DF_CURRENT_TIMESTAMP constant accessible from both namespaces'
		);

		// ========================================================================
		// 3. Basic schema operations: create, alter, drop
		// ========================================================================
		$tName = 'test_schema_basic';
		if (in_array($tName, $schema->getTables()))
			$schema->dropTable($tName);

		$tc = $schema->createTable($tName);
		$test->expect(
			$tc instanceof \DB\Cortex\Schema\TableCreator,
			$type.': createTable() returns TableCreator instance'
		);
		$tc->addColumn('name')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256);
		$tc->addColumn('age')->type(\DB\Cortex\Schema\Schema::DT_INT4);
		$result = $tc->build();
		$test->expect(
			$result instanceof \DB\Cortex\Schema\TableModifier,
			$type.': TableCreator::build() returns TableModifier instance'
		);
		$test->expect(
			in_array($tName, $schema->getTables()),
			$type.': table created and visible in getTables()'
		);

		// alter table - add column
		$tm = $schema->alterTable($tName);
		$test->expect(
			$tm instanceof \DB\Cortex\Schema\TableModifier,
			$type.': alterTable() returns TableModifier instance'
		);
		$tm->addColumn('email')->type(\DB\Cortex\Schema\Schema::DT_TEXT);
		$tm->build();
		$cols = $schema->alterTable($tName)->getCols();
		$test->expect(
			in_array('email', $cols),
			$type.': alterTable() successfully added email column'
		);

		// drop table
		$schema->dropTable($tName);
		$test->expect(
			!in_array($tName, $schema->getTables()),
			$type.': dropTable() removes table'
		);

		// ========================================================================
		// 4. Column fluent API via new namespace
		// ========================================================================
		$tName2 = 'test_schema_fluent';
		if (in_array($tName2, $schema->getTables()))
			$schema->dropTable($tName2);
		$tc2 = $schema->createTable($tName2);
		$tc2->addColumn('title')->type_varchar(128);
		$tc2->addColumn('count')->type_int();
		$tc2->addColumn('active')->type_bool()->defaults(1);
		$tc2->addColumn('created')->type_timestamp(true);
		$tc2->build();
		$test->expect(
			in_array($tName2, $schema->getTables()),
			$type.': fluent Column API (type_varchar, type_int, type_bool, type_timestamp) works'
		);
		$schema->dropTable($tName2);

		// ========================================================================
		// 5. enableForeignKeys() static method
		// ========================================================================
		\DB\Cortex\Schema\Schema::enableForeignKeys($db);
		if (str_contains($db->driver(), 'sqlite')) {
			$r = $db->exec('PRAGMA foreign_keys');
			$test->expect(
				$r[0]['foreign_keys'] == 1,
				$type.': Schema::enableForeignKeys() sets PRAGMA on SQLite'
			);
		} else {
			$test->expect(true, $type.': Schema::enableForeignKeys() no-op for non-SQLite');
		}

		// ========================================================================
		// 6. resolveColumnType() static method
		// ========================================================================
		$resolved = \DB\Cortex\Schema\Schema::resolveColumnType($db, 'INT4');
		$test->expect(
			!empty($resolved) && $resolved !== 'INT4',
			$type.': resolveColumnType(INT4) resolves to driver-specific type: '.$resolved
		);

		// ========================================================================
		// 7. Schema instance FK methods (createPivotTable, hasForeignKey, etc.)
		// ========================================================================
		// setup parent tables
		foreach (['stest_pivot', 'stest_a', 'stest_b'] as $t)
			if (in_array($t, $schema->getTables()))
				$schema->dropTable($t);
		$sa = $schema->createTable('stest_a');
		$sa->addColumn('name')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256);
		$sa->build();
		$sb = $schema->createTable('stest_b');
		$sb->addColumn('title')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256);
		$sb->build();

		// createPivotTable via Schema instance
		$result = $schema->createPivotTable('stest_pivot',
			'a_id', 'INT4', 'stest_a', 'id',
			'b_id', 'INT4', 'stest_b', 'id',
			'CASCADE'
		);
		$test->expect(
			$result === true && in_array('stest_pivot', $schema->getTables()),
			$type.': Schema::createPivotTable() creates pivot table'
		);

		// hasForeignKey via Schema instance
		\DB\Cortex\Schema\Schema::enableForeignKeys($db);
		$test->expect(
			$schema->hasForeignKey('stest_pivot', 'a_id') === true
			&& $schema->hasForeignKey('stest_pivot', 'b_id') === true,
			$type.': Schema::hasForeignKey() detects FK on pivot columns'
		);

		// hasIndex via Schema instance
		$test->expect(
			$schema->hasIndex('stest_pivot', ['a_id', 'b_id'], true) === true,
			$type.': Schema::hasIndex() detects UNIQUE on pivot columns'
		);

		// createPivotTable returns false for existing
		$test->expect(
			$schema->createPivotTable('stest_pivot',
				'a_id', 'INT4', 'stest_a', 'id',
				'b_id', 'INT4', 'stest_b', 'id') === false,
			$type.': Schema::createPivotTable() returns false for existing table'
		);

		// ========================================================================
		// 8. foreignKeyClause() instance method
		// ========================================================================
		$clause = $schema->foreignKeyClause('col1', 'ref_table', 'ref_id', 'CASCADE');
		$test->expect(
			str_contains($clause, 'FOREIGN KEY') && str_contains($clause, 'CASCADE'),
			$type.': Schema::foreignKeyClause() generates valid SQL fragment'
		);

		// ========================================================================
		// 9. addForeignKey() instance method
		// ========================================================================
		$childTable = 'stest_child';
		if (in_array($childTable, $schema->getTables()))
			$schema->dropTable($childTable);
		$cc = $schema->createTable($childTable);
		$cc->addColumn('parent_id')->type(\DB\Cortex\Schema\Schema::DT_INT4);
		$cc->build();
		$fkResult = $schema->addForeignKey($childTable, 'parent_id', 'stest_a', 'id', 'CASCADE');
		if (str_contains($db->driver(), 'sqlite')) {
			$test->expect(
				$fkResult === false,
				$type.': Schema::addForeignKey() returns false on SQLite (ALTER TABLE limitation)'
			);
		} else {
			$test->expect(
				$fkResult === true,
				$type.': Schema::addForeignKey() succeeds via ALTER TABLE'
			);
		}

		// ========================================================================
		// 10. recreatePivotWithFK() instance method
		// ========================================================================
		$rpName = 'stest_rpivot';
		if (in_array($rpName, $schema->getTables()))
			$schema->dropTable($rpName);
		// create plain pivot without FK
		$rp = $schema->createTable($rpName);
		$rp->addColumn('a_id')->type(\DB\Cortex\Schema\Schema::DT_INT4);
		$rp->addColumn('b_id')->type(\DB\Cortex\Schema\Schema::DT_INT4);
		$rp->addIndex(['a_id', 'b_id'], true);
		$rp->build();
		// insert parent data
		$db->exec("INSERT INTO ".$db->quotekey('stest_a')." (".$db->quotekey('name').") VALUES (?)", ['test']);
		$db->exec("INSERT INTO ".$db->quotekey('stest_b')." (".$db->quotekey('title').") VALUES (?)", ['test']);
		$aId = $db->exec("SELECT ".$db->quotekey('id')." FROM ".$db->quotekey('stest_a')." LIMIT 1")[0]['id'];
		$bId = $db->exec("SELECT ".$db->quotekey('id')." FROM ".$db->quotekey('stest_b')." LIMIT 1")[0]['id'];
		$db->exec("INSERT INTO ".$db->quotekey($rpName)
			." (".$db->quotekey('a_id').", ".$db->quotekey('b_id').") VALUES (?, ?)", [$aId, $bId]);
		// recreate with FK
		$recreateResult = $schema->recreatePivotWithFK($rpName,
			'a_id', 'INT4', 'stest_a', 'id',
			'b_id', 'INT4', 'stest_b', 'id',
			'CASCADE'
		);
		$test->expect(
			$recreateResult === true,
			$type.': Schema::recreatePivotWithFK() returns true'
		);
		// verify data preserved
		$cnt = $db->exec("SELECT COUNT(*) as cnt FROM ".$db->quotekey($rpName));
		$test->expect(
			$cnt[0]['cnt'] == 1,
			$type.': Schema::recreatePivotWithFK() preserved data'
		);

		// ========================================================================
		// 11. renameColumn() keeps NOT NULL and DEFAULT
		// MySQL/MariaDB CHANGE rewrites the whole column; type-only SQL drops both.
		// SQLite rename goes through _sqlite_rebuild() - a separate known failure.
		// PostgreSQL RENAME COLUMN already preserves constraints (parity check).
		// ========================================================================
		if (!str_contains($db->driver(), 'sqlite')) {
			$tRename = 'test_schema_rename';
			if (in_array($tRename, $schema->getTables()))
				$schema->dropTable($tRename);
			$tcRename = $schema->createTable($tRename);
			$tcRename->addColumn('num')->type(\DB\Cortex\Schema\Schema::DT_INT4)
				->nullable(false)->defaults(7);
			$tcRename->addColumn('label')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256);
			$tcRename->build();
			$db->exec("INSERT INTO ".$db->quotekey($tRename)
				." (".$db->quotekey('label').") VALUES (?)", ['kept']);
			$tmRename = $schema->alterTable($tRename);
			$tmRename->renameColumn('num','num2');
			$tmRename->build();

			$colsRename = $schema->alterTable($tRename)->getCols(TRUE);
			$test->expect(
				isset($colsRename['num2']) && !isset($colsRename['num']),
				$type.': renameColumn() renames num to num2'
			);

			// Engine catalogs, not getCols() - same source SHOW COLUMNS would use.
			$meta = $this->columnMeta($db, $tRename, 'num2');
			$test->expect(
				$meta !== null && $meta['nullable'] === false,
				$type.': renameColumn() preserves NOT NULL'
			);
			$test->expect(
				$meta !== null && (string)$meta['default'] === '7',
				$type.': renameColumn() preserves DEFAULT 7'
			);

			// Existing values survive even on the broken CHANGE - sanity only.
			$rowRename = $db->exec("SELECT ".$db->quotekey('num2')." FROM ".$db->quotekey($tRename));
			$test->expect(
				isset($rowRename[0]['num2']) && $rowRename[0]['num2']==7,
				$type.': renameColumn() preserves existing values'
			);

			// Omitting the column must still apply DEFAULT 7. Broken CHANGE inserts NULL.
			$db->exec("INSERT INTO ".$db->quotekey($tRename)
				." (".$db->quotekey('label').") VALUES (?)", ['after']);
			$rowAfter = $db->exec("SELECT ".$db->quotekey('num2')." FROM ".$db->quotekey($tRename)
				." WHERE ".$db->quotekey('label')." = ?", ['after']);
			$test->expect(
				isset($rowAfter[0]['num2']) && $rowAfter[0]['num2']==7,
				$type.': renameColumn() still applies DEFAULT on insert'
			);

			$nullRejected = false;
			try {
				$db->exec("INSERT INTO ".$db->quotekey($tRename)
					." (".$db->quotekey('num2').", ".$db->quotekey('label').") VALUES (?, ?)",
					[null, 'nulls']);
			} catch (\Exception $e) {
				$nullRejected = true;
			}
			$test->expect(
				$nullRejected,
				$type.': renameColumn() still rejects NULL after rename'
			);
			$schema->dropTable($tRename);
		}

		// ========================================================================
		// 12. updateColumn() with a bare type keeps NOT NULL and DEFAULT
		// MySQL/MariaDB MODIFY COLUMN rewrites the whole column; type-only SQL drops both.
		// SQLite update goes through _sqlite_rebuild() — a separate known failure.
		// PostgreSQL ALTER COLUMN … TYPE already preserves constraints (parity check).
		// ========================================================================
		if (!str_contains($db->driver(), 'sqlite')) {
			$tUpdate = 'test_schema_update';
			if (in_array($tUpdate, $schema->getTables()))
				$schema->dropTable($tUpdate);
			$tcUpdate = $schema->createTable($tUpdate);
			$tcUpdate->addColumn('num')->type(\DB\Cortex\Schema\Schema::DT_INT4)
				->nullable(false)->defaults(7);
			$tcUpdate->addColumn('label')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256);
			$tcUpdate->build();
			$db->exec("INSERT INTO ".$db->quotekey($tUpdate)
				." (".$db->quotekey('label').") VALUES (?)", ['kept']);
			$tmUpdate = $schema->alterTable($tUpdate);
			$tmUpdate->updateColumn('num', \DB\Cortex\Schema\Schema::DT_INT8);
			$tmUpdate->build();

			$meta = $this->columnMeta($db, $tUpdate, 'num');
			$test->expect(
				$meta !== null && stripos((string)$meta['type'], 'bigint') !== false,
				$type.': updateColumn() changes INT4 to INT8/bigint'
			);
			$test->expect(
				$meta !== null && $meta['nullable'] === false,
				$type.': updateColumn() preserves NOT NULL'
			);
			$test->expect(
				$meta !== null && (string)$meta['default'] === '7',
				$type.': updateColumn() preserves DEFAULT 7'
			);

			$rowUpdate = $db->exec("SELECT ".$db->quotekey('num')." FROM ".$db->quotekey($tUpdate));
			$test->expect(
				isset($rowUpdate[0]['num']) && $rowUpdate[0]['num']==7,
				$type.': updateColumn() preserves existing values'
			);

			$db->exec("INSERT INTO ".$db->quotekey($tUpdate)
				." (".$db->quotekey('label').") VALUES (?)", ['after']);
			$rowAfter = $db->exec("SELECT ".$db->quotekey('num')." FROM ".$db->quotekey($tUpdate)
				." WHERE ".$db->quotekey('label')." = ?", ['after']);
			$test->expect(
				isset($rowAfter[0]['num']) && $rowAfter[0]['num']==7,
				$type.': updateColumn() still applies DEFAULT on insert'
			);

			$nullRejected = false;
			try {
				$db->exec("INSERT INTO ".$db->quotekey($tUpdate)
					." (".$db->quotekey('num').", ".$db->quotekey('label').") VALUES (?, ?)",
					[null, 'nulls']);
			} catch (\Exception $e) {
				$nullRejected = true;
			}
			$test->expect(
				$nullRejected,
				$type.': updateColumn() still rejects NULL after type change'
			);
			$schema->dropTable($tUpdate);
		}

		// ========================================================================
		// 13. Column defaults are SQL-quoted, not HTML-encoded
		// htmlspecialchars() before quote() stored Tom &amp; Jerry instead of
		// Tom & Jerry. quote() already makes the DDL literal safe.
		// CREATE TABLE (not ALTER rebuild), so SQLite is included.
		// ========================================================================
		$tDefault = 'test_schema_defraw';
		if (in_array($tDefault, $schema->getTables()))
			$schema->dropTable($tDefault);
		$amp = 'Tom & Jerry';
		$html = '<b>a&b</b>';
		$apos = "O'Brien";
		$tcDefault = $schema->createTable($tDefault);
		$tcDefault->addColumn('label')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256);
		$tcDefault->addColumn('amp')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256)
			->defaults($amp);
		$tcDefault->addColumn('html')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256)
			->defaults($html);
		$tcDefault->addColumn('apos')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256)
			->defaults($apos);
		$tcDefault->build();

		$db->exec("INSERT INTO ".$db->quotekey($tDefault)
			." (".$db->quotekey('label').") VALUES (?)", ['omit']);
		$rowDef = $db->exec("SELECT ".$db->quotekey('amp').", "
			.$db->quotekey('html').", ".$db->quotekey('apos')
			." FROM ".$db->quotekey($tDefault)
			." WHERE ".$db->quotekey('label')." = ?", ['omit']);
		$got = $rowDef[0] ?? [];
		$test->expect(
			isset($got['amp']) && $got['amp'] === $amp,
			$type.': VARCHAR default preserves & (not HTML-encoded)'
		);
		$test->expect(
			isset($got['html']) && $got['html'] === $html,
			$type.': VARCHAR default preserves HTML tags and &'
		);
		$test->expect(
			isset($got['apos']) && $got['apos'] === $apos,
			$type.': VARCHAR default preserves apostrophe via SQL quoting'
		);
		$schema->dropTable($tDefault);

		// ========================================================================
		// 14. Add TIMESTAMP + CURRENT_TIMESTAMP to an existing table
		// SQLite cannot ALTER-add a dynamic default; it rebuilds the table.
		// The rebuild used to TypeError: unguarded rebuild_cmd['update'] and
		// dropTable() reading a protected TableBuilder::$name.
		// ========================================================================
		$tStamp = 'test_schema_stamp';
		if (in_array($tStamp, $schema->getTables()))
			$schema->dropTable($tStamp);
		$tcStamp = $schema->createTable($tStamp);
		$tcStamp->addColumn('label')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256);
		$tcStamp->build();
		$db->exec("INSERT INTO ".$db->quotekey($tStamp)
			." (".$db->quotekey('label').") VALUES (?)", ['kept']);

		$built = false;
		$buildErr = '';
		try {
			$tmStamp = $schema->alterTable($tStamp);
			$tmStamp->addColumn('stamp')->type(\DB\Cortex\Schema\Schema::DT_TIMESTAMP)
				->defaults(\DB\Cortex\Schema\Schema::DF_CURRENT_TIMESTAMP);
			$tmStamp->build();
			$built = true;
		} catch (\Throwable $e) {
			$buildErr = $e->getMessage();
		}
		$test->expect(
			$built,
			$type.': add TIMESTAMP CURRENT_TIMESTAMP to existing table'
				.($buildErr ? ' ('.$buildErr.')' : '')
		);

		$colsStamp = $built ? $schema->alterTable($tStamp)->getCols() : [];
		$test->expect(
			in_array('stamp', $colsStamp),
			$type.': TIMESTAMP column exists after alter'
		);

		$rowKept = $built ? $db->exec("SELECT ".$db->quotekey('label')
			." FROM ".$db->quotekey($tStamp)
			." WHERE ".$db->quotekey('label')." = ?", ['kept']) : [];
		$test->expect(
			isset($rowKept[0]['label']) && $rowKept[0]['label'] === 'kept',
			$type.': existing rows survive TIMESTAMP alter'
		);

		if ($built) {
			$db->exec("INSERT INTO ".$db->quotekey($tStamp)
				." (".$db->quotekey('label').") VALUES (?)", ['after']);
			$rowAfter = $db->exec("SELECT ".$db->quotekey('stamp')
				." FROM ".$db->quotekey($tStamp)
				." WHERE ".$db->quotekey('label')." = ?", ['after']);
			$stampVal = $rowAfter[0]['stamp'] ?? null;
			$test->expect(
				$stampVal !== null && $stampVal !== '',
				$type.': omit-column insert applies CURRENT_TIMESTAMP'
			);
		} else {
			$test->expect(false, $type.': omit-column insert applies CURRENT_TIMESTAMP');
		}
		if (in_array($tStamp, $schema->getTables()))
			$schema->dropTable($tStamp);

		// ========================================================================
		// CLEANUP
		// ========================================================================
		foreach (['stest_rpivot', 'stest_child', 'stest_pivot', 'stest_b', 'stest_a',
			'test_schema_defraw', 'test_schema_stamp'] as $t)
			if (in_array($t, $schema->getTables()))
				$schema->dropTable($t);

		return $test->results();
	}

	/**
	 * Nullability + default from the engine catalog, not Cortex getCols().
	 * @return array{nullable:bool,default:?string,type:string}|null
	 */
	private function columnMeta($db, string $table, string $column): ?array {
		$driver = $db->driver();
		if (str_contains($driver, 'mysql')) {
			$cols = $db->exec("SHOW COLUMNS FROM ".$db->quotekey($table)." LIKE ?", [$column]);
			if (empty($cols))
				return null;
			return [
				'nullable' => $cols[0]['Null'] === 'YES',
				'default' => $cols[0]['Default'] === null ? null : (string)$cols[0]['Default'],
				'type' => (string)$cols[0]['Type'],
			];
		}
		if (str_contains($driver, 'pgsql')) {
			$cols = $db->exec(
				"SELECT is_nullable, column_default, data_type FROM information_schema.columns
				 WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?",
				[$table, $column]
			);
			if (empty($cols))
				return null;
			$default = $cols[0]['column_default'];
			if ($default !== null && preg_match("/^'?(-?\d+)/", (string)$default, $m))
				$default = $m[1];
			return [
				'nullable' => $cols[0]['is_nullable'] === 'YES',
				'default' => $default === null ? null : (string)$default,
				'type' => (string)$cols[0]['data_type'],
			];
		}
		if (str_contains($driver, 'sqlsrv') || str_contains($driver, 'mssql')) {
			$cols = $db->exec(
				"SELECT IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_NAME = ? AND COLUMN_NAME = ?",
				[$table, $column]
			);
			if (empty($cols))
				return null;
			$default = $cols[0]['COLUMN_DEFAULT'];
			if ($default !== null && preg_match("/^\(?\s*'?(.*?)'?\s*\)?$/", (string)$default, $m))
				$default = $m[1];
			if ($default !== null && preg_match("/^'?(-?\d+)/", (string)$default, $m))
				$default = $m[1];
			return [
				'nullable' => $cols[0]['IS_NULLABLE'] === 'YES',
				'default' => ($default === null || $default === '') ? null : (string)$default,
				'type' => (string)$cols[0]['DATA_TYPE'],
			];
		}
		return null;
	}
}
