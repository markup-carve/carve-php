<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every losing duplicate's `+` takes nothing, not only the first one (carve#5474, corpus 980).
 */
final class EveryLosingFootnotePlusTakesNothingTest extends TestCase
{
    public function testSecondLosingPlusKeepsTheIndentedList(): void
    {
        $expected = <<<'HTML'
<ul>
  <li>item</li>
</ul>
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>one<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert("[^n]: one\n[^n]: +\n[^n]: +\n  - item\n\nsee[^n]\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function releasedBlocks(): array
    {
        return [
            'one losing plus, indented list' => ["[^n]: one\n[^n]: +\n  - item\n\nsee[^n]\n", "<ul>\n  <li>item</li>"],
            'text between' => ["[^n]: one\n[^n]: two\n[^n]: +\n  - item\n\nsee[^n]\n", "<ul>\n  <li>item</li>"],
            'blank between' => ["[^n]: one\n\n[^n]: +\n\n[^n]: +\n  - item\n\nsee[^n]\n", "<ul>\n  <li>item</li>"],
            'deep paragraph' => ["[^n]: one\n[^n]: +\n    deep\n\nsee[^n]\n", '<p>deep</p>'],
            'inside a quote' => ["> [^n]: one\n> [^n]: +\n> [^n]: +\n>   - item\n\nsee[^n]\n", '<li>item</li>'],
            'winner plus, losing plus, flush' => ["[^n]: +\n[^n]: +\n[^n]: +\n flush\n\nsee[^n]\n", '<p>flush</p>'],
        ];
    }

    #[DataProvider('releasedBlocks')]
    public function testLosingPlusReleasesTheBlockBelow(string $source, string $needle): void
    {
        $html = (new CarveConverter())->convert($source);
        $notes = strpos($html, '<section role="doc-endnotes"');
        self::assertNotFalse($notes);
        self::assertStringContainsString($needle, substr($html, 0, $notes));
    }

    public function testWinnerPlusKeepsTheBlockAtItsBodyColumn(): void
    {
        $html = (new CarveConverter())->convert("[^n]: +\n[^n]: +\n  # h\n\nsee[^n]\n");
        $notes = (int)strpos($html, '<section role="doc-endnotes"');

        self::assertStringNotContainsString('h</', substr($html, 0, $notes));
        self::assertStringContainsString('<h1 id="h">h</h1>', substr($html, $notes));
    }
}
