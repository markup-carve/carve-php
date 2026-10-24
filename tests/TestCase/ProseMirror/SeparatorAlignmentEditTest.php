<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

class SeparatorAlignmentEditTest extends TestCase
{
    public function testEditedHeadAlignmentWinsOverSavedSeparatorAlignment(): void
    {
        $source = "|> Wide|<|~ Last|\n|--:|:--|:--:|\n|a|b|c|\n";
        $proseMirror = (new ProseMirrorRenderer())->render((new CarveConverter())->parse($source));

        $proseMirror['content'][0]['content'][0]['content'][0]['attrs']['alignment'] = 'center';
        $proseMirror['content'][0]['content'][0]['content'][0]['attrs']['textAlign'] = 'center';

        $actual = CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($proseMirror));

        $this->assertSame("| Wide | < | Last |\n|:--:|:--|:--:|\n|> a | b | c |\n", $actual);
    }
}
