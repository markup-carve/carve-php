<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition term has no content column, so a block opener indented past
 * the enclosing container's content column is term text at every depth
 * (markup-carve/carve#2411). List markers keep Rule B and still open.
 */
final class ATermFoldsAnIndentedBlockOpenerAtEveryDepthTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'top level heading' => [":: c\n  # H\n", '<dl><dt>c# H</dt></dl>'],
            'quoted heading' => ["> :: c\n>   # H\n", '<blockquote><dl><dt>c# H</dt></dl></blockquote>'],
            'heading in a list item' => ["- item\n\n  :: c\n    # H\n", '<ul><li>item<dl><dt>c# H</dt></dl></li></ul>'],
            'heading in a description' => [
                ":: a\n: b\n  :: c\n    # H\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c# H</dt></dl></dd></dl>',
            ],
            'note in a description' => [
                ":: a\n: b\n  :: c\n    ::: note\n    body\n    :::\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c::: notebody:::</dt></dl></dd></dl>',
            ],
            'second term in a description' => [
                ":: a\n: b\n  :: c\n    :: d\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c:: d</dt></dl></dd></dl>',
            ],
            'heading at the description column opens' => [
                ":: a\n: b\n  :: c\n  # H\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c</dt></dl><h1 id="H">H</h1></dd></dl>',
            ],
            'heading at column 0 opens' => [":: c\n# H\n", '<dl><dt>c</dt></dl><section id="H"><h1>H</h1></section>'],
            'list marker opens' => [
                ":: a\n: b\n  :: c\n    - x\n",
                '<dl><dt>a</dt><dd><p>b</p><dl><dt>c</dt></dl><ul><li>x</li></ul></dd></dl>',
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testRendersAndFormatsToAFixedPoint(string $source, string $expected): void
    {
        $flat = static fn (string $html): string => (string)preg_replace('/\n\s*/', '', $html);
        $html = (new CarveConverter())->convert($source);
        $this->assertSame($expected, $flat($html));
        $formatted = CarveConverter::toCarve($source);
        $this->assertSame($html, (new CarveConverter())->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
