<?php
require __DIR__.'/vendor/autoload.php';

$f3 = \Base::instance();
$f3->set('QUIET', true);
$f3->set('DEBUG', 0);

$dataDir = __DIR__.'/test/data';
if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);

$db = new \DB\SQL('sqlite:'.$dataDir.'/test-quick.db');
$f3->set('DB', $db);
echo "DB OK\n";

$fields = [
    'title' => ['type' => \DB\SQL\Schema::DT_TEXT],
    'num1' => ['type' => \DB\SQL\Schema::DT_INT4],
];
\DB\Cortex::setup($db, 'test_quick', $fields);
echo "Setup OK\n";

$cx = new \DB\Cortex($db, 'test_quick');
$cx->title = 'hello';
$cx->save();
echo "Save OK\n";

$cx->reset();
$cx->load(['title = ?', 'hello']);
echo "Load OK: title=" . $cx->title . "\n";

\DB\Cortex::setdown($db, 'test_quick');
echo "Setdown OK\n";

@unlink($dataDir.'/test-quick.db');
echo "ALL BASIC TESTS PASSED\n";
