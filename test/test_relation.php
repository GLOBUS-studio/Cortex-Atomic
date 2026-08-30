<?php

use App\Controller;

class Test_Relation {

	/**
	 * unify results for better comparison
	 */
	private function getResult($result)
	{
		$out = array();
		foreach ($result?:[] as $row) {
			if(is_object($row))
				$row = $row->cast();
			unset($row['_id']);
			unset($row['id']);
			unset($row['aid']);
			unset($row['uid']);
			unset($row['nid']);
			unset($row['tid']);
			unset($row['pid']);
			unset($row['profile_id']);
			foreach ($row as $col => $val) {
				if (empty($val) || is_null($val))
					unset($row[$col]);
			}
			$out[] = $row;
		}
		return $out;
	}

	function run($db,$type)
	{
		$test = new \Test();

		// clear existing data
		\AuthorModel::setdown();
		\TagModel::setdown();
		\NewsModel::setdown();
		\ProfileModel::setdown();

		// setup models
		\AuthorModel::setup();
		\TagModel::setup();
		\NewsModel::setup();
		\ProfileModel::setup();

		// setup Author
		///////////////////////////////////
		$author_id = array();

		$author = new \AuthorModel();
		$ac=$author::resolveConfiguration();
		$author_pk = (is_int(strpos($type,'sql'))?$ac['primary']:'_id');

		$author->name = 'Johnny English';
		$author->mail = 'user1@domain.com';
		$author->save();
		$author_id[] = $author->_id;
		$author->reset();
		$author->name = 'Ridley Scott';
		$author->mail = 'user2@domain.com';
		$author->save();
		$author_id[] = $author->_id;
		$author->reset();
		$author->name = 'James T. Kirk';
		$author->mail = 'user3@domain.com';
		$author->save();
		$author_id[] = $author->_id;
		$author->reset();

		$allauthors = $author->find()->castAll();
		$allauthors = $this->getResult($allauthors);
		$test->expect(
			json_encode($allauthors) ==
			'[{"name":"Johnny English","mail":"user1@domain.com"},{"name":"Ridley Scott","mail":"user2@domain.com"},{"name":"James T. Kirk","mail":"user3@domain.com"}]',
			$type.': all AuthorModel items created'
		);

		// setup Tags
		///////////////////////////////////
		$tag_id = array();

		$tag = new \TagModel();
		$tc=$tag::resolveConfiguration();
		$tag_pk = (is_int(strpos($type,'sql'))?$tc['primary']:'_id');

		$tag->title = 'Web Design';
		$tag->save();
		$tag_id[] = $tag->_id;
		$tag->reset();
		$tag->title = 'Responsive';
		$tag->save();
		$tag_id[] = $tag->_id;
		$tag->reset();
		$tag->title = 'Usability';
		$tag->save();
		$tag_id[] = $tag->_id;
		$tag->reset();

		$allTags = $this->getResult($tag->find());
		$test->expect(
			json_encode($allTags) ==
			'[{"title":"Web Design"},{"title":"Responsive"},{"title":"Usability"}]',
			$type.': all TagModel items created'
		);

		// setup News
		///////////////////////////////////
		$news_id = array();

		$news = new \NewsModel();
		$nc=$news::resolveConfiguration();
		$news_pk = (is_int(strpos($type,'sql'))?$nc['primary']:'_id');

		$news->title = 'Responsive Images';
		$news->text = 'Lorem Ipsun';
		$news->save();
		$news_id[] = $news->_id;
		$news->reset();
		$news->title = 'CSS3 Showcase';
		$news->text = 'News Text 2';
		$news->save();
		$news_id[] = $news->_id;
		$news->reset();
		$news->title = 'Touchable Interfaces';
		$news->text = 'Lorem Foo';
		$news->save();
		$news_id[] = $news->_id;
		$news->reset();

		$allnews = $this->getResult($news->find(null,array('order'=>'title')));
		$test->expect(
			count($allnews) == 3 &&
			$allnews[0]['title'] == 'CSS3 Showcase' &&
			$allnews[1]['title'] == 'Responsive Images' &&
			$allnews[2]['title'] == 'Touchable Interfaces',
			$type.': all NewsModel items created'
		);

		// belongs-to author relation
		///////////////////////////////////

		$author->load();
		$news->load(array($news_pk.' = ?',$news_id[0]));
		$news->author = $author;
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[0]));
		$test->expect(
			$news->author->name == 'Johnny English',
			$type.': belongs-to-one: author relation created'
		);

		$news->author = NULL;
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[0]));
		$test->expect(
			empty($news->author),
			$type.': belongs-to-one: author relation released'
		);

		$news->author = $author->_id;
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[0]));
		$test->expect(
			$news->author->name == 'Johnny English',
			$type.': belongs-to-one: relation created by raw id'
		);

		// belongs-to-many tag relation
		///////////////////////////////////

		$tag1 = new \TagModel();
		$tag1->load(array($tag_pk.' = ?', $tag_id[0]));
		$tag2 = new \TagModel();
		$tag2->load(array($tag_pk.' = ?', $tag_id[1]));
		$news->tags = array($tag1,$tag2);
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[0]));
		$test->expect(
			$news->tags[0]->title == 'Web Design' && $news->tags[1]->title == 'Responsive',
			$type.': belongs-to-many: relations created with array of mapper objects'
		);

		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[1]));
		$news->tags = array($tag_id[1],$tag_id[2]);
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[1]));
		$test->expect(
			count($news->tags) == 2 &&
			$news->tags[0]->title == 'Responsive' && $news->tags[1]->title == 'Usability',
			$type.': belongs-to-many: relations created with array of IDs'
		);

		$news->tags = null;
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[1]));
		$test->expect(
			empty($news->tags),
			$type.': belongs-to-many: relations released'
		);

		$tag->reset();
		$news->load(array($news_pk.' = ?', $news_id[1]));
		$tag->load(array($tag_pk.' != ?',$tag_id[0]));
		$news->tags = $tag;
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[1]));
		$test->expect(
			$news->tags[0]->title == 'Responsive' && $news->tags[1]->title == 'Usability',
			$type.': belongs-to-many: relations created with hydrated mapper'
		);


		$news->reset();
		$tag->reset();
		$news->load(array($news_pk.' = ?', $news_id[2]));
		$news->tags = $tag_id[0].';'.$tag_id[2];
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[2]));
		$test->expect(
			$news->tags[0]->title == 'Web Design' && $news->tags[1]->title == 'Usability',
			$type.': belongs-to-many: relations created with split-able string'
		);
		$test->expect(
			is_object($news->tags) && $news->tags instanceof \DB\CortexCollection,
			$type.': belongs-to-many: result is collection'
		);


		// has-one relation
		///////////////////////////////////
		$profile = new ProfileModel();
		$pc=$profile::resolveConfiguration();
		$profile_pk = (is_int(strpos($type,'sql'))?$pc['primary']:'_id');

		$profile->message = 'Hello World';
		$author->load(array($author_pk.' = ?',$author_id[0]));
		$profile->author = $author;
		$profile->save();
		$profile_id = $profile->_id;
		$profile->reset();
		$author->reset();
		$author->load(array($author_pk.' = ?', $author_id[0]));
		$profile->load(array($profile_pk.' = ?', $profile_id));
		$test->expect(
			$author->profile->message == 'Hello World' &&
			$profile->author->name == "Johnny English",
			$type.': has-one: relation assigned'
		);

		$profile->reset();
		$profile->message = 'I\'m feeling lucky';
		$profile->image = 'lolcat.jpg';
		$author->reset();
		$author->load(array($author_pk.' = ?',$author_id[1]));
		$author->profile = $profile;
		$author->save();
		$profile->reset();
		$author->reset();
		$author->load(array($author_pk.' = ?', $author_id[1]));
		$test->expect(
			$author->profile->message == 'I\'m feeling lucky',
			$type.': has-one: inverse relation'
		);


		// has-many relation
		///////////////////////////////////

		$author->load(array($author_pk.' = ?', $author_id[0]));
		$result = $this->getResult($author->news);
		$test->expect(
			$result[0]['title'] == "Responsive Images" &&
			$result[0]['tags'][0]['title'] == 'Web Design' &&
			$result[0]['tags'][1]['title'] == 'Responsive',
			$type.': has-many inverse relation'
		);

		$author->news[] = $news_id[1];
		$author->save();
		$author->reset();
		$author->load(array($author_pk.' = ?', $author_id[0]));
		$result = $this->getResult($author->news);
		$test->expect(
			count($result)==2 &&
			$result[0]['title'] == "Responsive Images" &&
			$result[1]['title'] == "CSS3 Showcase",
			$type.': has-many add relation, inverse ways'
		);

		$author->news = [$news_id[1]];
		$author->save();
		$author->reset();
		$author->load(array($author_pk.' = ?', $author_id[0]));
		$result = $this->getResult($author->news);
		$test->expect(
			$result[0]['title'] == "CSS3 Showcase",
			$type.': has-many remove relation, inverse ways'
		);

		$author->news = null;
		$author->save();
		$author->reset();
		$author->load(array($author_pk.' = ?', $author_id[0]));
		$result = $this->getResult($author->news);
		$test->expect(
			empty($result),
			$type.': has-many clear relation, inverse ways'
		);
		$author->news = [$news_id[0]];
		$author->save();
		$author->reset();

		// many to many relation
		///////////////////////////////////

		$news->load(array($news_pk.' = ?',$news_id[0]));
		$news->tags2 = array($tag_id[0],$tag_id[1]);
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?',$news_id[0]));
		$test->expect(
			$news->tags2[0]['title'] == 'Web Design' &&
			$news->tags2[1]['title'] == 'Responsive',
			$type.': many-to-many relation created'
		);

		$test->expect(
			is_object($news->tags2) && $news->tags2 instanceof \DB\CortexCollection,
			$type.': many-to-many: result is collection'
		);


		$tag->load(array($tag_pk.' = ?',$tag_id[2]));
		$tag3 = $tag;
		$news->tags2[] = $tag3;
		$news->save();
		$a=count($news->tags2);
		$news->reset();
		$news->load(array($news_pk.' = ?',$news_id[0]));
		$test->expect(
			count($news->tags2) == 3 && $a == 3,
			$type.': many-to-many relation added implicitly'
		);

		$news->load(array($news_pk.' = ?', $news_id[0]));
		$news->tags2 = NULL;
		$news->save();
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[0]));
		$test->expect(
			is_null($news->tags2),
			$type.': many-to-many relation released'
		);

		$news->reset();
		$news->title='Can it run Crysis?';
		$news->text='XOXO';
		$news->tags2 = array($tag_id[0]);
		$news->save();
		$news_id[] = $news->_id;
		$news->reset();
		$news->load(array($news_pk.' = ?', $news_id[3]));
		$a=count($news->tags2);
		$tag1 = $tag->find(array($tag_pk.' = ?',$tag_id[0]));
		$b=count($tag1[0]->news);
		$c=$tag1[0]->news[0]->title == 'Can it run Crysis?';
		$news->erase();
		$tag1 = $tag->find(array($tag_pk.' = ?',$tag_id[0]));
		$d=count($tag1[0]->news?:[]);
		$test->expect(
			$a == 1 && $b == 1 && $c && $d == 0,
			$type.': many-to-many relation cleaned by erase cascade'
		);

		$news->load(array($news_pk.' = ?', $news_id[0]));
		$all = $news->find(null,['order'=>'title']);

		$test->expect(
			$all[0]->tags2 === NULL
			&& $all[2]->author === NULL,
			$type.': empty relations are NULL'
		);

		$arr = $news->cast();
		$test->expect(
			is_array($arr['tags']),
			$type.': collection becomes array in casted model'
		);

		if ($type == 'mongo') {
			$test->expect(
				is_string($arr['_id']),
				$type.': id becomes string in casted model'
			);
		}

		$author = new AuthorModel();
		$author->load(array($author_pk.' = ?',$author_id[0]));
		$test->expect(
			$author->friends === NULL,
			$type.': self-ref relation is empty'
		);

		$author->friends = [$author_id[1]];
		$author->save();
		$author->reset();
		$author->load(array($author_pk.' = ?',$author_id[0]));
		$test->expect(
			$author->friends && $author->friends[0]->get('_id') == $author_id[1],
			$type.': self-ref relation populated'
		);
		$authorB = $author->findone([$author_pk.' = ?',$author_id[1]]);
		$test->expect(
			$authorB->friends && $authorB->friends[0]->get('_id') == $author_id[0],
			$type.': self-ref relation inverse way'
		);

		$authorB->friends[] = $author_id[2];
		$authorB->save();
		$authorB = new AuthorModel();
		$authorB->load(array($author_pk.' = ?',$author_id[1]));
		$ids = $authorB->friends->getAll('_id');
		$test->expect(
			$authorB->friends && count($authorB->friends) == 2 &&
			in_array($author_id[0],$ids) && in_array($author_id[2],$ids),
			$type.': self-ref relation collection merging'
		);

        $news = new NewsModel();
        $news->load([$news_pk.' = ?', $news_id[1]]);
        $news->author = $author_id[1];
        $news->save();

        $news1 = new NewsModel();
        $news1->fields([$news_pk, "author.name"]);
        $news1->load([$news_pk.' = ?', $news_id[0]]);

        $news2 = new NewsModel();
        $news2->load([$news_pk.' = ?', $news_id[1]]);

        $test->expect(
            $news1->author->mail === null && $news2->author->mail === 'user2@domain.com',
            $type.': whitelist on relations does not persist'
        );

		// #20: relation-filtered erase must preserve field configuration while
		// preparing Mongo filters, so string relation IDs become ObjectIds.
		$eraseAuthor = new AuthorModel();
		$eraseAuthor->name = 'Relation erase batch';
		$eraseAuthor->save();
		$eraseBatchId = (string)$eraseAuthor->_id;

		$eraseProfile = new ProfileModel();
		$eraseProfile->message = 'relation-erase-batch';
		$eraseProfile->author = $eraseAuthor;
		$eraseProfile->save();
		(new ProfileModel())->erase(['author = ?', $eraseBatchId]);
		$test->expect(
			(new ProfileModel())->find(['message = ?', 'relation-erase-batch']) === false,
			$type.': #20 erase(filter) deletes a relation match on a model without m:m relations'
		);

		$eraseAuthor = new AuthorModel();
		$eraseAuthor->name = 'Relation erase m:m';
		$eraseAuthor->save();
		$eraseMmId = (string)$eraseAuthor->_id;

		$eraseNews = new NewsModel();
		$eraseNews->title = 'relation-erase-mm';
		$eraseNews->author = $eraseAuthor;
		$eraseNews->save();
		(new NewsModel())->erase(['author = ?', $eraseMmId]);
		$test->expect(
			(new NewsModel())->find(['title = ?', 'relation-erase-mm']) === false,
			$type.': #20 erase(filter) deletes a relation match on a model with m:m relations'
		);

		// ================================================================
		// #22 relation-filter lifecycle
		// A relation filter belongs to exactly one query. After that query it
		// stays available for deferred relation access on the record it
		// fetched, and it must not take part in any later query.
		// ================================================================
		$rfAuthor = new AuthorModel();
		$rfAuthor->name = 'RelFilter Owner';
		$rfAuthor->save();

		$rfProfile = new ProfileModel();
		$rfProfile->message = 'RF profile';
		$rfProfile->author = $rfAuthor;
		$rfProfile->save();

		foreach (['RF Alpha', 'RF Beta'] as $rfNewsTitle) {
			$rfNews = new NewsModel();
			$rfNews->title = $rfNewsTitle;
			$rfNews->author = $rfAuthor;
			$rfNews->save();
		}

		// an unsatisfied has() must not narrow the next query
		$rfHas = new AuthorModel();
		$rfEmptyHas = $rfHas->has('news', ['title = ?', 'RF Missing'])->find();
		$rfAfterHas = $rfHas->find(['name = ?', 'RelFilter Owner']);
		$test->expect(
			$rfEmptyHas === false && $rfAfterHas && count($rfAfterHas) == 1,
			$type.': #22 an unsatisfied has() does not affect the next find()'
		);

		// an empty find() clears the relation filter configuration
		$rfEmptyFind = new AuthorModel();
		$rfEmptyFind->filter('news', ['title = ?', 'RF Alpha']);
		$rfEmptyFind->find(['name = ?', 'RF Missing Author']);
		$rfPlainFind = $rfEmptyFind->find(['name = ?', 'RelFilter Owner']);
		$test->expect(
			$rfPlainFind && count($rfPlainFind[0]->news) == 2,
			$type.': #22 an empty find() clears the relation filter configuration'
		);

		// a successful find() does not reuse its filter for the next find()
		$rfReuse = new AuthorModel();
		$rfReuse->filter('news', ['title = ?', 'RF Alpha']);
		$rfReuseFirst = $rfReuse->find(['name = ?', 'RelFilter Owner']);
		$rfReuseSecond = $rfReuse->find(['name = ?', 'RelFilter Owner']);
		$test->expect(
			$rfReuseFirst && count($rfReuseFirst[0]->news) == 1 &&
			$rfReuseSecond && count($rfReuseSecond[0]->news) == 2,
			$type.': #22 a successful find() does not reuse its relation filter'
		);

		// a filtered load() keeps its filter for deferred relation access only
		$rfLoad = new AuthorModel();
		$rfLoad->filter('news', ['title = ?', 'RF Alpha']);
		$rfLoad->load(['name = ?', 'RelFilter Owner']);
		$rfLoadFiltered = $rfLoad->news ? count($rfLoad->news) : 0;
		$rfLoad->load(['name = ?', 'RelFilter Owner']);
		$rfLoadPlain = $rfLoad->news ? count($rfLoad->news) : 0;
		$test->expect(
			$rfLoadFiltered == 1,
			$type.': #22 a filtered load() applies its filter to deferred relation access'
		);
		$test->expect(
			$rfLoadPlain == 2,
			$type.': #22 a later unfiltered load() returns the complete relation'
		);

		// pending filters of different relations stay separate
		$rfMulti = new AuthorModel();
		$rfMulti->filter('news', ['title = ?', 'RF Alpha']);
		$rfMulti->filter('profile', ['message = ?', 'RF missing profile']);
		$rfMulti->load(['name = ?', 'RelFilter Owner']);
		$rfMultiNews = $rfMulti->news ? count($rfMulti->news) : 0;
		$rfMultiProfile = $rfMulti->profile;
		$test->expect(
			$rfMultiNews == 1 && empty($rfMultiProfile),
			$type.': #22 pending relation filters stay isolated by relation key'
		);

		// a nested filter reaches the relation of the related model
		$rfNested = new NewsModel();
		$rfNested->filter('author.news', ['title = ?', 'RF Alpha']);
		$rfNested->load(['title = ?', 'RF Beta']);
		$rfNestedAuthor = $rfNested->author;
		$rfNestedFiltered = ($rfNestedAuthor && $rfNestedAuthor->news)
			? count($rfNestedAuthor->news) : 0;
		$rfNested->load(['title = ?', 'RF Beta']);
		$rfNestedPlainAuthor = $rfNested->author;
		$rfNestedPlain = ($rfNestedPlainAuthor && $rfNestedPlainAuthor->news)
			? count($rfNestedPlainAuthor->news) : 0;
		$test->expect(
			$rfNestedFiltered == 1 && $rfNestedPlain == 2,
			$type.': #22 a nested relation filter is consumed by one load() only'
		);

		// eager loading through with() still sees the filter
		$rfEager = new AuthorModel();
		$rfEagerResult = $rfEager
			->filter('news', ['title = ?', 'RF Alpha'])
			->with(['news'])
			->find(['name = ?', 'RelFilter Owner']);
		$rfEagerCount = ($rfEagerResult && $rfEagerResult[0]->news)
			? count($rfEagerResult[0]->news) : 0;
		$test->expect(
			$rfEagerCount == 1,
			$type.': #22 filtered eager loading through with() still applies the filter'
		);

		// a clone must not inherit the pending filter of its source
		$rfCloneSrc = new AuthorModel();
		$rfCloneSrc->filter('news', ['title = ?', 'RF Alpha']);
		$rfCloneSrc->load(['name = ?', 'RelFilter Owner']);
		$rfClone = clone $rfCloneSrc;
		$rfClone->load(['name = ?', 'RelFilter Owner']);
		$rfCloneCount = $rfClone->news ? count($rfClone->news) : 0;
		$rfCloneSrcCount = $rfCloneSrc->news ? count($rfCloneSrc->news) : 0;
		$test->expect(
			$rfCloneCount == 2 && $rfCloneSrcCount == 1,
			$type.': #22 a clone does not inherit the pending relation filter'
		);

		// a collection model keeps the filter for deferred access, not for its
		// own next query
		$rfColl = new AuthorModel();
		$rfColl->filter('news', ['title = ?', 'RF Alpha']);
		$rfCollResult = $rfColl->find(['name = ?', 'RelFilter Owner']);
		$rfCollFiltered = ($rfCollResult && $rfCollResult[0]->news)
			? count($rfCollResult[0]->news) : 0;
		$rfCollOwn = $rfCollResult ? $rfCollResult[0]->find(['name = ?', 'RelFilter Owner']) : false;
		$rfCollOwnCount = ($rfCollOwn && $rfCollOwn[0]->news) ? count($rfCollOwn[0]->news) : 0;
		$test->expect(
			$rfCollFiltered == 1 && $rfCollOwnCount == 2,
			$type.': #22 a collection model does not share its pending filter with its own query'
		);

		// remove the records of this block again, the following test files
		// share the data set created above
		(new NewsModel())->erase(['title IN ?', ['RF Alpha', 'RF Beta']]);
		(new ProfileModel())->erase(['message = ?', 'RF profile']);
		(new AuthorModel())->erase(['name = ?', 'RelFilter Owner']);

		///////////////////////////////////
		return $test->results();
	}
}
