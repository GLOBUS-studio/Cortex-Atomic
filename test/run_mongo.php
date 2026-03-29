<?php
/**
 * CLI Test Runner for Cortex-Atomic — MongoDB
 * Usage: php test/run_mongo.php
 *
 * Requires: MongoDB on localhost:27017 (default), no auth
 * A fresh database 'cortex_test' will be used and dropped automatically.
 */

// Autoloader
require __DIR__.'/vendor/autoload.php';

// Bootstrap F3
$f3 = \Base::instance();
$f3->set('QUIET', true);
$f3->set('DEBUG', 3);

// --- MongoDB connection ---
$dbName = 'cortex_test';
$db = new \DB\Mongo('mongodb://localhost:27017/', $dbName);

$dbs = [
    'mongo' => $db,
];

$results = [];
$passed  = 0;
$failed  = 0;

// Test Syntax
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Syntax();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Relations
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Relation();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Filter
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Filter();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Coverage (basic methods)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Coverage();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Collection & extended coverage
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Collection();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Coverage Extra (compare, rel, error paths, events, fluid mode, etc.)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Coverage_Extra();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Edge Cases (NULL handling, count TTL, diverse data)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_EdgeCases();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// NOTE: The following test suites are SQL-only and excluded from MongoDB runner:
//   - Test_Common      (no $db/$type params, hardcoded SQL calls)
//   - Test_Transaction (SQL transactions: begin/commit/rollback)
//   - Test_Constraints (FK, UNIQUE — SQL schema concepts)
//   - Test_Eager       (depends on SQL\Schema in seedData)
//   - Test_Hardened    (findByRawSQL, SQL parser, quotekey)
//   - Test_Schema      (SQL DDL: CREATE/ALTER/DROP TABLE)

// --- Output results ---
echo str_repeat('=', 70).PHP_EOL;
echo "  CORTEX-ATOMIC TEST RESULTS  [ MongoDB ]".PHP_EOL;
echo str_repeat('=', 70).PHP_EOL;

foreach ($results as $r) {
    $status = $r['status'] ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
    echo "  [{$status}] {$r['text']}".PHP_EOL;
    if ($r['status']) $passed++;
    else $failed++;
}

echo str_repeat('=', 70).PHP_EOL;
$total = $passed + $failed;
$color = $failed ? "\033[31m" : "\033[32m";
echo "  {$color}Total: {$total} | Passed: {$passed} | Failed: {$failed}\033[0m".PHP_EOL;
echo str_repeat('=', 70).PHP_EOL;

// Cleanup — drop test database
$db->drop();

exit($failed > 0 ? 1 : 0);
