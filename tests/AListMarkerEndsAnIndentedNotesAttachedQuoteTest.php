<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line that cannot fold ends a note's `+`-attached quote and, below the note's column, the note (carve#5541, carve-php#4940).
 */
final class AListMarkerEndsAnIndentedNotesAttachedQuoteTest extends TestCase
{
    public function testIndentedNote(): void
    {
        $expected = <<<'HTML'
<ul>
  <li>i</li>
</ul>
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <blockquote><p>aq</p></blockquote>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert("  [^a]: +\n  > aq\n  - i\n\nsee[^a]\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function indents(): array
    {
        return [
            'one column' => [" [^a]: +\n > aq\n - i\n\nsee[^a]\n"],
            'three columns' => ["   [^a]: +\n   > aq\n   - i\n\nsee[^a]\n"],
            'ordered marker' => ["  [^a]: +\n  > aq\n  1. i\n\nsee[^a]\n"],
        ];
    }

    #[DataProvider('indents')]
    public function testListLeavesTheNote(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertMatchesRegularExpression('#^<(ul|ol)>\n  <li>i</li>#', $html);
        self::assertStringContainsString('<blockquote><p>aq</p></blockquote>', $html);
    }

    public function testLazyLineAfterAnItemNotesQuoteFoldsIntoIt(): void
    {
        $html = (new CarveConverter())->convert("- [^a]: +\n  > aq\npara\n\nsee[^a]\n");

        self::assertStringContainsString("<blockquote><p>aq\npara</p></blockquote>", $html);
        self::assertStringNotContainsString('<p>para</p>', $html);
    }
}
