<?php
/**
 * CLI Test Runner for Cortex-Atomic — MySQL
 * Usage: php test/run_mysql.php
 *
 * Requires: MySQL on 127.0.0.1:3306, user root, password root
 * A fresh database 'cortex_test' will be created and dropped automatically.
 */

// Autoloader
require __DIR__.'/vendor/autoload.php';

// Bootstrap F3
$f3 = \Base::instance();
$f3->set('QUIET', true);
$f3->set('DEBUG', 3);

// --- MySQL connection ---
$dsn  = 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$user = 'root';
$pass = 'root';
$dbName = 'cortex_test';

// Create the test database (drop if leftover from failed run)
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$pdo->exec("CREATE DATABASE `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
unset($pdo);

$db = new \DB\SQL("mysql:host=127.0.0.1;port=3306;dbname={$dbName};charset=utf8mb4", $user, $pass);
$db->exec("SET NAMES utf8mb4");

$dbs = [
    'sql-mysql' => $db,
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
$f3->set('DB', $dbs['sql-mysql']);
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

// Test Fixes (#7-#21 regression/coverage tests)
foreach ($dbs as $type => $db) {
    $f3->set('DB', $db);
    $test = new \Test_Fixes();
    $results = array_merge($results, (array)$test->run($db, $type));
}

// --- Output results ---
echo str_repeat('=', 70).PHP_EOL;
echo "  CORTEX-ATOMIC TEST RESULTS  [ MySQL ]".PHP_EOL;
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
$pdo = new PDO($dsn, $user, $pass);
$pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
unset($pdo);

exit($failed > 0 ? 1 : 0);
