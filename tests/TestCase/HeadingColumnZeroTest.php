<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A heading marker must start at column 0 (no leading indent). This matches the
 * carve spec grammar (heading_first_line = heading_marker, space, ...) and the
 * carve-js / carve-rs implementations. An indented `#`-line is a paragraph, and
 * an indented `#`-marker continuation line folds as literal text, not as a
 * heading-marker continuation.
 */
class HeadingColumnZeroTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function columnZeroProvider(): array
    {
        return [
            'three leading spaces is a paragraph' => [
                '   # H',
                "<p># H</p>\n",
            ],
            'one leading space is a paragraph' => [
                ' # H',
                "<p># H</p>\n",
            ],
            'two leading spaces is a paragraph' => [
                '  ## H',
                "<p>## H</p>\n",
            ],
            'column zero is a heading' => [
                '# H',
                "<section id=\"H\">\n  <h1>H</h1>\n</section>\n",
            ],
            'column zero level two is a heading' => [
                '## H',
                "<section id=\"H\">\n  <h2>H</h2>\n</section>\n",
            ],
        ];
    }

    #[DataProvider('columnZeroProvider')]
    public function testColumnZeroRule(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($input));
    }

    /**
     * An indented `#`-marker line is NOT a continuation marker: it folds into the
     * open heading as literal text (the leading indent and the `##` are kept), and
     * the heading id is slugged from the full literal text.
     */
    public function testIndentedContinuationFoldsAsLiteralText(): void
    {
        $input = "## H\n   ## indented";
        $expected = "<section id=\"H-indented\">\n  <h2>H\n   ## indented</h2>\n</section>\n";

        $this->assertSame($expected, $this->converter->convert($input));
    }
}
