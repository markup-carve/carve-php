<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Import\HubImporter;
use PHPUnit\Framework\TestCase;

/**
 * `carve import`: the categories and exit codes of C2-M-006 and C2-W-101.
 */
final class ImportCommandTest extends TestCase
{
    /**
     * @param array<string> $args
     * @param string $stdin
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function call(array $args, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $stdin);
        rewind($in);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function testImportWritesTheDiagnosticsToStandardError(): void
    {
        [$status, $out, $err] = self::call(['import', '--from=html'], '<p onclick="x()">a</p>');
        $this->assertSame(0, $status);
        $this->assertSame("<stdin>:1:1: warning import-attribute-dropped Attribute onclick dropped: an event handler\n", $err);
        $this->assertNotSame('', $out);
    }

    /**
     * carve-php#5965: the command line skips the tree check and writes the source the library writes.
     */
    public function testDjotImportWritesTheLibrarySource(): void
    {
        $djot = str_repeat('[a [b] c](x) ', 40) . "\n\n- _x_ [y](z)\n";
        $result = HubImporter::import($djot, 'djot');
        [$status, $out, $err] = self::call(['import', '--from', 'djot'], $djot);

        $this->assertSame(0, $status, $err);
        $this->assertSame($result->source, $out);
        $this->assertStringStartsWith('\\[a [b\\] c](x) ', $out);
    }

    public function testRawHtmlFlagKeepsComments(): void
    {
        [$status, $out] = self::call(['import', '--from', 'html', '--raw-html', '-'], '<!--c-->');
        $this->assertSame(0, $status);
        $this->assertStringContainsString('<!--c-->', $out);
    }

    public function testUsageAndInputErrors(): void
    {
        $this->assertSame(2, self::call(['import'])[0]);
        $this->assertSame(2, self::call(['import', '--from=rst'])[0]);
        $this->assertSame(2, self::call(['import', '--from=html', '--bogus'])[0]);
        [$status, , $err] = self::call(['import', '--from=html', '/nonexistent/x.html']);
        $this->assertSame(2, $status);
        $this->assertStringStartsWith('carve[input-unreadable]: ', $err);
    }

    public function testC2M006TooDeepInputExitsWithTwo(): void
    {
        [$status, , $err] = self::call(['import', '--from=html'], str_repeat('<div>', 300));
        $this->assertSame(2, $status);
        $this->assertStringStartsWith('carve[import-input-invalid]: <stdin>: ', $err);
    }
}
