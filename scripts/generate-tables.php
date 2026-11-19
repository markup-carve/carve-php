<?php

declare(strict_types=1);

/**
 * Generates src/Generated/Tables.php from the normative data files in spec/data.
 *
 * Run: php scripts/generate-tables.php [--check]
 * With --check it exits 1 when the checked-in file differs (the drift test runs the same code).
 */

$root = dirname(__DIR__);
$target = $root . '/src/Generated/Tables.php';

require_once $root . '/scripts/TableGenerator.php';
require_once $root . '/scripts/ImportTableGenerator.php';
require_once $root . '/scripts/WriterTableGenerator.php';

$files = [
    $target => TableGenerator::generate($root . '/spec/data'),
    $root . '/src/Generated/ImportTables.php' => ImportTableGenerator::generate($root . '/spec/data'),
    $root . '/src/Generated/WriterTables.php' => WriterTableGenerator::generate($root . '/spec/data'),
];
if (in_array('--check', $argv, true)) {
    foreach ($files as $file => $code) {
        $current = is_file($file) ? file_get_contents($file) : '';
        if ($current !== $code) {
            fwrite(STDERR, basename($file) . " is out of date: run php scripts/generate-tables.php\n");
            exit(1);
        }
    }
    echo "Tables.php, ImportTables.php and WriterTables.php are current\n";
    exit(0);
}
foreach ($files as $file => $code) {
    file_put_contents($file, $code);
    echo "wrote $file\n";
}
