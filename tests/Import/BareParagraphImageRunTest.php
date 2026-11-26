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
}
