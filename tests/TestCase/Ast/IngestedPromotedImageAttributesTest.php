<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class IngestedPromotedImageAttributesTest extends TestCase
{
    public function testBlockAttributesLeadInlineImageAttributesWithoutChangingTheWire(): void
    {
        $codec = new AstCodec();
        $converter = new CarveConverter();
        $wire = $codec->encode($converter->parse("![a](b){.inner k=two}\n"));
        $wire['children'][0]['attrs'] = [
            'classes' => ['outer'],
            'keyValues' => ['k' => 'one'],
            'order' => ['.class', 'k'],
        ];

        $decoded = $codec->decode($wire);

        self::assertSame('<img src="b" alt="a" class="outer inner" k="two">' . "\n", $converter->render($decoded));
        self::assertEquals($wire, $codec->encode($decoded));
    }
}
