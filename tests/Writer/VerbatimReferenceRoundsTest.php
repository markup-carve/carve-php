<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-W-075: the rounds read every reference as written from the tree, and the verbatim texts go back after
 * them where the output still reads back (markup-carve/carve#6802, carve#6772). Outputs are carve-js `next2`.
 */
final class VerbatimReferenceRoundsTest extends TestCase
{
    /**
     * A verbatim text past the inline depth cap kept the rounds from bracing the nested strongs it holds, so they
     * repeated a state and the write was refused.
     *
     * @return void
     */
    public function testReferencesPastTheDepthCapWrite(): void
    {
        $source = str_repeat("[a *[a][x]*[x]: /u\n", 122);

        $result = Writer::formatWithDiagnostics($source);

        $this->assertSame([], $result->diagnostics);
        $tree = TreeEncoder::decodedForWriter(Carve::parse($source));
        $read = TreeEncoder::decodedForWriter(Carve::parse($result->source));
        $this->assertTrue(TreeEquality::equal($tree, $read));
    }

    /**
     * C2-W-088: where the kind rounds end with a node that did not read back, rounds that escape only the reported
     * openers write the group again. A generated variant of the carve#6813 family, which those rounds write.
     *
     * @return void
     */
    public function testReportedOpenerRoundsWriteWhereTheKindRoundsStop(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/fixtures/writer/reported-openers-fallback.txt');

        $result = Writer::formatWithDiagnostics($source);

        $this->assertSame([], $result->diagnostics);
        $tree = TreeEncoder::decodedForWriter(Carve::parse($source));
        $read = TreeEncoder::decodedForWriter(Carve::parse($result->source));
        $this->assertTrue(TreeEquality::equal($tree, $read));
    }

    /**
     * C2-W-088 (carve#6813): no output of the rounds reads back on 122 collapsed references past the depth cap, so the
     * paragraph is written as authored (CC-2323).
     *
     * @return void
     */
    public function testAuthoredBlockWhereTheRoundsLose(): void
    {
        $source = str_repeat("[a *[a][]*[x]: /u\n", 122);

        $result = Writer::formatWithDiagnostics($source);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($source, $result->source);
    }

    /**
     * A supplied tree without a sidecar has no authored source to fall back to, and keeps the refusal (C2-W-117).
     *
     * @return void
     */
    public function testNoAuthoredBlockWithoutASidecar(): void
    {
        $tree = TreeEncoder::decodedForWriter(Carve::parse(str_repeat("[a *[a][]*[x]: /u\n", 122)));

        $result = Writer::writeWithDiagnostics($tree);

        $this->assertSame(['writer-refused'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function verbatim(): array
    {
        return [
            'authored backslash' => ["a [b \\* c][k] d\n\n[k]: /u\n", "a [b \\* c][k] d\n\n[k]: /u\n"],
            'quote prefix on the later line' => ["> q [b\n> \\[c][k] d\n\n[k]: /u\n", "> q [b\n> \\[c][k] d\n\n[k]: /u\n"],
            'cell' => ["| [a\\]b][k] | x |\n\n[k]: /u\n", "| [a\\]b][k] | x |\n\n[k]: /u\n"],
            'image' => ["[*a*][k] and ![\\*i][k]\n\n[k]: /u\n", "[*a*][k] and ![\\*i][k]\n\n[k]: /u\n"],
            'line-end blanks' => ["- [x  \n  \\_y][k]\n\n[k]: /u\n", "- [x\n  \\_y][k]\n\n[k]: /u\n"],
        ];
    }

    #[DataProvider('verbatim')]
    public function testVerbatimTextGoesBackAfterTheRounds(string $source, string $expected): void
    {
        $result = Writer::formatWithDiagnostics($source);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($expected, $result->source);
    }

    /**
     * C2-W-127: `normalize` writes every reference from the tree.
     *
     * @return void
     */
    public function testNormalizeWritesFromTheTree(): void
    {
        $this->assertSame("a [b * c][k] d\n\n[k]: /u\n", Writer::format("a [b \\* c][k] d\n\n[k]: /u\n", ['normalize' => true]));
    }
}
