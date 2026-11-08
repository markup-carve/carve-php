<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A losing duplicate definition's `+` takes nothing (carve#5474, ruling A).
 */
final class LosingFootnotePlusTakesNothingTest extends TestCase
{
    public function testFlushLineAfterLosingPlusIsATopLevelParagraph(): void
    {
        $expected = <<<'HTML'
<p>flush</p>
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>x<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert("[^a]: x\n[^a]: +\n flush\n\nsee[^a]\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function releasedFollowers(): array
    {
        return [
            'column 0 text' => ["[^a]: x\n[^a]: +\nflush\n\nsee[^a]\n", '<p>flush</p>'],
            'first plus took its own block' => ["[^a]: +\n taken\n[^a]: +\n flush\n\nsee[^a]\n", '<p>flush</p>'],
            'list item' => ["[^a]: x\n[^a]: +\n- item\n\nsee[^a]\n", '<li>item</li>'],
            'block quote' => ["[^a]: x\n[^a]: +\n> q\n\nsee[^a]\n", '<blockquote>'],
            'code fence' => ["[^a]: x\n[^a]: +\n```\ncode\n```\n\nsee[^a]\n", '<code>code'],
            'third definition' => ["[^a]: x\n[^a]: +\n[^a]: +\n flush\n\nsee[^a]\n", '<p>flush</p>'],
            'shifted marker' => ["[^a]: x\n [^a]: +\n  body\n\nsee[^a]\n", '<p>body</p>'],
            'inside a quote' => ["> [^a]: x\n> [^a]: +\n>  flush\n>\n> see[^a]\n", '<p>flush</p>'],
            'inside an item' => ["- [^a]: x\n  [^a]: +\n   flush\n\n  see[^a]\n", 'flush'],
            'inside a div' => ["::: note\n[^a]: x\n[^a]: +\n flush\n\nsee[^a]\n:::\n", '<p>flush</p>'],
        ];
    }

    #[DataProvider('releasedFollowers')]
    public function testLosingPlusReleasesTheBlockBelow(string $source, string $needle): void
    {
        $html = (new CarveConverter())->convert($source);
        $notes = strpos($html, '<section role="doc-endnotes"');
        self::assertNotFalse($notes);
        self::assertStringContainsString($needle, substr($html, 0, $notes));
    }

    public function testLosingPlusKeepsItsOrdinaryBodyInvisible(): void
    {
        $html = (new CarveConverter())->convert("[^a]: x\n[^a]: +\n  body\n\nsee[^a]\n");

        self::assertStringNotContainsString('body', $html);
    }

    public function testDistinctLabelStillTakesTheBlock(): void
    {
        $html = (new CarveConverter())->convert("[^a]: x\n[^b]: +\nflush\n\nsee[^a][^b]\n");

        self::assertStringNotContainsString('<p>flush</p>', $html);
        self::assertStringContainsString('<p>flush<a href="#fnref2"', $html);
    }
}
