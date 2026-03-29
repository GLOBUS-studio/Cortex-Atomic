<?php

/**
 * Eager loading API tests for Cortex::with().
 * Verifies with() on find/load, dot-notation nesting, depth-based loading,
 * auto-clear after use, and integration with relation filters.
 */
class Test_Eager {

	// helper: populate test data and return IDs
	private function seedData($db) {
		$schema = new \DB\SQL\Schema($db);

		\NewsModel::setdown();
		\TagModel::setdown();
		\ProfileModel::setdown();
		\AuthorModel::setdown();

		\AuthorModel::setup();
		\ProfileModel::setup();
		\TagModel::setup();
		\NewsModel::setup();

		// create authors
		$a1 = new \AuthorModel();
		$a1->name = 'Author A';
		$a1->save();
		$a2 = new \AuthorModel();
		$a2->name = 'Author B';
		$a2->save();

		// create profiles
		$p1 = new \ProfileModel();
		$p1->message = 'Profile of Author A';
		$p1->author = $a1->id;
		$p1->save();

		// create tags
		$t1 = new \TagModel();
		$t1->title = 'PHP';
		$t1->save();
		$t2 = new \TagModel();
		$t2->title = 'SQL';
		$t2->save();

		// create news articles
		$n1 = new \NewsModel();
		$n1->title = 'News One';
		$n1->text = 'first article';
		$n1->author = $a1->id;
		$n1->tags = [$t1->id, $t2->id]; // belongs-to-many
		$n1->save();

		$n2 = new \NewsModel();
		$n2->title = 'News Two';
		$n2->text = 'second article';
		$n2->author = $a1->id;
		$n2->tags = [$t1->id];
		$n2->save();

		$n3 = new \NewsModel();
		$n3->title = 'News Three';
		$n3->text = 'third article';
		$n3->author = $a2->id;
		$n3->save();

		return [
			'authors' => [$a1->id, $a2->id],
			'tags'    => [$t1->id, $t2->id],
			'news'    => [$n1->id, $n2->id, $n3->id],
		];
	}

	function run($db, $type) {
		$test = new \Test();

		$ids = $this->seedData($db);

		// ====================================================================
		// 1. with() returns model instance (fluent chaining)
		// ====================================================================
		$author = new \AuthorModel();
		$result = $author->with(['news']);
		$test->expect(
			$result instanceof \AuthorModel,
			$type.': with() returns $this for fluent chaining'
		);

		// ====================================================================
		// 2. with() + find() - has-many relation preloaded
		// ====================================================================
		$author = new \AuthorModel();
		$authors = $author->with(['news'])->find();
		$test->expect(
			$authors instanceof \DB\CortexCollection && count($authors) == 2,
			$type.': with([news]) + find() returns collection of 2 authors'
		);
		$firstAuthor = null;
		foreach ($authors as $a) {
			if ($a->name == 'Author A') {
				$firstAuthor = $a;
				break;
			}
		}
		$test->expect(
			$firstAuthor && $firstAuthor->news instanceof \DB\CortexCollection
				&& count($firstAuthor->news) == 2,
			$type.': with([news]) - Author A has 2 news articles preloaded'
		);

		// ====================================================================
		// 3. with() + find() - belongs-to-one relation preloaded
		// ====================================================================
		$news = new \NewsModel();
		$allNews = $news->with(['author'])->find();
		$test->expect(
			$allNews instanceof \DB\CortexCollection && count($allNews) == 3,
			$type.': with([author]) + find() returns collection of 3 news'
		);
		$newsOne = null;
		foreach ($allNews as $n) {
			if ($n->title == 'News One') {
				$newsOne = $n;
				break;
			}
		}
		$test->expect(
			$newsOne && $newsOne->author instanceof \DB\Cortex
				&& $newsOne->author->name == 'Author A',
			$type.': with([author]) - News One has author preloaded (Author A)'
		);

		// ====================================================================
		// 4. with() + find() - belongs-to-many preloaded
		// ====================================================================
		$news = new \NewsModel();
		$allNews = $news->with(['tags'])->find();
		$newsOne = null;
		foreach ($allNews as $n) {
			if ($n->title == 'News One') {
				$newsOne = $n;
				break;
			}
		}
		$test->expect(
			$newsOne && $newsOne->tags instanceof \DB\CortexCollection
				&& count($newsOne->tags) == 2,
			$type.': with([tags]) - News One has 2 tags preloaded (belongs-to-many)'
		);

		// ====================================================================
		// 5. with() nested dot-notation: news.author
		// ====================================================================
		$author = new \AuthorModel();
		$authors = $author->with(['news', 'news.author'])->find();
		$firstAuthor = null;
		foreach ($authors as $a) {
			if ($a->name == 'Author A') {
				$firstAuthor = $a;
				break;
			}
		}
		$test->expect(
			$firstAuthor && $firstAuthor->news instanceof \DB\CortexCollection
				&& count($firstAuthor->news) > 0,
			$type.': with([news, news.author]) - top-level news loaded'
		);
		$firstNews = $firstAuthor ? $firstAuthor->news[0] : null;
		$test->expect(
			$firstNews && $firstNews->author instanceof \DB\Cortex
				&& $firstNews->author->name == 'Author A',
			$type.': with([news, news.author]) - nested author preloaded on news'
		);

		// ====================================================================
		// 6. with() nested dot-notation: news.tags (belongs-to-many)
		// ====================================================================
		$author = new \AuthorModel();
		$authors = $author->with(['news', 'news.tags'])->find();
		$firstAuthor = null;
		foreach ($authors as $a) {
			if ($a->name == 'Author A') {
				$firstAuthor = $a;
				break;
			}
		}
		// Author A has 2 news; News One has 2 tags, News Two has 1 tag
		$newsWithTags = null;
		if ($firstAuthor && $firstAuthor->news) {
			foreach ($firstAuthor->news as $n) {
				if ($n->title == 'News One') {
					$newsWithTags = $n;
					break;
				}
			}
		}
		$test->expect(
			$newsWithTags && $newsWithTags->tags instanceof \DB\CortexCollection
				&& count($newsWithTags->tags) == 2,
			$type.': with([news, news.tags]) - nested tags preloaded (2 tags on News One)'
		);

		// ====================================================================
		// 7. with(int) - depth-based eager loading
		// ====================================================================
		$author = new \AuthorModel();
		$authors = $author->with(1)->find();
		$firstAuthor = null;
		foreach ($authors as $a) {
			if ($a->name == 'Author A') {
				$firstAuthor = $a;
				break;
			}
		}
		$test->expect(
			$firstAuthor && $firstAuthor->news instanceof \DB\CortexCollection
				&& count($firstAuthor->news) == 2,
			$type.': with(1) - depth 1 loads has-many news'
		);
		$test->expect(
			$firstAuthor && $firstAuthor->profile instanceof \DB\Cortex
				&& $firstAuthor->profile->message == 'Profile of Author A',
			$type.': with(1) - depth 1 loads has-one profile'
		);

		// ====================================================================
		// 8. with(string) - single string shorthand
		// ====================================================================
		$author = new \AuthorModel();
		$authors = $author->with('news')->find();
		$firstAuthor = null;
		foreach ($authors as $a) {
			if ($a->name == 'Author A') {
				$firstAuthor = $a;
				break;
			}
		}
		$test->expect(
			$firstAuthor && $firstAuthor->news instanceof \DB\CortexCollection
				&& count($firstAuthor->news) == 2,
			$type.': with(string) - single string shorthand works'
		);

		// ====================================================================
		// 9. with() + load() - single record eager loading
		// ====================================================================
		$author = new \AuthorModel();
		$author->with(['news'])->load(['name = ?', 'Author A']);
		$test->expect(
			!$author->dry() && $author->name == 'Author A',
			$type.': with() + load() - loaded Author A'
		);
		$test->expect(
			$author->news instanceof \DB\CortexCollection
				&& count($author->news) == 2,
			$type.': with() + load() - news relation preloaded (2 articles)'
		);

		// ====================================================================
		// 10. with() + load() for non-existent record
		// ====================================================================
		$author = new \AuthorModel();
		$loaded = $author->with(['news'])->load(['name = ?', 'NonExistent']);
		$test->expect(
			$loaded === false || $author->dry(),
			$type.': with() + load() on non-existent record returns false'
		);

		// ====================================================================
		// 11. with() auto-clears after find()
		// ====================================================================
		$author = new \AuthorModel();
		$author->with(['news']);
		$author->find();
		// second find without with() should not eager-load
		$noEagerThrown = true;
		try {
			$result = $author->find();
		} catch (\Exception $e) {
			$noEagerThrown = false;
		}
		$test->expect(
			$noEagerThrown === true,
			$type.': with() auto-clears after find() (second find does not crash)'
		);

		// ====================================================================
		// 12. with() auto-clears on empty find() result
		// ====================================================================
		$author = new \AuthorModel();
		$author->with(['news']);
		$emptyResult = $author->find(['name = ?', 'NonExistent']);
		$test->expect(
			$emptyResult === false,
			$type.': with() on empty find() returns false and auto-clears'
		);

		// ====================================================================
		// 13. with() + filter() on relations
		// ====================================================================
		$author = new \AuthorModel();
		$author->filter('news', ['title = ?', 'News One']);
		$authors = $author->with(['news'])->find();
		$firstAuthor = null;
		foreach ($authors as $a) {
			if ($a->name == 'Author A') {
				$firstAuthor = $a;
				break;
			}
		}
		$test->expect(
			$firstAuthor && $firstAuthor->news instanceof \DB\CortexCollection
				&& count($firstAuthor->news) == 1
				&& $firstAuthor->news[0]->title == 'News One',
			$type.': with() + filter() - only filtered news preloaded'
		);

		// ====================================================================
		// 14. with() + has-one relation (profile)
		// ====================================================================
		$author = new \AuthorModel();
		$authors = $author->with(['profile'])->find();
		$firstAuthor = null;
		$secondAuthor = null;
		foreach ($authors as $a) {
			if ($a->name == 'Author A') $firstAuthor = $a;
			if ($a->name == 'Author B') $secondAuthor = $a;
		}
		$test->expect(
			$firstAuthor && $firstAuthor->profile instanceof \DB\Cortex
				&& $firstAuthor->profile->message == 'Profile of Author A',
			$type.': with([profile]) - has-one profile preloaded for Author A'
		);
		$test->expect(
			$secondAuthor && $secondAuthor->profile === null,
			$type.': with([profile]) - Author B has no profile (null)'
		);

		// ====================================================================
		// 15. with(null) / with(false) resets eager load
		// ====================================================================
		$author = new \AuthorModel();
		$author->with(['news']);
		$author->with(null); // reset
		$authors = $author->find();
		// should still work, just without eager loading
		$test->expect(
			$authors instanceof \DB\CortexCollection && count($authors) == 2,
			$type.': with(null) resets eager loading (find still works)'
		);

		// ====================================================================
		// 16. with(2) depth-based nested loading
		// ====================================================================
		$author = new \AuthorModel();
		$authors = $author->with(2)->find();
		$firstAuthor = null;
		foreach ($authors as $a) {
			if ($a->name == 'Author A') {
				$firstAuthor = $a;
				break;
			}
		}
		// depth 2: author.news loaded, then news.author and news.tags loaded
		$newsItem = null;
		if ($firstAuthor && $firstAuthor->news) {
			foreach ($firstAuthor->news as $n) {
				if ($n->title == 'News One') {
					$newsItem = $n;
					break;
				}
			}
		}
		$test->expect(
			$newsItem && $newsItem->author instanceof \DB\Cortex,
			$type.': with(2) - depth 2 loaded news.author (nested)'
		);
		$test->expect(
			$newsItem && $newsItem->tags instanceof \DB\CortexCollection
				&& count($newsItem->tags) == 2,
			$type.': with(2) - depth 2 loaded news.tags (nested, 2 tags)'
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
