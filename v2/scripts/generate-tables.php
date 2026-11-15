<?php

declare(strict_types=1);

/**
 * Generates v2/src/Generated/Tables.php from the normative data files in v2/spec/data.
 *
 * Run: php v2/scripts/generate-tables.php [--check]
 * With --check it exits 1 when the checked-in file differs (the drift test runs the same code).
 */

$root = dirname(__DIR__);
$target = $root . '/src/Generated/Tables.php';

require_once $root . '/scripts/TableGenerator.php';

$code = TableGenerator::generate($root . '/spec/data');
if (in_array('--check', $argv, true)) {
    $current = is_file($target) ? file_get_contents($target) : '';
    if ($current !== $code) {
        fwrite(STDERR, "Tables.php is out of date: run php v2/scripts/generate-tables.php\n");
        exit(1);
    }
    echo "Tables.php is current\n";
    exit(0);
}
file_put_contents($target, $code);
echo "wrote $target\n";
