<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition the winner's `+` skips, ended by a losing line, leaves the block below to the winner (PART 9R R2, carve-php#4909).
 */
final class LosingTextDefinitionKeepsTheWinnersBlockTest extends TestCase
{
    public function testLosingTextDefinitionBetweenWinnerAndLosingPlus(): void
    {
        $expected = <<<'HTML'
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <ul>
        <li>item</li>
      </ul>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert("[^n]: +\n[^n]: d1\n[^n]: +\n  - item\n\nsee[^n]\n"));
    }

    public function testItemNotePlusTakesTheListPastASkippedDefinition(): void
    {
        $expected = <<<'HTML'
<ul>
  <li></li>
</ul>
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <ul>
        <li>item</li>
      </ul>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert("- [^n]: +\n  [^n]: d1\n  - item\n\nsee[^n]\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function winnerBlocks(): array
    {
        return [
            'other label kept' => ["[^n]: +\n[^m]: d1\n[^n]: +\n  - item\n\nsee[^n][^m]\n", "<li id=\"fn1\">\n      <ul>\n        <li>item</li>"],
            'lazy line in the skipped note' => ["[^n]: +\n[^m]: d1\nlazy\n[^n]: +\n  - item\n\nsee[^n][^m]\n", "<li id=\"fn1\">\n      <ul>\n        <li>item</li>"],
            'two text definitions' => ["[^n]: +\n[^n]: d1\n[^n]: d2\n[^n]: +\n  para\n\nsee[^n]\n", "<li id=\"fn1\">\n      <p>para<a"],
            'losing plus first' => ["[^n]: +\n[^n]: +\n[^n]: d2\n[^n]: +\n  > q\n\nsee[^n]\n", "<li id=\"fn1\">\n      <blockquote>"],
            'inside an item' => ["- [^n]: +\n  [^n]: d1\n  [^n]: +\n    - item\n\nsee[^n]\n", "<li id=\"fn1\">\n      <ul>\n        <li>item</li>"],
            'inside a quote' => ["> [^n]: +\n> [^n]: d1\n> [^n]: +\n>   - item\n\nsee[^n]\n", "<li id=\"fn1\">\n      <ul>\n        <li>item</li>"],
            'item note takes a list directly' => ["* [^n]: +\n  - item\n\nsee[^n]\n", "<li id=\"fn1\">\n      <ul>\n        <li>item</li>"],
            'item note takes an ordered list' => ["* [^n]: +\n  1. item\n\nsee[^n]\n", "<li id=\"fn1\">\n      <ol>\n        <li>item</li>"],
            'several losing pluses move the skipped note once' => ["[^n]: +\n[^m]: d1\n[^n]: +\n[^n]: +\n[^n]: +\n  - item\n\nsee[^m][^n]\n", "<p>d1<a"],
            'item note past another label' => ["* [^n]: +\n  [^m]: d1\n  - item\n\nsee[^n][^m]\n", "<li id=\"fn1\">\n      <ul>\n        <li>item</li>"],
        ];
    }

    #[DataProvider('winnerBlocks')]
    public function testWinnerKeepsTheBlock(string $source, string $needle): void
    {
        self::assertStringContainsString($needle, (new CarveConverter())->convert($source));
    }

    public function testLosingNoteTextBodyStaysDropped(): void
    {
        $html = (new CarveConverter())->convert("[^n]: +\n[^n]: d1\n  - item\n\nsee[^n]\n");

        self::assertStringNotContainsString('item', $html);
    }
}
