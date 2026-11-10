<?php

use App\Controller;

class UpgradeOwner extends \DB\Cortex {
	protected $db = 'DB', $table = 'test_upgrade_owner', $fieldConf = [
		'name' => ['type' => 'VARCHAR128'],
		'news' => ['has-many' => ['UpgradeItem', 'owner']],
		'profile' => ['has-one' => ['UpgradeItem', 'owner']],
	];
}
class UpgradeItem extends \DB\Cortex {
	protected $db = 'DB', $table = 'test_upgrade_item', $fieldConf = [
		'title' => ['type' => 'VARCHAR128'],
		'owner' => ['belongs-to-one' => 'UpgradeOwner'],
		'options' => ['type' => self::DT_JSON],
		'targets' => ['belongs-to-many' => ['UpgradeTarget', 'code']],
	];
}
class UpgradeTarget extends \DB\Cortex {
	protected $db = 'DB', $table = 'test_upgrade_target', $primary = 'code', $fieldConf = [
		'code' => ['type' => 'VARCHAR128'],
		'title' => ['type' => 'VARCHAR128'],
	];
}
class UpgradeParser extends \DB\Cortex\CortexQueryParser {
	public $hashCalls = 0;
	protected function computeConfigurationHash($fields, $primary) {
		$this->hashCalls++;
		return parent::computeConfigurationHash($fields, $primary);
	}
}

class Test_Syntax {

	function run($db,$type)
	{
		$test = new \Test();
		$tname = 'test_cortex';

		\DB\Cortex::setdown($db, $tname);

		$fields = array(
			'title' => array('type' => \DB\SQL\Schema::DT_TEXT),
			'num1' => array('type' => \DB\SQL\Schema::DT_INT4),
			'num2' => array('type' => \DB\SQL\Schema::DT_INT4),
		);
		\DB\Cortex::setup($db, $tname, $fields);

		// adding some testing data
		$cx = new \DB\Cortex($db, $tname);
		$cx->title = 'bar1';
		$cx->save();
		$cx->reset();

		$cx->title = 'baz2';
		$cx->num1 = 1;
		$cx->save();
		$cx->reset();

		$cx->title = 'foo3';
		$cx->num1 = 4;
		$cx->save();
		$cx->reset();

		$cx->title = 'FOO4';
		$cx->num1 = 3;
		$cx->save();
		$cx->reset();

		$cx->title = 'foo5';
		$cx->num1 = 3;
		$cx->num2 = 5;
		$cx->save();
		$cx->reset();

		$cx->title = 'foo6';
		$cx->num1 = 3;
		$cx->num2 = 1;
		$cx->save();
		$cx->reset();

		$cx->title = 'foo7';
		$cx->num1 = 3;
		$cx->num2 = 10;
		$cx->save();
		$cx->reset();

		$cx->title = 'foo8';
		$cx->num1 = 5;
		$cx->save();
		$cx->reset();

		$cx->title = 'foo9';
		$cx->num1 = 8;
		$cx->save();
		$cx->reset();

		$result = $this->getResult($cx->find());

		$expected = array(
			0 => array(
				'title' => 'bar1',
			),
			1 => array(
				'num1' => 1,
				'title' => 'baz2',
			),
			2 => array(
				'num1' => 4,
				'title' => 'foo3',
			),
			3 => array(
				'num1' => 3,
				'title' => 'FOO4',
			),
			4 => array(
				'num1' => 3,
				'num2' => 5,
				'title' => 'foo5',
			),
			5 => array(
				'num1' => 3,
				'num2' => 1,
				'title' => 'foo6',
			),
			6 => array(
				'num1' => 3,
				'num2' => 10,
				'title' => 'foo7',
			),
			7 => array(
				'num1' => 5,
				'title' => 'foo8',
			),
			8 => array(
				'num1' => 8,
				'title' => 'foo9',
			),
		);

		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': init mapper, adding records'
		);

		// operator =
		$result = $this->getResult($cx->find(array('title = ?', 'foo7')));
		$expected = array(
			0 => array(
				'num1' => 3,
				'num2' => 10,
				'title' => 'foo7',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': operator check: ='
		);

		// operator >
		$result = $this->getResult($cx->find(array('num1 > ?', 4)));
		$expected = array(
			0 => array(
				'num1' => 5,
				'title' => 'foo8',
			),
			1 => array(
				'num1' => 8,
				'title' => 'foo9',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': operator check: >'
		);

		// operator >=
		$result = $this->getResult($cx->find(array('num1 >= ?', 5)));
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': operator check: >='
		);

		// operator <
		$result = $this->getResult($cx->find(array('num2 < ?', 2)));
		$expected = array(
			0 => array(
				'num1' => 3,
				'num2' => 1,
				'title' => 'foo6',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': operator check: <'
		);

		// operator <=
		$result = $this->getResult($cx->find(array('num2 <= ?', 1)));
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': operator check: <='
		);

		// operator without binding
		$result = $this->getResult($cx->find(array('num1 > 4')));
		$expected = array(
			0 => array(
				'num1' => 5,
				'title' => 'foo8',
			),
			1 => array(
				'num1' => 8,
				'title' => 'foo9',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': operator without binding'
		);

		// field comparision
		$result = $this->getResult($cx->find(
			array('num2 > num1', 1)));
		$expected = array(
			0 => array(
				'num1' => 3,
				'num2' => 5,
				'title' => 'foo5',
			),
			1 => array(
				'num1' => 3,
				'num2' => 10,
				'title' => 'foo7',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': check field comparision'
		);

		// lookahead search
		$result = $this->getResult($cx->find(array('title like ?', '%o6')));
		$expected = array(
			0 => array(
				'num1' => 3,
				'num2' => 1,
				'title' => 'foo6',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': lookahead search'
		);

		// lookbehind search
		$result = $this->getResult($cx->find(array('title like ?', 'bar%')));
		$expected = array(
			0 => array(
				'title' => 'bar1',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': lookbehind search'
		);

		// full search
		$result = $this->getResult($cx->find(array('title like ?', '%a%')));
		$expected = array(
			0 => array(
				'title' => 'bar1',
			),
			1 => array(
				'num1' => 1,
				'title' => 'baz2',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': full search'
		);

		// negated search
		$result = $this->getResult($cx->find(array('title not like ?', 'foo%')));
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': negated search'
		);

		// AND / OR chaining
		$result = $this->getResult($cx->find(
			array('(num2 < ? AND num1 > ?) OR title like ?', 2, 1, '%o9')));
		$expected = array(
			0 => array(
				'num1' => 3,
				'num2' => 1,
				'title' => 'foo6',
			),
			1 => array(
				'num1' => 8,
				'title' => 'foo9',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': check logical operator chaining'
		);

		// mixed AND/OR without parentheses — AND binds tighter, same rows as above
		$result = $this->getResult($cx->find(
			array('num2 < ? AND num1 > ? OR title like ?', 2, 1, '%o9')));
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': mixed AND/OR without parentheses (AND tighter than OR)'
		);

		// A OR B AND C — not (A OR B) AND C: foo9 matches LIKE even though num2 is unset
		$result = $this->getResult($cx->find(
			array('title like ? OR num1 > ? AND num2 < ?', '%o9', 1, 2)));
		$titles = array_column($result, 'title');
		sort($titles);
		$test->expect(
			$titles === ['foo6', 'foo9'],
			$type.': mixed OR/AND without parentheses (AND tighter than OR)'
		);

		if (str_contains($type, 'mongo')) {
			$qp = \DB\Cortex\CortexQueryParser::instance();
			$parsed = $qp->prepareFilter(
				['a = ? OR b = ? AND c = ?', 1, 2, 3], 'mongo', $db);
			$test->expect(
				isset($parsed['$or']) && count($parsed['$or']) === 2
				&& $parsed['$or'][0] === ['a' => 1]
				&& isset($parsed['$or'][1]['$and'])
				&& $parsed['$or'][1]['$and'] === [['b' => 2], ['c' => 3]],
				$type.': prepareFilter mixed OR/AND emits $or of $and-group'
			);
		}

		// check limit
		$result = $this->getResult($cx->find(null, array('limit' => 2)));
		$expected = array(
			0 => array(
				'title' => 'bar1',
			),
			1 => array(
				'num1' => 1,
				'title' => 'baz2',
			),
		);
		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': check limit'
		);

		// check order
		$result = $this->getResult($cx->find(
			array('num2 >= ?', 1), array('order' => 'num2 desc')));
		$expected = array(
			0 => array(
				'num1' => 3,
				'num2' => 10,
				'title' => 'foo7',
			),
			1 => array(
				'num1' => 3,
				'num2' => 5,
				'title' => 'foo5',
			),
			2 => array(
				'num1' => 3,
				'num2' => 1,
				'title' => 'foo6',
			),
		);

		$test->expect(
			json_encode($result) == json_encode($expected),
			$type.': check order'
		);

		// IN search
		$rc = $cx->find(array('num1 IN ?',array(4,5,8)));
		$result = $rc->getAll('title');
		sort($result);
		$test->expect(
			json_encode($result) == json_encode(array('foo3','foo8','foo9')),
			$type.': IN operator'
		);

		$rc = $cx->find(array('num1 IN ? && num2 > ? && num2 NOT IN ?',array(3,4),1,array(10)));
		$result = $rc->getAll('title');
		$test->expect(
			json_encode($result) == json_encode(array('foo5')),
			$type.': enhanced IN, NOT IN operator'
		);

		$result = $cx->find(array('num1 = ?',NULL));
		$test->expect(
			count($result) == 1 && $result[0]->title == 'bar1',
			$type.': check NULL'
		);

		$result = $cx->find(array('num2 != ?',NULL));
		$test->expect(
			count($result) == 3,
			$type.': check NOT NULL'
		);

		///////////////////////////////////
		$this->upgradeRegressions($db, $type, $test);
		return $test->results();
	}

	/**
	 * unify results for better comparison
	 */
	private function getResult($result)
	{
		$out = array();
		foreach ($result?:[] as $row) {
			$row = $row->cast();
			unset($row['_id']);
			unset($row['id']);
			ksort($row);
			foreach ($row as $col => $val) {
				if (empty($val) || is_null($val))
					unset($row[$col]);
			}
			$out[] = $row;
		}
		return $out;
	}

	private function upgradeRegressions($db, $type, $test) {
		$isSql = $db instanceof \DB\SQL;
		$probe = function ($label, $fn) use ($test, $type) {
			try { $test->expect((bool)$fn(), $type.': upgrade '.$label); }
			catch (\Throwable $e) { $test->expect(false, $type.': upgrade '.$label.' THREW '.$e->getMessage()); }
		};
		foreach (['UpgradeItem', 'UpgradeOwner', 'UpgradeTarget'] as $class)
			$class::setdown();
		if ($isSql) {
			$db->exec('CREATE TABLE '.$db->quotekey('test_upgrade_target')
				.' ('.$db->quotekey('code').' VARCHAR(128) NOT NULL PRIMARY KEY, '
				.$db->quotekey('title').' VARCHAR(128))');
		}
		UpgradeOwner::setup();
		UpgradeItem::setup();
		$a = new UpgradeOwner();
		$a->name = 'Owner';
		$a->save();
		$id = $a->getRaw('_id');
		foreach (['Alpha', 'Beta'] as $title) {
			$n = new UpgradeItem();
			$n->title = $title;
			$n->owner = $a;
			$n->save();
		}
		foreach (['load', 'find'] as $operation) {
			$probe('filter after '.$operation, function () use ($operation, $id) {
				$m = new UpgradeOwner();
				if ($operation == 'load') $m->load(['_id = ?', $id]);
				else $m = $m->find(['_id = ?', $id])[0];
				$m->filter('news', ['title = ?', 'Alpha']);
				return $m->news && count($m->news) === 1 && $m->news[0]->title === 'Alpha';
			});
		}
		$probe('filter after load has-one', function () use ($id) {
			$m = new UpgradeOwner();
			$m->load(['_id = ?', $id]);
			$m->filter('profile', ['title = ?', 'Missing']);
			return $m->profile === null;
		});
		$probe('collection record filter stays independent of shared relation cache', function () use ($id) {
			$r = (new UpgradeOwner())->find(['_id = ?', $id]);
			// A preloaded shared set must not defeat a later record filter.
			$r->setRelSet('news', null);
			$r[0]->filter('news', ['title = ?', 'Beta']);
			return $r[0]->news && count($r[0]->news) === 1 && $r[0]->news[0]->title === 'Beta';
		});
		$probe('post-load filter does not leak into next query', function () use ($id) {
			$m = new UpgradeOwner();
			$m->load(['_id = ?', $id]);
			$m->filter('news', ['title = ?', 'Alpha']);
			$count = count($m->news);
			$m->load(['_id = ?', $id]);
			return $count === 1 && count($m->news) === 2;
		});
		if ($isSql) {
			$probe('clear _id preserves primary key', function () {
				$n = new UpgradeItem();
				$n->title = 'Clear';
				$n->save();
				$id = $n->getRaw('_id');
				$n->clear('_id');
				$n->title = 'Updated';
				$n->save();
				$r = new UpgradeItem();
				return $r->load(['_id = ?', $id]) && $r->title === 'Updated';
			});
			$probe('clear ordinary field persists NULL', function () {
				$n = new UpgradeItem();
				$n->title = 'Clear ordinary';
				$n->save();
				$id = $n->getRaw('_id');
				$n->clear('title');
				$n->save();
				$r = new UpgradeItem();
				return $r->load(['_id = ?', $id]) && $r->title === null;
			});
			foreach (['{"a":1}' => ['a' => 1], '[1,2]' => [1,2]] as $text => $expected) {
				$probe('JSON container text '.$text, function () use ($text, $expected) {
					$n = new UpgradeItem();
					$n->options = $text;
					$set = $n->options;
					$n->save();
					$r = new UpgradeItem();
					$r->load(['_id = ?', $n->getRaw('_id')]);
					$cast = $r->cast(0);
					$c = new UpgradeItem();
					$c->copyfrom(['options' => $text]);
					return $set === $expected && $r->options === $expected
						&& $cast['options'] === $expected && $c->options === $expected;
				});
			}
			foreach (['hello', '', '0', false, 0, 'false', 'null', '"hello"', '{invalid'] as $value) {
				$probe('JSON scalar '.var_export($value, true), function () use ($value) {
					$n = new UpgradeItem();
					$n->options = $value;
					$set = $n->options;
					$n->save();
					$r = new UpgradeItem();
					$r->load(['_id = ?', $n->getRaw('_id')]);
					$c = new UpgradeItem();
					$cast = $r->cast(null, 0);
					$c->copyfrom(['options' => $r->getRaw('options')]);
					return $set === $value && $r->options === $value && $cast['options'] === $value && $c->options === $value;
				});
			}
		}
		$target = new UpgradeTarget();
		$target->code = '123';
		$target->title = 'Numeric text';
		$target->save();
		$probe('BTM numeric text key saved by ORM', function () {
			$n = new UpgradeItem();
			$n->title = 'ORM numeric key';
			$n->targets = ['123'];
			$n->save();
			$q = new UpgradeItem();
			$q->has('targets', ['title = ?', 'Numeric text']);
			$r = $q->find(['title = ?', 'ORM numeric key']);
			return $r && count($r) === 1;
		});
		if ($isSql) foreach (['[123]', '["123"]'] as $json) {
			$probe('BTM existing JSON '.$json, function () use ($db, $json) {
				$db->exec('INSERT INTO '.$db->quotekey('test_upgrade_item')
					.' ('.$db->quotekey('title').','.$db->quotekey('targets').') VALUES (?,?)', [$json, $json]);
				$q = new UpgradeItem();
				$q->has('targets', ['title = ?', 'Numeric text']);
				$r = $q->find(['title = ?', $json]);
				return $r && count($r) === 1;
			});
		}
		$probe('parser configuration fingerprint reused and invalidated', function () use ($db, $isSql) {
			$p = new UpgradeParser();
			$fields = ['title' => ['type' => 'VARCHAR128']];
			$engine = $isSql ? 'sql' : 'jig';
			for ($i = 0; $i < 5; $i++) $p->prepareFilter(['_id = ?', 'abc'], $engine, $db, $fields, 'id');
			$once = $p->hashCalls === 1;
			$fields['title']['type'] = 'TEXT';
			$p->prepareFilter(['_id = ?', 'abc'], $engine, $db, $fields, 'id');
			$changed = $p->hashCalls === 2;
			$r = $p->prepareFilter(['_id = ?', 'abc'], $engine, $db, $fields, 'code');
			return $once && $changed && $p->hashCalls === 3
				&& (!$isSql || str_contains($r[0], 'code'));
		});
		foreach (['UpgradeItem', 'UpgradeOwner', 'UpgradeTarget'] as $class)
			$class::setdown();
	}
}
