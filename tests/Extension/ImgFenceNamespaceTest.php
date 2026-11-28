<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class ImgFenceNamespaceTest extends TestCase
{
    public function testInvalidXlinkNamespaceDeclarationsAreDroppedAtAnyDepth(): void
    {
        $values = [' urn:x', 'urn:x ', 'urn: x', "urn:\u{A0}x", '&#160;', '&#x20;urn:x', "urn:\tx", ''];
        foreach (['img', 'image'] as $language) {
            $name = 'carve.imgFence.' . $language;
            $options = ['extensions' => [$name], 'renderers' => [$name => 'standard']];
            foreach ($values as $value) {
                foreach (['<svg xmlns:xlink="%s"><g/></svg>', '<svg><g><rect xmlns:xlink="%s"/></g></svg>'] as $shape) {
                    $svg = sprintf($shape, $value);
                    $expectedSvg = str_replace(' xmlns:xlink="' . $value . '"', '', $svg);
                    $expectedSvg = str_replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg"', $expectedSvg);
                    self::assertSame(
                        '<img src="data:image/svg+xml,' . rawurlencode($expectedSvg) . '" alt="">',
                        Carve::render("```$language\n$svg\n```\n", $options),
                        $language . ' with ' . json_encode($value) . ' in ' . $shape,
                    );
                }
            }
        }
    }

    public function testValidXlinkNamespaceDeclarationIsRetained(): void
    {
        $svg = '<svg><g xmlns:xlink="urn:x"/></svg>';
        $expectedSvg = '<svg xmlns="http://www.w3.org/2000/svg"><g xmlns:xlink="urn:x"/></svg>';
        self::assertSame(
            '<img src="data:image/svg+xml,' . rawurlencode($expectedSvg) . '" alt="">',
            Carve::render("```img\n$svg\n```\n", [
                'extensions' => ['carve.imgFence.img'],
                'renderers' => ['carve.imgFence.img' => 'standard'],
            ]),
        );
    }
}
