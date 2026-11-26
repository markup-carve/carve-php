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
     * A source that is not UTF-8 has its sidecar `source` in base64, and the fallback reads the bytes it encodes.
     *
     * @return void
     */
    public function testAuthoredBlockOfABase64Source(): void
    {
        $source = str_repeat("[a *[a][]*[x]: /u\n", 122) . "\n\xFF\n";

        $result = Writer::formatWithDiagnostics($source);

        $this->assertNotContains('writer-refused', array_map(static fn ($d): string => $d->code, $result->diagnostics));
        $this->assertStringStartsWith(str_repeat("[a *[a][]*[x]: /u\n", 122), $result->source);
    }

    /**
     * Under `normalize` the authored-block fallback still reads the sidecar's source (C2-W-127, carve#6848).
     *
     * @return void
     */
    public function testNoAuthoredBlockUnderNormalize(): void
    {
        $source = str_repeat("[a *[a][]*[x]: /u\n", 122);
        $result = Writer::formatWithDiagnostics($source, ['normalize' => true]);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($source, $result->source);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function authoredInContainers(): array
    {
        $line = '[a *[a][]*[x]: /u';
        $quote = implode("\n", array_map(static fn (int $i): string => '> ' . $line . ($i === 3 ? '  ' : ''), range(1, 122)));
        $item = '- ' . implode("\n  ", array_map(static fn (int $i): string => $line . ($i === 3 ? "\t" : ''), range(1, 122)));
        $crlf = str_repeat($line . "\r\n", 122) . "\r\n" . str_repeat($line . "\r\n", 122);

        return [
            'quote' => [$quote . "\n", false],
            'quote normalize' => [$quote . "\n", true],
            'list item' => [$item . "\n", false],
            'list item normalize' => [$item . "\n", true],
            'two paragraphs, CR LF' => [$crlf, false],
            'two paragraphs, CR LF, normalize' => [$crlf, true],
        ];
    }

    /**
     * The authored-block fallback takes a leaf block inside a container with its prefixes, and every block it takes
     * loses the CR of a CR LF and the blanks at its line ends (C2-W-088, carve#6848).
     *
     * @param string $source
     * @param bool $normalize
     *
     * @return void
     */
    #[DataProvider('authoredInContainers')]
    public function testAuthoredBlockAsTheWriterWritesIt(string $source, bool $normalize): void
    {
        $result = Writer::formatWithDiagnostics($source, $normalize ? ['normalize' => true] : []);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame((string)preg_replace('/[ \t]+\n/', "\n", str_replace("\r\n", "\n", $source)), $result->source);
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
            // carve-php#6106: a label break has no line in the written reference, so it takes the leaf's continuation.
            'wrapped label in a quote' => ["> See [the ref][a \n> label] now.\n", "> See [the ref][a\n> label] now.\n"],
            'wrapped label in a list item' => ["- See [the ref][a\n  label] now.\n", "- See [the ref][a\n  label] now.\n"],
            'wrapped image label in a footnote' => ["[^fn]: See ![alt][a\n  label] now.\n", "[^fn]: See ![alt][a\n  label] now.\n"],
            'wrapped label in a list in a quote' => ["> - See [x][a\n>   b] now.\n", "> - See [x][a\n>   b] now.\n"],
            'wrapped label, defined' => ["- [x][a\n  b c] y\n\n[a b c]: /u\n", "- [x][a\n  b c] y\n\n[a b c]: /u\n"],
            'text and label wrap' => ["> [x\n> y][a\n> b\n> c] z\n", "> [x\n> y][a\n> b\n> c] z\n"],
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
     * carve#6772: the rounds read the reference from the tree on every pass, so the slash before it keeps its backslash
     * and a second pass gives the same bytes.
     *
     * @return void
     */
    public function testEscapesBesideAReferenceAreStable(): void
    {
        $once = Writer::format("^[/\\`[`[/[^]]][<]\n");
        $this->assertSame("^\\[\\/\\`[\\`\\[\\/\\[^\\]\\]][<]\n", $once);
        $this->assertSame($once, Writer::format($once));
    }

    /**
     * C2-W-075: each block decides its own verbatim texts. Forty blocks whose verbatim text does not read back come
     * before one whose text does; that one keeps its authored backslashes.
     *
     * @return void
     */
    public function testEachBlockDecidesItsVerbatimTexts(): void
    {
        $failing = "^[/\\`[`[/[^]]][<]\n\n";
        $source = str_repeat($failing, 40) . "[a\\-b\\.][k]\n\n[<]: /u\n\n[k]: /k\n";

        $result = Writer::formatWithDiagnostics($source);

        $this->assertSame([], $result->diagnostics);
        $this->assertStringContainsString("\n[a\\-b\\.][k]\n", $result->source);
        $this->assertStringContainsString("^\\[\\/\\`[\\`\\[\\/\\[^\\]\\]][<]\n", $result->source);
    }

    /**
     * C2-W-075: a reference inside the text of another one is part of that one's verbatim text, so taking both does not
     * splice the inner one twice. The second block keeps its own verbatim label.
     *
     * @return void
     */
    public function testNestedReferenceKeepsTheOtherBlocksVerbatimTexts(): void
    {
        $source = "[a [b][ X ] c][ X ]\n\nd [e][ X ]\n\n[x]: /u\n";

        $result = Writer::formatWithDiagnostics($source);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($source, $result->source);
    }

    /**
     * C2-W-003 (carve-php#6099): a generated document of the carve#6813 family with references nested in references
     * past the depth cap, whose second `fmt` gave other bytes. Output is carve-js `main`.
     *
     * @return void
     */
    public function testNestedReferencesPastTheDepthCapAreAFixedPoint(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/fixtures/writer/nested-verbatim-references.txt');

        $first = Writer::formatWithDiagnostics($source);
        $second = Writer::formatWithDiagnostics($first->source);

        $this->assertSame([], $first->diagnostics);
        $this->assertSame([], $second->diagnostics);
        $this->assertSame($first->source, $second->source);
        $expected = (string)file_get_contents(dirname(__DIR__) . '/fixtures/writer/nested-verbatim-references.fmt.txt');
        $this->assertSame($expected, $first->source);
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
