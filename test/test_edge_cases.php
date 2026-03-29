<?php

/**
 * Edge-case tests for Cortex-Atomic.
 * Covers: NULL filter handling, count() TTL, empty-string vs NULL,
 * nullable FK relations, compound filters, named params, diverse data.
 */
class Test_EdgeCases {

	/**
	 * Seed diverse data covering NULLs, empty strings, zeroes, missing FKs.
	 * Returns IDs keyed by name for easy reference in assertions.
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

		// --- Authors (6) ---
		// Alice: all fields populated
		$a1 = new \AuthorModel();
		$a1->name = 'Alice';
		$a1->mail = 'alice@test.com';
		$a1->website = 'https://alice.dev';
		$a1->save();

		// Bob: mail is NULL
		$a2 = new \AuthorModel();
		$a2->name = 'Bob';
		$a2->website = 'https://bob.dev';
		$a2->save();

		// Charlie: mail is empty string, website is NULL
		$a3 = new \AuthorModel();
		$a3->name = 'Charlie';
		$a3->mail = '';
		$a3->save();

		// Diana: all fields populated
		$a4 = new \AuthorModel();
		$a4->name = 'Diana';
		$a4->mail = 'diana@test.com';
		$a4->website = 'https://diana.dev';
		$a4->save();

		// Eve: mail and website both NULL
		$a5 = new \AuthorModel();
		$a5->name = 'Eve';
		$a5->save();

		// Frank: website is empty string
		$a6 = new \AuthorModel();
		$a6->name = 'Frank';
		$a6->mail = 'frank@test.com';
		$a6->website = '';
		$a6->save();

		// --- Profiles (4) ---
		$p1 = new \ProfileModel();
		$p1->message = 'Hello from Alice';
		$p1->author = $a1->id;
		$p1->save();

		$p2 = new \ProfileModel();
		// message left NULL
		$p2->author = $a2->id;
		$p2->save();

		$p3 = new \ProfileModel();
		$p3->message = 'Diana here';
		$p3->author = $a4->id;
		$p3->save();

		$p4 = new \ProfileModel();
		$p4->message = '';
		$p4->author = $a6->id;
		$p4->save();

		// --- Tags (4) ---
		$t1 = new \TagModel();
		$t1->title = 'PHP';
		$t1->save();

		$t2 = new \TagModel();
		$t2->title = 'SQL';
		$t2->save();

		$t3 = new \TagModel();
		$t3->title = 'JavaScript';
		$t3->save();

		$t4 = new \TagModel();
		$t4->title = 'DevOps';
		$t4->save();

		// --- News (8) - diverse NULL/value combinations ---
		// n1: fully populated, 2 tags
		$n1 = new \NewsModel();
		$n1->title = 'First Post';
		$n1->text = 'Full article text';
		$n1->author = $a1->id;
		$n1->tags = [$t1->id, $t2->id];
		$n1->save();

		// n2: empty-string text, 1 tag
		$n2 = new \NewsModel();
		$n2->title = 'Second Post';
		$n2->text = '';
		$n2->author = $a1->id;
		$n2->tags = [$t1->id];
		$n2->save();

		// n3: NULL text, no tags
		$n3 = new \NewsModel();
		$n3->title = 'Third Post';
		$n3->author = $a2->id;
		$n3->save();

		// n4: author with empty-string mail, 2 tags
		$n4 = new \NewsModel();
		$n4->title = 'Fourth Post';
		$n4->text = 'Article by Charlie';
		$n4->author = $a3->id;
		$n4->tags = [$t2->id, $t3->id];
		$n4->save();

		// n5: NULL author (orphan), has text
		$n5 = new \NewsModel();
		$n5->title = 'Orphan Post';
		$n5->text = 'No author assigned';
		$n5->save();

		// n6: NULL author and NULL text
		$n6 = new \NewsModel();
		$n6->title = 'Double Null Post';
		$n6->save();

		// n7: text is the string "0" (falsy but not null/empty)
		$n7 = new \NewsModel();
		$n7->title = 'Zero Text Post';
		$n7->text = '0';
		$n7->author = $a6->id;
		$n7->save();

		// n8: Diana's post, 3 tags
		$n8 = new \NewsModel();
		$n8->title = 'Diana Post';
		$n8->text = 'Multi-tag article';
		$n8->author = $a4->id;
		$n8->tags = [$t1->id, $t3->id, $t4->id];
		$n8->save();

		return [
			'authors' => [
				'Alice' => $a1->id, 'Bob' => $a2->id, 'Charlie' => $a3->id,
				'Diana' => $a4->id, 'Eve' => $a5->id, 'Frank' => $a6->id,
			],
			'tags' => [
				'PHP' => $t1->id, 'SQL' => $t2->id,
				'JavaScript' => $t3->id, 'DevOps' => $t4->id,
			],
			'news' => [
				'First' => $n1->id, 'Second' => $n2->id, 'Third' => $n3->id,
				'Fourth' => $n4->id, 'Orphan' => $n5->id, 'DoubleNull' => $n6->id,
				'ZeroText' => $n7->id, 'Diana' => $n8->id,
			],
		];
	}

	function run($db, $type) {
		$test = new \Test();
		$ids = $this->seedData($db);

		// ====================================================================
		// 1. count() default TTL is 0 (not 60)
		// ====================================================================
		$author = new \AuthorModel();
		$total = $author->count();
		$test->expect(
			$total == 6,
			$type.': count() returns correct total (6 authors)'
		);

		$news = new \NewsModel();
		$newsTotal = $news->count();
		$test->expect(
			$newsTotal == 8,
			$type.': count() returns correct total (8 news)'
		);

		// count() with filter
		$author2 = new \AuthorModel();
		$nullMailCount = $author2->count(['mail = ?', null]);
		$test->expect(
			$nullMailCount == 2,
			$type.': count([mail = ?, null]) returns 2 (Bob, Eve have NULL mail)'
		);

		// count() with non-null filter
		$author3 = new \AuthorModel();
		$nonNullMailCount = $author3->count(['mail != ?', null]);
		$test->expect(
			$nonNullMailCount == 4,
			$type.': count([mail != ?, null]) returns 4 (Alice, Charlie, Diana, Frank)'
		);

		// ====================================================================
		// 2. NULL as filter parameter - equals
		// ====================================================================
		$author4 = new \AuthorModel();
		$nullMail = $author4->find(['mail = ?', null]);
		$names = [];
		if ($nullMail) foreach ($nullMail as $a) $names[] = $a->name;
		sort($names);
		$test->expect(
			$nullMail && count($nullMail) == 2
				&& $names == ['Bob', 'Eve'],
			$type.': find([mail = ?, null]) returns Bob and Eve'
		);

		// ====================================================================
		// 3. NULL as filter parameter - not equals
		// ====================================================================
		$author5 = new \AuthorModel();
		$nonNullMail = $author5->find(['mail != ?', null]);
		$names2 = [];
		if ($nonNullMail) foreach ($nonNullMail as $a) $names2[] = $a->name;
		sort($names2);
		$test->expect(
			$nonNullMail && count($nonNullMail) == 4
				&& $names2 == ['Alice', 'Charlie', 'Diana', 'Frank'],
			$type.': find([mail != ?, null]) returns 4 non-null-mail authors'
		);

		// ====================================================================
		// 4. NULL filter on another field (website)
		// ====================================================================
		$author6 = new \AuthorModel();
		$nullWeb = $author6->find(['website = ?', null]);
		$names3 = [];
		if ($nullWeb) foreach ($nullWeb as $a) $names3[] = $a->name;
		sort($names3);
		$test->expect(
			$nullWeb && count($nullWeb) == 2
				&& $names3 == ['Charlie', 'Eve'],
			$type.': find([website = ?, null]) returns 2 authors with NULL website'
		);

		// ====================================================================
		// 5. NULL on text field in NewsModel
		// ====================================================================
		$news2 = new \NewsModel();
		$nullText = $news2->find(['text = ?', null]);
		$titles = [];
		if ($nullText) foreach ($nullText as $n) $titles[] = $n->title;
		sort($titles);
		$test->expect(
			$nullText && count($nullText) == 2
				&& $titles == ['Double Null Post', 'Third Post'],
			$type.': find([text = ?, null]) returns 2 news with NULL text'
		);

		// ====================================================================
		// 6. NOT NULL on text field
		// ====================================================================
		$news3 = new \NewsModel();
		$nonNullText = $news3->find(['text != ?', null]);
		$test->expect(
			$nonNullText && count($nonNullText) == 6,
			$type.': find([text != ?, null]) returns 6 news with non-NULL text'
		);

		// ====================================================================
		// 7. Empty string is NOT the same as NULL
		// ====================================================================
		$author7 = new \AuthorModel();
		$emptyMail = $author7->find(['mail = ?', '']);
		$test->expect(
			$emptyMail && count($emptyMail) == 1
				&& $emptyMail[0]->name == 'Charlie',
			$type.': find([mail = ?, ""]) returns only Charlie (empty string != NULL)'
		);

		$author8 = new \AuthorModel();
		$emptyWeb = $author8->find(['website = ?', '']);
		$test->expect(
			$emptyWeb && count($emptyWeb) == 1
				&& $emptyWeb[0]->name == 'Frank',
			$type.': find([website = ?, ""]) returns only Frank (empty string != NULL)'
		);

		// ====================================================================
		// 8. String "0" is a distinct non-null, non-empty value
		// ====================================================================
		$news4 = new \NewsModel();
		$zeroText = $news4->find(['text = ?', '0']);
		$test->expect(
			$zeroText && count($zeroText) == 1
				&& $zeroText[0]->title == 'Zero Text Post',
			$type.': find([text = ?, "0"]) returns exactly 1 record'
		);

		// ====================================================================
		// 9. NULL in compound filter - AND
		// ====================================================================
		$author9 = new \AuthorModel();
		$result = $author9->find(['mail = ? AND website = ?', null, null]);
		$test->expect(
			$result && count($result) == 1
				&& $result[0]->name == 'Eve',
			$type.': find([mail = ? AND website = ?, null, null]) - both NULL (Eve only)'
		);

		// NULL + non-null value
		$author10 = new \AuthorModel();
		$result2 = $author10->find(['mail = ? AND name = ?', null, 'Bob']);
		$test->expect(
			$result2 && count($result2) == 1
				&& $result2[0]->name == 'Bob',
			$type.': find([mail = ? AND name = ?, null, "Bob"]) - mixed null+value'
		);

		// non-null + NULL
		$author11 = new \AuthorModel();
		$result3 = $author11->find(['name = ? AND mail = ?', 'Eve', null]);
		$test->expect(
			$result3 && count($result3) == 1
				&& $result3[0]->name == 'Eve',
			$type.': find([name = ? AND mail = ?, "Eve", null]) - value+null order'
		);

		// ====================================================================
		// 10. NULL in compound filter - OR
		// ====================================================================
		$author12 = new \AuthorModel();
		$result4 = $author12->find(['mail = ? OR website = ?', null, null]);
		$names6 = [];
		if ($result4) foreach ($result4 as $a) $names6[] = $a->name;
		sort($names6);
		// Bob(mail=null,web=set), Charlie(mail='',web=null), Eve(both null)
		$test->expect(
			$result4 && count($result4) == 3
				&& $names6 == ['Bob', 'Charlie', 'Eve'],
			$type.': find([mail = ? OR website = ?, null, null]) - OR with nulls'
		);

		// ====================================================================
		// 11. Named parameters with NULL
		// ====================================================================
		$author13 = new \AuthorModel();
		$result5 = $author13->find(['mail = :mail', ':mail' => null]);
		$names7 = [];
		if ($result5) foreach ($result5 as $a) $names7[] = $a->name;
		sort($names7);
		$test->expect(
			$result5 && count($result5) == 2
				&& $names7 == ['Bob', 'Eve'],
			$type.': find([mail = :mail, :mail => null]) - named param NULL'
		);

		// Named NOT NULL
		$author14 = new \AuthorModel();
		$result6 = $author14->find(['mail != :mail', ':mail' => null]);
		$test->expect(
			$result6 && count($result6) == 4,
			$type.': find([mail != :mail, :mail => null]) - named param NOT NULL'
		);

		// ====================================================================
		// 12. NULL foreign key (belongs-to-one) - orphan records
		// ====================================================================
		$news5 = new \NewsModel();
		$orphans = $news5->find(['author = ?', null]);
		$orphanTitles = [];
		if ($orphans) foreach ($orphans as $n) $orphanTitles[] = $n->title;
		sort($orphanTitles);
		$test->expect(
			$orphans && count($orphans) == 2
				&& $orphanTitles == ['Double Null Post', 'Orphan Post'],
			$type.': find([author = ?, null]) returns orphan news (NULL FK)'
		);

		// Non-null FK
		$news6 = new \NewsModel();
		$withAuthor = $news6->find(['author != ?', null]);
		$test->expect(
			$withAuthor && count($withAuthor) == 6,
			$type.': find([author != ?, null]) returns 6 news with non-NULL author'
		);

		// ====================================================================
		// 13. load() with NULL parameter
		// ====================================================================
		$author15 = new \AuthorModel();
		$loaded = $author15->load(['mail = ?', null]);
		$test->expect(
			$loaded && !$author15->dry()
				&& ($author15->name == 'Bob' || $author15->name == 'Eve'),
			$type.': load([mail = ?, null]) loads a record with NULL mail'
		);

		// ====================================================================
		// 14. find() with null filter (no filter) returns all
		// ====================================================================
		$author16 = new \AuthorModel();
		$allAuthors = $author16->find();
		$test->expect(
			$allAuthors && count($allAuthors) == 6,
			$type.': find(null) returns all 6 authors'
		);

		$author17 = new \AuthorModel();
		$allAuthors2 = $author17->find(null);
		$test->expect(
			$allAuthors2 && count($allAuthors2) == 6,
			$type.': find(null) explicit - returns all 6 authors'
		);

		// ====================================================================
		// 15. find() with empty array filter
		// ====================================================================
		$author18 = new \AuthorModel();
		$allAuthors3 = $author18->find([]);
		$test->expect(
			$allAuthors3 && count($allAuthors3) == 6,
			$type.': find([]) empty array returns all 6 authors'
		);

		// ====================================================================
		// 16. count() with NULL filter param
		// ====================================================================
		$news7 = new \NewsModel();
		$orphanCount = $news7->count(['author = ?', null]);
		$test->expect(
			$orphanCount == 2,
			$type.': count([author = ?, null]) returns 2 orphan news'
		);

		// ====================================================================
		// 17. count() without filter
		// ====================================================================
		$tag = new \TagModel();
		$tagCount = $tag->count();
		$test->expect(
			$tagCount == 4,
			$type.': count() no filter returns 4 tags'
		);

		// ====================================================================
		// 18. NULL + LIKE in same compound filter
		// ====================================================================
		$news8 = new \NewsModel();
		$result7 = $news8->find(['author != ? AND title LIKE ?', null, '%Post%']);
		$test->expect(
			$result7 && count($result7) >= 4,
			$type.': find([author != ? AND title LIKE ?, null, "%Post%"]) - compound'
		);

		// ====================================================================
		// 19. Multiple records - selective null on different fields
		// ====================================================================
		// authors where mail is not null but website IS null
		$author19 = new \AuthorModel();
		$result8 = $author19->find(['mail != ? AND website = ?', null, null]);
		$names8 = [];
		if ($result8) foreach ($result8 as $a) $names8[] = $a->name;
		sort($names8);
		$test->expect(
			$result8 && count($result8) == 1
				&& $names8 == ['Charlie'],
			$type.': find([mail != ? AND website = ?, null, null]) - Charlie only'
		);

		// ====================================================================
		// 20. find() returns false for impossible NULL combo
		// ====================================================================
		$author20 = new \AuthorModel();
		$impossible = $author20->find(['name = ?', null]);
		$test->expect(
			$impossible === false,
			$type.': find([name = ?, null]) returns false (no author has NULL name)'
		);

		// ====================================================================
		// 21. count() for impossible NULL combo
		// ====================================================================
		$author21 = new \AuthorModel();
		$zeroCount = $author21->count(['name = ?', null]);
		$test->expect(
			$zeroCount == 0,
			$type.': count([name = ?, null]) returns 0'
		);

		// ====================================================================
		// 22. belongs-to-one relation access on orphan record
		// ====================================================================
		$news9 = new \NewsModel();
		$news9->load(['title = ?', 'Orphan Post']);
		$test->expect(
			!$news9->dry() && $news9->author === null,
			$type.': orphan news - author relation is null'
		);

		// ====================================================================
		// 23. find with IN operator still works (not broken by null fix)
		// ====================================================================
		$author22 = new \AuthorModel();
		$inResult = $author22->find(['name IN ?', ['Alice', 'Diana', 'Frank']]);
		$test->expect(
			$inResult && count($inResult) == 3,
			$type.': find([name IN ?, [...]]) returns 3 matching authors'
		);

		// ====================================================================
		// 24. find with LIKE still works
		// ====================================================================
		$news10 = new \NewsModel();
		$likeResult = $news10->find(['title LIKE ?', '%Post%']);
		$test->expect(
			$likeResult && count($likeResult) >= 5,
			$type.': find([title LIKE ?, "%Post%"]) returns matching news'
		);

		// ====================================================================
		// 25. Combination: count + find give same number
		// ====================================================================
		$news11 = new \NewsModel();
		$findCount = count($news11->find(['text != ?', null]));
		$news12 = new \NewsModel();
		$countCount = $news12->count(['text != ?', null]);
		$test->expect(
			$findCount == $countCount && $findCount == 6,
			$type.': count() and find() agree on non-null text count (6)'
		);

		// ====================================================================
		// 26. find with multiple non-null conditions
		// ====================================================================
		$author23 = new \AuthorModel();
		$both = $author23->find(['mail != ? AND website != ?', null, null]);
		$names9 = [];
		if ($both) foreach ($both as $a) $names9[] = $a->name;
		sort($names9);
		$test->expect(
			$both && count($both) == 3
				&& $names9 == ['Alice', 'Diana', 'Frank'],
			$type.': find([mail != ? AND website != ?, null, null]) - 3 fully-set'
		);

		// ====================================================================
		// 27. Verify empty string in compound null query is distinct
		// ====================================================================
		$author24 = new \AuthorModel();
		$emptyOrNull = $author24->find([
			'mail = ? OR mail = ?', null, ''
		]);
		$names10 = [];
		if ($emptyOrNull) foreach ($emptyOrNull as $a) $names10[] = $a->name;
		sort($names10);
		$test->expect(
			$emptyOrNull && count($emptyOrNull) == 3
				&& $names10 == ['Bob', 'Charlie', 'Eve'],
			$type.': find([mail = ? OR mail = ?, null, ""]) - null + empty string'
		);

		// ====================================================================
		// 28. has-many relation on author with no news
		// ====================================================================
		$author25 = new \AuthorModel();
		$author25->load(['name = ?', 'Eve']);
		$test->expect(
			!$author25->dry() && (
				$author25->news === null || (
					$author25->news instanceof \DB\CortexCollection
					&& count($author25->news) == 0
				)
			),
			$type.': Eve has no news (null or empty collection)'
		);

		// ====================================================================
		// 29. CortexQueryParser - NULL handling at parser level
		// ====================================================================
		$qp = \DB\CortexQueryParser::instance();

		// double-equals variant
		$f1 = $qp->prepareFilter(['name == ?', null], 'sql', $db);
		$test->expect(
			is_array($f1) && str_contains($f1[0], 'IS NULL'),
			$type.': prepareFilter [name == ?, null] produces IS NULL'
		);

		// not-double-equals
		$f2 = $qp->prepareFilter(['name !== ?', null], 'sql', $db);
		$test->expect(
			is_array($f2) && str_contains($f2[0], 'IS NOT NULL'),
			$type.': prepareFilter [name !== ?, null] produces IS NOT NULL'
		);

		// ====================================================================
		// 30. NULL handling does not leak bind params
		// ====================================================================
		$f3 = $qp->prepareFilter(['name = ?', null], 'sql', $db);
		$test->expect(
			is_array($f3) && count($f3) == 1
				&& str_contains($f3[0], 'IS NULL'),
			$type.': prepareFilter [name = ?, null] - no bind param leaked (count=1)'
		);

		$f4 = $qp->prepareFilter(['name != ?', null], 'sql', $db);
		$test->expect(
			is_array($f4) && count($f4) == 1
				&& str_contains($f4[0], 'IS NOT NULL'),
			$type.': prepareFilter [name != ?, null] - no bind param leaked (count=1)'
		);

		// With non-null value for comparison
		$f5 = $qp->prepareFilter(['name = ?', 'Alice'], 'sql', $db);
		$test->expect(
			is_array($f5) && count($f5) == 2 && $f5[1] == 'Alice',
			$type.': prepareFilter [name = ?, "Alice"] keeps bind param (count=2)'
		);

		// ====================================================================
		// 31. Compound NULL at parser level
		// ====================================================================
		$f6 = $qp->prepareFilter(['a = ? AND b = ?', null, 'test'], 'sql', $db);
		$test->expect(
			is_array($f6) && count($f6) == 2
				&& str_contains($f6[0], 'IS NULL')
				&& $f6[1] == 'test',
			$type.': prepareFilter compound [a=null AND b=test] - 1 bind param'
		);

		$f7 = $qp->prepareFilter(['a = ? AND b = ?', 'test', null], 'sql', $db);
		$test->expect(
			is_array($f7) && count($f7) == 2
				&& str_contains($f7[0], 'IS NULL')
				&& $f7[1] == 'test',
			$type.': prepareFilter compound [a=test AND b=null] - 1 bind param'
		);

		$f8 = $qp->prepareFilter(['a = ? AND b = ?', null, null], 'sql', $db);
		$test->expect(
			is_array($f8) && count($f8) == 1,
			$type.': prepareFilter compound [a=null AND b=null] - 0 bind params'
		);

		// ====================================================================
		// CLEANUP
		// ====================================================================
		\NewsModel::setdown();
		\TagModel::setdown();
		\ProfileModel::setdown();
		\AuthorModel::setdown();

		return $test->results();
	}
}
