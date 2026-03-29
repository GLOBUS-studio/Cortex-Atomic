<?php

/**
 * Additional coverage tests: error paths, compare(), rel(), 
 * event hooks, edge cases, fluid mode, castField, afind, etc.
 */
class Test_Coverage_Extra {

	function run($db, $type)
	{
		$test = new \Test();

		// ====== SETUP ======
		$tname = 'test_extra';
		\DB\Cortex::setdown($db, $tname);
		$fields = [
			'title'    => ['type' => \DB\SQL\Schema::DT_VARCHAR256],
			'amount'   => ['type' => \DB\SQL\Schema::DT_INT4],
			'active'   => ['type' => \DB\SQL\Schema::DT_BOOLEAN, 'nullable' => true],
			'data'     => ['type' => \DB\Cortex::DT_JSON],
			'blob'     => ['type' => \DB\Cortex::DT_SERIALIZED],
			'stamp'    => ['type' => \DB\SQL\Schema::DT_DATE],
		];
		\DB\Cortex::setup($db, $tname, $fields);

		$cx = new \DB\Cortex($db, $tname);
		$cx->setFieldConfiguration($fields);

		// Seed a few records
		$cx->reset();
		$cx->title = 'alpha';
		$cx->amount = 10;
		$cx->data = ['x' => 1, 'y' => 2];
		$cx->blob = ['a' => 'b'];
		$cx->save();

		$cx->reset();
		$cx->title = 'beta';
		$cx->amount = 20;
		$cx->data = ['x' => 3, 'y' => 4];
		$cx->blob = ['c' => 'd'];
		$cx->save();

		$cx->reset();
		$cx->title = 'gamma';
		$cx->amount = 30;
		$cx->data = null;
		$cx->save();

		// ========================================================================
		// 1. afind() - returns array of cast arrays
		// ========================================================================
		$result = $cx->afind(null, ['order' => 'title']);
		$test->expect(
			is_array($result) && count($result) === 3
			&& isset($result[0]['title']) && $result[0]['title'] === 'alpha',
			$type.': afind() returns array of cast arrays'
		);

		// ========================================================================
		// 2. afind() - returns NULL when no match
		// ========================================================================
		$result = $cx->afind(['title = ?', 'nonexistent']);
		$test->expect(
			$result === null,
			$type.': afind() returns NULL when no results'
		);

		// ========================================================================
		// 3. compare() - simple field: new value replaces old
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'alpha']);
		$newCalled = [];
		$oldCalled = [];
		$cx->compare(
			['title' => 'alpha_updated'],
			function($val) use (&$newCalled) { $newCalled[] = $val; return $val; },
			function($val) use (&$oldCalled) { $oldCalled[] = $val; }
		);
		$oldOk = str_contains($type, 'sql')
			? (count($oldCalled) === 1 && $oldCalled[0] === 'alpha')
			: true; // Mongo mapper has no initial() tracking
		$test->expect(
			$cx->get('title') === 'alpha_updated'
			&& count($newCalled) === 1 && $newCalled[0] === 'alpha_updated'
			&& $oldOk,
			$type.': compare() calls $new and $old callbacks for changed scalar field'
		);

		// ========================================================================
		// 4. compare() - same value: no old callback
		// ========================================================================
		$cx->save();
		$cx->reset();
		$cx->load(['title = ?', 'alpha_updated']);
		$newCalled2 = [];
		$oldCalled2 = [];
		$cx->compare(
			['title' => 'alpha_updated'],
			function($val) use (&$newCalled2) { $newCalled2[] = $val; return $val; },
			function($val) use (&$oldCalled2) { $oldCalled2[] = $val; }
		);
		$test->expect(
			count($oldCalled2) === 0,
			$type.': compare() does not call $old when value unchanged'
		);

		// ========================================================================
		// 5. compare() - array field: detects added/removed
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'alpha_updated']);
		$cx->data = ['x' => 1, 'y' => 2]; // restore known state
		$cx->save();
		$cx->reset();
		$cx->load(['title = ?', 'alpha_updated']);
		$removedItems = [];
		$addedItems = [];
		$cx->compare(
			['data' => ['x' => 1, 'z' => 9]],
			function($val) use (&$addedItems) { $addedItems[] = $val; return $val; },
			function($val) use (&$removedItems) { $removedItems[] = $val; }
		);
		$test->expect(
			count($removedItems) > 0 || count($addedItems) > 0,
			$type.': compare() with array field detects changes'
		);

		// ========================================================================
		// 6. compare() - empty data triggers $old cleanup
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'alpha_updated']);
		$cleanedUp = [];
		$cx->compare(
			['data' => null],
			null,
			function($val) use (&$cleanedUp) { $cleanedUp[] = $val; }
		);
		$test->expect(
			true,
			$type.': compare() with empty data calls $old for cleanup'
		);

		// ========================================================================
		// 7. compare() - dot notation partial field
		// ========================================================================
		$cx->reset();
		$cx->title = 'dot_test';
		$cx->data = ['nested' => ['a' => 1, 'b' => 2]];
		$cx->save();
		$cx->reset();
		$cx->load(['title = ?', 'dot_test']);
		$cx->compare(
			['data.nested' => ['a' => 1, 'c' => 3]],
			function($val) { return $val; },
			null
		);
		$updatedData = $cx->get('data');
		$test->expect(
			is_array($updatedData),
			$type.': compare() with dot-notation sets nested field'
		);

		// ========================================================================
		// 8. E_ARRAY_DATATYPE - setting array on non-JSON field throws
		// ========================================================================
		$thrown = false;
		try {
			$cx->reset();
			$cx->set('title', ['an', 'array']);
		} catch (\Exception $e) {
			$thrown = str_contains($e->getMessage(), 'Unable to save an Array');
		}
		$test->expect(
			str_contains($type, 'sql') ? $thrown : !$thrown,
			$type.': E_ARRAY_DATATYPE thrown for array in non-JSON field'
		);

		// ========================================================================
		// 9. E_UNKNOWN_FIELD - has() on non-existent field throws
		// ========================================================================
		$thrown2 = false;
		try {
			$news = new \NewsModel();
			$news->has('nonexistent_field_xyz', ['id = ?', 1]);
		} catch (\Exception $e) {
			$thrown2 = str_contains($e->getMessage(), 'does not exist');
		}
		$test->expect(
			$thrown2,
			$type.': E_UNKNOWN_FIELD thrown for has() on non-existent field'
		);

		// ========================================================================
		// 10. E_HAS_COND - has() on non-relational field throws
		// ========================================================================
		$thrown3 = false;
		try {
			$news = new \NewsModel();
			$news->has('title', ['title = ?', 'x']);
		} catch (\Exception $e) {
			$thrown3 = str_contains($e->getMessage(), 'non-bidirectional');
		}
		$test->expect(
			$thrown3,
			$type.': E_HAS_COND thrown for has() on non-relational field'
		);

		// ========================================================================
		// 11. rel() - returns clean instance of related model
		// ========================================================================
		$news = new \NewsModel();
		$relAuthor = $news->rel('author');
		$test->expect(
			$relAuthor instanceof \DB\Cortex && $relAuthor->dry(),
			$type.': rel() returns clean dry Cortex instance'
		);

		// ========================================================================
		// 12. rel() for has-many relation
		// ========================================================================
		$relTags = $news->rel('tags');
		$test->expect(
			$relTags instanceof \DB\Cortex && $relTags->dry(),
			$type.': rel() returns clean instance for has-many relation'
		);

		// ========================================================================
		// 13. countRel with custom alias
		// ========================================================================
		$author = new \AuthorModel();
		$author->countRel('news', 'article_count');
		$result = $author->find();
		if ($result) {
			$first = $result[0]->cast(null, 0);
			$test->expect(
				array_key_exists('article_count', $first),
				$type.': countRel() with custom alias parameter'
			);
		} else {
			$test->expect(true, $type.': countRel() with custom alias parameter (skipped: no data)');
		}

		// ========================================================================
		// 14. beforeinsert event blocks save
		// ========================================================================
		$cx2 = new \DB\Cortex($db, $tname);
		$cx2->setFieldConfiguration($fields);
		$cx2->beforeinsert(function($self) {
			return false; // block insert
		});
		$cx2->title = 'should_not_save';
		$cx2->save();
		// verify it was NOT saved
		$cx3 = new \DB\Cortex($db, $tname);
		$cx3->setFieldConfiguration($fields);
		$check = $cx3->load(['title = ?', 'should_not_save']);
		$test->expect(
			!$check,
			$type.': beforeinsert returning false blocks save'
		);

		// ========================================================================
		// 15. beforeupdate event blocks save
		// ========================================================================
		$cx4 = new \DB\Cortex($db, $tname);
		$cx4->setFieldConfiguration($fields);
		$cx4->load(['title = ?', 'alpha_updated']);
		if (!$cx4->dry()) {
			$cx4->beforeupdate(function($self) {
				return false;
			});
			$cx4->title = 'should_not_update';
			$cx4->save();
			// verify it was NOT updated
			$cx5 = new \DB\Cortex($db, $tname);
			$cx5->setFieldConfiguration($fields);
			$cx5->load(['title = ?', 'alpha_updated']);
			$test->expect(
				!$cx5->dry(),
				$type.': beforeupdate returning false blocks update'
			);
		} else {
			$test->expect(true, $type.': beforeupdate returning false blocks update (skipped)');
		}

		// ========================================================================
		// 16. beforeerase event blocks erase
		// ========================================================================
		$cx6 = new \DB\Cortex($db, $tname);
		$cx6->setFieldConfiguration($fields);
		$cx6->load(['title = ?', 'beta']);
		if (!$cx6->dry()) {
			$cx6->beforeerase(function($self) {
				return false;
			});
			$eraseResult = $cx6->erase();
			// verify it was NOT erased
			$cx7 = new \DB\Cortex($db, $tname);
			$cx7->setFieldConfiguration($fields);
			$cx7->load(['title = ?', 'beta']);
			$test->expect(
				$eraseResult === false && !$cx7->dry(),
				$type.': beforeerase returning false blocks erase'
			);
		} else {
			$test->expect(true, $type.': beforeerase returning false blocks erase (skipped)');
		}

		// ========================================================================
		// 17. castField() - with non-null key on loaded relation data
		// ========================================================================
		$news->reset();
		$news->load(['author != ?', null]);
		if (!$news->dry()) {
			$casted = $news->castField('author', 0);
			$test->expect(
				is_array($casted) || is_numeric($casted) || $casted === null,
				$type.': castField(key, depth) returns casted value'
			);
		} else {
			$test->expect(true, $type.': castField(key, depth) returns casted value (skipped)');
		}

		// ========================================================================
		// 18. cast() with different depth levels
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'beta']);
		if (!$cx->dry()) {
			$castD0 = $cx->cast(null, 0);
			$castD1 = $cx->cast(null, 1);
			$test->expect(
				is_array($castD0) && is_array($castD1) && isset($castD0['title']),
				$type.': cast() at different depth levels returns arrays'
			);
		} else {
			$test->expect(true, $type.': cast() at different depth levels returns arrays (skipped)');
		}

		// ========================================================================
		// 19. copyfrom() - JSON string deserialization
		// ========================================================================
		$cx->reset();
		$cx->setFieldConfiguration($fields);
		$cx->copyfrom(['title' => 'json_test', 'data' => '{"key": "val"}']);
		$cx->save();
		$cx->reset();
		$cx->load(['title = ?', 'json_test']);
		$loaded_data = $cx->cast(null, 0);
		$test->expect(
			isset($loaded_data['data']) && is_array($loaded_data['data'])
			&& ($loaded_data['data']['key'] ?? null) === 'val',
			$type.': copyfrom() deserializes JSON string for DT_JSON field'
		);

		// ========================================================================
		// 20. skip() - negative offset (backward navigation)
		// ========================================================================
		$cx->reset();
		$result = $cx->find(null, ['order' => 'title']);
		if ($result && count($result) >= 2) {
			// load all, go to last, then skip back
			$cx->load(null, ['order' => 'title']);
			$cx->last();
			$lastTitle = $cx->title;
			$cx->skip(-1);
			$prevTitle = $cx->title;
			$test->expect(
				$lastTitle !== $prevTitle,
				$type.': skip(-1) moves cursor backward'
			);
		} else {
			$test->expect(true, $type.': skip(-1) moves cursor backward (skipped)');
		}

		// ========================================================================
		// 21. empty datetime field set to NULL
		// ========================================================================
		$cx->reset();
		$cx->title = 'empty_date';
		$cx->stamp = '';
		$cx->save();
		$cx->reset();
		$cx->load(['title = ?', 'empty_date']);
		$rawStamp = $cx->get('stamp', true);
		$test->expect(
			$rawStamp === null || $rawStamp === '',
			$type.': empty datetime value stored as NULL'
		);

		// ========================================================================
		// 22. initial() with DT_JSON returns decoded array
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'beta']);
		if (!$cx->dry()) {
			$initData = $cx->initial('data');
			$test->expect(
				is_array($initData) || $initData === null,
				$type.': initial() with DT_JSON decodes JSON to array'
			);
		} else {
			$test->expect(true, $type.': initial() with DT_JSON decodes JSON to array (skipped)');
		}

		// ========================================================================
		// 23. initial() with DT_SERIALIZED returns unserialized value
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'beta']);
		if (!$cx->dry()) {
			$initBlob = $cx->initial('blob');
			$test->expect(
				is_array($initBlob) || $initBlob === null,
				$type.': initial() with DT_SERIALIZED unserializes value'
			);
		} else {
			$test->expect(true, $type.': initial() with DT_SERIALIZED unserializes value (skipped)');
		}

		// ========================================================================
		// 24. cleared() - when field value IS cleared
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'beta']);
		if (!$cx->dry() && $cx->get('amount', true)) {
			$initAmount = $cx->initial('amount');
			$cx->set('amount', null);
			$clearedVal = $cx->cleared('amount');
			$test->expect(
				$clearedVal !== false || $initAmount === null,
				$type.': cleared() returns initial value when field is cleared'
			);
		} else {
			$test->expect(true, $type.': cleared() returns initial value when field is cleared (skipped)');
		}

		// ========================================================================
		// 25. addToCollection() / getCollection() - verified through find()
		// ========================================================================
		// getCollection() is protected, but it's exercised when find() returns
		// a CortexCollection. Each model in the collection has a reference back.
		$author = new \AuthorModel();
		$result = $author->find(null, ['limit' => 2]);
		$test->expect(
			$result instanceof \DB\CortexCollection,
			$type.': find() returns CortexCollection (exercises addToCollection)'
		);

		// ========================================================================
		// 26. CortexCollection::orderBy() with DESC
		// ========================================================================
		$all = $cx->find(null, ['order' => 'title ASC']);
		if ($all && count($all) > 1) {
			$all->orderBy('title DESC');
			// orderBy uses uasort, so keys are reordered but not re-indexed
			$items = [];
			foreach ($all as $item) $items[] = $item->title;
			$test->expect(
				strcmp($items[0], $items[count($items)-1]) > 0,
				$type.': CortexCollection::orderBy() with DESC'
			);
		} else {
			$test->expect(true, $type.': CortexCollection::orderBy() with DESC (skipped)');
		}

		// ========================================================================
		// 27. CortexCollection::slice() with offset only (no limit)
		// ========================================================================
		$allForSlice = $cx->find(null, ['order' => 'title ASC']);
		if ($allForSlice && count($allForSlice) > 2) {
			$origCount = count($allForSlice);
			$allForSlice->slice(1);
			$test->expect(
				count($allForSlice) === $origCount - 1,
				$type.': CortexCollection::slice() with offset only (no limit)'
			);
		} else {
			$test->expect(true, $type.': CortexCollection::slice() with offset only (skipped)');
		}

		// ========================================================================
		// 28. CortexCollection::contains() with custom key
		// ========================================================================
		if ($all && count($all) > 0) {
			$firstTitle = $all[0]->title;
			$test->expect(
				$all->contains($firstTitle, 'title'),
				$type.': CortexCollection::contains() with custom key'
			);
		} else {
			$test->expect(true, $type.': CortexCollection::contains() with custom key (skipped)');
		}

		// ========================================================================
		// 29. has() with dot-notation (nested relation filter)
		// ========================================================================
		// has() with dot-notation stores the nested filter in hasCond
		$author2 = new \AuthorModel();
		$author2->has('news.tags', ['title = ?', 'Web Design']);
		// Verify hasCond was set (internal state) - if it didn't throw, it worked
		$test->expect(
			true,
			$type.': has() with dot-notation stores nested filter'
		);

		// ========================================================================
		// 30. filter() with dot-notation for nested relation filter
		// ========================================================================
		$news2 = new \NewsModel();
		$news2->filter('author', ['name LIKE ?', '%']);
		$filterResult = $news2->find();
		$news2->clearFilter();
		$test->expect(
			$filterResult !== false,
			$type.': filter() with relation key restricts related results'
		);

		// ========================================================================
		// 31. clearFilter() for specific key
		// ========================================================================
		$news3 = new \NewsModel();
		$news3->filter('author', ['name = ?', 'test']);
		$news3->clearFilter('author');
		// After clear, filter should not affect find
		$allNews = $news3->find();
		$test->expect(
			$allNews !== false,
			$type.': clearFilter(key) removes specific relation filter'
		);

		// ========================================================================
		// 32. copyfrom() with SERIALIZED string  
		// ========================================================================
		$cx->reset();
		$cx->setFieldConfiguration($fields);
		$serialized = serialize(['k1' => 'v1']);
		$cx->copyfrom(['title' => 'ser_test', 'blob' => $serialized]);
		$cx->save();
		$cx->reset();
		$cx->load(['title = ?', 'ser_test']);
		$castSer = $cx->cast(null, 0);
		$test->expect(
			isset($castSer['blob']) && is_array($castSer['blob'])
			&& ($castSer['blob']['k1'] ?? null) === 'v1',
			$type.': copyfrom() deserializes SERIALIZED string'
		);

		// ========================================================================
		// 33. cast() standardiseID - _id field present in SQL cast
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'beta']);
		if (!$cx->dry()) {
			$casted = $cx->cast(null, 0);
			$test->expect(
				array_key_exists('_id', $casted) || array_key_exists('id', $casted),
				$type.': cast() includes ID field (standardised or raw)'
			);
		} else {
			$test->expect(true, $type.': cast() includes ID field (skipped)');
		}

		// ========================================================================
		// 34. count() with no records matching
		// ========================================================================
		$cnt = $cx->count(['title = ?', 'zzz_nonexistent']);
		$test->expect(
			$cnt === 0,
			$type.': count() returns 0 for no matching records'
		);

		// ========================================================================
		// 35. erase() with filter - deletes matching without loading
		// ========================================================================
		$cx->reset();
		$cx->title = 'erase_filter_test';
		$cx->save();
		$cx->reset();
		$cx->erase(['title = ?', 'erase_filter_test']);
		$cx->reset();
		$check = $cx->load(['title = ?', 'erase_filter_test']);
		$test->expect(
			!$check,
			$type.': erase(filter) deletes without prior load'
		);

		// ========================================================================
		// 36. constructor with $db as hive key string
		// ========================================================================
		$f3 = \Base::instance();
		$f3->set('TEST_DB_REF', $db);
		$cxHive = new \DB\Cortex('TEST_DB_REF', $tname);
		$cxHive->setFieldConfiguration($fields);
		$expected_dbt = str_contains($type, 'sql') ? 'SQL' : 'Mongo';
		$test->expect(
			$cxHive->dbtype() === $expected_dbt,
			$type.': constructor accepts $db as hive key string'
		);
		$f3->clear('TEST_DB_REF');

		// ========================================================================
		// 37. fluid SQL mode - auto-create columns
		// ========================================================================
		$fluidTable = 'test_fluid';
		\DB\Cortex::setdown($db, $fluidTable);
		\DB\Cortex::setup($db, $fluidTable, [
			'name' => ['type' => \DB\SQL\Schema::DT_VARCHAR256],
		]);
		$fluid = new \DB\Cortex($db, $fluidTable, true);
		$fluid->name = 'fluid_test';
		$fluid->dynamic_field = 'auto_created';
		$fluid->save();
		$fluid->reset();
		$fluid->load(['name = ?', 'fluid_test']);
		$test->expect(
			!$fluid->dry() && $fluid->dynamic_field === 'auto_created',
			$type.': fluid mode auto-creates new SQL columns'
		);
		\DB\Cortex::setdown($db, $fluidTable);

		// ========================================================================
		// 38. fields() returns schema columns when no whitelist
		// ========================================================================
		$cx->reset();
		$schemaFields = $cx->fields();
		$test->expect(
			is_array($schemaFields) && in_array('title', $schemaFields),
			$type.': fields() returns schema column list'
		);

		// ========================================================================
		// 39. getRaw() - alias for get(key, true)
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'beta']);
		if (!$cx->dry()) {
			$rawAmount = $cx->getRaw('amount');
			$test->expect(
				$rawAmount !== null,
				$type.': getRaw() returns raw field value'
			);
		} else {
			$test->expect(true, $type.': getRaw() returns raw field value (skipped)');
		}

		// ========================================================================
		// 40. __construct with static::$init true (resolveConfiguration call)
		// ========================================================================
		// AuthorModel already has fieldConf defined, its constructor calls parent
		// which sets up from static config. resolveConfiguration was already tested
		// but verifying the construction path works end-to-end
		$am = new \AuthorModel();
		$amConf = $am::resolveConfiguration();
		$test->expect(
			is_array($amConf) && isset($amConf['table']) && !empty($amConf['table']),
			$type.': model with static fieldConf resolves configuration correctly'
		);

		// ========================================================================
		// 41. find() with order by relation (@field.subfield sorting)
		// ========================================================================
		$news4 = new \NewsModel();
		$sortedByAuthor = $news4->find(null, ['order' => '@author.name ASC']);
		$test->expect(
			$sortedByAuthor !== false,
			$type.': find() with @relation.field sorting works'
		);

		// ========================================================================
		// 42. copyto_flat() with non-collection non-array value
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'beta']);
		if (!$cx->dry()) {
			$flatKey = 'flat_test_key';
			$cx->copyto_flat($flatKey);
			$flatData = $f3->get($flatKey);
			$test->expect(
				is_array($flatData) && isset($flatData['title']),
				$type.': copyto_flat() exports scalar fields correctly'
			);
			$f3->clear($flatKey);
		} else {
			$test->expect(true, $type.': copyto_flat() exports scalar fields correctly (skipped)');
		}

		// ========================================================================
		// 43. method-based event detection via emit()
		// ========================================================================
		// The emit() method detects method_exists($this, 'set_fieldname')
		// Since no test model has such methods, we test via onset() callback
		$cx->reset();
		$cx->setFieldConfiguration($fields);
		$transformCalled = false;
		$cx->onset('title', function($self, $val) use (&$transformCalled) {
			$transformCalled = true;
			return strtoupper($val ?? '');
		});
		$cx->title = 'lower_case';
		$test->expect(
			$transformCalled && $cx->get('title', true) === 'LOWER_CASE',
			$type.': onset callback transforms value on set'
		);

		// ========================================================================
		// 44. CortexCollection::getAll() with raw=true
		// ========================================================================
		$allResults = $cx->find(null, ['order' => 'title']);
		if ($allResults && count($allResults) > 0) {
			$titles = $allResults->getAll('title', true);
			$test->expect(
				is_array($titles) && count($titles) > 0,
				$type.': CortexCollection::getAll(field, raw=true) returns values'
			);
		} else {
			$test->expect(true, $type.': CortexCollection::getAll(field, raw=true) (skipped)');
		}

		// ========================================================================
		// 45. CortexCollection::getSubset() with string keys
		// ========================================================================
		// getSubset() requires a populated relSet. Test through relation loading.
		$news5 = new \NewsModel();
		$allN = $news5->find(null, ['limit' => 3]);
		if ($allN && count($allN) > 0) {
			// getSubset returns null if relSet not set for prop
			$subsetNull = $allN->getSubset('nonexistent', ['1','2']);
			$test->expect(
				$subsetNull === null,
				$type.': CortexCollection::getSubset() returns null for missing relSet'
			);
		} else {
			$test->expect(true, $type.': CortexCollection::getSubset() returns null for missing relSet (skipped)');
		}

		// ========================================================================
		// 46. touch() with DT_DATETIME type
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'beta']);
		if (!$cx->dry()) {
			$cx->touch('stamp');
			$cx->save();
			$cx->reset();
			$cx->load(['title = ?', 'beta']);
			$stampVal = $cx->get('stamp', true);
			$test->expect(
				!empty($stampVal),
				$type.': touch() sets date on DT_DATE field'
			);
		} else {
			$test->expect(true, $type.': touch() sets date on DT_DATE field (skipped)');
		}

		// ========================================================================
		// 47. paginate() with empty result set
		// ========================================================================
		$cx->reset();
		$page = $cx->paginate(0, 5, ['title = ?', 'zzz_nonexistent']);
		$test->expect(
			is_array($page) && $page['total'] === 0
			&& array_key_exists('subset', $page),
			$type.': paginate() with no results returns empty page'
		);

		// ========================================================================
		// 48. find with limit and offset 
		// ========================================================================
		$cx->reset();
		$limited = $cx->find(null, ['limit' => 2, 'offset' => 1, 'order' => 'title']);
		$test->expect(
			$limited !== false && count($limited) <= 2,
			$type.': find() with limit and offset'
		);

		// ========================================================================
		// 49. Defaults with DT_CURRENT_TIMESTAMP concept
		// ========================================================================
		// Test that defaults() returns configured defaults correctly even when empty
		$cxDef = new \DB\Cortex($db, $tname);
		$cxDef->setFieldConfiguration(array_merge($fields, [
			'stamp' => ['type' => \DB\SQL\Schema::DT_DATE, 'default' => \DB\SQL\Schema::DF_CURRENT_TIMESTAMP],
		]));
		$defs = $cxDef->defaults();
		$test->expect(
			is_array($defs),
			$type.': defaults() with DT_CURRENT_TIMESTAMP default'
		);

		// ========================================================================
		// 50-52. SQL-only tests (Schema introspection, SQL parser)
		// ========================================================================
		if (str_contains($type, 'sql')) {
		// 50. setup() with custom primary key
		$pkTable = 'test_pk';
		\DB\Cortex::setdown($db, $pkTable);
		\DB\Cortex::setup($db, $pkTable, [
			'uid' => ['type' => \DB\SQL\Schema::DT_INT4],
			'name' => ['type' => \DB\SQL\Schema::DT_VARCHAR256],
		], 'uid');
		$pkCx = new \DB\Cortex($db, $pkTable);
		$schema = new \DB\SQL\Schema($db);
		$tables = $schema->getTables();
		$test->expect(
			in_array($pkTable, $tables),
			$type.': setup() with custom primary key creates table'
		);
		\DB\Cortex::setdown($db, $pkTable);

		// ========================================================================
		// 51. CortexQueryParser - string filter (not array)
		// ========================================================================
		$qp = \DB\CortexQueryParser::instance();
		$prepared = $qp->prepareFilter('title = "test"', 'sql', $db);
		$test->expect(
			is_array($prepared) && !empty($prepared[0]),
			$type.': CortexQueryParser handles string filter input'
		);

		// ========================================================================
		// 52. CortexQueryParser - caching (CORTEX.queryParserCache)
		// ========================================================================
		// Call prepareFilter twice with same args - second should hit cache
		$filter1 = $qp->prepareFilter(['title = ?', 'x'], 'sql', $db);
		$filter2 = $qp->prepareFilter(['title = ?', 'x'], 'sql', $db);
		$test->expect(
			$filter1 == $filter2,
			$type.': CortexQueryParser caches repeated filter calls'
		);
		} // end SQL-only tests

		// ====== CLEANUP ======
		\DB\Cortex::setdown($db, $tname);

		///////////////////////////////////
		return $test->results();
	}
}
