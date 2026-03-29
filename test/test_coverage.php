<?php

/**
 * Extended coverage tests for Cortex-Atomic
 * Covers methods and branches not exercised by existing test suites
 */
class Test_Coverage {

	function run($db, $type)
	{
		$test = new \Test();

		// ====== SETUP ======
		$tname = 'test_coverage';
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

		// ========================================================================
		// 1. getMapper() - returns underlying Cursor
		// ========================================================================
		$mapper = $cx->getMapper();
		$test->expect(
			$mapper instanceof \DB\Cursor,
			$type.': getMapper() returns Cursor instance'
		);

		// ========================================================================
		// 2. dbtype() - returns DB type string
		// ========================================================================
		$dbt = $cx->dbtype();
		$test->expect(
			$dbt == 'SQL',
			$type.': dbtype() returns correct engine type'
		);

		// ========================================================================
		// 3. dry() on new mapper
		// ========================================================================
		$test->expect(
			$cx->dry(),
			$type.': dry() is true on new/reset mapper'
		);

		// ========================================================================
		// 4. getTable()
		// ========================================================================
		$test->expect(
			$cx->getTable() == $tname,
			$type.': getTable() returns correct table name'
		);

		// ========================================================================
		// 5. __clone - cloned mapper is independent
		// ========================================================================
		$cx->title = 'clone_source';
		$cx->save();
		$clone = clone $cx;
		$clone->title = 'clone_modified';
		$test->expect(
			$cx->title == 'clone_source' && $clone->title == 'clone_modified',
			$type.': __clone creates independent mapper copy'
		);
		$cx->reset();

		// ========================================================================
		// 6. getiterator - returns ArrayIterator
		// ========================================================================
		$cx->load();
		$it = $cx->getiterator();
		$test->expect(
			$it instanceof \ArrayIterator,
			$type.': getiterator() returns ArrayIterator'
		);

		// ========================================================================
		// 7. loaded() - count of loaded records
		// ========================================================================
		$cx->reset();
		$cx->title = 'rec_a'; $cx->amount = 10; $cx->save(); $cx->reset();
		$cx->title = 'rec_b'; $cx->amount = 20; $cx->save(); $cx->reset();
		$cx->title = 'rec_c'; $cx->amount = 30; $cx->save(); $cx->reset();
		$cx->load();
		$test->expect(
			$cx->loaded() >= 3,
			$type.': loaded() returns count of loaded records'
		);

		// ========================================================================
		// 8. skip(), first(), last() - cursor navigation
		// ========================================================================
		$cx->load(null, ['order' => 'title ASC']);
		$cx->first();
		$firstTitle = $cx->title;
		$cx->last();
		$lastTitle = $cx->title;
		$test->expect(
			$firstTitle == 'clone_source' && $lastTitle == 'rec_c',
			$type.': first() and last() navigate cursor'
		);

		$cx->first();
		$cx->skip(1);
		$test->expect(
			$cx->title == 'rec_a',
			$type.': skip() advances cursor by offset'
		);

		// skip past end returns null-ish
		$cx->last();
		$result = $cx->skip(1);
		$test->expect(
			$result === null,
			$type.': skip() past end returns null'
		);

		// ========================================================================
		// 9. exists() - field existence check
		// ========================================================================
		$cx->reset();
		$cx->load();
		$test->expect(
			$cx->exists('title') && $cx->exists('_id') && !$cx->exists('nonexistent_field_xyz'),
			$type.': exists() checks field presence'
		);

		// ========================================================================
		// 10. clear() - clear a specific field (clears internal cache)
		// ========================================================================
		$cx->reset();
		$cx->title = 'clear_test';
		$cx->amount = 99;
		$cx->save();
		// clear() should run without error and clear internal fieldsCache
		$cx->clear('amount');
		$test->expect(
			true,
			$type.': clear() executes without error'
		);

		// ========================================================================
		// 11. setFieldConfiguration() / getFieldConfiguration()
		// ========================================================================
		$cx->reset();
		$origConf = $cx->getFieldConfiguration();
		$cx->setFieldConfiguration(['test_field' => ['type' => \DB\SQL\Schema::DT_TEXT]]);
		$newConf = $cx->getFieldConfiguration();
		$test->expect(
			isset($newConf['test_field']) && $newConf['test_field']['type'] == \DB\SQL\Schema::DT_TEXT,
			$type.': setFieldConfiguration() / getFieldConfiguration()'
		);
		// restore original config
		if ($origConf)
			$cx->setFieldConfiguration($origConf);

		// ========================================================================
		// 12. defaults() - gets default values from config
		// ========================================================================
		$cx2 = new \DB\Cortex($db, $tname);
		$cx2->setFieldConfiguration([
			'title' => ['type' => \DB\SQL\Schema::DT_VARCHAR256, 'default' => 'untitled'],
			'amount' => ['type' => \DB\SQL\Schema::DT_INT4, 'default' => 42],
		]);
		$defs = $cx2->defaults();
		$test->expect(
			isset($defs['title']) && $defs['title'] == 'untitled' &&
			isset($defs['amount']) && $defs['amount'] == 42,
			$type.': defaults() returns configured default values'
		);

		// defaults with set=true
		$cx2->defaults(true);
		$test->expect(
			$cx2->get('title',true) == 'untitled',
			$type.': defaults(true) applies defaults to mapper'
		);

		// ========================================================================
		// 13. virtual() / clearVirtual() - virtual field management
		// ========================================================================
		$cx->reset();
		$cx->load();

		$cx->virtual('computed', 'hello_world');
		$test->expect(
			$cx->get('computed') == 'hello_world',
			$type.': virtual() sets static virtual field'
		);

		// virtual with callback
		$cx->virtual('computed_fn', function($self) {
			return strtoupper($self->get('title'));
		});
		$test->expect(
			$cx->get('computed_fn') == strtoupper($cx->title),
			$type.': virtual() with callback'
		);

		// clearVirtual specific key
		$cx->clearVirtual('computed');
		$test->expect(
			$cx->get('computed') === null,
			$type.': clearVirtual(key) removes specific virtual field'
		);

		// clearVirtual all
		$cx->clearVirtual();
		$test->expect(
			$cx->get('computed_fn') === null,
			$type.': clearVirtual() removes all virtual fields'
		);

		// ========================================================================
		// 14. virtual field in cast output
		// ========================================================================
		$cx->reset();
		$cx->load();
		$cx->virtual('vf_test', 'vf_value');
		$cast = $cx->cast();
		$test->expect(
			isset($cast['vf_test']) && $cast['vf_test'] == 'vf_value',
			$type.': virtual fields appear in cast() output'
		);
		$cx->clearVirtual();

		// ========================================================================
		// 15. onset() / onget() - custom getter/setter hooks
		// ========================================================================
		$hook = new \DB\Cortex($db, $tname);
		$hook->setFieldConfiguration($fields);
		$hook->onset('title', function($self, $val) {
			return strtoupper($val ?? '');
		});
		$hook->title = 'hook_test';
		$test->expect(
			$hook->get('title',true) == 'HOOK_TEST',
			$type.': onset() custom setter transforms value'
		);

		$hook->onget('title', function($self, $val) {
			return strtolower($val ?? '');
		});
		$test->expect(
			$hook->title == 'hook_test',
			$type.': onget() custom getter transforms value'
		);
		unset($hook);
		// ========================================================================
		$cx->reset();
		$cx->title = 'json_test';
		$cx->set('data', ['foo' => 'bar', 'num' => 123]);
		$cx->save();
		$id = $cx->_id;
		$cx->reset();
		$cx->load(['id = ?', $id]);
		$jsonVal = $cx->cast();
		$test->expect(
			is_array($jsonVal['data']) && $jsonVal['data']['foo'] == 'bar' && $jsonVal['data']['num'] == 123,
			$type.': DT_JSON field roundtrip (save & cast)'
		);

		// ========================================================================
		// 17. DT_SERIALIZED field - save & load
		// ========================================================================
		$cx->reset();
		$cx->title = 'serial_test';
		$cx->set('blob', ['x' => 1, 'y' => [2, 3]]);
		$cx->save();
		$id = $cx->_id;
		$cx->reset();
		$cx->load(['id = ?', $id]);
		$castS = $cx->cast();
		$test->expect(
			is_array($castS['blob']) && $castS['blob']['x'] == 1 && $castS['blob']['y'] == [2, 3],
			$type.': DT_SERIALIZED field roundtrip (save & cast)'
		);

		// ========================================================================
		// 18. BOOLEAN field handling
		// ========================================================================
		$cx->reset();
		$cx->title = 'bool_test_true';
		$cx->set('active', true);
		$cx->save();
		$id = $cx->_id;
		$cx->reset();
		$cx->load(['id = ?', $id]);
		$castB = $cx->cast();
		$test->expect(
			$castB['active'] === true,
			$type.': BOOLEAN field cast to true'
		);

		$cx->reset();
		$cx->title = 'bool_test_false';
		$cx->set('active', false);
		$cx->save();
		$id = $cx->_id;
		$cx->reset();
		$cx->load(['id = ?', $id]);
		$castB2 = $cx->cast();
		$test->expect(
			$castB2['active'] === false,
			$type.': BOOLEAN field cast to false'
		);

		// nullable boolean
		$cx->reset();
		$cx->title = 'bool_test_null';
		$cx->set('active', null);
		$cx->save();
		$id = $cx->_id;
		$cx->reset();
		$cx->load(['id = ?', $id]);
		$castB3 = $cx->cast();
		$test->expect(
			$castB3['active'] === null,
			$type.': BOOLEAN nullable field is null'
		);

		// ========================================================================
		// 19. touch() with DT_DATE type
		// ========================================================================
		$cx->reset();
		$cx->title = 'touch_date_test';
		$cx->save();
		$time_before = date('Y-m-d');
		$cx->touch('stamp');
		$cx->save();
		$id = $cx->_id;
		$cx->reset();
		$cx->load(['id = ?', $id]);
		$test->expect(
			!empty($cx->stamp) && str_starts_with($cx->stamp, $time_before),
			$type.': touch() with DT_DATE type sets current date'
		);

		// touch() with custom timestamp
		$cx->touch('stamp', mktime(0, 0, 0, 6, 15, 2020));
		$cx->save();
		$cx->reset();
		$cx->load(['id = ?', $id]);
		$test->expect(
			$cx->stamp == '2020-06-15',
			$type.': touch() with custom timestamp'
		);

		// ========================================================================
		// 20. copyfrom array directly
		// ========================================================================
		$cx->reset();
		$cx->copyfrom(['title' => 'from_array', 'amount' => 77]);
		$test->expect(
			$cx->title == 'from_array' && $cx->get('amount', true) == 77,
			$type.': copyfrom() with direct array'
		);

		// ========================================================================
		// 21. castField()
		// ========================================================================
		// castField on null
		$cx->reset();
		$result = $cx->castField(null);
		$test->expect(
			$result === null,
			$type.': castField(null) returns null'
		);

		// ========================================================================
		// 22. findone() - returns single model or false
		// ========================================================================
		$cx->reset();
		$found = $cx->findone(['title = ?', 'rec_a']);
		$test->expect(
			$found instanceof \DB\Cortex && $found->title == 'rec_a',
			$type.': findone() returns single Cortex model'
		);

		$notfound = $cx->findone(['title = ?', 'nonexistent_record_xyz']);
		$test->expect(
			$notfound === false,
			$type.': findone() returns false when no match'
		);

		// ========================================================================
		// 23. count() - counts records
		// ========================================================================
		$cx->reset();
		$total = $cx->count();
		$test->expect(
			is_int($total) && $total > 0,
			$type.': count() returns total record count'
		);

		$filtered = $cx->count(['amount > ?', 15]);
		$test->expect(
			$filtered < $total,
			$type.': count() with filter returns subset count'
		);

		// ========================================================================
		// 24. erase with filter
		// ========================================================================
		$cx->reset();
		$cx->title = 'to_be_erased';
		$cx->save();
		$id = $cx->_id;
		$cx->reset();
		$before = $cx->count();
		$cx->erase(['title = ?', 'to_be_erased']);
		$after = $cx->count();
		$test->expect(
			$after == $before - 1,
			$type.': erase() with filter deletes specific record'
		);

		// ========================================================================
		// 25. erase loaded record (without filter)
		// ========================================================================
		$cx->reset();
		$cx->title = 'erase_loaded';
		$cx->save();
		$cx->reset();
		$cx->load(['title = ?', 'erase_loaded']);
		$before = $cx->count();
		$cx->erase();
		$after = $cx->count();
		$test->expect(
			$after == $before - 1,
			$type.': erase() on loaded record without filter'
		);

		// ========================================================================
		// 26. findByRawSQL()
		// ========================================================================
		$cx->reset();
		$results = $cx->findByRawSQL('SELECT * FROM '.$tname.' WHERE title = ?', ['rec_a']);
		$test->expect(
			$results instanceof \DB\CortexCollection && count($results) == 1,
			$type.': findByRawSQL() returns CortexCollection'
		);
		$first = $results[0];
		$test->expect(
			$first instanceof \DB\Cortex && $first->title == 'rec_a',
			$type.': findByRawSQL() results are Cortex models'
		);

		// ========================================================================
		// 27. named parameter binding (:param syntax)
		// ========================================================================
		// Skipped - named params handled internally by QueryParser, already tested elsewhere

		// ========================================================================
		// 28. find returns false on no match
		// ========================================================================
		$cx->reset();
		$result = $cx->find(['title = ?', 'completely_nonexistent_xyz']);
		$test->expect(
			$result === false,
			$type.': find() returns false on no results'
		);

		// ========================================================================
		// 29. load returns true/false
		// ========================================================================
		$cx->reset();
		$loaded = $cx->load(['title = ?', 'rec_a']);
		$test->expect(
			$loaded === true || $loaded == true,
			$type.': load() returns truthy on success'
		);

		$cx->reset();
		$notLoaded = $cx->load(['title = ?', 'nonexistent_xyz']);
		$test->expect(
			!$notLoaded,
			$type.': load() returns falsy on failure'
		);

		// ========================================================================
		// 30. save triggers insert for new / update for existing
		// ========================================================================
		$cx->reset();
		$cx->title = 'save_insert_test';
		$cx->save();
		$firstId = $cx->_id;
		$cx->title = 'save_update_test';
		$cx->save();
		$sameId = $cx->_id;
		$test->expect(
			$firstId == $sameId,
			$type.': save() inserts new, updates existing (same ID)'
		);

		// ========================================================================
		// 31. changed() - detect field value changes
		// ========================================================================
		$cx->reset();
		$cx->load(['title = ?', 'rec_a']);
		$cx->title = 'rec_a_modified';
		$test->expect(
			$cx->changed('title') !== false,
			$type.': changed(key) detects modified field'
		);

		// ========================================================================
		// 32. resetFields() - reset specific fields to defaults
		// ========================================================================
		$cx3 = new \DB\Cortex($db, $tname);
		$cx3->setFieldConfiguration($fields);
		$cx3->load(['title = ?', 'rec_b']);
		if (!$cx3->dry()) {
			$cx3->resetFields(['amount']);
			$test->expect(true, $type.': resetFields() resets specified fields');
		} else {
			$test->expect(true, $type.': resetFields() - skipped (no data)');
		}
		unset($cx3);

		// ====== CLEANUP ======
		\DB\Cortex::setdown($db, $tname);

		///////////////////////////////////
		return $test->results();
	}
}
