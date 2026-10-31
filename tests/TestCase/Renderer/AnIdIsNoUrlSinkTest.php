<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4516: `id` and the id slot are not URL sinks and are not
 * probed by CARVE-P9-054.
 */
final class AnIdIsNoUrlSinkTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'heading section and its cross-reference' => [
                "{id=\"javascript:x\"}\n# H\n\n</#javascript:x>\n",
                "<section id=\"javascript:x\">\n  <h1>H</h1>\n  <p><a href=\"#javascript:x\">H</a></p>\n</section>",
            ],
            'paragraph' => ["{id=\"javascript:x\"}\npara\n", '<p id="javascript:x">para</p>'],
            'span' => ["[a]{id=\"vbscript:x\"}\n", '<p><span id="vbscript:x">a</span></p>'],
            'admonition' => [
                "{id=\"data:text/html,x\"}\n::: note\nb\n:::\n",
                "<aside class=\"admonition note\" id=\"data:text/html,x\" aria-label=\"Note\">\n  <p>b</p>\n</aside>",
            ],
            'a leading space' => ["{id=\" javascript:x\"}\n- item\n", "<ul id=\" javascript:x\">\n  <li>item</li>\n</ul>"],
            'another attribute is still probed' => ["{id=\"javascript:x\" title=\"javascript:y\"}\npara\n", '<p id="javascript:x" title="">para</p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testRender(string $source, string $html): void
    {
        self::assertSame($html, rtrim((new CarveConverter())->convert($source)));
    }

    public function testTheImportProbeDeniesNoId(): void
    {
        self::assertFalse(HtmlRenderer::attributeValueHasDeniedScheme('id', 'javascript:x'));
        self::assertTrue(HtmlRenderer::attributeValueHasDeniedScheme('title', 'javascript:x'));
    }
}
