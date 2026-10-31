<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph in a note body a first-block `+` opened takes a lazy line (PART 0 S4, carve-php#4325).
 */
class AFirstBlockNoteBodyTakesLazyLinesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'heading then paragraph' => [
                "[^f]: +\n# H\n  c\nsee\n\nx[^f]\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <h1 id=\"H\">H</h1>\n      <p>c\nsee<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            'paragraph' => [
                "[^f]: +\np\nsee\n\nx[^f]\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>p\nsee<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }
}
