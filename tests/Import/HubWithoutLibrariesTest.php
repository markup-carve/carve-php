<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use PHPUnit\Framework\TestCase;

/**
 * league/commonmark and php-collective/djot are suggested, not required: without them a hub import fails with an
 * import error and an install hint, and HTML import keeps working.
 */
final class HubWithoutLibrariesTest extends TestCase
{
    public function testHubImportRefusesCleanlyWithoutTheLibraries(): void
    {
        $script = dirname(__DIR__) . '/fixtures/hub-without-libraries/run.php';
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
        $this->assertIsString($output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        $this->assertFalse($report['libraries']);
        $this->assertSame('markdown import requires the league/commonmark package: composer require league/commonmark', $report['markdown']);
        $this->assertSame('gfm import requires the league/commonmark package: composer require league/commonmark', $report['gfm']);
        $this->assertSame('djot import requires the php-collective/djot package: composer require php-collective/djot', $report['djot']);
        $this->assertSame("Hi\n", $report['html']);
        $this->assertSame(
            [2, '', "carve[import-input-invalid]: <stdin>: djot import requires the php-collective/djot package: composer require php-collective/djot\n"],
            $report['cli'],
        );
        $this->assertSame(
            [2, '', "carve[input-invalid]: gfm import requires the league/commonmark package: composer require league/commonmark\n"],
            $report['directory'],
        );
        $this->assertFalse($report['directoryOutput']);
    }
}
