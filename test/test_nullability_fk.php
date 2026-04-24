<?php

/**
 * Test suite for belongs-to-one FK nullability and ON DELETE behavior.
 *
 * Covers the core fix: relation nullability and FK delete behavior
 * must be consistent. nullable => false must use RESTRICT (not SET NULL),
 * nullable => true uses SET NULL, and explicit onDelete overrides both.
 */
class Test_Nullability_FK {

	function run($db, $type) {
		$test = new \Test();
		$schema = new \DB\Cortex\Schema\Schema($db);
		$isSqlite = str_contains($db->driver(), 'sqlite');

		// ====================================================================
		// SETUP: clean slate
		// ====================================================================
		foreach ([
			'CascadeNewsModel', 'StrictNewsModel',
			'NewsModel', 'TagModel', 'ProfileModel', 'AuthorModel'
		] as $m) {
			if (class_exists($m)) $m::setdown();
		}

		\AuthorModel::setup();
		\ProfileModel::setup();
		\TagModel::setup();
		\NewsModel::setup();
		\StrictNewsModel::setup();
		\CascadeNewsModel::setup();

		// ====================================================================
		// 1. resolveForeignKeyOnDelete() unit tests via reflection
		// ====================================================================
		$method = new \ReflectionMethod(\AuthorModel::class, 'resolveForeignKeyOnDelete');

		// 1a. nullable=true -> SET NULL
		$test->expect(
			$method->invoke(null, ['nullable' => true]) === 'SET NULL',
			$type.': resolveForeignKeyOnDelete: nullable=true -> SET NULL'
		);

		// 1b. nullable=false -> RESTRICT
		$test->expect(
			$method->invoke(null, ['nullable' => false]) === 'RESTRICT',
			$type.': resolveForeignKeyOnDelete: nullable=false -> RESTRICT'
		);

		// 1c. nullable absent -> RESTRICT (empty() on unset is true)
		$test->expect(
			$method->invoke(null, []) === 'RESTRICT',
			$type.': resolveForeignKeyOnDelete: nullable absent -> RESTRICT'
		);

		// 1d. explicit onDelete overrides nullable
		$test->expect(
			$method->invoke(null, ['nullable' => true, 'onDelete' => 'CASCADE']) === 'CASCADE',
			$type.': resolveForeignKeyOnDelete: explicit onDelete=CASCADE overrides nullable=true'
		);

		// 1e. onDelete uppercases the value
		$test->expect(
			$method->invoke(null, ['nullable' => true, 'onDelete' => 'restrict']) === 'RESTRICT',
			$type.': resolveForeignKeyOnDelete: onDelete value is uppercased'
		);

		// ====================================================================
		// 2. Column nullability verification at DDL level
		// ====================================================================

		// 2a. NewsModel.author (nullable default=true) -> column should be NULL
		$newsAuthorNullable = $this->isColumnNullable($db, 'news', 'author');
		$test->expect(
			$newsAuthorNullable === true,
			$type.': NewsModel.author (nullable default) creates NULL column'
		);

		// 2b. StrictNewsModel.author (nullable=false) -> column should be NOT NULL
		$strictAuthorNullable = $this->isColumnNullable($db, 'strict_news', 'author');
		$test->expect(
			$strictAuthorNullable === false,
			$type.': StrictNewsModel.author (nullable=false) creates NOT NULL column'
		);

		// 2c. CascadeNewsModel.author (nullable=true, onDelete=CASCADE) -> column should be NULL
		$cascadeAuthorNullable = $this->isColumnNullable($db, 'cascade_news', 'author');
		$test->expect(
			$cascadeAuthorNullable === true,
			$type.': CascadeNewsModel.author (nullable=true, onDelete=CASCADE) creates NULL column'
		);

		// 2d. ProfileModel.author (nullable default=true) -> column should be NULL
		$profileAuthorNullable = $this->isColumnNullable($db, 'profile', 'author');
		$test->expect(
			$profileAuthorNullable === true,
			$type.': ProfileModel.author (nullable default) creates NULL column'
		);

		// ====================================================================
		// 3. FK ON DELETE behavior (non-SQLite only for ALTER TABLE FK)
		// ====================================================================
		if (!$isSqlite) {
			// second pass to add FKs
			\AuthorModel::setupForeignKeys();
			\ProfileModel::setupForeignKeys();
			\TagModel::setupForeignKeys();
			\NewsModel::setupForeignKeys();
			\StrictNewsModel::setupForeignKeys();
			\CascadeNewsModel::setupForeignKeys();

			// 3a. Verify FK exists on news.author
			$test->expect(
				$schema->hasForeignKey('news', 'author'),
				$type.': FK exists on news.author (nullable default -> SET NULL)'
			);

			// 3b. Verify FK exists on strict_news.author
			$test->expect(
				$schema->hasForeignKey('strict_news', 'author'),
				$type.': FK exists on strict_news.author (nullable=false -> RESTRICT)'
			);

			// 3c. Verify FK exists on cascade_news.author
			$test->expect(
				$schema->hasForeignKey('cascade_news', 'author'),
				$type.': FK exists on cascade_news.author (onDelete=CASCADE)'
			);

			// 3d. Test SET NULL behavior: delete author -> news.author becomes NULL
			$a1 = new \AuthorModel();
			$a1->name = 'SetNullAuthor';
			$a1->save();

			$n1 = new \NewsModel();
			$n1->title = 'SetNull Test News';
			$n1->author = $a1->id;
			$n1->save();
			$n1id = $n1->id;

			$a1->erase();

			$n1check = new \NewsModel();
			$n1check->load(['id = ?', $n1id]);
			$test->expect(
				!$n1check->dry() && $n1check->getRaw('author') === null,
				$type.': SET NULL FK: deleting author nullifies news.author'
			);

			// 3e. Test RESTRICT behavior: delete author blocked when strict_news exists
			$a2 = new \AuthorModel();
			$a2->name = 'RestrictAuthor';
			$a2->save();

			$sn = new \StrictNewsModel();
			$sn->title = 'Restrict Test';
			$sn->author = $a2->id;
			$sn->save();

			$restrictBlocked = false;
			try {
				$a2->erase();
			} catch (\PDOException $e) {
				$restrictBlocked = true;
			}
			$test->expect(
				$restrictBlocked,
				$type.': RESTRICT FK: deleting author blocked when strict_news references it'
			);

			// 3f. Test CASCADE behavior: delete author -> cascade_news row deleted
			$a3 = new \AuthorModel();
			$a3->name = 'CascadeAuthor';
			$a3->save();

			$cn = new \CascadeNewsModel();
			$cn->title = 'Cascade Test';
			$cn->author = $a3->id;
			$cn->save();
			$cnid = $cn->id;

			$a3->erase();

			$cncheck = new \CascadeNewsModel();
			$cncheck->load(['id = ?', $cnid]);
			$test->expect(
				$cncheck->dry(),
				$type.': CASCADE FK: deleting author cascades to delete cascade_news row'
			);
		} else {
			$test->expect(true,
				$type.': FK behavior tests skipped on SQLite (ALTER TABLE limitation)'
			);
		}

		// ====================================================================
		// 4. setupForeignKeys() respects nullability consistently
		// ====================================================================
		// Tear down and set up again to test second-pass FK addition
		foreach ([
			'CascadeNewsModel', 'StrictNewsModel',
			'NewsModel', 'TagModel', 'ProfileModel', 'AuthorModel'
		] as $m) $m::setdown();

		\AuthorModel::setup();
		\StrictNewsModel::setup();
		\CascadeNewsModel::setup();
		\NewsModel::setup();

		if (!$isSqlite) {
			// Call setupForeignKeys (second pass) for all
			\AuthorModel::setupForeignKeys();
			\StrictNewsModel::setupForeignKeys();
			\CascadeNewsModel::setupForeignKeys();
			\NewsModel::setupForeignKeys();

			$test->expect(
				$schema->hasForeignKey('strict_news', 'author'),
				$type.': setupForeignKeys() adds FK for nullable=false field'
			);

			$test->expect(
				$schema->hasForeignKey('cascade_news', 'author'),
				$type.': setupForeignKeys() adds FK for onDelete=CASCADE field'
			);

			$test->expect(
				$schema->hasForeignKey('news', 'author'),
				$type.': setupForeignKeys() adds FK for default nullable field'
			);

			// 4b. setupForeignKeys idempotent
			$idempotentOk = true;
			try {
				\StrictNewsModel::setupForeignKeys();
				\CascadeNewsModel::setupForeignKeys();
				\NewsModel::setupForeignKeys();
			} catch (\Exception $e) {
				$idempotentOk = false;
			}
			$test->expect(
				$idempotentOk,
				$type.': setupForeignKeys() idempotent for all nullability variants'
			);
		} else {
			$test->expect(true,
				$type.': setupForeignKeys() FK tests skipped on SQLite'
			);
		}

		// ====================================================================
		// 5. ORM-level nullable=false validation on save
		// ====================================================================
		// 5a. Saving StrictNewsModel without author should fail at DB level
		$nullSaveRejected = false;
		try {
			$sn2 = new \StrictNewsModel();
			$sn2->title = 'Missing Author';
			$sn2->save();
		} catch (\PDOException $e) {
			$nullSaveRejected = true;
		}
		$test->expect(
			$nullSaveRejected,
			$type.': saving StrictNewsModel without required author throws PDOException'
		);

		// 5b. Saving StrictNewsModel WITH author succeeds
		$a4 = new \AuthorModel();
		$a4->name = 'ValidAuthor';
		$a4->save();

		$sn3 = new \StrictNewsModel();
		$sn3->title = 'Valid Strict News';
		$sn3->author = $a4->id;
		$sn3->save();
		$test->expect(
			!$sn3->dry() && $sn3->id > 0,
			$type.': StrictNewsModel with valid author saves successfully'
		);

		// 5c. nullable=true relation can be saved as NULL
		$n2 = new \NewsModel();
		$n2->title = 'Orphan OK';
		$n2->save();
		$test->expect(
			!$n2->dry() && $n2->id > 0,
			$type.': NewsModel (nullable default) saves without author (NULL FK)'
		);

		// ====================================================================
		// 6. resolveRelationConf preserves nullable=false
		// ====================================================================
		$resolveMethod = new \ReflectionMethod(\AuthorModel::class, 'resolveRelationConf');

		$resolved = $resolveMethod->invoke(null, [
			'belongs-to-one' => '\AuthorModel',
			'nullable' => false,
		]);
		$test->expect(
			$resolved['nullable'] === false,
			$type.': resolveRelationConf preserves nullable=false for belongs-to-one'
		);

		// 6b. resolveRelationConf defaults nullable to true when absent
		$resolved2 = $resolveMethod->invoke(null, [
			'belongs-to-one' => '\AuthorModel',
		]);
		$test->expect(
			$resolved2['nullable'] === true,
			$type.': resolveRelationConf defaults nullable to true for belongs-to-one'
		);

		// ====================================================================
		// 7. belongs-to-many respects explicit nullable and defaults to true
		// ====================================================================
		$btmResolved = $resolveMethod->invoke(null, [
			'belongs-to-many' => '\TagModel',
			'nullable' => false,
		]);
		$test->expect(
			$btmResolved['nullable'] === false,
			$type.': belongs-to-many respects explicit nullable=false'
		);

		$btmDefault = $resolveMethod->invoke(null, [
			'belongs-to-many' => '\TagModel',
		]);
		$test->expect(
			$btmDefault['nullable'] === true,
			$type.': belongs-to-many defaults nullable to true when absent'
		);

		// ====================================================================
		// 8. Explicit onDelete variants preserved through resolveRelationConf
		// ====================================================================
		$withOnDelete = $resolveMethod->invoke(null, [
			'belongs-to-one' => '\AuthorModel',
			'onDelete' => 'CASCADE',
		]);
		$test->expect(
			isset($withOnDelete['onDelete']) && $withOnDelete['onDelete'] === 'CASCADE',
			$type.': resolveRelationConf preserves onDelete key'
		);

		// ====================================================================
		// 9. ORM-level nullable validation in set() works for SQL
		// ====================================================================
		$a5 = new \AuthorModel();
		$a5->name = 'SetTestAuthor';
		$a5->save();

		$ormRejectNull = false;
		try {
			$sn4 = new \StrictNewsModel();
			$sn4->title = 'Null Set Test';
			$sn4->author = null;
		} catch (\Exception $e) {
			$ormRejectNull = true;
		}
		$test->expect(
			$ormRejectNull,
			$type.': set() on nullable=false belongs-to-one rejects NULL with ORM exception'
		);

		// 9b. set() on nullable=false also catches empty values (coerced to NULL)
		$ormRejectEmpty = false;
		try {
			$sn5 = new \StrictNewsModel();
			$sn5->title = 'Empty Set Test';
			$sn5->author = 0;
		} catch (\Exception $e) {
			$ormRejectEmpty = true;
		}
		$test->expect(
			$ormRejectEmpty,
			$type.': set() on nullable=false belongs-to-one rejects empty (0) with ORM exception'
		);

		// 9c. set() on nullable=true allows NULL
		$ormAllowNull = true;
		try {
			$n3 = new \NewsModel();
			$n3->title = 'Null Allowed';
			$n3->author = null;
		} catch (\Exception $e) {
			$ormAllowNull = false;
		}
		$test->expect(
			$ormAllowNull,
			$type.': set() on nullable=true (default) allows NULL'
		);

		// ====================================================================
		// 10. ConstraintAdapter default behavior
		// ====================================================================
		$adapter = \DB\Cortex\ConstraintAdapter::class;
		$adapterRef = new \ReflectionMethod($adapter, 'addBelongsToForeignKey');
		$params = $adapterRef->getParameters();
		$onDeleteParam = null;
		foreach ($params as $p)
			if ($p->getName() === 'onDelete')
				$onDeleteParam = $p;
		$test->expect(
			$onDeleteParam && $onDeleteParam->getDefaultValue() === 'RESTRICT',
			$type.': ConstraintAdapter::addBelongsToForeignKey() defaults to RESTRICT (not SET NULL)'
		);

		// ====================================================================
		// CLEANUP
		// ====================================================================
		foreach ([
			'CascadeNewsModel', 'StrictNewsModel',
			'NewsModel', 'TagModel', 'ProfileModel', 'AuthorModel'
		] as $m) {
			if (class_exists($m)) $m::setdown();
		}

		return $test->results();
	}

	/**
	 * Check if a column is nullable in the database.
	 * Returns true if nullable, false if NOT NULL, null if column not found.
	 */
	private function isColumnNullable($db, string $table, string $column): ?bool {
		$driver = $db->driver();
		if (str_contains($driver, 'sqlite')) {
			$cols = $db->exec("PRAGMA table_info(".$db->quotekey($table).")");
			foreach ($cols as $col)
				if ($col['name'] === $column)
					return (int)$col['notnull'] === 0;
		} elseif (str_contains($driver, 'mysql')) {
			$cols = $db->exec("SHOW COLUMNS FROM ".$db->quotekey($table)." LIKE ?", [$column]);
			if (!empty($cols))
				return $cols[0]['Null'] === 'YES';
		} elseif (str_contains($driver, 'pgsql')) {
			$cols = $db->exec(
				"SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?",
				[$table, $column]
			);
			if (!empty($cols))
				return $cols[0]['is_nullable'] === 'YES';
		}
		return null;
	}
}
