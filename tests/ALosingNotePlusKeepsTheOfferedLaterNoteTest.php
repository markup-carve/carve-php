<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A losing duplicate `+` line is read as if it were not there, so a live `+` definition skipped before it keeps the block below (carve#5528).
 */
final class ALosingNotePlusKeepsTheOfferedLaterNoteTest extends TestCase
{
    public function testReproduction(): void
    {
        $expected = <<<'HTML'
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a><a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
    <li id="fn2">
      <p>para<a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert("[^m]: +\n[^n]: +\n[^n]: +\n\n  para\n\nsee[^m][^n]\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function losingLines(): array
    {
        return [
            'two losing lines' => ["[^m]: +\n[^n]: +\n[^n]: +\n[^n]: +\n\n  para\n\nsee[^m][^n]\n", "[^m]: +\n[^n]: +\n\n  para\n\nsee[^m][^n]\n"],
            'losing first label' => ["[^m]: +\n[^n]: +\n[^m]: +\n\n  - item\n\nsee[^m][^n]\n", "[^m]: +\n[^n]: +\n\n  - item\n\nsee[^m][^n]\n"],
            'in a list item' => ["- [^m]: +\n  [^n]: +\n  [^n]: +\n\n    > q\n\nsee[^m][^n]\n", "- [^m]: +\n  [^n]: +\n\n    > q\n\nsee[^m][^n]\n"],
            'in a quote' => ["> [^m]: +\n> [^n]: +\n> [^n]: +\n>\n>   para\n\nsee[^m][^n]\n", "> [^m]: +\n> [^n]: +\n>\n>   para\n\nsee[^m][^n]\n"],
        ];
    }

    #[DataProvider('losingLines')]
    public function testRendersAsWithoutTheLosingLine(string $source, string $reduced): void
    {
        $converter = new CarveConverter();

        self::assertSame($converter->convert($reduced), $converter->convert($source));
    }

    public function testALaterTextDefinitionKeepsTheLosingLineEndingTheRun(): void
    {
        $html = (new CarveConverter())->convert("[^m]: +\n[^n]: +\n[^k]: d\n[^n]: +\n  para\n\nsee[^m][^n][^k]\n");

        self::assertStringContainsString("<li id=\"fn1\">\n      <p>para<a", $html);
    }
}
