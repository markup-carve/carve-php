<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §16: an authored `{#id}` on a footnote reference replaces `fnref{n}` /
 * `fnref{n}-{k}`, and the note's backlink targets it (carve#2978).
 */
class AnAuthoredFootnoteReferenceIdReplacesTheGeneratedOneTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "a [t[^1]{#z}][r] [^1]{#y} [^1]\n\n[r]: /u\n\n[^1]: n\n";

    public function testTheAuthoredIdReplacesTheGeneratedOneAndTheBacklinkFollows(): void
    {
        $html = (new CarveConverter())->convert(self::SOURCE);

        $this->assertSame(
            '<p>a <a href="/u">t<sup id="z">1</sup></a> <a id="y" href="#fn1" role="doc-noteref"><sup>1</sup></a>'
                . ' <a id="fnref1-3" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>',
            explode("\n", $html)[0],
        );
        preg_match_all('/<a href="([^"]*)" role="doc-backlink"/', $html, $m);
        $this->assertSame(['#z', '#y', '#fnref1-3'], $m[1]);
    }

    public function testTheAuthoredIdIsEscapedInTheBacklink(): void
    {
        $html = (new CarveConverter())->convert("a [^1]{id=\"q&r\"}\n\n[^1]: n\n");

        $this->assertStringContainsString('<a id="q&amp;r" href="#fn1" role="doc-noteref">', $html);
        $this->assertStringContainsString('<a href="#q&amp;r" role="doc-backlink"', $html);
    }

    public function testTheReferenceInsideLinkTextImportsAsAFootnoteAgain(): void
    {
        $html = (new CarveConverter())->convert(self::SOURCE);

        $this->assertSame("a [t[^1]](/u) [^1] [^1]\n\n[^1]: n\n", (new HtmlToCarve())->convert($html));
    }
}
