<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph a tightness rule writes bare keeps its `<p>` where the bare HTML would import as other blocks
 * (C2-M-012, C2-M-013, C2-M-092).
 */
final class BareParagraphImageRunTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function renders(): array
    {
        return [
            'paragraph before a block image' => [
                "- a\n\n  ![](i.png)\n",
                "<ul>\n  <li><p>a</p>\n    <img src=\"i.png\" alt=\"\">\n  </li>\n</ul>",
            ],
            'two images on one line' => [
                "- ![](i.png)![](j.png)\n",
                "<ul>\n  <li><p><img src=\"i.png\" alt=\"\"><img src=\"j.png\" alt=\"\"></p></li>\n</ul>",
            ],
            'two images across a soft break' => [
                "- ![](i.png)\n  ![](j.png)\n",
                "<ul>\n  <li><p><img src=\"i.png\" alt=\"\">\n<img src=\"j.png\" alt=\"\"></p></li>\n</ul>",
            ],
            'image-only description' => [
                ":: t\n: ![](a.png)![](b.png)\n",
                "<dl>\n  <dt>t</dt>\n  <dd><p><img src=\"a.png\" alt=\"\"><img src=\"b.png\" alt=\"\"></p></dd>\n</dl>",
            ],
            'text and image stay bare' => [
                "- a ![](i.png)\n",
                "<ul>\n  <li>a <img src=\"i.png\" alt=\"\"></li>\n</ul>",
            ],
            'lone block image stays bare' => [
                "- ![](i.png)\n",
                "<ul>\n  <li>\n    <img src=\"i.png\" alt=\"\">\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('renders')]
    public function testRender(string $source, string $html): void
    {
        self::assertSame($html, Carve::render($source));
    }

    #[DataProvider('renders')]
    public function testRenderImportRenderIsAFixedPoint(string $source, string $html): void
    {
        self::assertSame($html, Carve::render(Carve::importHtml($html)->source));
    }

    /**
     * C2-H-186: `data-carve-tight` stands over a `<p>` that C2-H-018 keeps; without it C2-M-033 reads the list as loose.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function carriers(): array
    {
        return [
            'image-only item before a text item' => [
                "<ul data-carve-tight>\n  <li><p><img src=\"i.png\" alt=\"\"><img src=\"j.png\" alt=\"\"></p></li>\n  <li>c</li>\n</ul>",
                'tight',
                'loose',
            ],
            'paragraph before a block image, comment between' => [
                "<ul data-carve-tight>\n  <li><p>a</p>\n    <!-- x -->\n    <img src=\"i.png\" alt=\"\">\n  </li>\n  <li>c</li>\n</ul>",
                'tight',
                'loose',
            ],
            'text paragraph is still a loose sign' => [
                "<ul data-carve-tight>\n  <li><p>a</p></li>\n  <li>c</li>\n</ul>",
                'loose',
                'loose',
            ],
            'text next to an image is still a loose sign' => [
                "<ul data-carve-tight>\n  <li><p>a <img src=\"i.png\" alt=\"\"></p></li>\n  <li>c</li>\n</ul>",
                'loose',
                'loose',
            ],
        ];
    }

    #[DataProvider('carriers')]
    public function testTightCarrierOverAKeptParagraph(string $html, string $withCarrier, string $without): void
    {
        self::assertSame($withCarrier, self::spacing($html));
        self::assertSame($without, self::spacing(str_replace(' data-carve-tight', '', $html)));
    }

    private static function spacing(string $html): mixed
    {
        $children = Carve::importHtml($html)->document['children'] ?? null;
        $list = is_array($children) ? $children[0] ?? null : null;

        return is_array($list) ? $list['spacing'] ?? null : null;
    }

    public function testTightListRoundTripsUnderRoundtrip(): void
    {
        $source = "- ![](i.png)![](j.png)\n- c\n";
        $html = Carve::render($source, ['roundtrip' => true]);
        self::assertStringContainsString('<ul data-carve-tight>', $html);
        self::assertSame($html, Carve::render(Carve::importHtml($html)->source, ['roundtrip' => true]));
    }
}
