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
		// CLEANUP
		// ========================================================================
		foreach (['stest_rpivot', 'stest_child', 'stest_pivot', 'stest_b', 'stest_a'] as $t)
			if (in_array($t, $schema->getTables()))
				$schema->dropTable($t);

		return $test->results();
	}
}
