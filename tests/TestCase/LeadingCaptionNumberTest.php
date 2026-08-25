<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class LeadingCaptionNumberTest extends TestCase
{
    public function testAPlaceholderAtTheStartOfACaptionIsNumbered(): void
    {
        $source = ">\n^ # H\n";

        $this->assertStringContainsString('<figcaption>1 H</figcaption>', (new CarveConverter())->convert($source));

        $ast = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $this->assertSame('caption_number', $ast['children'][0]['caption'][0]['type']);
        $this->assertSame(1, $ast['children'][0]['caption'][0]['n']);
    }

    public function testAHashInsideAWordIsStillLiteral(): void
    {
        $source = "> q\n^ a#b\n";

        $this->assertStringContainsString('<figcaption>a#b</figcaption>', (new CarveConverter())->convert($source));
    }
}
