<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\CodeGroup;
use MarkupCarve\Carve\Extension\Details;
use MarkupCarve\Carve\Extension\FencedRender;
use MarkupCarve\Carve\Extension\ImgFence;
use MarkupCarve\Carve\Extension\MathBlock;
use MarkupCarve\Carve\Extension\Spoiler;
use PHPUnit\Framework\TestCase;

final class StandardNameSelectionTest extends TestCase
{
    public function testEveryBundledRendererCanBeSelectedByName(): void
    {
        $cases = [
            ['carve.details', "::: details \"More\"\nBody.\n:::\n", Details::bundle()],
            ['carve.spoiler', 'A :spoiler[secret].', Spoiler::bundle()],
            ['carve.spoilerBlock', "::: spoiler \"More\"\nBody.\n:::\n", Spoiler::bundle()],
            ['carve.fencedRender.mermaid', "```mermaid\ngraph TD\n```\n", FencedRender::mermaid()],
            ['carve.mathBlock', "```math\nx+y\n```\n", MathBlock::bundle()],
            ['carve.codeGroup', "::: code-group\n```js [A]\na\n```\n:::\n", CodeGroup::bundle()],
        ];
        foreach ($cases as [$name, $source, $bundle]) {
            foreach (['interactive', 'static'] as $mode) {
                $expected = Carve::render($source, ['extensions' => [$bundle], 'mode' => $mode]);
                self::assertSame($expected, Carve::render($source, [
                    'extensions' => [$name],
                    'renderers' => [$name => 'standard'],
                    'mode' => $mode,
                ]), $name . ' in ' . $mode . ' mode');
            }
        }
        self::assertSame("<details>\n  <summary>More</summary>\n  <p>Body.</p>\n</details>", Carve::render(
            "::: details \"More\"\nBody.\n:::\n",
            ['extensions' => ['carve.details'], 'renderers' => ['carve.details' => 'standard']],
        ));
    }

    public function testCodeGroupNamedRendererAcceptsItsAriaMode(): void
    {
        $source = "::: code-group\n```js [A]\na\n```\n:::\n";
        $expected = Carve::render($source, ['extensions' => [CodeGroup::bundle('aria')]]);
        self::assertSame($expected, Carve::render($source, [
            'extensions' => ['carve.codeGroup'],
            'renderers' => ['carve.codeGroup' => ['standard' => ['mode' => 'aria']]],
        ]));
    }

    public function testImageFenceBundleIncludesBothNamedRenderers(): void
    {
        $bundle = ImgFence::bundle();
        self::assertSame(['carve.imgFence.img', 'carve.imgFence.image'], array_map(
            static fn ($definition): string => $definition->name,
            $bundle->definitions(),
        ));
        foreach (['img', 'image'] as $language) {
            $source = "```$language\n<svg><rect width=\"1\" height=\"1\"/></svg>\n```\n";
            foreach (['interactive', 'static'] as $mode) {
                $byBundle = Carve::render($source, ['extensions' => [$bundle], 'mode' => $mode]);
                self::assertStringStartsWith('<img src="data:image/svg+xml,', $byBundle);
                self::assertSame($byBundle, Carve::render($source, [
                    'extensions' => ['carve.imgFence.' . $language],
                    'renderers' => ['carve.imgFence.' . $language => 'standard'],
                    'mode' => $mode,
                ]));
            }
        }
    }
}
