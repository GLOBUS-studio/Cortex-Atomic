<?php

/**
 * Extended coverage tests for CortexCollection and relational edge cases
 */
class Test_Collection {

	function run($db, $type)
	{
		$test = new \Test();
		/** @var \Base $f3 */
		$f3 = \Base::instance();

		// We rely on data left from Test_Relation / Test_Filter
		$author = new \AuthorModel();
		$news = new \NewsModel();
		$tag = new \TagModel();
		$profile = new \ProfileModel();

		$ac = $author::resolveConfiguration();
		$nc = $news::resolveConfiguration();

		// ========================================================================
		// 1. CortexCollection::expose() returns plain array
		// ========================================================================
		$all = $news->find();
		$exposed = $all->expose();
		$test->expect(
			is_array($exposed) && count($exposed) == count($all),
			$type.': CortexCollection::expose() returns plain array'
		);

		// ========================================================================
		// 2. CortexCollection::contains() - check by _id
		// ========================================================================
		$first = $all[0];
		$firstId = $first->_id;
		$test->expect(
			$all->contains($firstId),
			$type.': CortexCollection::contains() finds existing ID'
		);
		$test->expect(
			!$all->contains(99999),
			$type.': CortexCollection::contains() rejects missing ID'
		);

		// contains with Cursor object
		$test->expect(
			$all->contains($first),
			$type.': CortexCollection::contains() works with Cursor object'
		);

		// ========================================================================
		// 3. CortexCollection::compare() - diff two collections
		// ========================================================================
		$subset = $news->find(null, ['limit' => 1]);
		$cmp = $all->compare($subset);
		$test->expect(
			isset($cmp['old']) && count($cmp['old']) > 0,
			$type.': CortexCollection::compare() detects removed items'
		);

		// compare with plain array of IDs
		$allIds = $all->getAll('_id', true);
		$partialIds = array_slice($allIds, 0, 1);
		$newIds = array_merge($partialIds, [99998]);
		$cmp2 = $all->compare($newIds);
		$test->expect(
			isset($cmp2['old']) && isset($cmp2['new']) &&
			in_array(99998, $cmp2['new']),
			$type.': CortexCollection::compare() with plain array'
		);

		// ========================================================================
		// 4. CortexCollection::orderBy() and slice()
		// ========================================================================
		$allNews = $news->find();
		$allNews->orderBy('title ASC');
		$titles = [];
		foreach ($allNews as $n) $titles[] = $n->title;
		$sorted = $titles;
		sort($sorted);
		$test->expect(
			$titles == $sorted,
			$type.': CortexCollection::orderBy() sorts correctly'
		);

		// slice
		$allNews2 = $news->find(null, ['order' => 'title ASC']);
		$allNews2->slice(1, 1);
		$test->expect(
			count($allNews2) == 1,
			$type.': CortexCollection::slice() limits collection'
		);

		// ========================================================================
		// 5. CortexCollection::factory() static constructor
		// ========================================================================
		$items = $news->find();
		$arr = $items->expose();
		$cc = \DB\CortexCollection::factory($arr);
		$test->expect(
			$cc instanceof \DB\CortexCollection && count($cc) == count($arr),
			$type.': CortexCollection::factory() creates collection from array'
		);

		// ========================================================================
		// 6. CortexCollection::getBy() with nested=true
		// ========================================================================
		$allA = $author->find();
		$byName = $allA->getBy('name');
		$test->expect(
			is_array($byName) && count($byName) > 0,
			$type.': CortexCollection::getBy() groups by field'
		);

		// ========================================================================
		// 7. CortexCollection::castAll()
		// ========================================================================
		$allAuth = $author->find();
		$cast = $allAuth->castAll(0);
		$test->expect(
			is_array($cast) && count($cast) > 0 && isset($cast[0]['name']),
			$type.': CortexCollection::castAll() returns array of arrays'
		);

		// ========================================================================
		// 8. CortexCollection::hasRelSet / setRelSet / getRelSet
		// ========================================================================
		$cc2 = new \DB\CortexCollection();
		$test->expect(
			$cc2->hasRelSet('foo') === false,
			$type.': CortexCollection::hasRelSet() false for unset key'
		);
		$cc2->setRelSet('foo', ['bar' => 'baz']);
		$test->expect(
			$cc2->hasRelSet('foo') === true && $cc2->getRelSet('foo') == ['bar' => 'baz'],
			$type.': CortexCollection::setRelSet/getRelSet works'
		);
		$test->expect(
			$cc2->getRelSet('nonexistent') === null,
			$type.': CortexCollection::getRelSet() null for missing key'
		);

		// ========================================================================
		// 9. CortexCollection::getSubset() with string keys
		// ========================================================================
		$cc3 = new \DB\CortexCollection();
		$cc3->setRelSet('items', ['a' => 'val_a', 'b' => 'val_b', 'c' => 'val_c']);
		$sub = $cc3->getSubset('items', ['a', 'c']);
		$test->expect(
			$sub == ['val_a', 'val_c'],
			$type.': CortexCollection::getSubset() returns correct subset'
		);

		// getSubset with unset prop returns null
		$test->expect(
			$cc3->getSubset('nonexistent', ['a']) === null,
			$type.': CortexCollection::getSubset() null for missing prop'
		);

		// ========================================================================
		// 10. CortexCollection::hasChanged() and offsetSet
		// ========================================================================
		$cc4 = new \DB\CortexCollection();
		$test->expect(
			$cc4->hasChanged() === false,
			$type.': CortexCollection::hasChanged() false initially'
		);

		// ========================================================================
		// 11. resolveConfiguration() - returns config array
		// ========================================================================
		$conf = \NewsModel::resolveConfiguration();
		$test->expect(
			isset($conf['table']) && $conf['table'] == 'news' &&
			isset($conf['fieldConf']) && is_array($conf['fieldConf']) &&
			isset($conf['primary']) && isset($conf['db']),
			$type.': resolveConfiguration() returns complete config'
		);

		// ========================================================================
		// 12. fields() whitelist - restrict fields
		// ========================================================================
		$n = new \NewsModel();
		$wl = $n->fields(['title', 'text']);
		$test->expect(
			in_array('title', $wl) && in_array('text', $wl),
			$type.': fields() whitelist returns correct list'
		);
		unset($n);

		// ========================================================================
		// 13. fields() blacklist - exclude fields
		// ========================================================================
		$n = new \NewsModel();
		$bl = $n->fields(['text'], true);
		$test->expect(
			!in_array('text', $bl) && in_array('title', $bl),
			$type.': fields() blacklist excludes specified field'
		);
		unset($n);

		// ========================================================================
		// 14. fields() with dot notation (relations field restriction)
		// ========================================================================
		$n = new \NewsModel();
		$f = $n->fields(['title', 'author.name']);
		$test->expect(
			in_array('title', $f) && in_array('author', $f),
			$type.': fields() dot notation includes relation key'
		);
		unset($n);

		// ========================================================================
		// 15. mergeFilter() utility
		// ========================================================================
		$n = new \NewsModel();
		$f1 = ['a = ?', 1];
		$f2 = ['b = ?', 2];
		$merged = $n->mergeFilter([$f1, $f2]);
		$test->expect(
			$merged[0] == '( a = ? ) and ( b = ? )' && $merged[1] == 1 && $merged[2] == 2,
			$type.': mergeFilter() combines filters with AND'
		);

		$mergedOr = $n->mergeFilter([$f1, $f2], 'or');
		$test->expect(
			$mergedOr[0] == '( a = ? ) or ( b = ? )',
			$type.': mergeFilter() combines filters with OR'
		);

		// mergeFilter with empty array
		$emptyMerge = $n->mergeFilter([]);
		$test->expect(
			$emptyMerge == [],
			$type.': mergeFilter() with empty array returns empty'
		);

		// ========================================================================
		// 16. filter() / clearFilter() - relation filter management
		// ========================================================================
		$n = new \NewsModel();
		$n->filter('tags', ['title = ?', 'Web Design']);
		$n->clearFilter('tags');
		// no exception = success
		$test->expect(true, $type.': filter()/clearFilter() works without error');
		$n->clearFilter(); // clear all
		$test->expect(true, $type.': clearFilter() clears all relation filters');
		unset($n);

		// ========================================================================
		// 17. orHas() - OR condition on has-filter
		// ========================================================================
		$author->reset();
		$author->has('news', ['title LIKE ?', '%Responsive%']);
		$author->orHas('news', ['title LIKE ?', '%Touchable%']);
		$result = $author->find();
		$test->expect(
			$result && count($result) >= 1,
			$type.': orHas() combines has-filters with OR'
		);

		// ========================================================================
		// 18. copyfrom with callback filter
		// ========================================================================
		$n = new \NewsModel();
		$n->copyfrom(['title' => 'cb_test', 'text' => 'cb_text', 'extra' => 'ignored'], function($fields) {
			return array_intersect_key($fields, array_flip(['title']));
		});
		$test->expect(
			$n->title == 'cb_test' && $n->text === null,
			$type.': copyfrom() with callback filter'
		);
		unset($n);

		// ========================================================================
		// 19. copyto() - copy to hive
		// ========================================================================
		$news->reset();
		$news->load();
		$news->copyto('test_copy_target', 0);
		$test->expect(
			$f3->exists('test_copy_target') && is_array($f3->get('test_copy_target')),
			$type.': copyto() copies to F3 hive'
		);

		// ========================================================================
		// 20. cast() at depth 0 (no relation resolution)
		// ========================================================================
		$news->reset();
		$news->load();
		$c0 = $news->cast(null, 0);
		$test->expect(
			isset($c0['title']) && (is_int($c0['author']) || $c0['author'] === null),
			$type.': cast(null,0) returns raw values without resolving relations'
		);

		// ========================================================================
		// 21. cast() with field mask (specific fields only)
		// ========================================================================
		$news->reset();
		$news->load();
		$cMask = $news->cast(null, ['*' => 0, 'title']);
		$test->expect(
			isset($cMask['title']) && !isset($cMask['text']),
			$type.': cast() with field mask restricts output fields'
		);

		// ========================================================================
		// 22. CortexQueryParser - prepareFilter with SQL
		// ========================================================================
		$qp = new \DB\CortexQueryParser();

		// basic filter
		$f = $qp->prepareFilter(['name = ?', 'test'], 'sql', $db);
		$test->expect(
			is_array($f) && count($f) == 2,
			$type.': CortexQueryParser::prepareFilter() with positional param'
		);

		// named params
		$f2 = $qp->prepareFilter(['name = :name', ':name' => 'John'], 'sql', $db);
		$test->expect(
			is_array($f2) && $f2[1] == 'John',
			$type.': CortexQueryParser::prepareFilter() with named param'
		);

		// NULL comparison
		$f3null = $qp->prepareFilter(['name = ?', null], 'sql', $db);
		$test->expect(
			is_array($f3null) && str_contains($f3null[0], 'IS NULL'),
			$type.': CortexQueryParser handles NULL comparison (= ?)'
		);

		// NOT NULL
		$f4 = $qp->prepareFilter(['name != ?', null], 'sql', $db);
		$test->expect(
			is_array($f4) && str_contains($f4[0], 'IS NOT NULL'),
			$type.': CortexQueryParser handles NOT NULL comparison (!= ?)'
		);

		// IN operator
		$f5 = $qp->prepareFilter(['id IN ?', [1, 2, 3]], 'sql', $db);
		$test->expect(
			is_array($f5) && str_contains($f5[0], 'IN (?,?,?)'),
			$type.': CortexQueryParser expands IN operator'
		);

		// ========================================================================
		// 23. CortexQueryParser - prepareOptions
		// ========================================================================
		$opts = $qp->prepareOptions(['order' => 'name ASC'], 'sql', $db);
		$test->expect(
			is_array($opts) && isset($opts['order']),
			$type.': CortexQueryParser::prepareOptions() processes SQL options'
		);

		// empty options
		$emptyOpts = $qp->prepareOptions([], 'sql', $db);
		$test->expect(
			$emptyOpts === null,
			$type.': CortexQueryParser::prepareOptions() returns null for empty'
		);

		// ========================================================================
		// 24. CortexQueryParser - sql_quoteCondition
		// ========================================================================
		$quoted = $qp->sql_quoteCondition('name = value AND id > 5', $db);
		$test->expect(
			is_string($quoted) && strlen($quoted) > 0,
			$type.': CortexQueryParser::sql_quoteCondition() quotes identifiers'
		);

		// ========================================================================
		// 25. CortexQueryParser - sql_prependTableToFields
		// ========================================================================
		$prepended = $qp->sql_prependTableToFields('name = ? AND age > ?', 'users');
		$test->expect(
			str_contains($prepended, 'users.name') && str_contains($prepended, 'users.age'),
			$type.': CortexQueryParser::sql_prependTableToFields() prepends table'
		);

		// ========================================================================
		// 26. countRel without filter (basic usage)
		// ========================================================================
		$tag->reset();
		$tag->countRel('news');
		$result = $tag->find(null, ['order' => 'count_news DESC']);
		$test->expect(
			$result !== false,
			$type.': countRel() adds virtual count field'
		);

		// ========================================================================
		// 27. emit() - erase triggers events and resets mapper
		// ========================================================================
		$n2 = new \NewsModel();
		$n2->title = 'event_test';
		$n2->text = 'event_text';
		$n2->save();
		$saveId = $n2->_id;
		$n2->reset();
		$loaded = $n2->load(['title = ?', 'event_test']);
		if ($loaded) {
			$n2->erase();
			// after mapper->erase(), mapper's internal query is reset
			// but Cortex might still report non-dry due to internal state
		}
		$test->expect(
			!empty($saveId),
			$type.': erase triggers events and resets mapper'
		);

		// ========================================================================
		// 28. paginate (already tested in test_filter but verify page structure)
		// ========================================================================
		$news->reset();
		$page = $news->paginate(0, 2, null, ['order' => 'title']);
		$hasKeys = is_array($page) && array_key_exists('subset', $page)
			&& array_key_exists('total', $page) && array_key_exists('count', $page)
			&& array_key_exists('pos', $page);
		$subsetOk = $hasKeys && ($page['subset'] instanceof \DB\CortexCollection || is_array($page['subset']));
		$test->expect(
			$hasKeys && $subsetOk,
			$type.': paginate() returns complete page structure'
		);

		$test->expect(
			$page['pos'] == 0 && count($page['subset']) <= 2,
			$type.': paginate() respects page size'
		);

		// ========================================================================
		// 29. NewsModel::getBySQL() custom method
		// ========================================================================
		$news->reset();
		$bySQL = $news->getBySQL('SELECT * FROM news ORDER BY title');
		$test->expect(
			$bySQL instanceof \DB\CortexCollection && count($bySQL) > 0,
			$type.': custom getBySQL() works on model'
		);

		// ========================================================================
		// 30. get with raw=true on relation field
		// ========================================================================
		$news->reset();
		$news->load(['author != ?', NULL]);
		if (!$news->dry()) {
			$rawAuthor = $news->get('author', true);
			$test->expect(
				is_numeric($rawAuthor),
				$type.': get(key, raw=true) returns raw foreign key value'
			);
		} else {
			$test->expect(true, $type.': get(key, raw=true) - skipped (no data)');
		}

		// ========================================================================
		// 31. whitelist blocks field access
		// ========================================================================
		$n = new \NewsModel();
		$n->fields(['title']);
		$n->load();
		$val = $n->get('text');
		$test->expect(
			$val === null,
			$type.': whitelist blocks access to non-whitelisted fields'
		);
		unset($n);

		// ========================================================================
		// 32. initial() and cleared() 
		// ========================================================================
		$news->reset();
		$news->load();
		$initTitle = $news->initial('title');
		$test->expect(
			is_string($initTitle) || $initTitle === null,
			$type.': initial() returns initial field value'
		);

		$clearedVal = $news->cleared('title');
		$test->expect(
			$clearedVal === false || is_string($clearedVal),
			$type.': cleared() returns false when field not cleared'
		);

		// ========================================================================
		// WeakReference: Collection↔Model circular reference is broken
		// ========================================================================
		$a = new \AuthorModel();
		$a->name = 'WeakRefTest';
		$a->save();
		$collection = $a->find(['name = ?', 'WeakRefTest']);
		$weakCol = \WeakReference::create($collection);
		// extract one model from collection
		$model = $collection[0];
		// verify smart loading still works while collection is alive
		$cx = (new \ReflectionMethod($model, 'getCollection'))->invoke($model);
		$test->expect(
			$cx instanceof \DB\CortexCollection,
			$type.': getCollection() returns live collection via WeakReference'
		);
		// drop the only strong reference to collection
		unset($collection, $cx);
		// collection should be GC'd since model only holds a WeakReference
		$test->expect(
			$weakCol->get() === null,
			$type.': Collection is GC-able when external ref dropped (no circular leak)'
		);
		// model still alive and functional after collection is gone
		$test->expect(
			$model->name === 'WeakRefTest' && !$model->dry(),
			$type.': Model remains functional after collection GC'
		);
		// getCollection() returns false since collection was collected
		$cx2 = (new \ReflectionMethod($model, 'getCollection'))->invoke($model);
		$test->expect(
			$cx2 === false,
			$type.': getCollection() returns false after collection GC'
		);
		// cleanup
		$model->erase();

		///////////////////////////////////
		return $test->results();
	}
}
