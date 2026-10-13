<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class CodeFenceCarrierMarkerTest extends TestCase
{
    public function testTitleAndLabelSurviveMarkdownRoundTrip(): void
    {
        $source = "```js \"app.js\" [Example]\nlet x = 1\n```\n";
        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode(true);
        $markdown = (new CarveConverter(renderer: $renderer))->convert($source);
        self::assertStringContainsString('<!-- carve: ```js "app.js" [Example] -->', $markdown);

        $importer = new MarkdownToCarve();
        $imported = $importer->convert($markdown);
        self::assertFalse($importer->hadDamagedCarrierMarkers());
        $tree = (new AstCodec())->encode((new CarveConverter())->parse($imported));
        self::assertSame('app.js', $tree['children'][0]['header']);
        self::assertSame('Example', $tree['children'][0]['label']);
    }

    public function testMalformedFenceMarkerDoesNotRestoreMetadata(): void
    {
        $markdown = "<!-- carve: ```js \"app.js\" [Example] -->\nplain text\n";
        $importer = new MarkdownToCarve();
        $carve = $importer->convert($markdown);
        self::assertTrue($importer->hadDamagedCarrierMarkers());
        self::assertStringContainsString('<!-- carve: ```js "app.js" [Example] -->', $carve);
    }

    public function testNestedFenceAndAttributeCarrierRoundTrip(): void
    {
        foreach (
            [
                "{.c}\n```js \"a.js\"\nx\n```\n",
                "> ```js \"q.js\"\n> x\n> ```\n",
                "- item\n\n  ````js \"f\" [g]\n  ```\n  ````\n",
            ] as $source
        ) {
            $renderer = new MarkdownRenderer();
            $renderer->setRoundTripMode(true);
            $markdown = (new CarveConverter(renderer: $renderer))->convert($source);
            $importer = new MarkdownToCarve();
            self::assertSame($source, $importer->convert($markdown));
            self::assertFalse($importer->hadDamagedCarrierMarkers());
        }
    }

    public function testFenceAsFirstListOrDefinitionBlockKeepsItsHost(): void
    {
        foreach (
            [
                "- ```js \"a.js\"\n  x\n  ```\n",
                "1. ```js \"a.js\"\n   x\n   ```\n",
                ":: T\n: ```js \"a.js\"\n  x\n  ```\n",
            ] as $source
        ) {
            $renderer = new MarkdownRenderer();
            $renderer->setRoundTripMode(true);
            $markdown = (new CarveConverter(renderer: $renderer))->convert($source);
            $importer = new MarkdownToCarve();
            self::assertSame($source, $importer->convert($markdown));
            self::assertFalse($importer->hadDamagedCarrierMarkers());
        }
    }
}
