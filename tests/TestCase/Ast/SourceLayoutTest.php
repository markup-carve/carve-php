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
}
