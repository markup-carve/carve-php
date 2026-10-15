<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

final class WholeGraphemeDelimiterTest extends TestCase
{
    public function testDecoratedDelimitersStayText(): void
    {
        $converter = new CarveConverter();
        $cases = [
            '*️⃣abc*' => '<p>*️⃣abc*</p>',
            '*a*️⃣' => '<p>*a*️⃣</p>',
            '*a*́ b*' => '<p><strong>a*́ b</strong></p>',
            '_︎x _y_' => '<p>_︎x <u>y</u></p>',
            '~a~‍b~' => '<p><s>a~‍b</s></p>',
            '*x* *️⃣ *y*' => '<p><strong>x</strong> *️⃣ <strong>y</strong></p>',
            '{*️⃣abc*}' => '<p>{*️⃣abc*}</p>',
            '/*️a*/' => '<p><em>*️a*</em></p>',
            '*ाx *y*' => '<p>*ाx <strong>y</strong></p>',
            '{^ाx^}' => '<p>{^ाx^}</p>',
            '{^⃣x^}' => '<p>{^⃣x^}</p>',
            '{,︎x,}' => '<p>{,︎x,}</p>',
            '/*a*/́x' => '<p>/<strong>a</strong>/́x</p>',
        ];
        foreach ($cases as $source => $expected) {
            self::assertSame($expected . "\n", $converter->convert($source), $source);
        }
    }

    public function testDecoratedForcedOpenerNeedsNoEscape(): void
    {
        self::assertSame("{^⃣x^}\n", CarveConverter::toCarve("{^⃣x^}\n"));
    }

    public function testDecoratedEditorialOpenersStayText(): void
    {
        $converter = new CarveConverter();
        $cases = [
            '{+⃣x+} {+y+}' => '<p>{+⃣x+} <ins>y</ins></p>',
            '{-︎x-} {-y-}' => '<p>{-︎x-} <del>y</del></p>',
            '{~́a~>b~} {~c~>d~}' => '<p>{~́a~&gt;b~} <del>c</del><ins>d</ins></p>',
            '{#️⃣ x#} {#y#}' => '<p>{#️⃣ x#} <span class="critic-comment">y</span></p>',
            'a{% y %}b {%ाx%}' => '<p>ab {%ाx%}</p>',
        ];
        foreach ($cases as $source => $expected) {
            self::assertSame($expected . "\n", $converter->convert($source . "\n"), $source);
            self::assertSame($source . "\n", CarveConverter::toCarve($source . "\n"), $source);
        }
    }

    public function testAnInsertedSpanCannotStartWithAnExtender(): void
    {
        $document = (new CarveConverter())->parse("{+x+}\n");
        $insert = $document->getChildren()[0]->getChildren()[0];
        $text = $insert->getChildren()[0];
        self::assertInstanceOf(Text::class, $text);
        $text->setContent('ाx');

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }

    public function testASuperscriptCannotStartWithAnExtender(): void
    {
        $document = (new CarveConverter())->parse("{^x^}\n");
        $superscript = $document->getChildren()[0]->getChildren()[0];
        $text = $superscript->getChildren()[0];
        self::assertInstanceOf(Text::class, $text);
        $text->setContent('ाx');

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }

    public function testACombinedTokenBeforeAnExtenderUsesAForcedCloser(): void
    {
        $document = (new CarveConverter())->parse("/*x*/ a\n");
        $text = $document->getChildren()[0]->getChildren()[1];
        self::assertInstanceOf(Text::class, $text);
        $text->setContent('́a');

        self::assertSame("{*/x/*}́a\n", (new CarveRenderer())->render($document));
    }
}
