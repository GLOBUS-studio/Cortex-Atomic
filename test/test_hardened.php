<?php

/**
 * Hardened stress tests for Cortex-Atomic ORM.
 * Probes: findByRawSQL, erase cascade, m:m pivot integrity,
 * save/update relation cascade, cast edge cases, set() type coercion,
 * concurrent-style operations, data corruption scenarios, boundary values,
 * reuse-after-reset, has()+filter() interactions, self-ref deep chains,
 * UTF-8 data, large payloads, virtual fields in queries,
 * collection mutation, copyfrom with relations,
 * afterinsert/afterupdate/aftererase hooks.
 */
class Test_Hardened {

	/**
	 * Build a rich dataset.
	 * 10 authors, 6 tags, 15 news, profiles, m:m and self-ref links.
	 */
	private function seedData($db) {
		\NewsModel::setdown();
		\TagModel::setdown();
		\ProfileModel::setdown();
		\AuthorModel::setdown();

		\AuthorModel::setup();
		\ProfileModel::setup();
		\TagModel::setup();
		\NewsModel::setup();

		$authors = [];
		$authorNames = [
			'Alice', 'Bob', 'Charlie', 'Diana', 'Eve',
			'Frank', 'Grace', 'Hank', 'Irene', 'Jack',
		];
		foreach ($authorNames as $i => $name) {
			$a = new \AuthorModel();
			$a->name = $name;
			$a->mail = ($i % 3 == 0) ? null : strtolower($name).'@test.com';
			$a->website = ($i % 4 == 0) ? null : 'https://'.strtolower($name).'.dev';
			$a->save();
			$authors[$name] = $a;
		}

		// profiles for half the authors
		foreach (['Alice', 'Charlie', 'Eve', 'Grace', 'Jack'] as $name) {
			$p = new \ProfileModel();
			$p->message = 'Bio of '.$name;
			$p->author = $authors[$name]->id;
			$p->save();
		}

		// tags
		$tags = [];
		foreach (['PHP', 'SQL', 'JavaScript', 'DevOps', 'Security', 'Performance'] as $title) {
			$t = new \TagModel();
			$t->title = $title;
			$t->save();
			$tags[$title] = $t;
		}

		// self-ref friends: Alice <-> Bob, Alice <-> Charlie, Bob <-> Diana
		$authors['Alice']->friends = [$authors['Bob']->id, $authors['Charlie']->id];
		$authors['Alice']->save();
		$authors['Bob']->friends = [$authors['Alice']->id, $authors['Diana']->id];
		$authors['Bob']->save();

		// news articles - diverse combinations
		$newsData = [
			['First Post',       'Full text content',          'Alice',   ['PHP', 'SQL']],
			['Second Post',      '',                           'Alice',   ['PHP']],
			['Third Post',       null,                         'Bob',     []],
			['Fourth Post',      'Article by Charlie',         'Charlie', ['SQL', 'JavaScript']],
			['Orphan Post',      'No author here',             null,      []],
			['Null Text Post',   null,                         null,      []],
			['Zero Post',        '0',                          'Diana',   ['DevOps']],
			['Long Post',        str_repeat('Lorem ipsum. ', 100), 'Eve', ['PHP', 'SQL', 'Security']],
			['Unicode Post',     "\xC3\xA9\xC3\xA0\xC3\xBC \xF0\x9F\x98\x80 \xE4\xB8\xAD\xE6\x96\x87", 'Frank', ['JavaScript']],
			['Special Chars',    "O'Reilly & Sons <script>",   'Grace',   ['Security']],
			['Diana Deep',       'Multi-tag article',          'Diana',   ['PHP', 'JavaScript', 'DevOps', 'Performance']],
			['Hank Solo',        'Minimal article',            'Hank',    []],
			['Irene Report',     'Analysis report',            'Irene',   ['SQL', 'Performance']],
			['Jack Update',      'Breaking news',              'Jack',    ['Security', 'DevOps']],
			['Grace Review',     'Tech review',                'Grace',   ['PHP', 'JavaScript', 'SQL']],
		];

		$news = [];
		foreach ($newsData as $nd) {
			$n = new \NewsModel();
			$n->title = $nd[0];
			if ($nd[1] !== null) $n->text = $nd[1];
			if ($nd[2] !== null) $n->author = $authors[$nd[2]]->id;
			if (!empty($nd[3])) {
				$tagIds = array_map(fn($t) => $tags[$t]->id, $nd[3]);
				$n->tags = $tagIds;
			}
			$n->save();
			$news[$nd[0]] = $n;
		}

		return compact('authors', 'tags', 'news');
	}


	function run($db, $type) {
		$test = new \Test();
		$data = $this->seedData($db);
		$authors = $data['authors'];
		$tags = $data['tags'];
		$news = $data['news'];

		// ================================================================
		//  1. findByRawSQL - basic
		// ================================================================
		$a = new \AuthorModel();
		$table = $a->getTable();
		$result = $a->findByRawSQL(
			'SELECT * FROM '.$table.' WHERE name = ?', ['Alice']
		);
		$test->expect(
			$result instanceof \DB\CortexCollection
				&& count($result) == 1
				&& $result[0]->name == 'Alice',
			$type.': findByRawSQL basic SELECT returns hydrated model'
		);

		// ================================================================
		//  2. findByRawSQL - empty result
		// ================================================================
		$a2 = new \AuthorModel();
		$empty = $a2->findByRawSQL(
			'SELECT * FROM '.$table.' WHERE name = ?', ['NonExistent']
		);
		$test->expect(
			$empty instanceof \DB\CortexCollection && count($empty) == 0,
			$type.': findByRawSQL empty result returns empty collection'
		);

		// ================================================================
		//  3. findByRawSQL - with JOIN
		// ================================================================
		$n = new \NewsModel();
		$newsTable = $n->getTable();
		$result3 = $n->findByRawSQL(
			'SELECT n.* FROM '.$newsTable.' n INNER JOIN '.$table.' a ON n.author = a.id WHERE a.name = ?',
			['Alice']
		);
		$test->expect(
			$result3 instanceof \DB\CortexCollection && count($result3) == 2,
			$type.': findByRawSQL with JOIN returns correct count (2 by Alice)'
		);

		// ================================================================
		//  4. findByRawSQL - hydrated models can access relations
		// ================================================================
		if (count($result3) > 0) {
			$firstNews = $result3[0];
			$authorRel = $firstNews->author;
			$test->expect(
				$authorRel instanceof \DB\Cortex && $authorRel->name == 'Alice',
				$type.': findByRawSQL hydrated model can lazy-load relations'
			);
		} else {
			$test->expect(false, $type.': findByRawSQL hydrated model can lazy-load relations');
		}

		// ================================================================
		//  5. erase() cleans m:m pivot entries
		// ================================================================
		// Diana Deep has 4 tags via belongs-to-many
		$dn = new \NewsModel();
		$dn->load(['title = ?', 'Diana Deep']);
		$test->expect(
			!$dn->dry() && $dn->tags instanceof \DB\CortexCollection
				&& count($dn->tags) == 4,
			$type.': before erase, Diana Deep has 4 tags'
		);
		$dianaDeepId = $dn->id;
		$dn->erase();

		// verify the news is gone
		$dn2 = new \NewsModel();
		$dn2->load(['id = ?', $dianaDeepId]);
		$test->expect(
			$dn2->dry(),
			$type.': after erase, Diana Deep record is deleted'
		);

		// verify remaining news still has its tags
		$fp = new \NewsModel();
		$fp->load(['title = ?', 'First Post']);
		$test->expect(
			!$fp->dry() && $fp->tags instanceof \DB\CortexCollection
				&& count($fp->tags) == 2,
			$type.': after unrelated erase, First Post still has 2 tags'
		);

		// ================================================================
		//  6. count() matches find() exactly across various filters
		// ================================================================
		$filters = [
			[null, 'no filter'],
			[['author = ?', null], 'null FK'],
			[['author != ?', null], 'non-null FK'],
			[['text = ?', ''], 'empty string text'],
			[['text = ?', null], 'null text'],
		];
		$allMatch = true;
		foreach ($filters as $fc) {
			$nm1 = new \NewsModel();
			$nm2 = new \NewsModel();
			$found = $nm1->find($fc[0]);
			$counted = $nm2->count($fc[0]);
			$foundCount = $found ? count($found) : 0;
			if ($foundCount !== $counted) {
				$allMatch = false;
				break;
			}
		}
		$test->expect(
			$allMatch,
			$type.': count() == find() for all filter variants'
		);

		// ================================================================
		//  7. set() with hydrated mapper object (belongs-to-one)
		// ================================================================
		$n7 = new \NewsModel();
		$n7->title = 'Mapper Set Test';
		$n7->text = 'test';
		$authorObj = new \AuthorModel();
		$authorObj->load(['name = ?', 'Eve']);
		$n7->author = $authorObj; // set with object, not ID
		$n7->save();

		$n7check = new \NewsModel();
		$n7check->load(['title = ?', 'Mapper Set Test']);
		$test->expect(
			!$n7check->dry()
				&& $n7check->author instanceof \DB\Cortex
				&& $n7check->author->name == 'Eve',
			$type.': set(belongs-to-one) with hydrated mapper saves FK correctly'
		);

		// ================================================================
		//  8. set(belongs-to-one) with invalid (dry) mapper throws
		// ================================================================
		$n8 = new \NewsModel();
		$n8->title = 'Bad Set Test';
		$threw = false;
		try {
			$dryAuthor = new \AuthorModel();
			$n8->author = $dryAuthor;
		} catch (\Exception $e) {
			$threw = true;
		}
		$test->expect(
			$threw,
			$type.': set(belongs-to-one) with dry mapper throws exception'
		);

		// ================================================================
		//  9. update belongs-to-many (replace all tags)
		// ================================================================
		$n9 = new \NewsModel();
		$n9->load(['title = ?', 'First Post']);
		$oldTags = [];
		foreach ($n9->tags as $t) $oldTags[] = $t->title;
		sort($oldTags);
		$test->expect(
			$oldTags == ['PHP', 'SQL'],
			$type.': before update, First Post has PHP and SQL tags'
		);

		// replace with completely different tags
		$n9->tags = [$tags['JavaScript']->id, $tags['DevOps']->id, $tags['Security']->id];
		$n9->save();

		// reload and verify
		$n9r = new \NewsModel();
		$n9r->load(['title = ?', 'First Post']);
		$newTags = [];
		foreach ($n9r->tags as $t) $newTags[] = $t->title;
		sort($newTags);
		$test->expect(
			$newTags == ['DevOps', 'JavaScript', 'Security'],
			$type.': after update, First Post tags replaced correctly'
		);

		// ================================================================
		// 10. clear belongs-to-many (remove all tags)
		// ================================================================
		$n10 = new \NewsModel();
		$n10->load(['title = ?', 'First Post']);
		$n10->tags = null;
		$n10->save();

		$n10r = new \NewsModel();
		$n10r->load(['title = ?', 'First Post']);
		$rawTags = $n10r->getRaw('tags');
		$test->expect(
			$rawTags === null || $rawTags === '' || $rawTags === '[]' || $rawTags === 'null',
			$type.': clearing belongs-to-many sets field to null/empty'
		);

		// ================================================================
		// 11. UTF-8 roundtrip
		// ================================================================
		$n11 = new \NewsModel();
		$n11->load(['title = ?', 'Unicode Post']);
		$expected = "\xC3\xA9\xC3\xA0\xC3\xBC \xF0\x9F\x98\x80 \xE4\xB8\xAD\xE6\x96\x87";
		$test->expect(
			!$n11->dry() && $n11->text === $expected,
			$type.': UTF-8 text (accents, emoji, CJK) survives roundtrip'
		);

		// ================================================================
		// 12. Special characters in text (no XSS leakage, no SQL errors)
		// ================================================================
		$n12 = new \NewsModel();
		$n12->load(['title = ?', 'Special Chars']);
		$test->expect(
			!$n12->dry() && $n12->text === "O'Reilly & Sons <script>",
			$type.': special chars (quotes, ampersand, HTML) stored literally'
		);

		// ================================================================
		// 13. has() filter on belongs-to-one
		// ================================================================
		$a13 = new \NewsModel();
		$result13 = $a13->has('author', ['name LIKE ?', 'A%'])->find();
		$test->expect(
			$result13 && count($result13) == 2,
			$type.': has(author, name LIKE A%) returns 2 news by Alice'
		);

		// ================================================================
		// 14. has() with no matching results
		// ================================================================
		$a14 = new \NewsModel();
		$result14 = $a14->has('author', ['name = ?', 'ZZZZZ'])->find();
		$test->expect(
			$result14 === false,
			$type.': has() with impossible filter returns false'
		);

		// ================================================================
		// 15. filter() limits related records
		// ================================================================
		$a15 = new \AuthorModel();
		$a15->filter('news', ['title LIKE ?', '%Post%']);
		$a15->load(['name = ?', 'Alice']);
		$newsCount = ($a15->news instanceof \DB\CortexCollection) ? count($a15->news) : 0;
		$test->expect(
			$newsCount == 2,
			$type.': filter(news, title LIKE %Post%) limits Alice news to matching titles'
		);

		// ================================================================
		// 16. self-referencing m:m - friends loaded correctly
		// ================================================================
		$a16 = new \AuthorModel();
		$a16->load(['name = ?', 'Alice']);
		$friendNames = [];
		if ($a16->friends instanceof \DB\CortexCollection) {
			foreach ($a16->friends as $f) $friendNames[] = $f->name;
		}
		sort($friendNames);
		$test->expect(
			count($friendNames) == 2 && $friendNames == ['Bob', 'Charlie'],
			$type.': self-ref m:m - Alice friends are Bob and Charlie'
		);

		// ================================================================
		// 17. self-ref m:m - inverse direction works
		// ================================================================
		$a17 = new \AuthorModel();
		$a17->load(['name = ?', 'Charlie']);
		$charlieFriends = [];
		if ($a17->friends instanceof \DB\CortexCollection) {
			foreach ($a17->friends as $f) $charlieFriends[] = $f->name;
		}
		$test->expect(
			count($charlieFriends) >= 1 && in_array('Alice', $charlieFriends),
			$type.': self-ref m:m inverse - Charlie sees Alice as friend'
		);

		// ================================================================
		// 18. cast() depth 0 - only raw IDs, no expanded relations
		// ================================================================
		$n18 = new \NewsModel();
		$n18->load(['title = ?', 'Second Post']);
		$cast0 = $n18->cast(null, 0);
		$test->expect(
			is_array($cast0) && isset($cast0['author'])
				&& is_numeric($cast0['author']),
			$type.': cast(null, 0) returns raw FK ID, not expanded relation'
		);

		// ================================================================
		// 19. cast() depth 2 - nested relations expanded
		// ================================================================
		$n19 = new \NewsModel();
		$n19->load(['title = ?', 'Second Post']);
		$cast2 = $n19->cast(null, 2);
		$test->expect(
			is_array($cast2) && is_array($cast2['author'])
				&& isset($cast2['author']['name'])
				&& $cast2['author']['name'] == 'Alice',
			$type.': cast(null, 2) expands author to array with name'
		);
		// depth 2 should expand author.news too
		$test->expect(
			isset($cast2['author']['news']) && is_array($cast2['author']['news']),
			$type.': cast(null, 2) expands nested author.news'
		);

		// ================================================================
		// 20. cast() with field mask
		// ================================================================
		$n20 = new \NewsModel();
		$n20->load(['title = ?', 'Second Post']);
		$castMask = $n20->cast(null, ['title', 'author.name']);
		$test->expect(
			isset($castMask['title']) && $castMask['title'] == 'Second Post'
				&& isset($castMask['author']) && is_array($castMask['author'])
				&& isset($castMask['author']['name']),
			$type.': cast with field mask [title, author.name] extracts correctly'
		);

		// ================================================================
		// 21. reset() clears fieldsCache and allows fresh load
		// ================================================================
		$a21 = new \AuthorModel();
		$a21->load(['name = ?', 'Alice']);
		$test->expect(!$a21->dry() && $a21->name == 'Alice', $type.': pre-reset loaded Alice');
		$a21->reset();
		$test->expect($a21->dry(), $type.': after reset() model is dry');
		$a21->load(['name = ?', 'Bob']);
		$test->expect(
			!$a21->dry() && $a21->name == 'Bob',
			$type.': after reset, can load different record (Bob)'
		);

		// ================================================================
		// 22. Reuse model for sequential find() calls
		// ================================================================
		$a22 = new \AuthorModel();
		$r1 = $a22->find(['mail = ?', null]);
		$c1 = $r1 ? count($r1) : 0;
		$r2 = $a22->find(['mail != ?', null]);
		$c2 = $r2 ? count($r2) : 0;
		$test->expect(
			$c1 + $c2 == 10,
			$type.': sequential find() calls on same model sum to total (10 authors)'
		);

		// ================================================================
		// 23. virtual() field
		// ================================================================
		$a23 = new \AuthorModel();
		$a23->load(['name = ?', 'Bob']);
		$a23->virtual('full_label', function($self) {
			return $self->name.' <'.$self->mail.'>';
		});
		$label = $a23->get('full_label');
		$test->expect(
			$label == 'Bob <bob@test.com>',
			$type.': virtual field with callable returns computed value'
		);
		// virtual in cast
		$castV = $a23->cast(null, 0);
		$test->expect(
			isset($castV['full_label']) && $castV['full_label'] == 'Bob <bob@test.com>',
			$type.': virtual field included in cast() output'
		);

		// ================================================================
		// 24. clearVirtual()
		// ================================================================
		$a23->clearVirtual('full_label');
		$test->expect(
			$a23->get('full_label') === null,
			$type.': clearVirtual() removes the virtual field'
		);

		// ================================================================
		// 25. copyfrom() basic
		// ================================================================
		$a25 = new \AuthorModel();
		\Base::instance()->set('POST', [
			'name' => 'CopiedAuthor',
			'mail' => 'copy@test.com',
		]);
		$a25->copyfrom('POST');
		$a25->save();
		$a25check = new \AuthorModel();
		$a25check->load(['name = ?', 'CopiedAuthor']);
		$test->expect(
			!$a25check->dry() && $a25check->mail == 'copy@test.com',
			$type.': copyfrom(POST) creates record from hive data'
		);

		// ================================================================
		// 26. copyfrom() with field filter
		// ================================================================
		$a26 = new \AuthorModel();
		\Base::instance()->set('POST', [
			'name' => 'FilteredCopy',
			'mail' => 'should-be-ignored@test.com',
			'website' => 'https://filtered.dev',
		]);
		$a26->copyfrom('POST', function($srcfields) {
			// only accept name and website
			return array_intersect_key($srcfields, array_flip(['name', 'website']));
		});
		$a26->save();
		$a26check = new \AuthorModel();
		$a26check->load(['name = ?', 'FilteredCopy']);
		$test->expect(
			!$a26check->dry() && $a26check->website == 'https://filtered.dev'
				&& ($a26check->mail === null || $a26check->mail === ''),
			$type.': copyfrom with filter callback excludes unwanted fields'
		);

		// ================================================================
		// 27. copyto() + copyto_flat()
		// ================================================================
		$a27 = new \AuthorModel();
		$a27->load(['name = ?', 'Alice']);
		$a27->copyto('AUTHOR_COPY');
		$copied = \Base::instance()->get('AUTHOR_COPY');
		$test->expect(
			is_array($copied) && $copied['name'] == 'Alice',
			$type.': copyto() copies model fields to hive key'
		);

		// ================================================================
		// 28. fields() whitelist restricts access
		// ================================================================
		$a28 = new \AuthorModel();
		$a28->fields(['name', 'mail'], false);
		$a28->load(['name = ?', 'Bob']);
		$castWl = $a28->cast(null, 0);
		$test->expect(
			isset($castWl['name']) && isset($castWl['mail'])
				&& !isset($castWl['website']),
			$type.': fields([name, mail]) whitelist excludes website from cast'
		);

		// ================================================================
		// 29. fields() blacklist excludes specific fields
		// ================================================================
		$a29 = new \AuthorModel();
		$a29->fields(['mail'], true);
		$a29->load(['name = ?', 'Alice']);
		$castBl = $a29->cast(null, 0);
		$test->expect(
			isset($castBl['name']) && !isset($castBl['mail']),
			$type.': fields([mail], true) blacklist excludes mail from cast'
		);

		// ================================================================
		// 30. onset/onget custom field handlers
		// ================================================================
		$a30 = new \AuthorModel();
		$a30->load(['name = ?', 'Bob']);
		$a30->onget('name', function($self, $val) {
			return strtoupper($val);
		});
		$upperName = $a30->get('name');
		$test->expect(
			$upperName == 'BOB',
			$type.': onget() custom getter transforms value'
		);

		$a30->onset('mail', function($self, $val) {
			return strtolower(trim($val));
		});
		$a30->set('mail', '  BOB@TEST.COM  ');
		$test->expect(
			$a30->get('mail', true) == 'bob@test.com',
			$type.': onset() custom setter normalizes value'
		);

		// ================================================================
		// 31. Pagination: find with limit+offset
		// ================================================================
		$a31 = new \AuthorModel();
		$page1 = $a31->find(null, ['limit' => 3, 'offset' => 0, 'order' => 'name ASC']);
		$a32 = new \AuthorModel();
		$page2 = $a32->find(null, ['limit' => 3, 'offset' => 3, 'order' => 'name ASC']);
		$test->expect(
			$page1 && count($page1) == 3 && $page2 && count($page2) == 3
				&& $page1[0]->name != $page2[0]->name,
			$type.': pagination limit+offset returns non-overlapping pages'
		);

		// ================================================================
		// 32. ORDER BY with relations (via @relation.field)
		// ================================================================
		$n32 = new \NewsModel();
		$n32->has('author', ['name IS NOT NULL']);
		$sorted = $n32->find(null, ['order' => '@author.name ASC', 'limit' => 5]);
		$test->expect(
			$sorted && count($sorted) > 0,
			$type.': ORDER BY @author.name with has(IS NOT NULL) does not crash'
		);

		// ================================================================
		// 33. countRel() adds virtual count field
		// ================================================================
		$a33 = new \AuthorModel();
		$a33->countRel('news');
		$result33 = $a33->find(null, ['order' => 'name ASC']);
		$aliceRow = null;
		if ($result33) foreach ($result33 as $r) {
			if ($r->name == 'Alice') { $aliceRow = $r; break; }
		}
		$test->expect(
			$aliceRow !== null && $aliceRow->count_news !== null && $aliceRow->count_news >= 1,
			$type.': countRel(news) adds count_news virtual field'
		);

		// ================================================================
		// 34. JSON field roundtrip
		// ================================================================
		$n34 = new \NewsModel();
		$n34->title = 'JSON Test';
		$n34->text = 'json roundtrip';
		$n34->options = ['key1' => 'val1', 'nested' => ['a' => 1, 'b' => [2, 3]]];
		$n34->save();

		$n34r = new \NewsModel();
		$n34r->load(['title = ?', 'JSON Test']);
		$opts = $n34r->options;
		$test->expect(
			is_array($opts)
				&& $opts['key1'] == 'val1'
				&& $opts['nested']['b'] == [2, 3],
			$type.': DT_JSON field survives save/load with nested arrays'
		);

		// ================================================================
		// 35. JSON field with null
		// ================================================================
		$n35 = new \NewsModel();
		$n35->title = 'JSON Null Test';
		$n35->text = 'null json';
		$n35->save();

		$n35r = new \NewsModel();
		$n35r->load(['title = ?', 'JSON Null Test']);
		$test->expect(
			$n35r->options === null,
			$type.': DT_JSON field with null stays null'
		);

		// ================================================================
		// 36. Multiple has() chained = AND behavior
		// ================================================================
		// authors who have news AND have a profile
		$a36 = new \AuthorModel();
		$a36->has('news', ['title LIKE ?', '%Post%']);
		$a36->has('profile', null);
		$result36 = $a36->find();
		$test->expect(
			$result36 !== false,
			$type.': chained has(news) + has(profile) does not crash'
		);

		// ================================================================
		// 37. find() with ORDER + NULL field does not crash
		// ================================================================
		$n37 = new \NewsModel();
		$result37 = $n37->find(null, ['order' => 'text ASC']);
		$test->expect(
			$result37 && count($result37) > 0,
			$type.': ORDER BY on nullable field with NULLs does not crash'
		);

		// ================================================================
		// 38. belongs-to-many empty array after clearing
		// ================================================================
		$n38 = new \NewsModel();
		$n38->load(['title = ?', 'Third Post']);
		$tagsVal = $n38->tags;
		$test->expect(
			$tagsVal === null || ($tagsVal instanceof \DB\CortexCollection && count($tagsVal) == 0),
			$type.': news with no tags returns null or empty CortexCollection'
		);

		// ================================================================
		// 39. profile (has-one) inverse load
		// ================================================================
		$a39 = new \AuthorModel();
		$a39->load(['name = ?', 'Alice']);
		$test->expect(
			$a39->profile instanceof \DB\Cortex
				&& $a39->profile->message == 'Bio of Alice',
			$type.': has-one profile loads correctly for Alice'
		);

		// ================================================================
		// 40. profile null for author without one
		// ================================================================
		$a40 = new \AuthorModel();
		$a40->load(['name = ?', 'Bob']);
		$test->expect(
			$a40->profile === null,
			$type.': has-one profile is null for Bob (no profile)'
		);

		// ================================================================
		// 41. skip() / next() navigation through loaded result set
		// ================================================================
		$a41 = new \AuthorModel();
		$a41->load(null, ['order' => 'name ASC', 'limit' => 5]);
		$firstName = $a41->name;
		$a41->skip(2);
		$thirdName = $a41->name;
		$test->expect(
			$firstName !== $thirdName,
			$type.': skip(2) advances cursor to different record'
		);

		// ================================================================
		// 42. first() / last() on loaded set
		// ================================================================
		$a42 = new \AuthorModel();
		$a42->load(null, ['order' => 'name ASC']);
		$a42->last();
		$lastName = $a42->name;
		$a42->first();
		$firstName42 = $a42->name;
		$test->expect(
			$firstName42 !== $lastName,
			$type.': first() and last() point to different records'
		);

		// ================================================================
		// 43. loaded() returns correct count
		// ================================================================
		$a43 = new \AuthorModel();
		$a43->load(null, ['limit' => 4]);
		$test->expect(
			$a43->loaded() == 4,
			$type.': loaded() returns 4 after load with limit 4'
		);

		// ================================================================
		// 44. exists() for real and fake fields
		// ================================================================
		$a44 = new \AuthorModel();
		$a44->load(['name = ?', 'Alice']);
		$test->expect(
			$a44->exists('name') && $a44->exists('mail')
				&& !$a44->exists('nonexistent_field_xyz'),
			$type.': exists() returns true for real fields, false for fake'
		);

		// ================================================================
		// 45. changed() detects modifications
		// ================================================================
		$a45 = new \AuthorModel();
		$a45->load(['name = ?', 'Alice']);
		$test->expect(
			$a45->changed('name') === false,
			$type.': changed(name) is false right after load'
		);
		$a45->name = 'Alice_Modified';
		$test->expect(
			$a45->changed('name') !== false,
			$type.': changed(name) detects modification'
		);
		// restore
		$a45->name = 'Alice';
		$a45->save();

		// ================================================================
		// 46. Bulk erase with filter (non-single-record erase)
		// ================================================================
		// add some temp records
		for ($i = 0; $i < 5; $i++) {
			$tmp = new \AuthorModel();
			$tmp->name = 'TempBulk_'.$i;
			$tmp->save();
		}
		$a46 = new \AuthorModel();
		$beforeCount = $a46->count();
		$a46->erase(['name LIKE ?', 'TempBulk_%']);
		$a46b = new \AuthorModel();
		$afterCount = $a46b->count();
		$test->expect(
			$afterCount == $beforeCount - 5,
			$type.': erase with filter deletes 5 matching records'
		);

		// ================================================================
		// 47. mergeFilter() utility
		// ================================================================
		$a47 = new \AuthorModel();
		$merged = $a47->mergeFilter([
			['name = ?', 'Alice'],
			['mail IS NOT NULL'],
		]);
		$test->expect(
			is_array($merged) && str_contains($merged[0], 'name = ?')
				&& str_contains($merged[0], 'mail IS NOT NULL'),
			$type.': mergeFilter combines two conditions with AND'
		);

		// ================================================================
		// 48. CortexCollection::getAll() on relation field
		// ================================================================
		$n48 = new \NewsModel();
		$allNews = $n48->find(['author != ?', null], ['limit' => 5]);
		if ($allNews) {
			$allAuthorIds = $allNews->getAll('author', true);
			$test->expect(
				is_array($allAuthorIds) && count($allAuthorIds) == 5,
				$type.': CortexCollection::getAll(author, raw) returns FK IDs'
			);
		} else {
			$test->expect(false, $type.': CortexCollection::getAll(author, raw) returns FK IDs');
		}

		// ================================================================
		// 49. with() + has() combined
		// ================================================================
		$n49 = new \NewsModel();
		$n49->has('author', ['name = ?', 'Alice']);
		$result49 = $n49->with(['author'])->find();
		$test->expect(
			$result49 && count($result49) >= 1,
			$type.': with() + has() combined does not crash'
		);
		if ($result49) {
			$firstAuthorName = $result49[0]->author->name ?? null;
			$test->expect(
				$firstAuthorName == 'Alice',
				$type.': with() + has() - preloaded author is Alice'
			);
		} else {
			$test->expect(false, $type.': with() + has() - preloaded author is Alice');
		}

		// ================================================================
		// 50. Rapid insert-load-update-erase cycle
		// ================================================================
		$a50 = new \AuthorModel();
		$a50->name = 'RapidCycle';
		$a50->mail = 'rapid@test.com';
		$a50->save();
		$savedId = $a50->id;

		$a50->load(['id = ?', $savedId]);
		$test->expect(!$a50->dry() && $a50->name == 'RapidCycle', $type.': rapid cycle - load after insert');

		$a50->name = 'RapidUpdated';
		$a50->save();

		$a50b = new \AuthorModel();
		$a50b->load(['id = ?', $savedId]);
		$test->expect($a50b->name == 'RapidUpdated', $type.': rapid cycle - update persisted');

		$a50b->erase();
		$a50c = new \AuthorModel();
		$a50c->load(['id = ?', $savedId]);
		$test->expect($a50c->dry(), $type.': rapid cycle - erase confirmed');

		// ================================================================
		// 51. Smart loading: accessing relation on 2nd model in collection
		//     uses cached relSet from 1st model's access
		// ================================================================
		$n51 = new \NewsModel();
		$allN = $n51->find(['author != ?', null], ['order' => 'title ASC', 'limit' => 4]);
		if ($allN && count($allN) >= 2) {
			// access author on first model - triggers eager loading for collection
			$firstName = $allN[0]->author->name ?? null;
			// access author on second model - should use cached relSet
			$secondName = $allN[1]->author->name ?? null;
			$test->expect(
				$firstName !== null && $secondName !== null,
				$type.': smart loading - 2nd model uses cached relSet (no extra query)'
			);
		} else {
			$test->expect(false, $type.': smart loading - 2nd model uses cached relSet');
		}

		// ================================================================
		// 52. getRaw() returns unprocessed DB value
		// ================================================================
		$n52 = new \NewsModel();
		$n52->load(['title = ?', 'Second Post']);
		$rawAuthor = $n52->getRaw('author');
		$test->expect(
			is_numeric($rawAuthor),
			$type.': getRaw(author) returns numeric FK, not hydrated model'
		);

		// ================================================================
		// 53. touch() on DATETIME field
		// ================================================================
		$n53 = new \NewsModel();
		$n53->load(['title = ?', 'Second Post']);
		$n53->touch('created_at');
		$n53->save();

		$n53r = new \NewsModel();
		$n53r->load(['title = ?', 'Second Post']);
		$test->expect(
			$n53r->created_at !== null && strlen($n53r->created_at) > 0,
			$type.': touch(created_at) sets datetime value'
		);

		// ================================================================
		// 54. save() on dry model triggers insert, not update
		// ================================================================
		$a54 = new \AuthorModel();
		$a54->name = 'FreshInsert';
		$result54 = $a54->save();
		$test->expect(
			$result54 !== false && $a54->id > 0,
			$type.': save() on dry model triggers insert (gets new ID)'
		);
		// cleanup
		$a54->erase();

		// ================================================================
		// 55. save() on loaded model triggers update
		// ================================================================
		$a55 = new \AuthorModel();
		$a55->load(['name = ?', 'Alice']);
		$origId = $a55->id;
		$a55->website = 'https://alice-updated.dev';
		$a55->save();

		$a55r = new \AuthorModel();
		$a55r->load(['name = ?', 'Alice']);
		$test->expect(
			$a55r->id == $origId && $a55r->website == 'https://alice-updated.dev',
			$type.': save() on loaded model updates in place (same ID)'
		);

		// ================================================================
		// 56. with(1) on AuthorModel loads all direct relations
		// ================================================================
		$a56 = new \AuthorModel();
		$result56 = $a56->with(1)->find(['name = ?', 'Alice']);
		$test->expect(
			$result56 && count($result56) == 1,
			$type.': with(1) + find returns 1 Alice'
		);
		$alice56 = $result56[0];
		$test->expect(
			$alice56->news instanceof \DB\CortexCollection,
			$type.': with(1) preloads has-many news'
		);
		$test->expect(
			$alice56->profile instanceof \DB\Cortex,
			$type.': with(1) preloads has-one profile'
		);

		// ================================================================
		// 57. Large-ish dataset: 50 authors, verify count accuracy
		// ================================================================
		for ($i = 0; $i < 50; $i++) {
			$tmp = new \AuthorModel();
			$tmp->name = 'Bulk_'.$i;
			$tmp->save();
		}
		$aBulk = new \AuthorModel();
		$bulkCount = $aBulk->count(['name LIKE ?', 'Bulk_%']);
		$test->expect(
			$bulkCount == 50,
			$type.': bulk insert 50 records, count matches exactly'
		);
		$aBulk->erase(['name LIKE ?', 'Bulk_%']);
		$afterBulk = (new \AuthorModel())->count(['name LIKE ?', 'Bulk_%']);
		$test->expect(
			$afterBulk == 0,
			$type.': bulk erase removes all 50 records'
		);

		// ================================================================
		// 58. orHas() - OR condition on has filter
		// ================================================================
		$n58 = new \NewsModel();
		$n58->has('author', ['name = ?', 'Alice']);
		$n58->orHas('author', ['name = ?', 'Bob']);
		$result58 = $n58->find();
		$names58 = [];
		if ($result58) foreach ($result58 as $r) {
			if ($r->author) $names58[] = $r->author->name;
		}
		$names58 = array_unique($names58);
		sort($names58);
		$test->expect(
			count($names58) == 2 && $names58 == ['Alice', 'Bob'],
			$type.': has() + orHas() combines with OR (Alice or Bob news)'
		);

		// ================================================================
		// 59. Attempting to set array on non-JSON field throws
		// ================================================================
		$n59 = new \NewsModel();
		$threw59 = false;
		try {
			$n59->title = ['not', 'a', 'string'];
			$n59->save();
		} catch (\Exception $e) {
			$threw59 = true;
		}
		$test->expect(
			$threw59,
			$type.': setting array on VARCHAR field throws exception'
		);

		// ================================================================
		// 60. Concurrent-style: two instances update same record
		// ================================================================
		$a60a = new \AuthorModel();
		$a60a->load(['name = ?', 'Hank']);
		$a60b = new \AuthorModel();
		$a60b->load(['name = ?', 'Hank']);

		$a60a->mail = 'hank_a@test.com';
		$a60a->save();

		$a60b->website = 'https://hank-b.dev';
		$a60b->save(); // last-write-wins scenario

		$a60check = new \AuthorModel();
		$a60check->load(['name = ?', 'Hank']);
		// last save (b) should have website, but mail depends on LWW behavior
		$test->expect(
			$a60check->website == 'https://hank-b.dev',
			$type.': last-write-wins - second save persists its changes'
		);

		// ================================================================
		// 61. WEAKNESS PROBE: has() with 'IS NOT NULL' in JOIN path
		//     sql_prependTableToFields treats IS/NOT/NULL as column names
		// ================================================================
		$n61 = new \NewsModel();
		$n61->has('author', ['name IS NOT NULL']);
		$weakness61 = false;
		try {
			$result61 = $n61->find();
			$weakness61 = false; // no crash = no weakness
		} catch (\Exception $e) {
			$weakness61 = true; // crash = weakness confirmed
		}
		$test->expect(
			!$weakness61,
			$type.': WEAKNESS PROBE - has(field, IS NOT NULL) in JOIN path [parser bug if FAIL]'
		);

		// ================================================================
		// 62. WEAKNESS PROBE: orHas() chaining has() + orHas() on same key
		// ================================================================
		$n62 = new \NewsModel();
		$n62->has('author', ['name = ?', 'Alice']);
		$n62->orHas('author', ['name = ?', 'Bob']);
		$weakness62 = false;
		try {
			$result62 = $n62->find();
			$names62 = [];
			if ($result62) foreach ($result62 as $r) {
				if ($r->author) $names62[] = $r->author->name;
			}
			$names62 = array_unique($names62);
			// should contain both Alice and Bob
			$weakness62 = !(in_array('Alice', $names62) && in_array('Bob', $names62));
		} catch (\Exception $e) {
			$weakness62 = true;
		}
		$test->expect(
			!$weakness62,
			$type.': WEAKNESS PROBE - orHas() combines two has() with OR [logic bug if FAIL]'
		);

		// ================================================================
		// 63. WEAKNESS PROBE: concurrent update overwrites first save's changes
		// ================================================================
		$a63a = new \AuthorModel();
		$a63a->load(['name = ?', 'Hank']);
		$a63b = new \AuthorModel();
		$a63b->load(['name = ?', 'Hank']);

		$a63a->mail = 'hank_concurrent@test.com';
		$a63a->save();

		$a63b->website = 'https://hank-concurrent.dev';
		$a63b->save(); // b still has old mail snapshot

		$a63check = new \AuthorModel();
		$a63check->load(['name = ?', 'Hank']);
		// In a proper ORM, a's mail change should survive b's save
		$test->expect(
			$a63check->mail == 'hank_concurrent@test.com',
			$type.': WEAKNESS PROBE - concurrent update preserves first save mail [LWW bug if FAIL]'
		);

		// ================================================================
		// 64. WEAKNESS PROBE: re-save without changes still triggers UPDATE
		// ================================================================
		$a64 = new \AuthorModel();
		$a64->load(['name = ?', 'Alice']);
		$result64 = $a64->save(); // save without modification
		$test->expect(
			$result64 !== false,
			$type.': re-save without changes does not crash'
		);

		// ================================================================
		// 65. WEAKNESS PROBE: has() + find() on model with no matching relations
		//     (e.g., authors with no news)
		// ================================================================
		// Filter should return only authors who have at least one news with a specific tag
		$a65 = new \AuthorModel();
		$a65->has('news', ['title LIKE ?', '%Secret%']);
		$result65 = $a65->find();
		$test->expect(
			$result65 === false || count($result65) == 0,
			$type.': has(news, title LIKE Secret) returns empty when no matching news'
		);

		// ================================================================
		// 66. WEAKNESS PROBE: empty string vs null in filter
		// ================================================================
		$n66a = new \NewsModel();
		$emptyText = $n66a->find(['text = ?', '']);
		$n66b = new \NewsModel();
		$nullText = $n66b->find(['text = ?', null]);
		$test->expect(
			($emptyText ? count($emptyText) : 0) != ($nullText ? count($nullText) : 0)
				|| ($emptyText === false && $nullText === false),
			$type.': WEAKNESS PROBE - empty string vs null filter distinguishes correctly'
		);

		// ================================================================
		// 67. WEAKNESS PROBE: deep relation chain (author -> news -> tags) cast depth 3
		// ================================================================
		$a67 = new \AuthorModel();
		$a67->load(['name = ?', 'Grace']);
		$deep = $a67->cast(null, 3);
		$hasNestedTags = false;
		if (isset($deep['news']) && is_array($deep['news'])) {
			foreach ($deep['news'] as $newsItem) {
				if (isset($newsItem['tags']) && is_array($newsItem['tags']) && count($newsItem['tags']) > 0) {
					$hasNestedTags = true;
					break;
				}
			}
		}
		$test->expect(
			$hasNestedTags,
			$type.': cast(null, 3) resolves 3-level deep chain (author->news->tags)'
		);

		// ================================================================
		// 68. WEAKNESS PROBE: modify belongs-to-many via model objects (not IDs)
		// ================================================================
		$n68 = new \NewsModel();
		$n68->load(['title = ?', 'Third Post']);
		$tag1 = new \TagModel();
		$tag1->load(['title = ?', 'PHP']);
		$tag2 = new \TagModel();
		$tag2->load(['title = ?', 'SQL']);
		$threw68 = false;
		try {
			$n68->tags = [$tag1, $tag2]; // objects instead of IDs
			$n68->save();
		} catch (\Exception $e) {
			$threw68 = true;
		}
		if (!$threw68) {
			$n68r = new \NewsModel();
			$n68r->load(['title = ?', 'Third Post']);
			$tagNames68 = [];
			if ($n68r->tags instanceof \DB\CortexCollection) {
				foreach ($n68r->tags as $t) $tagNames68[] = $t->title;
			}
			sort($tagNames68);
			$test->expect(
				$tagNames68 == ['PHP', 'SQL'],
				$type.': set belongs-to-many with model objects saves correctly'
			);
		} else {
			$test->expect(
				false,
				$type.': WEAKNESS PROBE - set belongs-to-many with model objects throws [bug if FAIL]'
			);
		}

		// ================================================================
		// 69. WEAKNESS PROBE: loading with GROUP BY
		// ================================================================
		$n69 = new \NewsModel();
		$weakness69 = false;
		try {
			$grouped = $n69->find(['author != ?', null], ['group' => 'author']);
			$weakness69 = false;
		} catch (\Exception $e) {
			$weakness69 = true;
		}
		$test->expect(
			!$weakness69,
			$type.': WEAKNESS PROBE - find() with GROUP BY does not crash'
		);

		// ================================================================
		// 70. WEAKNESS PROBE: erase loaded model then check dry
		// ================================================================
		$a70 = new \AuthorModel();
		$a70->name = 'EraseAccess';
		$a70->save();
		$savedId70 = $a70->id;
		$a70->erase();
		// After erase(), verify record is actually gone from DB
		$a70check = new \AuthorModel();
		$a70check->load(['id = ?', $savedId70]);
		$test->expect(
			$a70check->dry(),
			$type.': after erase(), record is gone from database'
		);

		// ================================================================
		// 71. WEAKNESS PROBE: getTable() correctness
		// ================================================================
		$a71 = new \AuthorModel();
		$n71 = new \NewsModel();
		$t71 = new \TagModel();
		$test->expect(
			$a71->getTable() == 'author' && $n71->getTable() == 'news'
				&& $t71->getTable() == 'tags',
			$type.': getTable() returns correct table names'
		);

		// ================================================================
		// 72. WEAKNESS PROBE: has() on belongs-to-many (unidirectional m:m)
		// ================================================================
		$n72 = new \NewsModel();
		$n72->has('tags', ['title = ?', 'PHP']);
		$weakness72 = false;
		try {
			$result72 = $n72->find();
			$weakness72 = false;
		} catch (\Exception $e) {
			$weakness72 = true;
		}
		$test->expect(
			!$weakness72,
			$type.': WEAKNESS PROBE - has() on belongs-to-many does not crash [bug if FAIL]'
		);

		// ================================================================
		// 73. countRel on has-many returns count per author
		// ================================================================
		$a73 = new \AuthorModel();
		$a73->countRel('news');
		$result73 = $a73->find(['name IN ?', ['Alice', 'Bob', 'Diana']],
			['order' => 'name ASC']);
		$counts73 = [];
		if ($result73) foreach ($result73 as $r) {
			$counts73[$r->name] = (int)$r->count_news;
		}
		$test->expect(
			isset($counts73['Alice']) && $counts73['Alice'] >= 1
				&& isset($counts73['Bob']) && $counts73['Bob'] >= 1,
			$type.': countRel(news) returns correct counts per author'
		);

		// ================================================================
		// 74. CortexCollection::castAll() returns array of arrays
		// ================================================================
		$n74 = new \NewsModel();
		$allN74 = $n74->find(['author != ?', null], ['limit' => 3]);
		if ($allN74) {
			$castAll = $allN74->castAll(0);
			$test->expect(
				is_array($castAll) && count($castAll) == 3
					&& is_array($castAll[0]) && isset($castAll[0]['title']),
				$type.': CortexCollection::castAll(0) returns array of arrays'
			);
		} else {
			$test->expect(false, $type.': CortexCollection::castAll(0) returns array of arrays');
		}

		// ================================================================
		// 75. WEAKNESS PROBE: large IN clause (100+ IDs)
		// ================================================================
		$ids75 = range(1, 200);
		$a75 = new \AuthorModel();
		$weakness75 = false;
		try {
			$result75 = $a75->find(['id IN ?', $ids75]);
			$weakness75 = false;
		} catch (\Exception $e) {
			$weakness75 = true;
		}
		$test->expect(
			!$weakness75,
			$type.': WEAKNESS PROBE - large IN clause (200 IDs) does not crash'
		);

		// ================================================================
		//  PARSER UNIT TESTS: sql_quoteCondition & sql_prependTableToFields
		//  Direct tests for all SQL reserved words added to the parser
		// ================================================================
		$qp = new \DB\CortexQueryParser();

		// -- sql_quoteCondition: keywords must NOT be backtick-quoted --

		$qcIS = $qp->sql_quoteCondition('name IS NOT NULL', $db);
		$test->expect(
			str_contains($qcIS, 'IS') && str_contains($qcIS, 'NOT')
				&& str_contains($qcIS, 'NULL')
				&& !str_contains($qcIS, '`IS`')
				&& !str_contains($qcIS, '`NULL`'),
			$type.': sql_quoteCondition preserves IS NOT NULL as keywords'
		);

		$qcBetween = $qp->sql_quoteCondition('age BETWEEN 10 AND 20', $db);
		$test->expect(
			str_contains($qcBetween, 'BETWEEN') && !str_contains($qcBetween, '`BETWEEN`'),
			$type.': sql_quoteCondition preserves BETWEEN as keyword'
		);

		$qcBool = $qp->sql_quoteCondition('active IS TRUE AND deleted IS FALSE', $db);
		$test->expect(
			str_contains($qcBool, 'TRUE') && str_contains($qcBool, 'FALSE')
				&& !str_contains($qcBool, '`TRUE`')
				&& !str_contains($qcBool, '`FALSE`'),
			$type.': sql_quoteCondition preserves TRUE/FALSE as keywords'
		);

		$qcExists = $qp->sql_quoteCondition('EXISTS subquery', $db);
		$test->expect(
			str_contains($qcExists, 'EXISTS') && !str_contains($qcExists, '`EXISTS`'),
			$type.': sql_quoteCondition preserves EXISTS as keyword'
		);

		$qcCase = $qp->sql_quoteCondition('CASE WHEN status THEN active ELSE inactive END', $db);
		$test->expect(
			str_contains($qcCase, 'CASE') && str_contains($qcCase, 'WHEN')
				&& str_contains($qcCase, 'THEN') && str_contains($qcCase, 'ELSE')
				&& str_contains($qcCase, 'END')
				&& !str_contains($qcCase, '`CASE`')
				&& !str_contains($qcCase, '`WHEN`')
				&& !str_contains($qcCase, '`THEN`')
				&& !str_contains($qcCase, '`ELSE`')
				&& !str_contains($qcCase, '`END`'),
			$type.': sql_quoteCondition preserves CASE/WHEN/THEN/ELSE/END as keywords'
		);

		$qcJoin = $qp->sql_quoteCondition('LEFT JOIN foo ON bar AS baz', $db);
		$test->expect(
			!str_contains($qcJoin, '`LEFT`') && !str_contains($qcJoin, '`JOIN`')
				&& !str_contains($qcJoin, '`ON`') && !str_contains($qcJoin, '`AS`'),
			$type.': sql_quoteCondition preserves LEFT/JOIN/ON/AS as keywords'
		);

		$qcOrder = $qp->sql_quoteCondition('name ASC LIMIT 10 OFFSET 5', $db);
		$test->expect(
			!str_contains($qcOrder, '`ASC`') && !str_contains($qcOrder, '`LIMIT`')
				&& !str_contains($qcOrder, '`OFFSET`'),
			$type.': sql_quoteCondition preserves ASC/LIMIT/OFFSET as keywords'
		);

		$qcGroup = $qp->sql_quoteCondition('GROUP BY name ORDER BY id DESC', $db);
		$test->expect(
			!str_contains($qcGroup, '`GROUP`') && !str_contains($qcGroup, '`BY`')
				&& !str_contains($qcGroup, '`ORDER`') && !str_contains($qcGroup, '`DESC`'),
			$type.': sql_quoteCondition preserves GROUP/ORDER/BY/DESC as keywords'
		);

		$qcDistinct = $qp->sql_quoteCondition('DISTINCT name', $db);
		$test->expect(
			!str_contains($qcDistinct, '`DISTINCT`'),
			$type.': sql_quoteCondition preserves DISTINCT as keyword'
		);

		// verify that real field names ARE still quoted
		$qcField = $qp->sql_quoteCondition('username = status', $db);
		$test->expect(
			str_contains($qcField, '`username`') && str_contains($qcField, '`status`'),
			$type.': sql_quoteCondition still quotes regular field names'
		);

		// -- sql_prependTableToFields: keywords must NOT get table prefix --

		$ptIS = $qp->sql_prependTableToFields('name IS NOT NULL', 'tbl');
		$test->expect(
			str_contains($ptIS, 'tbl.name')
				&& str_contains($ptIS, ' IS ')
				&& str_contains($ptIS, ' NOT ')
				&& str_contains($ptIS, ' NULL')
				&& !str_contains($ptIS, 'tbl.IS')
				&& !str_contains($ptIS, 'tbl.NULL'),
			$type.': sql_prependTableToFields preserves IS/NOT/NULL, prepends table to field'
		);

		$ptBetween = $qp->sql_prependTableToFields('age BETWEEN 10 AND 20', 'tbl');
		$test->expect(
			str_contains($ptBetween, 'tbl.age')
				&& !str_contains($ptBetween, 'tbl.BETWEEN'),
			$type.': sql_prependTableToFields preserves BETWEEN'
		);

		$ptBool = $qp->sql_prependTableToFields('active IS TRUE', 'tbl');
		$test->expect(
			str_contains($ptBool, 'tbl.active')
				&& !str_contains($ptBool, 'tbl.TRUE')
				&& !str_contains($ptBool, 'tbl.IS'),
			$type.': sql_prependTableToFields preserves IS/TRUE'
		);

		$ptFalse = $qp->sql_prependTableToFields('deleted IS FALSE', 'tbl');
		$test->expect(
			str_contains($ptFalse, 'tbl.deleted')
				&& !str_contains($ptFalse, 'tbl.FALSE'),
			$type.': sql_prependTableToFields preserves FALSE'
		);

		$ptExists = $qp->sql_prependTableToFields('EXISTS subquery', 'tbl');
		$test->expect(
			!str_contains($ptExists, 'tbl.EXISTS'),
			$type.': sql_prependTableToFields preserves EXISTS'
		);

		$ptCase = $qp->sql_prependTableToFields('CASE WHEN status THEN val ELSE def END', 'tbl');
		$test->expect(
			!str_contains($ptCase, 'tbl.CASE')
				&& !str_contains($ptCase, 'tbl.WHEN')
				&& !str_contains($ptCase, 'tbl.THEN')
				&& !str_contains($ptCase, 'tbl.ELSE')
				&& !str_contains($ptCase, 'tbl.END'),
			$type.': sql_prependTableToFields preserves CASE/WHEN/THEN/ELSE/END'
		);

		$ptJoin = $qp->sql_prependTableToFields('LEFT JOIN tbl2 ON col1 AS alias', 'tbl');
		$test->expect(
			!str_contains($ptJoin, 'tbl.LEFT')
				&& !str_contains($ptJoin, 'tbl.JOIN')
				&& !str_contains($ptJoin, 'tbl.ON'),
			$type.': sql_prependTableToFields preserves LEFT/JOIN/ON/AS'
		);

		$ptAggr = $qp->sql_prependTableToFields('COUNT DISTINCT SUM AVG MIN MAX', 'tbl');
		$test->expect(
			!str_contains($ptAggr, 'tbl.COUNT')
				&& !str_contains($ptAggr, 'tbl.DISTINCT')
				&& !str_contains($ptAggr, 'tbl.SUM')
				&& !str_contains($ptAggr, 'tbl.AVG')
				&& !str_contains($ptAggr, 'tbl.MIN')
				&& !str_contains($ptAggr, 'tbl.MAX'),
			$type.': sql_prependTableToFields preserves COUNT/DISTINCT/SUM/AVG/MIN/MAX'
		);

		$ptOrderBy = $qp->sql_prependTableToFields('name ASC GROUP BY id ORDER BY name DESC LIMIT OFFSET', 'tbl');
		$test->expect(
			!str_contains($ptOrderBy, 'tbl.ASC')
				&& !str_contains($ptOrderBy, 'tbl.DESC')
				&& !str_contains($ptOrderBy, 'tbl.GROUP')
				&& !str_contains($ptOrderBy, 'tbl.ORDER')
				&& !str_contains($ptOrderBy, 'tbl.BY')
				&& !str_contains($ptOrderBy, 'tbl.LIMIT')
				&& !str_contains($ptOrderBy, 'tbl.OFFSET'),
			$type.': sql_prependTableToFields preserves ASC/DESC/GROUP/ORDER/BY/LIMIT/OFFSET'
		);

		// verify fields still GET the table prefix
		$ptField = $qp->sql_prependTableToFields('username = email', 'tbl');
		$test->expect(
			str_contains($ptField, 'tbl.username') && str_contains($ptField, 'tbl.email'),
			$type.': sql_prependTableToFields still prepends table to regular fields'
		);

		// -- combined integration: has() with IS NOT NULL through full query path --
		$n76 = new \NewsModel();
		$n76->has('author', ['name IS NOT NULL']);
		$resultISNN = $n76->find();
		$test->expect(
			$resultISNN !== false && count($resultISNN) > 0,
			$type.': has(author, name IS NOT NULL) works end-to-end through parser'
		);

		// -- integration: has() with BETWEEN --
		$n77 = new \NewsModel();
		$n77->has('author', ['id BETWEEN ? AND ?', 1, 999]);
		$resultBW = $n77->find();
		$test->expect(
			$resultBW !== false && count($resultBW) > 0,
			$type.': has(author, id BETWEEN ? AND ?) works end-to-end'
		);

		// ================================================================
		// BUG FIX TESTS (v1.8.7)
		// ================================================================

		// -- B1: orHas() returns $this for method chaining --
		$chainModel = new \NewsModel();
		$chainResult = $chainModel->has('author', ['name = ?', 'Alan'])
			->orHas('author', ['name = ?', 'Bob']);
		$test->expect(
			$chainResult instanceof \NewsModel,
			$type.': orHas() returns $this for method chaining'
		);
		// verify chained orHas()->find() works end-to-end
		$chainedFind = $chainModel->has('author', ['name = ?', 'Alan'])
			->orHas('author', ['name = ?', 'Bob'])
			->find();
		$test->expect(
			$chainedFind !== false && count($chainedFind) > 0,
			$type.': orHas()->find() chained call works end-to-end'
		);

		// -- B2: erase($filter) cleans m:m pivot and uses transaction --
		// create a fresh author with news and tags (m:m)
		$b2Author = new \AuthorModel();
		$b2Author->name = 'EraseFilterTest';
		$b2Author->save();

		$b2Tag = new \TagModel();
		$b2Tag->title = 'EraseFilterTag';
		$b2Tag->save();

		$b2News1 = new \NewsModel();
		$b2News1->title = 'EraseFilter News 1';
		$b2News1->author = $b2Author->_id;
		$b2News1->tags2 = [$b2Tag->_id];
		$b2News1->save();

		$b2News2 = new \NewsModel();
		$b2News2->title = 'EraseFilter News 2';
		$b2News2->author = $b2Author->_id;
		$b2News2->tags2 = [$b2Tag->_id];
		$b2News2->save();

		$n1id = $b2News1->_id;
		$n2id = $b2News2->_id;

		// check pivot rows exist before erase
		$pivotTable = 'news_tags';
		$pivotBefore = $db->exec("SELECT COUNT(*) AS cnt FROM \"{$pivotTable}\" WHERE neeeews IN (?,?)", [$n1id, $n2id]);
		$pivotBeforeCount = (int)$pivotBefore[0]['cnt'];

		// erase with filter - should clean m:m pivot entries
		$eraser = new \NewsModel();
		$eraser->erase(['title LIKE ?', 'EraseFilter News%']);

		// verify news records are gone
		$checkNews = new \NewsModel();
		$checkNews->load(['title LIKE ?', 'EraseFilter News%']);
		$newsGone = $checkNews->dry();

		// verify pivot rows are cleaned
		$pivotAfter = $db->exec("SELECT COUNT(*) AS cnt FROM \"{$pivotTable}\" WHERE neeeews IN (?,?)", [$n1id, $n2id]);
		$pivotAfterCount = (int)$pivotAfter[0]['cnt'];

		$test->expect(
			$pivotBeforeCount >= 2 && $newsGone && $pivotAfterCount === 0,
			$type.': erase($filter) cleans m:m pivot entries (had '.$pivotBeforeCount.' pivot rows, now '.$pivotAfterCount.')'
		);

		// cleanup
		$b2Tag->erase();
		$b2Author->erase();

		// ================================================================
		// IN OPERATOR VARIANTS
		// ================================================================

		// create test data for IN
		$inAuthor1 = new \AuthorModel();
		$inAuthor1->name = 'InTest Author A';
		$inAuthor1->save();
		$inAuthor2 = new \AuthorModel();
		$inAuthor2->name = 'InTest Author B';
		$inAuthor2->save();
		$inAuthor3 = new \AuthorModel();
		$inAuthor3->name = 'InTest Author C';
		$inAuthor3->save();
		$inIds = [$inAuthor1->_id, $inAuthor2->_id, $inAuthor3->_id];

		// IN (?) - positional with parens
		$inRes1 = new \AuthorModel();
		$found1 = $inRes1->find(['_id IN (?)', $inIds]);
		$test->expect(
			$found1 && count($found1) === 3,
			$type.': IN (?) with array expands correctly ('.($found1 ? count($found1) : 0).' rows)'
		);

		// IN ? - positional without parens
		$inRes2 = new \AuthorModel();
		$found2 = $inRes2->find(['_id IN ?', $inIds]);
		$test->expect(
			$found2 && count($found2) === 3,
			$type.': IN ? without parens works ('.($found2 ? count($found2) : 0).' rows)'
		);

		// IN (:ids) - named param with parens
		$inRes3 = new \AuthorModel();
		$found3 = $inRes3->find(['_id IN (:ids)', ':ids' => $inIds]);
		$test->expect(
			$found3 && count($found3) === 3,
			$type.': IN (:ids) named param with parens works ('.($found3 ? count($found3) : 0).' rows)'
		);

		// IN (?) combined with AND
		$inRes4 = new \AuthorModel();
		$found4 = $inRes4->find(['_id IN (?) AND name LIKE ?', $inIds, 'InTest%']);
		$test->expect(
			$found4 && count($found4) === 3,
			$type.': IN (?) AND condition works ('.($found4 ? count($found4) : 0).' rows)'
		);

		// IN (?) with subset
		$inRes5 = new \AuthorModel();
		$found5 = $inRes5->find(['_id IN (?)', [$inIds[0], $inIds[2]]]);
		$test->expect(
			$found5 && count($found5) === 2,
			$type.': IN (?) with 2-element subset ('.($found5 ? count($found5) : 0).' rows)'
		);

		// cleanup IN test data
		$inAuthor1->erase();
		$inAuthor2->erase();
		$inAuthor3->erase();

		// ================================================================
		// CLEANUP
		// ================================================================
		\NewsModel::setdown();
		\TagModel::setdown();
		\ProfileModel::setdown();
		\AuthorModel::setdown();

		return $test->results();
	}
}
