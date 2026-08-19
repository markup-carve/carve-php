<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An image has the same three forms as a link, and only the leading `!` and the
 * `<img src>` output differ, so its alt text is the run a link's text is: closed
 * by the same balanced, escape- and literal-span-aware scan
 * (markup-carve/carve#1206, markup-carve/carve#1197).
 *
 * The alt run is RAW. Nothing inside is inline-parsed and no escape inside it is
 * resolved, so `![t\]z](/i.png)` publishes `alt="t\]z"` with the backslash
 * intact: the escape says where the run ends, it is not a spelling of anything.
 *
 * This engine's link scan was already right. The alt was a SECOND scan, written
 * beside it, that agreed on depth and on `\` but skipped neither of the two
 * opaque runs - a code span and an editorial comment - so an image linked to the
 * right destination while its alt stopped at a `]` the parse had already ruled
 * was content.
 */
class AnImageAltTextClosesWhereLinkTextClosesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function altProvider(): array
    {
        return [
            'a balanced pair is content' => [
                'a ![t[z]](/i.png) b',
                '<p>a <img src="/i.png" alt="t[z]"> b</p>',
            ],
            'nested two deep' => [
                'a ![t[z[q]]](/i.png) b',
                '<p>a <img src="/i.png" alt="t[z[q]]"> b</p>',
            ],
            'an escaped bracket does not close, and stays in the alt' => [
                'a ![t\\]z](/i.png) b',
                '<p>a <img src="/i.png" alt="t\\]z"> b</p>',
            ],
            'a bracket inside a code span is content' => [
                'a ![t`]`z](/i.png) b',
                '<p>a <img src="/i.png" alt="t`]`z"> b</p>',
            ],
            'a bracket inside an editorial comment is content' => [
                'a ![t{# ] #}z](/i.png) b',
                '<p>a <img src="/i.png" alt="t{# ] #}z"> b</p>',
            ],
            'a longer code fence inside the alt' => [
                'a ![t``]``z](/i.png) b',
                '<p>a <img src="/i.png" alt="t``]``z"> b</p>',
            ],
            'an unbalanced closer is no image at all' => [
                'a ![t]z](/i.png) b',
                '<p>a ![t]z](/i.png) b</p>',
            ],
        ];
    }

    #[DataProvider('altProvider')]
    public function testTheAltRunClosesWhereTheLinkTextWould(string $source, string $expected): void
    {
        $this->assertSame($expected . "\n", CarveConverter::create()->convert($source . "\n"));
    }

    /**
     * CONTROL: a LINK's text is inline content, not a raw run. The same two
     * opaque runs are opaque to its scan too, and its text is parsed rather than
     * copied - so a change that made the alt raw scan agree with this one must
     * not have moved this one.
     *
     * @return array<string, array{string, string}>
     */
    public static function linkControlProvider(): array
    {
        return [
            'a balanced pair is content' => [
                'a [t[z]](/u) b',
                '<p>a <a href="/u">t[z]</a> b</p>',
            ],
            'a bracket inside a code span is content' => [
                'a [t`]`z](/u) b',
                '<p>a <a href="/u">t<code>]</code>z</a> b</p>',
            ],
            'a bracket inside an editorial comment is content' => [
                'a [t{# ] #}z](/u) b',
                '<p>a <a href="/u">t<span class="critic-comment"> ] </span>z</a> b</p>',
            ],
        ];
    }

    #[DataProvider('linkControlProvider')]
    public function testALinkTextIsUnchangedAndStillParsed(string $source, string $expected): void
    {
        $this->assertSame($expected . "\n", CarveConverter::create()->convert($source . "\n"));
    }

    /**
     * The reference form and the attribute tail read the same run.
     */
    public function testTheReferenceFormClosesTheSameWay(): void
    {
        $source = "a ![t[z[q]]][r]{.c} b\n\n[r]: /i.png\n";

        $this->assertSame(
            "<p>a <img src=\"/i.png\" alt=\"t[z[q]]\" class=\"c\"> b</p>\n",
            CarveConverter::create()->convert($source),
        );
    }
}
