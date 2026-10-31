<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use PHPUnit\Framework\TestCase;

/**
 * An ingested span of one is the table cell's omitted default (carve#4651).
 */
final class DefaultTableSpanIsNormalizedTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function cellPayload(): array
    {
        return (new AstCodec())->encode((new CarveConverter())->parse("| a |\n"));
    }

    public function testBothDefaultSpansAreAcceptedAndOmitted(): void
    {
        $payload = self::cellPayload();
        $payload['children'][0]['rows'][0]['cells'][0]['colspan'] = 1;
        $payload['children'][0]['rows'][0]['cells'][0]['rowspan'] = 1;

        $cell = (new AstCodec())->encode((new AstCodec())->decode($payload))['children'][0]['rows'][0]['cells'][0];

        self::assertArrayNotHasKey('colspan', $cell);
        self::assertArrayNotHasKey('rowspan', $cell);
    }

    public function testARealSpanSurvivesBesideTheOmittedDefault(): void
    {
        $payload = self::cellPayload();
        $payload['children'][0]['rows'][0]['cells'][0]['colspan'] = 2;
        $payload['children'][0]['rows'][0]['cells'][0]['rowspan'] = 1;

        $cell = (new AstCodec())->encode((new AstCodec())->decode($payload))['children'][0]['rows'][0]['cells'][0];

        self::assertSame(2, $cell['colspan']);
        self::assertArrayNotHasKey('rowspan', $cell);
    }

    public function testZeroIsStillRejected(): void
    {
        $payload = self::cellPayload();
        $payload['children'][0]['rows'][0]['cells'][0]['colspan'] = 0;

        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode($payload);
    }
}
