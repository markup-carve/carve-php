<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Supplied trees whose nearest spelling does not read back: the loss is reported, and a loss-free source is a fixed
 * point of `fmt` (C2-W-131).
 */
final class WriterReadbackTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function trees(): array
    {
        $p = static fn (array ...$kids): array => [['type' => 'paragraph', 'children' => $kids]];
        $t = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $code = static fn (string $value): array => ['type' => 'code', 'value' => $value];
        $span = ['type' => 'span', 'attrs' => ['id' => 'hi'], 'children' => [$t('abc')]];
        $smile = ['type' => 'symbol', 'name' => 'smile'];
        $nbsp = ['type' => 'non_breaking_space'];
        $quote = static fn (string $side): array => [
            'type' => 'smart_punctuation',
            'kind' => $side . '_double_quote',
            'glyph' => $side === 'left' ? '“' : '”',
            'source' => '"',
        ];

        return [
            'C2-B-122 a syntax outside the language word' => [
                [['type' => 'code_block', 'value' => "x\n", 'syntax' => 'föö']],
                "```\nx\n```\n",
                ['field-unspellable /children/0/syntax'],
            ],
            'C2-B-122 a syntax of punctuation' => [
                [['type' => 'code_block', 'value' => "x\n", 'syntax' => ';']],
                "```\nx\n```\n",
                ['field-unspellable /children/0/syntax'],
            ],
            'C2-B-122 a syntax of language-word characters' => [
                [['type' => 'code_block', 'value' => "x\n", 'syntax' => 'c++/x.y#1']],
                "```c++/x.y#1\nx\n```\n",
                [],
            ],
            'C2-W-117 a link title with a line break' => [
                $p(['type' => 'link', 'href' => '/u', 'tooltip' => "a\nb", 'children' => [$t('x')]]),
                "[x](/u \"a\nb\")\n",
                ['structure-unspellable /children/0/children/0'],
            ],
            'C2-I-002 spaces before a line break in a code span' => [
                $p($code("a  \nb")),
                "`a  \nb`\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'C2-I-002 a tab before a line break in raw inline' => [
                $p(['type' => 'raw_inline', 'format' => 'html', 'value' => "a\t\nb"]),
                "`a\t\nb`{=html}\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'C2-I-002 indent after a line break in a code span' => [
                $p($code("a\n b")),
                "`a\n b`\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'C2-I-002 a space behind a backslash stays' => [$p($code("a\\ \nb")), "`a\\ \nb`\n", []],
            'C2-I-002 a line break before the closing run' => [$p($t('x '), $code("b\n"), $t(' y')), "x `b\n` y\n", []],
            'C2-I-002 a pad before the closing run on its own line' => [
                $p($t('x '), $code("`\n")),
                "x `` `\n ``\n",
                ['field-unspellable /children/0/children/1/value'],
            ],
            'C2-W-116 an empty code span' => [
                $p($t('a'), $code('')),
                "a``\n",
                ['structure-unspellable /children/0/children/1'],
            ],
            'C2-W-116 an empty literal-text span' => [
                $p(['type' => 'literal_inline', 'value' => '']),
                "!``\n",
                ['structure-unspellable /children/0/children/0'],
            ],
            'C2-I-035 an attribute block would take the span in' => [
                $p($t('hi{key=“'), $span, $t('”')),
                "hi\\{key=“[abc]{#hi}”\n",
                [],
            ],
            'C2-I-035 a bare key stops at the bracket of a link' => [
                $p($t('hi{k'), ['type' => 'link', 'href' => '/u', 'children' => [$t('abc')]]),
                "hi{k[abc](/u)\n",
                [],
            ],
            'C2-I-035 a value that no brace closes' => [
                $p($t('hi {key='), ['type' => 'strong', 'children' => [$t('s')]], $t("'")),
                "hi {key=*s*\\'\n",
                [],
            ],
            'C2-I-035 a symbol is no language tag' => [$p($t('hi{'), $smile), "hi{:smile:\n", []],
            // C2-W-095: the quotes of text take a backslash, so no quoted value forms around the symbol.
            'C2-I-035 an escaped quote opens no attribute value' => [
                $p($t('hi {k="a '), $smile, $t('"}')),
                "hi {k=\\\"a :smile:\\\"}\n",
                [],
            ],
            'C2-I-035 a smart quote opens an attribute value' => [
                $p($t('hi {k='), $quote('left'), $t('a '), $smile, $quote('right'), $t('}')),
                "hi \\{k=\"a :smile:\"}\n",
                [],
            ],
            'C2-W-022 a no-break space at the end of the content' => [
                $p($t('x'), $nbsp),
                "x\u{00A0}\n",
                ['structure-unspellable /children/0/children/1'],
            ],
            'C2-W-022 a no-break space before a soft break' => [
                $p($t('x'), $nbsp, ['type' => 'soft_break'], $t('y')),
                "x\u{00A0}\ny\n",
                ['structure-unspellable /children/0/children/1'],
            ],
            'C2-I-011 a no-break space between words' => [$p($t('x'), $nbsp, $t('y')), "x\\ y\n", []],
        ];
    }

    /**
     * @param array<mixed> $children
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('trees')]
    public function testWritesTheNearestSpellingAndReportsTheLoss(array $children, string $source, array $losses): void
    {
        $result = Writer::writeWithDiagnostics(['type' => 'document', 'astVersion' => '2.0', 'children' => $children]);
        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
        if ($losses === []) {
            $this->assertSame($source, Carve::format($source));
        }
    }
}
