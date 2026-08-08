<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class SourceLayoutTest extends TestCase
{
    public function testOptInSidecarKeepsCanonicalAstUnchanged(): void
    {
        $source = "\xEF\xBB\xBF- 😀\r\n";
        $converter = new CarveConverter();
        $result = $converter->parseWithSourceLayout($source);
        self::assertArrayNotHasKey('sourceLayout', $result['ast']);
        self::assertSame('crlf', $result['layout']['lineEndings']);
        self::assertTrue($result['layout']['bom']);
        self::assertSame(strlen($source), $result['ast']['srcByteLength']);
        foreach ($result['layout']['nodes'] as $node) {
            self::assertLessThanOrEqual(strlen($source), $node['endByte']);
        }
    }

    public function testSharedCrossEngineFixtures(): void
    {
        $path = dirname(__DIR__, 3) . '/tests/spec/resources/ast-source-layout-fixtures.json';
        $fixtures = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($fixtures['exact'] as $fixture) {
            self::assertSame($fixture['layout'], $converter->parseWithSourceLayout($fixture['source'])['layout'], $fixture['name']);
        }
        foreach ($fixtures['sourceFacts'] as $fixture) {
            $layout = $converter->parseWithSourceLayout($fixture['source'])['layout'];
            self::assertSame($fixture['lineEndings'], $layout['lineEndings'], $fixture['name']);
            self::assertSame($fixture['bom'], $layout['bom'], $fixture['name']);
        }
    }
}
