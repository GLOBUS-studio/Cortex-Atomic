<?php

/**
 * Regression/coverage tests for fixes #7–#21 applied in Cortex-Atomic.
 *
 * Each section is numbered to match the original issue.
 *
 * Notes on order:
 *  - Tests that need loaded data (#7–#16, #18) run before setup/setdown tests.
 *  - Tests #12 and #17 call setdown()/setup(); they come last among SQL tests.
 */
class Test_Fixes {

    function run($db, $type)
    {
        $test  = new \Test();
        $isSql   = str_contains($type, 'sql');
        $isJig   = str_contains($type, 'jig');
        $isMongo = str_contains($type, 'mongo');

        // ====== SETUP ======
        \AuthorModel::setdown();
        \TagModel::setdown();
        \NewsModel::setdown();
        \ProfileModel::setdown();
        \AuthorModel::setup();
        \ProfileModel::setup();
        \TagModel::setup();
        \NewsModel::setup();

        // Seed: 5 authors
        $names = ['Alice','Bob','Charlie','Diana','Eve'];
        foreach ($names as $n) {
            $a = new \AuthorModel();
            $a->name = $n;
            $a->mail = strtolower($n).'@fix.test';
            $a->save();
            $a->reset();
        }

        // Seed: 3 news items linked to Alice
        $alice = new \AuthorModel();
        $alice->load(['name = ?','Alice']);
        foreach (['Alpha','Beta','Gamma'] as $title) {
            $nx = new \NewsModel();
            $nx->title  = $title;
            $nx->text   = 'body_'.$title;
            $nx->author = $alice->id;
            $nx->save();
            $nx->reset();
        }

        // ====================================================================
        // #7  QueryParser LRU cache – cache stays bounded after overflow
        // ====================================================================
        $qp  = \DB\Cortex\CortexQueryParser::instance();
        $ref = new \ReflectionObject($qp);

        $cacheLimit = null;
        if ($ref->hasProperty('queryCacheLimit')) {
            $cacheLimit = $ref->getProperty('queryCacheLimit')->getValue($qp);
        }

        $cacheProp = null;
        if ($ref->hasProperty('queryCache')) {
            $cacheProp = $ref->getProperty('queryCache');
        }

        if ($cacheLimit && $cacheProp) {
            // Run ($cacheLimit + 5) distinct queries to overflow the cache
            $cx = new \AuthorModel();
            for ($i = 0; $i < $cacheLimit + 5; $i++) {
                @$cx->find(['name = ?','no_match_lru_'.$i], ['limit'=>1]);
            }
            $cacheSize = count($cacheProp->getValue($qp));
            $test->expect(
                $cacheSize <= $cacheLimit,
                $type.': #7 LRU cache: size bounded (<=limit) after overflow'
            );
        } else {
            $test->expect(true, $type.': #7 LRU cache: skip (property unavailable)');
        }

        // ====================================================================
        // #8  CortexCollection clone – independent cid and empty relSets
        // ====================================================================
        $collA = (new \AuthorModel())->find();
        if ($collA) {
            $collA->setRelSet('rk', ['dummy']);
            $cloneA = clone $collA;

            $test->expect(
                $cloneA instanceof \DB\Cortex\CortexCollection,
                $type.': #8 clone: result is CortexCollection'
            );
            $cidOrig  = (new \ReflectionProperty($collA, 'cid'))->getValue($collA);
            $cidClone = (new \ReflectionProperty($cloneA, 'cid'))->getValue($cloneA);
            $test->expect(
                $cidOrig !== $cidClone,
                $type.': #8 clone: clone has different cid'
            );
            $test->expect(
                $cloneA->getRelSet('rk') === null,
                $type.': #8 clone: cloned collection has empty relSets'
            );
        } else {
            for ($i = 0; $i < 3; $i++)
                $test->expect(true, $type.': #8 clone: skip');
        }

        // ====================================================================
        // #9  Reflection cache – repeated find() returns consistent results
        // ====================================================================
        $ra = new \AuthorModel();
        $r1 = $ra->find(['name = ?','Alice']);
        $r2 = $ra->find(['name = ?','Alice']);
        $test->expect(
            $r1 && $r2 && $r1->count() === 1 && $r2->count() === 1,
            $type.': #9 reflection cache: repeated find() returns same result'
        );

        // ====================================================================
        // #10 getAll/castAll safe iteration – all items returned with rel load
        // ====================================================================
        $coll5 = (new \AuthorModel())->find();
        if ($coll5) {
            $casted = $coll5->castAll(1); // rel_depths=1 triggers smart loading
            $test->expect(
                count($casted) === 5,
                $type.': #10 castAll(1) with smart loading returns all 5 items'
            );
        } else {
            $test->expect(true, $type.': #10 castAll: skip');
        }

        // ====================================================================
        // #11 resolveConfiguration() cache – idempotent result
        // ====================================================================
        $c1 = \AuthorModel::resolveConfiguration();
        $c2 = \AuthorModel::resolveConfiguration();
        $test->expect(
            $c1['table'] === $c2['table']
            && $c1['primary'] === $c2['primary']
            && array_keys($c1['fieldConf']) === array_keys($c2['fieldConf']),
            $type.': #11 resolveConfiguration() cache: idempotent'
        );

        // ====================================================================
        // #14 getIterator() – foreach over loaded model yields field data
        // ====================================================================
        $aIter = new \AuthorModel();
        $aIter->load(['name = ?','Alice']);
        $fields = [];
        foreach ($aIter as $k => $v) {
            $fields[$k] = $v;
        }
        $test->expect(
            isset($fields['name']) && $fields['name'] === 'Alice',
            $type.': #14 getIterator: foreach yields name field'
        );
        $test->expect(
            isset($fields['mail']) && $fields['mail'] === 'alice@fix.test',
            $type.': #14 getIterator: foreach yields mail field'
        );

        // ====================================================================
        // #15 emit() – value unchanged when method doesn't exist
        // ====================================================================
        $aEmit = new class($db, 'author') extends \DB\Cortex {
            // no custom get_name / set_name methods
        };
        $aEmit->setFieldConfiguration(\AuthorModel::resolveConfiguration()['fieldConf']);
        $aEmit->load(['name = ?','Bob']);

        $emitRef = new \ReflectionClass($aEmit);
        $emitResult = null;
        if ($emitRef->hasMethod('emit')) {
            $em = $emitRef->getMethod('emit');
            $emitResult = $em->invoke($aEmit, 'get_name', 'unchanged_val');
        }
        $test->expect(
            $emitResult === 'unchanged_val',
            $type.': #15 emit(): non-existent method leaves value unchanged'
        );

        // onget callback registered via onget fires and transforms value
        $aOnget = new \AuthorModel();
        $aOnget->load(['name = ?','Charlie']);
        $captured = null;
        $aOnget->onget('name', function($self, $val) use (&$captured) {
            $captured = strtoupper($val);
            return $captured;
        });
        $gotName  = $aOnget->get('name');
        $gotName2 = $aOnget->get('name'); // second call: trigger cached under event key
        $test->expect(
            $gotName === 'CHARLIE' && $gotName2 === 'CHARLIE',
            $type.': #15 emit(): onget callback fires on first and cached second call'
        );

        // ====================================================================
        // #16 $trigger property – declared (not dynamic), initializes as []
        // ====================================================================
        $aTrigger      = new \AuthorModel();
        $rTriggerClass = new \ReflectionClass($aTrigger);
        $test->expect(
            $rTriggerClass->hasProperty('trigger'),
            $type.': #16 $trigger property declared on Cortex class'
        );
        if ($rTriggerClass->hasProperty('trigger')) {
            $trigVal = $rTriggerClass->getProperty('trigger')->getValue($aTrigger);
            $test->expect(
                is_array($trigVal) && count($trigVal) === 0,
                $type.': #16 $trigger initializes as empty array'
            );
        } else {
            $test->expect(true, $type.': #16 $trigger: skip');
        }

        // ====================================================================
        // #18 skip/first/last – navigation advances correctly, essentials kept
        // ====================================================================
        $aNav = new \AuthorModel();
        // load all records ordered (no limit — query holds the full result set)
        $aNav->load(null, ['order'=>'name asc']);
        $firstName = $aNav->dry() ? null : $aNav->name;
        $aNav->skip(1);
        $secondName = $aNav->dry() ? null : $aNav->name;
        $test->expect(
            $firstName !== null && $secondName !== null && $firstName !== $secondName,
            $type.': #18 skip(1) advances to a different record'
        );
        $aNav->first();
        $backToFirst = $aNav->dry() ? null : $aNav->name;
        $test->expect(
            $backToFirst === $firstName,
            $type.': #18 first() returns to first record after skip()'
        );

        // ====================================================================
        // #13 erase() guard – normal erase-by-filter doesn't throw
        // ====================================================================
        $aErase = new \AuthorModel();
        $aErase->name = 'EraseMe';
        $aErase->mail = 'del@fix.test';
        $aErase->save();
        $aErase->reset();
        $beforeCount = (new \AuthorModel())->count();
        $threw13 = false;
        try {
            (new \AuthorModel())->erase(['name = ?','EraseMe']);
        } catch (\Exception $e) {
            $threw13 = true;
        }
        $afterCount = (new \AuthorModel())->count();
        $test->expect(
            !$threw13 && $afterCount === ($beforeCount - 1),
            $type.': #13 erase(filter): no exception, record removed'
        );

        // ====================================================================
        // #20 QueryParser E_INVALID_FIELD_NAME constant + Jig validation
        // ====================================================================
        $qpRef = new \ReflectionClass(\DB\Cortex\CortexQueryParser::instance());
        $test->expect(
            $qpRef->hasConstant('E_INVALID_FIELD_NAME'),
            $type.': #20 E_INVALID_FIELD_NAME constant defined on CortexQueryParser'
        );
        if ($isJig) {
            // Jig: invalid field name must throw
            $threw20 = false;
            try {
                $jm = $qpRef->getMethod('_jig_parse_filter');
                $jm->invoke(\DB\Cortex\CortexQueryParser::instance(),
                    ['1=1; DROP TABLE users --= ?','val'], []);
            } catch (\Exception $e) {
                $threw20 = true;
            }
            $test->expect(
                $threw20,
                $type.': #20 Jig: malicious field name throws exception'
            );
        } else {
            $test->expect(true, $type.': #20 Jig validation skip (non-Jig DB)');
        }

        // ====================================================================
        // #12 getTables() cache – setup/setdown roundtrip works correctly
        //     (runs after data tests; recreates table)
        // ====================================================================
        \AuthorModel::setdown();
        \AuthorModel::setup();
        $aSetup = new \AuthorModel();
        $aSetup->name = 'SetupCheck';
        $aSetup->mail = 'sc@fix.test';
        $aSetup->save();
        $aSetup->reset();
        $aSetup->load(['name = ?','SetupCheck']);
        $test->expect(
            !$aSetup->dry() && $aSetup->name === 'SetupCheck',
            $type.': #12 getTables() cache: setup/setdown roundtrip works'
        );

        // ====================================================================
        // #17 setdown() array_key_exists fix – self-ref m:m roundtrip + relation
        // ====================================================================
        // AuthorModel table was just re-created in #12, rebuild data
        $aF1 = new \AuthorModel(); $aF1->name = 'FriendA'; $aF1->mail = 'fa@fix.test'; $aF1->save();
        $aF2 = new \AuthorModel(); $aF2->name = 'FriendB'; $aF2->mail = 'fb@fix.test'; $aF2->save();
        $aF1->friends = [$aF2->id];
        $aF1->save();
        $aF1->reset();
        $aF1->load(['name = ?','FriendA']);
        $fCount = $aF1->friends ? $aF1->friends->count() : 0;
        $test->expect(
            $fCount === 1,
            $type.': #17 setdown() fix: self-ref m:m setdown/setup + relation works'
        );

        // ====================================================================
        // #21 Schema public→protected – getters work; $db direct access blocked
        // ====================================================================
        if ($isSql) {
            $schema = new \DB\Cortex\Schema\Schema($db);

            $test->expect(
                $schema->getDb() instanceof \DB\SQL,
                $type.': #21 Schema::getDb() returns DB\SQL instance'
            );
            $dataTypes = $schema->getDataTypes();
            $test->expect(
                is_array($dataTypes) && isset($dataTypes['INT4']),
                $type.': #21 Schema::getDataTypes() returns array with INT4'
            );
            $defTypes = $schema->getDefaultTypes();
            $test->expect(
                is_array($defTypes) && isset($defTypes['CUR_STAMP']),
                $type.': #21 Schema::getDefaultTypes() has CUR_STAMP'
            );
            // Direct $db access is protected -> must throw an Error
            $blocked = false;
            try { $_ = $schema->db; } catch (\Error $e) { $blocked = true; }
            $test->expect(
                $blocked,
                $type.': #21 Schema::$db protected – direct access throws Error'
            );

            // TableBuilder getName/getSchema + Column __get
            $t21 = 'test_fix21_col';
            if (in_array($t21, $schema->getTables())) $schema->dropTable($t21);
            $tc = $schema->createTable($t21);
            $tc->addColumn('c1')->type(\DB\Cortex\Schema\Schema::DT_VARCHAR256);
            $tc->build();
            $test->expect(
                in_array($t21, $schema->getTables()),
                $type.': #21 TableBuilder::build() creates table successfully'
            );
            $tm  = $schema->alterTable($t21);
            $col = $tm->addColumn('c2')->type(\DB\Cortex\Schema\Schema::DT_INT4);
            $test->expect(
                $col->type === 'INT4',
                $type.': #21 Column::__get() magic property read works'
            );
            $schema->dropTable($t21);
        } else {
            for ($i = 0; $i < 6; $i++)
                $test->expect(true, $type.': #21 Schema skip (non-SQL)');
        }

        // ====================================================================
        // #22 Operator parsing: lowercase IN (SQL) + word boundaries (Mongo)
        // ====================================================================
        $qp22 = new \DB\CortexQueryParser();

        if ($isSql) {
            // lowercase `in` must expand exactly like uppercase `IN`
            $f22a = $qp22->prepareFilter(['id in ?', [1,2,3]], 'sql', $db);
            $test->expect(
                is_array($f22a) && str_contains($f22a[0], 'IN (?,?,?)') && count($f22a) === 4,
                $type.': #22 lowercase `in` expands to IN (?,?,?)'
            );

            // mixed case plus extra spaces
            $f22b = $qp22->prepareFilter(['id  In   ?', [1,2]], 'sql', $db);
            $test->expect(
                is_array($f22b) && str_contains($f22b[0], 'IN (?,?)') && count($f22b) === 3,
                $type.': #22 mixed-case `In` with extra spaces expands'
            );

            // lowercase `not in` keeps the NOT prefix
            $f22c = $qp22->prepareFilter(['id not in ?', [1,2]], 'sql', $db);
            $test->expect(
                is_array($f22c) && preg_match('/not\s+in\s*\(\?,\?\)/i', $f22c[0]) === 1,
                $type.': #22 lowercase `not in` keeps NOT and expands'
            );

            $normalizeLike = (new \ReflectionClass($qp22))
                ->getMethod('normalizeParenthesizedBind');
            $quotedLikeInput = "title = 'literal LIKE(?) text'";
            $f22Quoted = $normalizeLike->invoke(
                $qp22, $quotedLikeInput, 'LIKE', false);
            $test->expect(
                $f22Quoted === $quotedLikeInput,
                $type.': #22 LIKE(?) text inside a quoted literal is unchanged'
            );
        } else {
            for ($i = 0; $i < 4; $i++)
                $test->expect(true, $type.': #22 SQL parser skip (non-SQL)');
        }

        if ($isMongo) {
            // `in` inside an identifier must not be read as the IN operator
            $f22d = $qp22->prepareFilter(['domain = ?', 'x'], 'mongo', $db);
            $test->expect(
                is_array($f22d) && array_key_exists('domain', $f22d) && $f22d['domain'] === 'x',
                $type.': #22 Mongo: `domain` not split on inner `in`'
            );

            $f22e = $qp22->prepareFilter(['min > ?', 5], 'mongo', $db);
            $test->expect(
                is_array($f22e) && isset($f22e['min']['$gt']) && $f22e['min']['$gt'] == 5,
                $type.': #22 Mongo: `min` not split on inner `in`'
            );

            // `like` inside an identifier must not be read as the LIKE operator
            $f22f = $qp22->prepareFilter(['dislike = ?', 'x'], 'mongo', $db);
            $test->expect(
                is_array($f22f) && array_key_exists('dislike', $f22f) && $f22f['dislike'] === 'x',
                $type.': #22 Mongo: `dislike` not split on inner `like`'
            );

            // field name containing `in` combined with the real IN operator
            $f22g = $qp22->prepareFilter(['points not in ?', [1,2]], 'mongo', $db);
            $test->expect(
                is_array($f22g) && isset($f22g['points']['$nin'])
                    && $f22g['points']['$nin'] === [1,2],
                $type.': #22 Mongo: `points not in ?` maps to $nin on `points`'
            );

            // lowercase operator still resolves
            $f22h = $qp22->prepareFilter(['name in ?', ['Alice','Bob']], 'mongo', $db);
            $test->expect(
                is_array($f22h) && isset($f22h['name']['$in']),
                $type.': #22 Mongo: lowercase `in` maps to $in'
            );

            // Exact keyword field names are identifiers when followed by a
            // symbolic comparison operator.
            $f22i = $qp22->prepareFilter(['in = ?', 'x'], 'mongo', $db);
            $test->expect(
                is_array($f22i) && array_key_exists('in', $f22i) && $f22i['in'] === 'x',
                $type.': #22 Mongo: field named `in` is not parsed as operator'
            );

            $f22j = $qp22->prepareFilter(['like = ?', 'x'], 'mongo', $db);
            $test->expect(
                is_array($f22j) && array_key_exists('like', $f22j) && $f22j['like'] === 'x',
                $type.': #22 Mongo: field named `like` is not parsed as operator'
            );

            // A keyword-named field must still support that keyword as the
            // actual operator; splitting must use the matched operator offset.
            $f22m = $qp22->prepareFilter(['in IN ?', [1,2]], 'mongo', $db);
            $test->expect(
                is_array($f22m) && isset($f22m['in']['$in'])
                    && $f22m['in']['$in'] === [1,2],
                $type.': #22 Mongo: field named `in` supports the IN operator'
            );

            $f22n = $qp22->prepareFilter(['like LIKE ?', 'Al%'], 'mongo', $db);
            $test->expect(
                is_array($f22n) && isset($f22n['like'])
                    && $f22n['like'] instanceof \MongoDB\BSON\Regex,
                $type.': #22 Mongo: field named `like` supports the LIKE operator'
            );

            foreach ([[], 'scalar'] as $invalidIn22) {
                $inError22 = null;
                try {
                    $qp22->prepareFilter(['name IN ?', $invalidIn22], 'mongo', $db);
                } catch (\Throwable $e) {
                    $inError22 = $e;
                }
                $test->expect(
                    $inError22 instanceof \Exception
                        && $inError22->getMessage() ===
                            \DB\CortexQueryParser::E_INBINDVALUE,
                    $type.': #22 Mongo: invalid IN bind is rejected explicitly'
                );
            }

            $f22k = $qp22->prepareFilter(['name LIKE(?)', 'Al%'], 'mongo', $db);
            $test->expect(
                is_array($f22k) && isset($f22k['name'])
                    && $f22k['name'] instanceof \MongoDB\BSON\Regex,
                $type.': #22 Mongo: LIKE(?) remains accepted'
            );

            $f22l = $qp22->prepareFilter(['name NOT LIKE(?)', 'Al%'], 'mongo', $db);
            $test->expect(
                is_array($f22l) && isset($f22l['name']['$not'])
                    && $f22l['name']['$not'] instanceof \MongoDB\BSON\Regex,
                $type.': #22 Mongo: NOT LIKE(?) remains accepted'
            );
        } else {
            for ($i = 0; $i < 13; $i++)
                $test->expect(true, $type.': #22 Mongo parser skip (non-Mongo)');
        }

        // end-to-end: lowercase `in` must match uppercase `IN` on every engine
        foreach (['In22A','In22B','In22C'] as $n22) {
            $seed22 = new \AuthorModel();
            $seed22->name = $n22;
            $seed22->save();
        }
        $names22 = ['In22A','In22B'];
        $ref22 = (new \AuthorModel())->find(['name IN ?', $names22]);
        $low22 = (new \AuthorModel())->find(['name in ?', $names22]);
        $test->expect(
            $ref22 && $low22 && count($ref22) === count($low22) && count($low22) > 0,
            $type.': #22 find() lowercase `in` matches uppercase `IN`'
        );

        $refN22 = (new \AuthorModel())->find(['name NOT IN ?', $names22]);
        $lowN22 = (new \AuthorModel())->find(['name not in ?', $names22]);
        $test->expect(
            $refN22 && $lowN22 && count($refN22) === count($lowN22) && count($lowN22) > 0,
            $type.': #22 find() lowercase `not in` matches uppercase `NOT IN`'
        );

        return $test->results();
    }
}
