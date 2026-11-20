<?php

/**
 * Column-level foreign keys in the schema builder:
 * Column::references() / Column::onDelete().
 *
 * Covers CREATE TABLE output per driver, real constraint enforcement
 * (orphan insert fails, CASCADE / RESTRICT / SET NULL on delete),
 * the ALTER TABLE path, and every validation error.
 */
class Test_Column_FK {

	function run($db, $type) {
		$test = new \Test();
		$schema = new \DB\Cortex\Schema\Schema($db);
		$driver = $db->driver();
		$isSqlite = str_contains($driver, 'sqlite');
		$q = fn($k) => $db->quotekey($k);
		$INT = \DB\Cortex\Schema\Schema::DT_INT4;
		$VARCHAR = \DB\Cortex\Schema\Schema::DT_VARCHAR128;

		$tables = ['fkc_cascade', 'fkc_restrict', 'fkc_setnull', 'fkc_alter',
			'fkc_never', 'fkc_parent'];
		$cleanup = function() use ($schema, $tables) {
			foreach ($tables as $t)
				if (in_array($t, $schema->getTables()))
					$schema->dropTable($t);
		};
		$cleanup();
		\DB\Cortex\Schema\Schema::enableForeignKeys($db);

		// expects $fn to throw $class; returns the message or null
		$throws = function(callable $fn, string $class) {
			try { $fn(); } catch (\Throwable $e) {
				return $e instanceof $class ? $e->getMessage() : null;
			}
			return null;
		};
		$fails = function(callable $fn) {
			try { $fn(); } catch (\Throwable $e) { return true; }
			return false;
		};

		$parent = $schema->createTable('fkc_parent');
		$parent->addColumn('name')->type($VARCHAR);
		$parent->build();

		// ================================================================
		// 1. CREATE TABLE output
		// ================================================================
		$t = $schema->createTable('fkc_cascade');
		$t->addColumn('author_id')->type($INT)->nullable(false)
			->references('fkc_parent')->onDelete('cascade');
		$t->addColumn('title')->type($VARCHAR);
		$sql = $t->build(false)[0];
		$fk = 'FOREIGN KEY('.$q('author_id').') REFERENCES '.$q('fkc_parent').'('.$q('id').') ON DELETE CASCADE';
		$test->expect(
			str_contains($sql, $fk),
			$type.': createTable emits inline FK with default ref column id and normalized CASCADE'
		);
		$test->expect(
			strpos($sql, $fk) > strpos($sql, $q('title')),
			$type.': FK clause comes after all column definitions'
		);
		$expected = [
			'sqlite' => 'CREATE TABLE `fkc_cascade` (`id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, `author_id` integer(11) NOT NULL, `title` varchar(128) NULL, FOREIGN KEY(`author_id`) REFERENCES `fkc_parent`(`id`) ON DELETE CASCADE);',
			'mysql' => 'CREATE TABLE `fkc_cascade` (`id` INTEGER NOT NULL PRIMARY KEY AUTO_INCREMENT, `author_id` int(11) NOT NULL, `title` varchar(128) NULL, FOREIGN KEY(`author_id`) REFERENCES `fkc_parent`(`id`) ON DELETE CASCADE) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;',
			'pgsql' => 'CREATE TABLE "fkc_cascade" ("id" SERIAL PRIMARY KEY, "author_id" integer NOT NULL, "title" character varying(128) NULL, FOREIGN KEY("author_id") REFERENCES "fkc_parent"("id") ON DELETE CASCADE);',
		];
		foreach ($expected as $drv => $exp)
			if (str_contains($driver, $drv))
				$test->expect($sql === $exp, $type.': exact CREATE TABLE for '.$drv.' ('.$sql.')');

		$t = $schema->createTable('fkc_custom');
		$t->addColumn('parent_code')->type($INT)->references('fkc_parent', 'code');
		$test->expect(
			str_contains($t->build(false)[0], 'REFERENCES '.$q('fkc_parent').'('.$q('code').') ON DELETE RESTRICT'),
			$type.': custom ref column is used and ON DELETE defaults to RESTRICT'
		);

		$t = $schema->createTable('fkc_plain');
		$t->addColumn('x')->type($INT);
		$test->expect(
			!str_contains($t->build(false)[0], 'FOREIGN KEY'),
			$type.': columns without references() emit no FK'
		);

		// addColumn(Column) copies the FK through the validated setters
		$src = $schema->createTable('fkc_src')->addColumn('author_id')->type($INT)
			->references('fkc_parent')->onDelete('SET NULL');
		$t = $schema->createTable('fkc_copy');
		$t->addColumn($src);
		$test->expect(
			str_contains($t->build(false)[0], 'REFERENCES '.$q('fkc_parent').'('.$q('id').') ON DELETE SET NULL'),
			$type.': addColumn(Column) keeps references() and onDelete()'
		);

		// ================================================================
		// 2. Real enforcement: orphan insert fails, CASCADE deletes children
		// ================================================================
		$t = $schema->createTable('fkc_cascade');
		$t->addColumn('author_id')->type($INT)->nullable(false)
			->references('fkc_parent')->onDelete('CASCADE');
		$t->build();
		$test->expect(
			$schema->hasForeignKey('fkc_cascade', 'author_id'),
			$type.': built table has the FK constraint'
		);
		$db->exec('INSERT INTO '.$q('fkc_parent').' ('.$q('name').') VALUES (?)', ['p1']);
		$pid = (int)$db->exec('SELECT MAX('.$q('id').') AS m FROM '.$q('fkc_parent'))[0]['m'];
		$db->exec('INSERT INTO '.$q('fkc_cascade').' ('.$q('author_id').') VALUES (?)', [$pid]);
		$test->expect(
			$fails(fn() => $db->exec('INSERT INTO '.$q('fkc_cascade').' ('.$q('author_id').') VALUES (?)', [99999])),
			$type.': inserting an orphan row fails'
		);
		$db->exec('DELETE FROM '.$q('fkc_parent').' WHERE '.$q('id').'=?', [$pid]);
		$test->expect(
			(int)$db->exec('SELECT COUNT(*) AS c FROM '.$q('fkc_cascade'))[0]['c'] === 0,
			$type.': ON DELETE CASCADE removes child rows'
		);

		// default RESTRICT blocks deleting a referenced parent
		$t = $schema->createTable('fkc_restrict');
		$t->addColumn('author_id')->type($INT)->nullable(false)->references('fkc_parent');
		$t->build();
		$db->exec('INSERT INTO '.$q('fkc_parent').' ('.$q('name').') VALUES (?)', ['p2']);
		$pid = (int)$db->exec('SELECT MAX('.$q('id').') AS m FROM '.$q('fkc_parent'))[0]['m'];
		$db->exec('INSERT INTO '.$q('fkc_restrict').' ('.$q('author_id').') VALUES (?)', [$pid]);
		$test->expect(
			$fails(fn() => $db->exec('DELETE FROM '.$q('fkc_parent').' WHERE '.$q('id').'=?', [$pid])),
			$type.': default RESTRICT blocks deleting a referenced parent'
		);
		$db->exec('DELETE FROM '.$q('fkc_restrict'));

		// SET NULL on a nullable column
		$t = $schema->createTable('fkc_setnull');
		$t->addColumn('author_id')->type($INT)->references('fkc_parent')->onDelete(' set   null ');
		$t->build();
		$db->exec('INSERT INTO '.$q('fkc_setnull').' ('.$q('author_id').') VALUES (?)', [$pid]);
		$db->exec('DELETE FROM '.$q('fkc_parent').' WHERE '.$q('id').'=?', [$pid]);
		$row = $db->exec('SELECT '.$q('author_id').' AS a FROM '.$q('fkc_setnull'));
		$test->expect(
			count($row) === 1 && $row[0]['a'] === null,
			$type.': ON DELETE SET NULL nulls the child column'
		);

		// ================================================================
		// 3. ALTER TABLE path (existing table)
		// ================================================================
		$t = $schema->createTable('fkc_alter');
		$t->addColumn('title')->type($VARCHAR);
		$t->build();
		$alter = $schema->alterTable('fkc_alter');
		$alter->addColumn('author_id')->type($INT)->references('fkc_parent')->onDelete('cascade');
		if ($isSqlite) {
			$msg = $throws(fn() => $alter->build(), \LogicException::class);
			$test->expect(
				$msg !== null && str_contains($msg, 'SQLite'),
				$type.': alterTable + references() throws on SQLite'
			);
			$test->expect(
				!array_key_exists('author_id', $schema->alterTable('fkc_alter')->getCols()),
				$type.': SQLite throw happens before the column is added'
			);
		} else {
			$queries = (array)$alter->build(false);
			$test->expect(
				count(array_filter($queries, fn($s) => str_contains($s, 'ADD CONSTRAINT '.$q('fk_fkc_alter_author_id')))) === 1,
				$type.': alterTable emits ADD CONSTRAINT fk_<table>_<column>'
			);
			// build(false) keeps its queued queries, so execute from a fresh modifier
			$alter = $schema->alterTable('fkc_alter');
			$alter->addColumn('author_id')->type($INT)->references('fkc_parent')->onDelete('cascade');
			$alter->build();
			$test->expect(
				$schema->hasForeignKey('fkc_alter', 'author_id'),
				$type.': alterTable adds the column and the FK'
			);
			$test->expect(
				$fails(fn() => $db->exec('INSERT INTO '.$q('fkc_alter').' ('.$q('author_id').') VALUES (?)', [99999])),
				$type.': altered table rejects orphan rows'
			);
		}

		// ================================================================
		// 4. Validation errors
		// ================================================================
		$col = fn() => $schema->createTable('fkc_never')->addColumn('author_id')->type($INT);
		$test->expect(
			$throws(fn() => $col()->onDelete('CASCADE'), \LogicException::class) !== null,
			$type.': onDelete() without references() throws'
		);
		foreach (['DROP', 'SET DEFAULT', '', 'CASCADE; DROP TABLE x'] as $bad)
			$test->expect(
				$throws(fn() => $col()->references('fkc_parent')->onDelete($bad), \InvalidArgumentException::class) !== null,
				$type.': onDelete("'.$bad.'") throws'
			);
		foreach ([['', 'id'], ['   ', 'id'], ['fkc_parent', ''], ['fkc_parent', ' ']] as [$rt, $rc])
			$test->expect(
				$throws(fn() => $col()->references($rt, $rc), \InvalidArgumentException::class) !== null,
				$type.': references("'.$rt.'", "'.$rc.'") throws'
			);
		$test->expect(
			$col()->references('fkc_parent')->onDelete('no action')->onDelete === 'NO ACTION'
			&& $col()->references('fkc_parent')->onDelete === 'RESTRICT',
			$type.': onDelete normalizes to uppercase, default RESTRICT'
		);

		// SET NULL on NOT NULL, in either call order, throws before any SQL runs
		$t = $schema->createTable('fkc_never');
		$t->addColumn('author_id')->type($INT)->nullable(false)->references('fkc_parent')->onDelete('SET NULL');
		$test->expect(
			$throws(fn() => $t->build(), \LogicException::class) !== null,
			$type.': SET NULL on a NOT NULL column throws'
		);
		$t = $schema->createTable('fkc_never');
		$t->addColumn('author_id')->type($INT)->references('fkc_parent')->onDelete('SET NULL')->nullable(false);
		$test->expect(
			$throws(fn() => $t->build(), \LogicException::class) !== null,
			$type.': SET NULL then nullable(false) also throws'
		);
		$test->expect(
			!in_array('fkc_never', $schema->getTables()),
			$type.': failed validation creates no table'
		);
		if (!$isSqlite) {
			$alter = $schema->alterTable('fkc_alter');
			$alter->addColumn('editor_id')->type($INT)->nullable(false)->defaults(0)
				->references('fkc_parent')->onDelete('SET NULL');
			$test->expect(
				$throws(fn() => $alter->build(), \LogicException::class) !== null
				&& !array_key_exists('editor_id', $schema->alterTable('fkc_alter')->getCols()),
				$type.': SET NULL on NOT NULL throws on alterTable before altering'
			);
		}

		// ================================================================
		// 5. SQL Server output (generated only, no live server needed)
		// ================================================================
		if (str_contains($driver, 'sqlite')) {
			$fake = (new \ReflectionClass(\DB\SQL::class))->newInstanceWithoutConstructor();
			$prop = new \ReflectionProperty(\DB\SQL::class, 'engine');
			$prop->setValue($fake, 'sqlsrv');
			$ms = new \DB\Cortex\Schema\Schema($fake);
			$t = $ms->createTable('posts');
			$t->addColumn('author_id')->type($INT)->nullable(false)->references('users');
			$t->addColumn('editor_id')->type($INT)->references('users')->onDelete('SET NULL');
			$sql = $t->build(false)[0];
			$test->expect(
				$sql === 'CREATE TABLE [posts] ([id] INT IDENTITY CONSTRAINT PK_posts_ID PRIMARY KEY, [author_id] int NOT NULL, [editor_id] int NULL, '.
					'FOREIGN KEY([author_id]) REFERENCES [users]([id]) ON DELETE NO ACTION, '.
					'FOREIGN KEY([editor_id]) REFERENCES [users]([id]) ON DELETE SET NULL);',
				$type.': sqlsrv CREATE TABLE maps RESTRICT to NO ACTION ('.$sql.')'
			);
		}

		$cleanup();
		return $test->results();
	}
}
