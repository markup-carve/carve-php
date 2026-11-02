<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4716: an invisible run between list or description siblings reads as if it were not there.
 */
final class InvisibleLinesBetweenSiblingsAreSkippedTest extends TestCase
{
    /**
     * @var string
     */
    private const LIST = "<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>";

    /**
     * @var string
     */
    private const DESCRIPTION = "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>";

    /**
     * @return array<string, array{string, string}>
     */
    public static function runs(): array
    {
        return [
            'reference between items' => ["- a\n[r]: /u\n- b\n", self::LIST],
            'abbreviation between items' => ["- a\n*[A]: b\n- b\n", self::LIST],
            'comment between items' => ["- a\n%% c\n- b\n", self::LIST],
            'reference and blanks between items' => ["- a\n\n[r]: /u\n\n- b\n", "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>"],
            'comment after a blank' => ["- a\n\n%% c\n- b\n", "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>"],
            'three blanks across the run stay a boundary' => ["- a\n\n\n\n[r]: /u\n- b\n", "<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>"],
            'a quote owns no abbreviation' => ["> - a\n> *[A]: b\n> - b\n", "<blockquote>\n  <ul>\n    <li>a\n*[A]: b</li>\n    <li>b</li>\n  </ul>\n</blockquote>"],
            'comment after a term' => [":: t\n%% c\n: d\n", self::DESCRIPTION],
            'reference after a term' => [":: t\n[r]: /u\n: d\n", self::DESCRIPTION],
            'abbreviation after a term' => [":: t\n*[A]: b\n: d\n", self::DESCRIPTION],
            'comment span after a term' => [":: t\n%%%\nc\n%%%\n: d\n", self::DESCRIPTION],
            'indented marker after a run' => [":: t\n%% c\n : d\n", self::DESCRIPTION],
            'a quote at the body column after a run' => [":: t\n%% c\n:  > a\n   > b\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote><p>a\nb</p></blockquote>\n  </dd>\n</dl>"],
            'comment between descriptions' => [":: t\n: d\n%% c\n: e\n", "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n  <dd>e</dd>\n</dl>"],
            'reference between descriptions' => [":: t\n: d\n[r]: /u\n: e\n", "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n  <dd>e</dd>\n</dl>"],
            'comment before the next term' => [":: t\n: d\n%% c\n:: u\n: e\n", "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n  <dt>u</dt>\n  <dd>e</dd>\n</dl>"],
            'a footnote still ends the term' => [":: t\n[^n]: z\n: d\n", "<dl>\n  <dt>t</dt>\n</dl>"],
        ];
    }

    #[DataProvider('runs')]
    public function testARunReadsAsIfItWereNotThere(string $source, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert($source));
    }

    public function testADefinitionInTheRunStillDefines(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a</li>\n  <li><a href=\"/u\">b</a></li>\n</ul>",
            (new CarveConverter())->convert("- a\n[r]: /u\n- [b][r]\n"),
        );
    }
}
