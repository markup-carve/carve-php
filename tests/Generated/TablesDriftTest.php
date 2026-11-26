<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Generated;

use PHPUnit\Framework\TestCase;
use TableGenerator;

/**
 * The PHP tables are generated from spec/0.2/data; a hand edit or a changed data file fails here.
 */
final class TablesDriftTest extends TestCase
{
    /**
     * @return void
     */
    public function testGeneratedTablesMatchSpecData(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/scripts/TableGenerator.php';
        $this->assertSame(
            TableGenerator::generate($root . '/spec/data'),
            (string)file_get_contents($root . '/src/Generated/Tables.php'),
            'Tables.php is out of date: run php scripts/generate-tables.php',
        );
    }

    /**
     * @return void
     */
    public function testPinnedSpecDataMatchesClone(): void
    {
        $clone = dirname(__DIR__, 3) . '/carve/spec/0.2/data';
        if (!is_dir($clone)) {
            $this->markTestSkipped('no spec clone next to the repository');
        }
        $local = dirname(__DIR__, 2) . '/spec/data';
        foreach (glob($local . '/*.json') ?: [] as $file) {
            $this->assertFileEquals($clone . '/' . basename($file), $file);
        }
    }
}
