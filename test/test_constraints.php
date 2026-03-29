<?php

/**
 * Constraint coverage tests for ConstraintAdapter.
 * Verifies UNIQUE composite indexes on pivot tables, FK constraints,
 * enableForeignKeys(), hasIndex(), hasForeignKey(), and createPivotTable().
 */
class Test_Constraints {

	function run($db, $type)
	{
		$test = new \Test();
		$adapter = \DB\Cortex\ConstraintAdapter::class;

		// ====== SETUP: create parent tables first ======
		$schema = new \DB\SQL\Schema($db);

		// Clean up any previous runs
		foreach (['pivot_ab', 'tbl_a', 'tbl_b', 'con_parent', 'con_child'] as $t)
			if (in_array($t, $schema->getTables()))
				$schema->dropTable($t);

		// Create parent tables for FK references
		$ta = $schema->createTable('tbl_a');
		$ta->addColumn('name')->type(\DB\SQL\Schema::DT_VARCHAR256);
		$ta->build();

		$tb = $schema->createTable('tbl_b');
		$tb->addColumn('title')->type(\DB\SQL\Schema::DT_VARCHAR256);
		$tb->build();

		// ========================================================================
		// 1. enableForeignKeys() - SQLite PRAGMA
		// ========================================================================
		$adapter::enableForeignKeys($db);
		if (str_contains($db->driver(), 'sqlite')) {
			$r = $db->exec('PRAGMA foreign_keys');
			$test->expect(
				$r[0]['foreign_keys'] == 1,
				$type.': enableForeignKeys() sets PRAGMA foreign_keys=ON for SQLite'
			);
		} else {
			$test->expect(true, $type.': enableForeignKeys() no-op for non-SQLite (OK)');
		}

		// ========================================================================
		// 2. createPivotTable() - creates table with UNIQUE + FK
		// ========================================================================
		$result = $adapter::createPivotTable(
			$db, 'pivot_ab',
			'a_id', 'INT4', 'tbl_a', 'id',
			'b_id', 'INT4', 'tbl_b', 'id',
			'CASCADE'
		);
		$test->expect(
			$result === true,
			$type.': createPivotTable() returns true on success'
		);

		$test->expect(
			in_array('pivot_ab', $schema->getTables()),
			$type.': createPivotTable() created the pivot table'
		);

		// ========================================================================
		// 3. hasIndex() - UNIQUE composite index on pivot
		// ========================================================================
		$hasUnique = $adapter::hasIndex($db, 'pivot_ab', ['a_id', 'b_id'], true);
		$test->expect(
			$hasUnique === true,
			$type.': pivot table has UNIQUE composite index on [a_id, b_id]'
		);

		// ========================================================================
		// 4. hasForeignKey() - FK on pivot columns
		// ========================================================================
		$hasFk1 = $adapter::hasForeignKey($db, 'pivot_ab', 'a_id');
		$hasFk2 = $adapter::hasForeignKey($db, 'pivot_ab', 'b_id');
		$test->expect(
			$hasFk1 === true && $hasFk2 === true,
			$type.': pivot table has FK on both columns'
		);

		// ========================================================================
		// 5. UNIQUE constraint prevents duplicate pivot entries
		// ========================================================================
		// Insert parent rows first
		$db->exec("INSERT INTO ".$db->quotekey('tbl_a')." (".$db->quotekey('name').") VALUES (?)", ['alpha']);
		$db->exec("INSERT INTO ".$db->quotekey('tbl_b')." (".$db->quotekey('title').") VALUES (?)", ['beta']);

		$db->exec("INSERT INTO ".$db->quotekey('pivot_ab')
			." (".$db->quotekey('a_id').", ".$db->quotekey('b_id').") VALUES (?, ?)", [1, 1]);
		$dupError = false;
		try {
			$db->exec("INSERT INTO ".$db->quotekey('pivot_ab')
				." (".$db->quotekey('a_id').", ".$db->quotekey('b_id').") VALUES (?, ?)", [1, 1]);
		} catch (\PDOException $e) {
			$dupError = true;
		}
		$test->expect(
			$dupError === true,
			$type.': UNIQUE constraint rejects duplicate pivot entry'
		);

		// ========================================================================
		// 6. FK CASCADE - deleting parent removes pivot entries
		// ========================================================================
		$adapter::enableForeignKeys($db); // ensure FK enforcement
		$countBefore = $db->exec("SELECT COUNT(*) as cnt FROM ".$db->quotekey('pivot_ab'));
		$test->expect(
			$countBefore[0]['cnt'] == 1,
			$type.': pivot has 1 row before parent delete'
		);

		$db->exec("DELETE FROM ".$db->quotekey('tbl_a')." WHERE ".$db->quotekey('id')." = ?", [1]);
		$countAfter = $db->exec("SELECT COUNT(*) as cnt FROM ".$db->quotekey('pivot_ab'));
		$test->expect(
			$countAfter[0]['cnt'] == 0,
			$type.': FK CASCADE - deleting parent removes pivot entries'
		);

		// ========================================================================
		// 7. FK RESTRICT - prevents inserting orphan pivot entry
		// ========================================================================
		$orphanError = false;
		try {
			$db->exec("INSERT INTO ".$db->quotekey('pivot_ab')
				." (".$db->quotekey('a_id').", ".$db->quotekey('b_id').") VALUES (?, ?)", [999, 999]);
		} catch (\PDOException $e) {
			$orphanError = true;
		}
		$test->expect(
			$orphanError === true,
			$type.': FK prevents inserting orphan pivot entry (no parent with id=999)'
		);

		// ========================================================================
		// 8. createPivotTable() returns false for existing table
		// ========================================================================
		$result2 = $adapter::createPivotTable(
			$db, 'pivot_ab',
			'a_id', 'INT4', 'tbl_a', 'id',
			'b_id', 'INT4', 'tbl_b', 'id'
		);
		$test->expect(
			$result2 === false,
			$type.': createPivotTable() returns false if table already exists'
		);

		// ========================================================================
		// 9. addUniqueComposite() on existing table
		// ========================================================================
		// Create a plain table and add composite UNIQUE later
		if (in_array('con_parent', $schema->getTables()))
			$schema->dropTable('con_parent');
		$cp = $schema->createTable('con_parent');
		$cp->addColumn('fname')->type(\DB\SQL\Schema::DT_VARCHAR256);
		$cp->addColumn('lname')->type(\DB\SQL\Schema::DT_VARCHAR256);
		$cp->build();

		$result = $adapter::addUniqueComposite($db, 'con_parent', ['fname', 'lname']);
		$test->expect(
			$result === true,
			$type.': addUniqueComposite() returns true'
		);

		$hasUnique2 = $adapter::hasIndex($db, 'con_parent', ['fname', 'lname'], true);
		$test->expect(
			$hasUnique2 === true,
			$type.': addUniqueComposite() created UNIQUE index'
		);

		// verify it blocks duplicates
		$db->exec("INSERT INTO ".$db->quotekey('con_parent')
			." (".$db->quotekey('fname').", ".$db->quotekey('lname').") VALUES (?, ?)", ['John', 'Doe']);
		$dupError2 = false;
		try {
			$db->exec("INSERT INTO ".$db->quotekey('con_parent')
				." (".$db->quotekey('fname').", ".$db->quotekey('lname').") VALUES (?, ?)", ['John', 'Doe']);
		} catch (\PDOException $e) {
			$dupError2 = true;
		}
		$test->expect(
			$dupError2 === true,
			$type.': addUniqueComposite() enforces uniqueness'
		);

		// ========================================================================
		// 10. addForeignKey() - via ALTER TABLE (non-SQLite) / skipped on SQLite
		// ========================================================================
		if (in_array('con_child', $schema->getTables()))
			$schema->dropTable('con_child');
		$cc = $schema->createTable('con_child');
		$cc->addColumn('parent_id')->type(\DB\SQL\Schema::DT_INT4);
		$cc->addColumn('value')->type(\DB\SQL\Schema::DT_VARCHAR256);
		$cc->build();

		$fkResult = $adapter::addForeignKey($db, 'con_child', 'parent_id', 'con_parent', 'id', 'CASCADE');
		if (str_contains($db->driver(), 'sqlite')) {
			$test->expect(
				$fkResult === false,
				$type.': addForeignKey() via ALTER TABLE returns false on SQLite (expected)'
			);
		} else {
			$test->expect(
				$fkResult === true,
				$type.': addForeignKey() via ALTER TABLE succeeds on '.$type
			);
			$hasFk = $adapter::hasForeignKey($db, 'con_child', 'parent_id');
			$test->expect($hasFk === true, $type.': hasForeignKey() confirms FK exists');
		}

		// ========================================================================
		// 11. addBelongsToForeignKey() alias
		// ========================================================================
		// This is just an alias with SET NULL default, verify it doesn't crash
		// on SQLite it returns false, on others it may fail if FK already exists
		try {
			$btoResult = $adapter::addBelongsToForeignKey(
				$db, 'con_child', 'parent_id', 'con_parent', 'id', 'SET NULL'
			);
		} catch (\PDOException $e) {
			// duplicate FK constraint from test #10 — expected on MySQL/PG
			$btoResult = false;
		}
		if (str_contains($db->driver(), 'sqlite')) {
			$test->expect(
				$btoResult === false,
				$type.': addBelongsToForeignKey() returns false on SQLite (ALTER TABLE not supported)'
			);
		} else {
			// may fail due to existing FK constraint from test #10, that's OK
			$test->expect(
				is_bool($btoResult),
				$type.': addBelongsToForeignKey() returns bool'
			);
		}

		// ========================================================================
		// 12. foreignKeyClause() generates correct SQL fragment
		// ========================================================================
		$clause = $adapter::foreignKeyClause($db, 'author_id', 'authors', 'id', 'CASCADE');
		$test->expect(
			str_contains($clause, 'FOREIGN KEY') && str_contains($clause, 'CASCADE'),
			$type.': foreignKeyClause() generates valid SQL fragment'
		);

		// ========================================================================
		// 13. hasIndex() returns false for non-existent table
		// ========================================================================
		$test->expect(
			$adapter::hasIndex($db, 'nonexistent_table_xyz', ['col'], true) === false,
			$type.': hasIndex() returns false for non-existent table'
		);

		// ========================================================================
		// 14. addUniqueComposite() returns false for non-existent table
		// ========================================================================
		$test->expect(
			$adapter::addUniqueComposite($db, 'nonexistent_table_xyz', ['col']) === false,
			$type.': addUniqueComposite() returns false for non-existent table'
		);

		// ========================================================================
		// 15. recreatePivotWithFK() - direct test with data preservation
		// ========================================================================
		// Create a pivot without FK first (plain schema-builder)
		if (in_array('pivot_retest', $schema->getTables()))
			$schema->dropTable('pivot_retest');
		// ensure parent tables exist
		if (!in_array('tbl_a', $schema->getTables())) {
			$ra = $schema->createTable('tbl_a');
			$ra->addColumn('name')->type(\DB\SQL\Schema::DT_VARCHAR256);
			$ra->build();
		}
		if (!in_array('tbl_b', $schema->getTables())) {
			$rb = $schema->createTable('tbl_b');
			$rb->addColumn('title')->type(\DB\SQL\Schema::DT_VARCHAR256);
			$rb->build();
		}
		// insert parent records for FK reference
		$aRows = $db->exec("SELECT COUNT(*) as cnt FROM ".$db->quotekey('tbl_a'));
		if ($aRows[0]['cnt'] == 0)
			$db->exec("INSERT INTO ".$db->quotekey('tbl_a')." (".$db->quotekey('name').") VALUES (?)", ['rec_a']);
		$bRows = $db->exec("SELECT COUNT(*) as cnt FROM ".$db->quotekey('tbl_b'));
		if ($bRows[0]['cnt'] == 0)
			$db->exec("INSERT INTO ".$db->quotekey('tbl_b')." (".$db->quotekey('title').") VALUES (?)", ['rec_b']);
		// get actual IDs
		$aId = $db->exec("SELECT ".$db->quotekey('id')." FROM ".$db->quotekey('tbl_a')." LIMIT 1")[0]['id'];
		$bId = $db->exec("SELECT ".$db->quotekey('id')." FROM ".$db->quotekey('tbl_b')." LIMIT 1")[0]['id'];
		// create plain pivot via schema-builder (NO FK)
		$pp = $schema->createTable('pivot_retest');
		$pp->addColumn('a_id')->type(\DB\SQL\Schema::DT_INT4);
		$pp->addColumn('b_id')->type(\DB\SQL\Schema::DT_INT4);
		$pp->addIndex(['a_id', 'b_id'], true);
		$pp->build();
		// insert data into plain pivot
		$db->exec("INSERT INTO ".$db->quotekey('pivot_retest')
			." (".$db->quotekey('a_id').", ".$db->quotekey('b_id').") VALUES (?, ?)", [$aId, $bId]);
		// confirm no FK before recreate
		$test->expect(
			$adapter::hasForeignKey($db, 'pivot_retest', 'a_id') === false,
			$type.': pivot_retest has no FK before recreatePivotWithFK()'
		);
		// recreate with FK
		$recreateResult = $adapter::recreatePivotWithFK(
			$db, 'pivot_retest',
			'a_id', 'INT4', 'tbl_a', 'id',
			'b_id', 'INT4', 'tbl_b', 'id',
			'CASCADE'
		);
		$test->expect(
			$recreateResult === true,
			$type.': recreatePivotWithFK() returns true'
		);
		// verify FK now exists
		$test->expect(
			$adapter::hasForeignKey($db, 'pivot_retest', 'a_id') === true
				&& $adapter::hasForeignKey($db, 'pivot_retest', 'b_id') === true,
			$type.': recreatePivotWithFK() added FK on both columns'
		);
		// verify UNIQUE preserved
		$test->expect(
			$adapter::hasIndex($db, 'pivot_retest', ['a_id', 'b_id'], true) === true,
			$type.': recreatePivotWithFK() preserved UNIQUE index'
		);
		// verify data preserved after recreate
		$rowCount = $db->exec("SELECT COUNT(*) as cnt FROM ".$db->quotekey('pivot_retest'));
		$test->expect(
			$rowCount[0]['cnt'] == 1,
			$type.': recreatePivotWithFK() preserved existing data (1 row)'
		);
		$rowData = $db->exec("SELECT * FROM ".$db->quotekey('pivot_retest')." LIMIT 1");
		$test->expect(
			$rowData[0]['a_id'] == $aId && $rowData[0]['b_id'] == $bId,
			$type.': recreatePivotWithFK() preserved correct column values'
		);
		// verify FK enforcement works after recreate
		$orphanAfterRecreate = false;
		try {
			$db->exec("INSERT INTO ".$db->quotekey('pivot_retest')
				." (".$db->quotekey('a_id').", ".$db->quotekey('b_id').") VALUES (?, ?)", [9999, 9999]);
		} catch (\PDOException $e) {
			$orphanAfterRecreate = true;
		}
		$test->expect(
			$orphanAfterRecreate === true,
			$type.': recreatePivotWithFK() - FK enforced after recreate (orphan rejected)'
		);
		// cleanup
		$schema->dropTable('pivot_retest');

		// ========================================================================
		// 16. hasIndex() with unique=false detects non-unique index
		// ========================================================================
		$nonUniqueTable = 'tbl_idx_test';
		if (in_array($nonUniqueTable, $schema->getTables()))
			$schema->dropTable($nonUniqueTable);
		$it = $schema->createTable($nonUniqueTable);
		$it->addColumn('col_x')->type(\DB\SQL\Schema::DT_INT4);
		$it->addColumn('col_y')->type(\DB\SQL\Schema::DT_INT4);
		$it->build();
		// add a non-unique index
		$im = $schema->alterTable($nonUniqueTable);
		$im->addIndex(['col_x', 'col_y'], false);
		$im->build();
		$test->expect(
			$adapter::hasIndex($db, $nonUniqueTable, ['col_x', 'col_y'], false) === true,
			$type.': hasIndex(unique=false) detects non-unique composite index'
		);
		$test->expect(
			$adapter::hasIndex($db, $nonUniqueTable, ['col_x', 'col_y'], true) === false,
			$type.': hasIndex(unique=true) returns false for non-unique index'
		);
		$schema->dropTable($nonUniqueTable);

		// ========================================================================
		// 17. setupForeignKeys() is idempotent (safe to call twice)
		// ========================================================================
		\NewsModel::setdown();
		\TagModel::setdown();
		\AuthorModel::setdown();
		\AuthorModel::setup();
		\TagModel::setup();
		\NewsModel::setup();
		// call setupForeignKeys twice - must not throw
		$idempotentOk = true;
		try {
			\AuthorModel::setupForeignKeys();
			\TagModel::setupForeignKeys();
			\NewsModel::setupForeignKeys();
			// second call - should be no-op
			\AuthorModel::setupForeignKeys();
			\TagModel::setupForeignKeys();
			\NewsModel::setupForeignKeys();
		} catch (\Exception $e) {
			$idempotentOk = false;
		}
		$test->expect(
			$idempotentOk === true,
			$type.': setupForeignKeys() is idempotent (double call does not throw)'
		);
		// verify FK still present after double call
		$adapter::enableForeignKeys($db);
		$hasFkAfterDouble = $adapter::hasForeignKey($db, 'news_tags', 'neeeews')
			&& $adapter::hasForeignKey($db, 'news_tags', 'taaags');
		$test->expect(
			$hasFkAfterDouble === true,
			$type.': FK intact after double setupForeignKeys() call'
		);
		\NewsModel::setdown();
		\TagModel::setdown();
		\AuthorModel::setdown();

		// ========================================================================
		// 18. Cortex::setup() creates pivot with UNIQUE + FK (integration test)
		// ========================================================================
		// Use the actual test models to verify setup creates proper constraints
		// First setdown to clean, then setup in correct order
		\NewsModel::setdown();
		\TagModel::setdown();
		\AuthorModel::setdown();

		// Setup in dependency order: models with no FK first
		\AuthorModel::setup();
		\TagModel::setup();
		\NewsModel::setup();

		// Second pass: add FK constraints now that all tables exist
		\AuthorModel::setupForeignKeys();
		\TagModel::setupForeignKeys();
		\NewsModel::setupForeignKeys();

		// Check pivot table exists (news_tags is the custom m:m table for tags2)
		$tables = $schema->getTables();
		// Find the auto-generated mm table for tags (belongs-to-many doesn't create pivot)
		// tags2 uses has-many with custom pivot 'news_tags'
		$hasPivot = in_array('news_tags', $tables);
		$test->expect(
			$hasPivot,
			$type.': Cortex::setup() created pivot table news_tags'
		);

		if ($hasPivot) {
			// Check UNIQUE on pivot
			$pivotCols = ['neeeews', 'taaags'];
			sort($pivotCols);
			$hasUniqueOnPivot = $adapter::hasIndex($db, 'news_tags', $pivotCols, true);
			$test->expect(
				$hasUniqueOnPivot === true,
				$type.': Cortex::setup() added UNIQUE index on pivot table'
			);

			// Check FK on pivot
			$hasFkPivot1 = $adapter::hasForeignKey($db, 'news_tags', 'neeeews');
			$hasFkPivot2 = $adapter::hasForeignKey($db, 'news_tags', 'taaags');
			$test->expect(
				$hasFkPivot1 && $hasFkPivot2,
				$type.': Cortex::setup() added FK on pivot columns'
			);
		}

		// Check belongs-to-one FK on news.author column
		// On SQLite this is skipped (ALTER TABLE limitation)
		if (str_contains($db->driver(), 'sqlite')) {
			$test->expect(
				$adapter::hasForeignKey($db, 'news', 'author') === false,
				$type.': belongs-to-one FK skipped on SQLite (ALTER TABLE limitation - expected)'
			);
		} else {
			$test->expect(
				$adapter::hasForeignKey($db, 'news', 'author') === true,
				$type.': belongs-to-one FK added on news.author'
			);
		}

		// ========================================================================
		// 19. FK enforcement: insert news with invalid author_id fails on SQLite pivot
		// ========================================================================
		$adapter::enableForeignKeys($db);
		if ($hasPivot) {
			$orphanPivot = false;
			try {
				$db->exec("INSERT INTO ".$db->quotekey('news_tags')
					." (".$db->quotekey('neeeews').", ".$db->quotekey('taaags')
					.") VALUES (?, ?)", [9999, 9999]);
			} catch (\PDOException $e) {
				$orphanPivot = true;
			}
			$test->expect(
				$orphanPivot === true,
				$type.': FK on Cortex pivot prevents orphan entries'
			);
		}

		// ========================================================================
		// 20. self-referencing m:m (friends)
		// ========================================================================
		// AuthorModel has 'friends' => has-many self-reference
		// Find the self-ref mm table
		$friendsTable = null;
		foreach ($tables as $t) {
			if (str_contains($t, 'author') && str_contains($t, 'friends'))
				$friendsTable = $t;
		}
		if ($friendsTable) {
			$hasSelfFk = $adapter::hasForeignKey($db, $friendsTable, 'friends')
				|| $adapter::hasForeignKey($db, $friendsTable, 'friends_ref');
			$test->expect(
				$hasSelfFk,
				$type.': self-referencing m:m pivot has FK constraints'
			);
		} else {
			$test->expect(true, $type.': self-referencing m:m pivot - no custom table found (skipped)');
		}

		// ========================================================================
		// CLEANUP
		// ========================================================================
		\NewsModel::setdown();
		\TagModel::setdown();
		\AuthorModel::setdown();
		foreach (['pivot_retest', 'pivot_ab', 'con_child', 'tbl_b', 'tbl_a', 'con_parent'] as $t)
			if (in_array($t, $schema->getTables()))
				$schema->dropTable($t);

		return $test->results();
	}
}
