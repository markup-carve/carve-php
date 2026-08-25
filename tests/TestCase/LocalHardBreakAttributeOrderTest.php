<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class LocalHardBreakAttributeOrderTest extends TestCase
{
    public function testLeadingAttributesKeepTheirSourceOrderAheadOfTheStructuralClass(): void
    {
        $source = "{#addr .contact}\n::: \\\none\ntwo\n:::\n";
        $ast = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $attrs = $ast['children'][0]['attrs'];

        $this->assertSame('addr', $attrs['id']);
        $this->assertSame(['contact', 'hardbreaks'], $attrs['classes']);
        $this->assertSame(['#id', '.class'], $attrs['order']);
    }
}
