<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\BbcodeToCarve;
use PHPUnit\Framework\TestCase;

/**
 * BBCode inside a code run is shown, not read (markup-carve/carve-php#1206).
 *
 * `escapePlainBbcodeText()` stashed code while it escaped and restored before
 * returning, so every converter after it saw the enclosed markup and rewrote
 * it. Showing markup is most of what `[code]` is used for on a forum, so the
 * sample the author was demonstrating came out as a different markup language.
 */
class BbcodeCodeContentIsLiteralTest extends TestCase
{
    private BbcodeToCarve $converter;

    protected function setUp(): void
    {
        $this->converter = new BbcodeToCarve();
    }

    public function testBbcodeInsideCodeIsNotConverted(): void
    {
        $this->assertSame(
            "```\n[b]not bold[/b]\n```\n",
            $this->converter->convert('[code][b]not bold[/b][/code]'),
        );
    }

    public function testALinkInsideCodeIsNotConverted(): void
    {
        $this->assertSame(
            "```\n[url=x]y[/url]\n```\n",
            $this->converter->convert('[code][url=x]y[/url][/code]'),
        );
    }

    /**
     * BOUND: the code run still becomes a fence and still keeps its language,
     * which is what the tags-stay-visible half of the fix protects. Stashing
     * the tags as well as the content breaks this row.
     */
    public function testTheFenceAndItsLanguageStillWork(): void
    {
        $this->assertSame("```php\necho 1;\n```\n", $this->converter->convert('[code=php]echo 1;[/code]'));
        $this->assertSame("```\na *b* c\n```\n", $this->converter->convert('[code]a *b* c[/code]'));
    }

    /**
     * Mixed content, and it asserts BOTH halves rather than being a pure bound:
     * markup outside the code run still converts (a fix that stashed too
     * greedily would leave `[b]a[/b]` literal), and markup inside it does not.
     * The second half fails against the previous converter.
     */
    public function testMarkupOutsideCodeStillConverts(): void
    {
        $this->assertSame("*bold*\n", $this->converter->convert('[b]bold[/b]'));
        $this->assertSame(
            "*a*\n\n```\n[i]b[/i]\n```\n",
            $this->converter->convert('[b]a[/b][code][i]b[/i][/code]'),
        );
    }
}
