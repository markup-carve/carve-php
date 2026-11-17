<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Writer;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Test\Support\WriterGate;
use MarkupCarve\Carve2\Writer\CarveWriteError;
use MarkupCarve\Carve2\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WriterClausesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function formatCases(): array
    {
        return [
            'C2-W-098 pipes in a cell keep their backslash' => [
                '| [x]{title="a\|b"} [y](/c\|d "t\|u") ![i\|j](/k) a\|b |' . "\n\n[x]: /u\n",
                '| [x]{title="a\|b"} [y](/c\|d "t\|u") ![i\|j](/k) a\|b |' . "\n\n[x]: /u\n",
            ],
            'C2-I-074 alt text is written as it stands' => ["![p\\*q](/k)\n", "![p\\*q](/k)\n"],
            'C2-W-099 a line of one hyphen needs no escape' => ["-\n", "-\n"],
            'C2-W-099 a dash run keeps every second hyphen escaped' => ["a -\\-\n", "a -\\-\n"],
            'C2-W-095 a backtick in text' => ["a \\` b\n", "a \\` b\n"],
            'C2-W-090 a sigil glued to a code span' => ["\\!`x`\n", "\\!`x`\n"],
            // C2-W-096: `{#` without its closer `#}` opens nothing, so only the tag sigil takes the backslash (C2-W-088).
            'C2-I-092 a tag name may start with a hyphen' => ["x{#\\-a\n", "x{\\#-a\n"],
            'C2-I-092 a sigil before no letter is text' => ["x #-1\n", "x #-1\n"],
            'C2-W-074 an empty link' => ["x[](/u)\n", "x[](/u)\n"],
            'C2-W-116 an empty span' => ["x[]{.c}\n", "x[]{.c}\n"],
            'C2-W-116 an empty inline extension' => ["x :n[]\n", "x :n[]\n"],
            'C2-W-094 a bracket pair with no tail is text' => ["\\[a] b\n", "[a] b\n"],
            'C2-W-094 a bracket pair before a link tail' => ["\\[a](u)\n", "\\[a](u)\n"],
            'C2-W-094 a bracket pair that names a definition' => ["\\[a]\n\n[a]: /u\n", "\\[a]\n\n[a]: /u\n"],
            'C2-W-094 a footnote label' => ["x \\[^a]\n", "x \\[^a]\n"],
            'C2-W-096 an attribute block glued to no node is text' => ["a {.c} b\n", "a {.c} b\n"],
            'C2-W-096 a braced opener without its closer is text' => ["a {*b\n", "a {*b\n"],
            'C2-W-092 a later paragraph line is not tested for a list marker' => ["x\n- a\n", "x\n- a\n"],
            'C2-W-092 a later line inside an item is tested for a list marker' => ["- a\n  \\- b\n", "- a\n  \\- b\n"],
            'C2-W-092 a first line that parses as a paragraph' => ["A. Smith\n", "A. Smith\n"],
            'C2-W-092 a description mark with no list before it' => ["\\: x\n", ": x\n"],
            'C2-W-121 a description mark behind a description list' => [":: t\n: d\n\n\\: x\n", ":: t\n: d\n\n\\: x\n"],
            'C2-W-095 a colon before an inline extension name' => ["x \\:n[a]\n", "x \\:n[a]\n"],
            'C2-W-125 case five: a caret at the start of span text' => ["x [\\^a]{.c}\n", "x [\\^a]{.c}\n"],
            'C2-I-036 an attribute block behind a mention is text' => ["@user{.c} and #tag{.c}\n", "@user{.c} and #tag{.c}\n"],
            'C2-I-036 a brace behind a host that holds no attribute block' => ["`x`{=_a}\n", "`x`{=_a}\n"],
            'C2-B-081 a definition shape inside an item' => ["- [a]: /u\n\n[x][a]\n", "- [a]: /u\n\n[x][a]\n"],
            'C2-W-129 a table across a comment below a list' => [
                "- a\n\n{footer-rows=0}\n%% c\n| a |\n",
                "- a\n\n{footer-rows=0}\n%% c\n| a |\n",
            ],
        ];
    }

    /**
     * @param string $input
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('formatCases')]
    public function testFormat(string $input, string $expected): void
    {
        $this->assertSame($expected, Carve2::format($input));
        $this->assertNull(WriterGate::check($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unspellableValues(): array
    {
        return [
            'a CR at the end of the input' => ["a\r"],
            'a CR before a soft break' => ["a\r\r\nb"],
            'a code span line that would open a list' => ["`a\n- b`\n"],
        ];
    }

    /**
     * C2-W-016: a parse the writer cannot keep is reported, never written as another tree.
     *
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('unspellableValues')]
    public function testC2W016LineOfALeafIsReported(string $source): void
    {
        $this->assertSame(['field-unspellable'], Writer::formatWithDiagnostics($source)->codes());
    }

    public function testTwoCodeSpansAreOne(): void
    {
        $result = Writer::writeWithDiagnostics(self::doc([
            [

                'type' => 'paragraph',
                'children' => [
                    ['type' => 'code', 'value' => 'a'],
                    ['type' => 'code', 'value' => 'b'],
                ],
            ],
        ]));

        // C2-W-112
        $this->assertSame("`ab`\n", $result->source);
        $this->assertSame(['structure-unspellable'], $result->codes());
    }

    public function testWriteThrowsOnLoss(): void
    {
        // C2-W-134
        $this->expectException(CarveWriteError::class);
        Carve2::write(self::doc([
            [

                'type' => 'paragraph',
                'children' => [
                    ['type' => 'code', 'value' => 'a'],
                    ['type' => 'code', 'value' => 'b'],
                ],
            ],
        ]));
    }

    public function testExtensionWithoutSourceFormWritesFallback(): void
    {
        $tree = self::doc([
            [
                'type' => 'block_extension',
                'name' => 'org.example.n',
                'version' => '1',
                'children' => [],
                'fallback' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'safe']]]],
                'source' => ['form' => 'fence', 'name' => '=html', 'body' => "<script>alert(1)</script>\n"],
            ],
        ]);
        $tree['extensions'] = [['name' => 'org.example.n', 'version' => '1', 'required' => false, 'slots' => []]];
        $result = Writer::writeWithDiagnostics($tree);

        // C2-X-023
        $this->assertSame("safe\n", $result->source);
        $this->assertSame(['structure-unspellable'], $result->codes());
    }

    public function testSidecarNumbersOverrideBareDot(): void
    {
        $tree = WriterGate::tree("1. a\n2. b\n");
        $layout = ['nodes' => [['path' => '/children/0', 'bare' => false]]];

        // C2-L-030, C2-L-031, C2-W-127
        $this->assertSame(". a\n. b\n", Carve2::write($tree));
        $this->assertSame("1. a\n2. b\n", Carve2::write($tree, $layout));
        $this->assertSame(". a\n. b\n", Carve2::write($tree, $layout, ['normalize' => true]));
    }

    /**
     * @param array<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private static function doc(array $children): array
    {
        return ['type' => 'document', 'astVersion' => '2.0', 'children' => $children];
    }
}
