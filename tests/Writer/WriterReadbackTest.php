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
        $tag = ['type' => 'tag', 'name' => 'tg'];
        $note = ['type' => 'comment', 'form' => 'braced', 'value' => 'hi'];
        $bob = ['type' => 'mention', 'target' => 'bob', 'kind' => 'user'];
        $link = ['type' => 'link', 'href' => '/u', 'children' => [$t('b')]];
        $linkOf = static fn (string $value): array => ['type' => 'link', 'href' => '/u', 'children' => [$t($value)]];
        $dash = ['type' => 'smart_punctuation', 'kind' => 'en_dash', 'glyph' => '–', 'source' => '--'];
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
            'C2-I-047 stars with no partner that can pair' => [
                $p(
                    $t('x*# } '),
                    ['type' => 'smart_punctuation', 'kind' => 'right_single_quote', 'glyph' => '’', 'source' => "'"],
                    $t('1*a'),
                ),
                "x*# } '1*a\n",
                [],
            ],
            'C2-I-047 an opening star with no star after it' => [$p($t('a *b *c'), $code('d')), "a *b *c`d`\n", []],
            'C2-I-047 a closer before an opener' => [$p($t('x*a *b')), "x*a *b\n", []],
            'C2-I-047 a star before a node that may hold its pair' => [
                $p($t('a *b '), ['type' => 'strong', 'children' => [$t('s')]]),
                "a \\*b *s*\n",
                [],
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
            'C2-I-090 a symbol behind a letter' => [
                $p($t('a.c'), $smile),
                "a.c:smile:\n",
                ['structure-unspellable /children/0/children/1'],
            ],
            'C2-I-090 a tag behind a letter' => [$p($t('ab'), $tag), "ab#tg\n", ['structure-unspellable /children/0/children/1']],
            'C2-I-090 a mention behind a digit' => [$p($t('x1'), $bob), "x1@bob\n", ['structure-unspellable /children/0/children/1']],
            'C2-I-026 a braced comment between a letter and a tag' => [
                $p($t('a'), $note, $tag),
                "a{% hi %}#tg\n",
                ['structure-unspellable /children/0/children/2'],
            ],
            'C2-I-026 a braced comment between a letter and a quote' => [
                $p($t('a'), $note, $quote('left'), $t('x')),
                "a{% hi %}\"x\n",
                ['field-unspellable /children/0/children/2/kind'],
            ],
            'C2-I-026 a braced comment between a space and a quote' => [
                $p($t('a '), $note, $quote('left'), $t('x')),
                "a {% hi %}\"x\n",
                [],
            ],
            'C2-I-090 a tag behind a node' => [$p(['type' => 'strong', 'children' => [$t('x')]], $tag), "*x*#tg\n", []],
            'C2-I-092 a letter behind a tag' => [$p($tag, $t('a')), "#tga\n", ['field-unspellable /children/0/children/0/name']],
            'C2-I-092 a digit behind a mention' => [$p($bob, $t('9')), "@bob9\n", ['field-unspellable /children/0/children/0/target']],
            'C2-I-092 a trailing hyphen is no name' => [$p($tag, $t('-} x')), "#tg-} x\n", []],
            'C2-I-092 a trailing dot is no name' => [$p($tag, $t('.(')), "#tg.(\n", []],
            'C2-W-125 a hyphen that lengthens the name' => [$p($tag, $t('-a')), "#tg\\-a\n", []],
            'C2-I-104 a left quote behind a letter' => [
                $p($t('a'), $quote('left'), $t('a')),
                "a\"a\n",
                ['field-unspellable /children/0/children/1/kind'],
            ],
            'C2-I-104 a left quote behind a left quote' => [$p($quote('left'), $quote('left'), $t('a')), "\"\"a\n", []],
            'C2-I-104 a left quote behind a node' => [
                $p(['type' => 'strong', 'children' => [$t('x')]], $quote('left'), $t('a')),
                "*x*\"a\n",
                ['field-unspellable /children/0/children/1/kind'],
            ],
            'C2-I-105 a left single quote before a digit' => [
                $p(['type' => 'smart_punctuation', 'kind' => 'left_single_quote', 'glyph' => '‘', 'source' => "'"], $t('9')),
                "'9\n",
                ['field-unspellable /children/0/children/0/kind'],
            ],
            'C2-I-043 a braced opener that nothing closes' => [$p($t('a {='), $link), "a {=[b](/u)\n", []],
            'C2-I-043 a braced opener before a link' => [$p($t('a {-k '), $link), "a {-k [b](/u)\n", []],
            'C2-I-043 an escaped mark opens nothing' => [$p($t('a {#i'), $code('c')), "a {\\#i`c`\n", []],
            'C2-I-043 a closer in later text' => [
                $p($t('a {=x '), ['type' => 'strong', 'children' => [$t('y')]], $t(' z=}')),
                "a \\{=x *y* z=}\n",
                [],
            ],
            'C2-I-043 a closer in the spelling of an atom' => [$p($t('a {-'), $tag, $t(' '), $dash, $t('}')), "a \\{-#tg --}\n", []],
            'C2-I-043 a closer of a strong mark' => [
                $p($t('a {*x '), ['type' => 'strong', 'children' => [$t('s')]], $t('}')),
                "a \\{\\*x *s*}\n",
                [],
            ],
            'C2-I-043 a braced comment closes a comment opener' => [
                $p($t('a {%'), ['type' => 'comment', 'form' => 'braced', 'value' => 'c']),
                "a \\{%{% c %}\n",
                [],
            ],
            'C2-I-043 an editorial comment closes an editorial opener' => [
                $p($t('a {#'), ['type' => 'critic_comment', 'value' => 'c']),
                "a \\{#{#c#}\n",
                [],
            ],
            'C2-I-043 the raw body of an editorial comment' => [
                $p($t('a {='), ['type' => 'critic_comment', 'value' => 'x=}']),
                "a {={#x=}#}\n",
                [],
            ],
            'C2-I-060 a brace takes the mark of a strong' => [
                $p($t('a {'), ['type' => 'strong', 'children' => [$t('s')]]),
                "a \\{*s*\n",
                [],
            ],
            'C2-I-043 a tag inside an attribute block' => [$p($t('a {'), $tag, $t('} b')), "a \\{#tg} b\n", []],
            // C2-I-055, C2-I-060: openers inside another node take the backslash, so a closer there is text.
            'C2-I-060 a closer inside a link' => [$p($linkOf('y-}')), "[y-}](/u)\n", []],
            'C2-I-060 a closer inside a strong' => [$p($t('a '), ['type' => 'strong', 'children' => [$t('y=}')]]), "a *y=}*\n", []],
            'C2-I-060 a closer behind a bare strong inside a link' => [
                $p(['type' => 'link', 'href' => '/u', 'children' => [$t('a '), ['type' => 'strong', 'children' => [$t('s')]], $t('} b')]]),
                "[a *s*} b](/u)\n",
                [],
            ],
            'C2-I-055 a closer of an enclosing braced node' => [
                $p(['type' => 'highlight', 'children' => [$t('a '), ['type' => 'strong', 'children' => [$t('y=}')]], $t(' b')]]),
                "{=a *y\\=}* b=}\n",
                [],
            ],
            'C2-I-055 a closer behind a bracket inside a braced node' => [
                $p(['type' => 'highlight', 'children' => [$t('a '), $linkOf('y=}'), $t(' b')]]),
                "{=a [y=}](/u) b=}\n",
                [],
            ],
            'C2-I-043 a closer in the text of a later node' => [
                $p($t('{-a '), ['type' => 'strong', 'children' => [$t('y-}')]], $t(' b')),
                "\\{-a *y-}* b\n",
                [],
            ],
            'C2-I-043 a closer behind a bracket of a later node' => [
                $p($t('{-a '), ['type' => 'strong', 'children' => [$linkOf('y-}')]]),
                "{-a *[y-}](/u)*\n",
                [],
            ],
            'C2-I-092 a sigil before a dash and a space' => [$p($t('a #'), $dash, $t(' b')), "a #-- b\n", []],
            'C2-I-092 a sigil before a dash and a letter' => [$p($t('a #'), $dash, $t('b')), "a \\#--b\n", []],
            'C2-I-092 a sigil before two dashes and a letter' => [$p($t('a #'), $dash, $t('-'), $dash, $t('x y')), "a \\#--\\---x y\n", []],
            'C2-I-092 a sigil before a dash at the end' => [$p($t('a @'), $dash), "a @--\n", []],
            'C2-I-092 a sigil before a dash and a node' => [
                $p($t('a #1'), $dash, ['type' => 'emphasis', 'children' => [$t('e')]]),
                "a #1--/e/\n",
                [],
            ],
            'C2-I-092 a sigil before a dot' => [$p($t('x-}#.x')), "x-}#.x\n", []],
            'C2-I-092 a sigil before an ellipsis' => [
                $p($t('a #'), ['type' => 'smart_punctuation', 'kind' => 'ellipsis', 'glyph' => '…', 'source' => '...'], $t('b')),
                "a #...b\n",
                [],
            ],
            'C2-W-069 a brace before a braced emphasis' => [
                $p($t('hi{'), ['type' => 'emphasis', 'children' => [$t('e')]], $t('a')),
                "hi{{/e/}a\n",
                [],
            ],
            'C2-W-069 a brace before a braced strong' => [
                $p($t('hi{'), ['type' => 'strong', 'children' => [$t('e')]], $t('a')),
                "hi{{*e*}a\n",
                [],
            ],
            'C2-W-069 a brace before a bare strong' => [
                $p($t('hi {'), ['type' => 'strong', 'children' => [$t('e')]], $t(' a')),
                "hi \\{*e* a\n",
                [],
            ],
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
