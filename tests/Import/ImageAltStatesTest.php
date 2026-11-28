<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6804: the three alt states of an image (C2-A-115) through import, render and `carve import`.
 */
final class ImageAltStatesTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cliCases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $id => $case) {
            if (in_array($id, ['CC-2214', 'CC-2215', 'CC-2290', 'CC-2291', 'CC-2292'], true)) {
                $out[$id] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cliCases')]
    public function testPinnedImportCommandCase(array $case): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $case['stdin']);
        rewind($in);
        $status = Command::main($case['args'], $in, $out, $err);
        rewind($out);
        rewind($err);
        $stdout = (string)stream_get_contents($out);
        $stderr = (string)stream_get_contents($err);
        $expected = $case['expected'];

        $this->assertSame($expected['status'], $status, $stderr);
        if (array_key_exists('stdout', $expected)) {
            $this->assertSame($expected['stdout'], $stdout);
        }
        $category = preg_match('/^carve\[([^\]]+)\]:/', $stderr, $m) === 1 ? $m[1] : null;
        $this->assertSame($expected['stderrCategory'], $category);
        if (isset($expected['stderrDiagnostics'])) {
            // C2-T-019: FILE[:LINE:COLUMN]: SEVERITY CODE MESSAGE.
            $lines = [];
            foreach (explode("\n", rtrim($stderr, "\n")) as $line) {
                if (preg_match('/^(<stdin>|[^:]+)(?::\d+:\d+)?: (?:warning|error|info) (\S+) /', $line, $m) === 1) {
                    $lines[] = ['code' => $m[2], 'file' => $m[1]];
                }
            }
            $this->assertSame($expected['stderrDiagnostics'], $lines);
        }
    }

    /**
     * Render then import gives back each of the three import states, and the import writes `![](x)` for two of them.
     */
    public function testRenderThenImportKeepsTheState(): void
    {
        foreach (
            [
                'alt text' => [['alt' => 't'], '<img src="x" alt="t">', "![t](x)\n", []],
                'unknown' => [[], '<img src="x">', "![](x)\n", ['image-alt-unknown']],
                'decorative' => [['decorative' => true], '<img src="x" alt="">', "![](x)\n", []],
            ] as $name => [$fields, $html, $carve, $codes]
        ) {
            $image = ['type' => 'image', 'src' => 'x'] + $fields;
            $tree = Carve::decodeTree((string)json_encode([
                'type' => 'document',
                'astVersion' => '2.0',
                'children' => [['type' => 'paragraph', 'role' => 'image', 'children' => [$image]]],
            ]));
            $rendered = Carve::render($tree);
            $this->assertSame($html, trim($rendered), $name);
            $imported = Carve::importHtml($rendered);
            $this->assertSame([$image], $imported->document['children'][0]['children'], $name);
            $this->assertSame($carve, $imported->source, $name);
            $this->assertSame($codes, $imported->codes(), $name);
        }
    }
}
