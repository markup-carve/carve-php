<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2LintSaturationTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function cases(): array
    {
        return [
            'C2-B-046: a task box is no label' => [
                "- [ ] a\n", 'blank-reference-label', 0,
            ],
            'C2-I-072: a bracket pair with nothing glued to it is no reference' => [
                "a [ ]\n", 'blank-reference-label', 0,
            ],
            'C2-I-079: a blank full reference label' => [
                "x [a][ ]\n", 'blank-reference-label', 1,
            ],
            'C2-I-079: a blank definition label' => [
                "[ ]: u\n", 'blank-reference-label', 1,
            ],
            'C2-I-079: an image with no alt text holds no label' => [
                "![](i.png)\n", 'blank-reference-label', 0,
            ],
            "C2-B-069: a term's later line is no opener candidate" => [
                "::: box\n   :: t\n: d\n:::\n", 'block-opener-without-blank-line', 0,
            ],
            "C2-B-069: a term's later line is no paragraph line" => [
                "2) :: t\n    : {#id}\n", 'markdown-definition-paragraph', 0,
            ],
            'C2-B-074: a colon line below a `::` line' => [
                ":::{.c}\n:: key\n:  key\n", 'markdown-definition-paragraph', 0,
            ],
            'C2-B-074: the first line of a paragraph' => [
                ": x\n", 'markdown-definition-paragraph', 0,
            ],
            'C2-B-074: a later paragraph line' => [
                "a\n: x\n", 'markdown-definition-paragraph', 1,
            ],
            'C2-B-031: a quote run that is text leaves the next quote line a candidate' => [
                ">>> |= A |= B |\n> | x | y |\n", 'block-opener-without-blank-line', 1,
            ],
            'C2-B-031: a glued mark is no container prefix' => [
                ">*\n> : more\n", 'markdown-definition-paragraph', 0,
            ],
            'C2-B-151: a two-mark heading is a candidate' => [
                "a\n## b\n", 'block-opener-without-blank-line', 1,
            ],
            'C2-B-151: a six-mark heading is a candidate' => [
                "a\n====== b\n", 'block-opener-without-blank-line', 1,
            ],
            'C2-B-151: seven marks are text' => [
                "a\n####### b\n", 'block-opener-without-blank-line', 0,
            ],
            'C2-B-149: a caption line in a footnote body' => [
                "[^n]: ^ x\n", 'unattached-caption', 1,
            ],
            'C2-B-081: a definition on a list marker line' => [
                "- [a]: u\n", 'definition-in-container', 1,
            ],
            'C2-B-081: a definition behind a description mark' => [
                ":: t\n: [a]: u\n", 'definition-in-container', 1,
            ],
            'C2-B-081: a footnote definition is not reported for its own mark' => [
                "[^n]: ^ x\n", 'definition-in-container', 0,
            ],
            "C2-B-075: a quote mark opening a term's text" => [
                ":: > x\n", 'definition-term-block-folded', 1,
            ],
            'C2-B-075: a block opener on a later line of the term' => [
                ":: c\n# H\n", 'definition-term-block-folded', 0,
            ],
            'C2-B-153: closing hashes on an `=` heading' => [
                "== H #\n", 'heading-closing-hashes', 1,
            ],
            'C2-B-153: a run that is the only text' => [
                "= ##\n", 'heading-closing-hashes', 0,
            ],
            'C2-B-067: a lower-column separator after a folded term' => [
                "::: box\n   :: t\n: d\n:::\n", 'description-separator-below-content-column', 1,
            ],
            'C2-B-067: a separator at the list column' => [
                ":: t\n: d\n", 'description-separator-below-content-column', 0,
            ],
            'C2-B-074: a line the column rule reports is left to it' => [
                "- :: t\n  : d\n: x\n", 'markdown-definition-paragraph', 0,
            ],
            'C2-B-004: a tab-indented term counts its tab stop' => [
                "\t:: t\n   : d\n", 'description-separator-below-content-column', 1,
            ],
            'C2-B-031: a glued mark before a definition is text' => [
                "\t>[a]: u\n", 'definition-in-container', 0,
            ],
            'C2-B-031: a glued mark before a hyphen run is text' => [
                ">----\n", 'thematic-break-spelling', 0,
            ],
            'C2-B-031: a glued mark before a spaced run is text' => [
                ">* * *\n", 'deprecated-spaced-thematic-break', 0,
            ],
            'C2-B-031: a quote candidate holds the list marker' => [
                ">[^n]:\n> - [\n", 'list-marker-without-blank-line', 0,
            ],
            'C2-B-160: a quoted hyphen run below paragraph text' => [
                "a\n> ---\n", 'setext-heading-underline', 1,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testRuleCount(string $source, string $rule, int $count): void
    {
        $found = array_filter(Carve::lint($source, ['upgrade' => true]), static fn ($d): bool => $d->code === $rule);
        self::assertCount($count, $found);
    }
}
