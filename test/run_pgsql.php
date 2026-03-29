<?php
/**
 * CLI Test Runner for Cortex-Atomic — PostgreSQL
 * Usage: php test/run_pgsql.php
 *
 * Requires: PostgreSQL on 127.0.0.1:5432, user postgres, password postgres
 * A fresh database 'cortex_test' will be created and dropped automatically.
 */

// Autoloader
require __DIR__.'/vendor/autoload.php';

// Bootstrap F3
$f3 = \Base::instance();
$f3->set('QUIET', true);
$f3->set('DEBUG', 3);

// --- PostgreSQL connection ---
$dsn  = 'pgsql:host=127.0.0.1;port=5432';
$user = 'postgres';
$pass = 'postgres';
$dbName = 'cortex_test';

// Create the test database (drop if leftover from failed run)
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS {$dbName}");
$pdo->exec("CREATE DATABASE {$dbName} ENCODING 'UTF8'");
unset($pdo);

$db = new \DB\SQL("pgsql:host=127.0.0.1;port=5432;dbname={$dbName}", $user, $pass);

$dbs = [
    'sql-pgsql' => $db,
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

// Test Common (needs DB set)
$f3->set('DB', $dbs['sql-pgsql']);
$test = new \Test_Common();
$results = array_merge($results, (array)$test->run());

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

// Test Transactions (implicit tx, rollback, nesting, user-managed)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Transaction();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Constraints (UNIQUE, FK, ConstraintAdapter)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Constraints();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Eager Loading (with() API)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Eager();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Edge Cases (NULL handling, count TTL, diverse data)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_EdgeCases();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Hardened (stress tests, edge cases, untested code paths)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Hardened();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// Test Schema (forked DB\Cortex\Schema classes and backward-compatible alias)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Schema();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// --- Output results ---
echo str_repeat('=', 70).PHP_EOL;
echo "  CORTEX-ATOMIC TEST RESULTS  [ PostgreSQL ]".PHP_EOL;
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
// Close active connections first
unset($db);
$dbs = [];
$f3->clear('DB');

$pdo = new PDO($dsn, $user, $pass);
// Terminate other connections to the database
$pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='{$dbName}' AND pid <> pg_backend_pid()");
$pdo->exec("DROP DATABASE IF EXISTS {$dbName}");
unset($pdo);

exit($failed > 0 ? 1 : 0);
