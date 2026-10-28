<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShiftedBlockImagePromotionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'direct image' => ["{.outer k=one}\n ![a](b){.inner k=two}\n"],
            'resolved reference' => ["{.outer k=one}\n    ![a][r]{.inner k=two}\n\n[r]: b\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testShiftedImageReceivesLeadingAttributesInHtmlAndAst(string $source): void
    {
        $converter = new CarveConverter();
        self::assertStringContainsString('<img src="b" alt="a" class="outer inner" k="two">', $converter->convert($source));

        $wire = (new AstCodec())->encode($converter->parse($source));
        $paragraph = $wire['children'][0];
        self::assertSame('paragraph', $paragraph['type']);
        self::assertTrue($paragraph['blockImage']);
        self::assertArrayNotHasKey('attrs', $paragraph);
        self::assertSame('image', $paragraph['children'][0]['type']);
        self::assertSame(['outer', 'inner'], $paragraph['children'][0]['attrs']['classes']);
        self::assertSame(['k' => 'two'], $paragraph['children'][0]['attrs']['keyValues']);
    }
}
