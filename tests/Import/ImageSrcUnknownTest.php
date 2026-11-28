<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

/**
 * carve#6975: an `<img>` without `src` keeps the image (C2-M-050), which the writer reports with `image-src-unknown`.
 * The cases are CC-2518 to CC-2522 of the carve spec, checked here until the spec pin moves to them.
 */
final class ImageSrcUnknownTest extends TestCase
{
    public function testAnImageWithoutSrcKeepsItsFields(): void
    {
        $imported = Carve::importHtml('<p>a <img alt="A" title="T" class="c"> b</p>');
        $this->assertSame(
            ['type' => 'image', 'alt' => 'A', 'tooltip' => 'T', 'attrs' => ['classes' => ['c']]],
            $imported->document['children'][0]['children'][1],
        );
        $this->assertSame("a ![A]( \"T\"){.c} b\n", $imported->source);
        $this->assertSame(['image-src-unknown'], $imported->codes());
    }

    public function testADeniedSrcStillGivesTheAltText(): void
    {
        $imported = Carve::importHtml('<p><img src="javascript:x" alt="A"></p>');
        $this->assertSame("A\n", $imported->source);
        $this->assertSame(['import-destination-denied'], $imported->codes());
    }

    public function testNeitherAltNorSrcReportsBoth(): void
    {
        $imported = Carve::importHtml('<p><img></p>');
        $this->assertSame("![]()\n", $imported->source);
        $this->assertSame(['image-alt-unknown', 'image-src-unknown'], $imported->codes());
    }

    /**
     * CC-2519: a supplied tree.
     */
    public function testTheWriterWritesAnEmptyDestinationAndReportsTheImage(): void
    {
        $image = ['type' => 'image', 'alt' => 'A', 'tooltip' => 'T'];
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'a '], $image, ['type' => 'text', 'value' => ' b']]],
            ],
        ];
        $result = Carve::writeWithDiagnostics($tree);
        $this->assertSame("a ![A]( \"T\") b\n", $result->source);
        $this->assertSame(['image-src-unknown'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
        $this->assertSame('/children/0/children/1', $result->diagnostics[0]->path);
    }

    /**
     * CC-2521, CC-2522: `carve import` exits 0 on it, `--strict-losses` fails with render-loss (C2-T-034).
     */
    public function testTheImportCommandFailsOnlyUnderStrictLosses(): void
    {
        $html = '<p><img data-src="x.png" alt="A"></p>';
        [$status, $stdout, $stderr] = self::command(['import', '--from', 'html', '-'], $html);
        $this->assertSame(0, $status, $stderr);
        $this->assertSame("![A](){data-src=x.png}\n", $stdout);
        $this->assertMatchesRegularExpression('/^<stdin>(?::\d+:\d+)?: warning image-src-unknown /', $stderr);
        [$status, $stdout, $stderr] = self::command(['import', '--from', 'html', '--strict-losses', '-'], $html);
        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringStartsWith('carve[render-loss]:', $stderr);
    }

    /**
     * @param array<string> $args
     * @param string $stdin
     *
     * @return array{int, string, string}
     */
    private static function command(array $args, string $stdin): array
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
}
