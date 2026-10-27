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
            self::assertSame(preg_replace('/\n\z/', '', $expected . "\n"), $converter->convert($source), $source);
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
            self::assertSame(preg_replace('/\n\z/', '', $expected . "\n"), $converter->convert($source . "\n"), $source);
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

    public function testEveryRemainingBracedOpenerTakesTheGuard(): void
    {
        $cases = [
            "{/\u{0301}x/}",
            "{_\u{FE0F}x_}",
            "{=\u{20E3}x=}",
            "{~\u{093E}x~}",
            "{*\u{200D}x*}",
        ];
        foreach ($cases as $source) {
            self::assertSame(preg_replace('/\n\z/', '', '<p>' . $source . "</p>\n"), (new CarveConverter())->convert($source . "\n"), $source);
            self::assertSame($source . "\n", CarveConverter::toCarve($source . "\n"), $source);
        }
    }

    public function testBareSlashAndEqualsTakeTheGuard(): void
    {
        $converter = new CarveConverter();
        self::assertSame(preg_replace('/\n\z/', '', "<p>/\u{0301}x <em>y</em></p>\n"), $converter->convert("/\u{0301}x /y/\n"));
        self::assertSame(preg_replace('/\n\z/', '', "<p>=\u{FE0F}x <mark>y</mark></p>\n"), $converter->convert("=\u{FE0F}x =y=\n"));
    }

    /**
     * U+200B is a grapheme boundary (UAX #29 Control), unlike U+200D, so a
     * test for "any format character" would wrongly make these text.
     */
    public function testAZeroWidthSpaceIsNotAnExtender(): void
    {
        $converter = new CarveConverter();
        self::assertSame(preg_replace('/\n\z/', '', "<p>x <strong>\u{200B}y</strong> z</p>\n"), $converter->convert("x *\u{200B}y* z\n"));
        self::assertSame(preg_replace('/\n\z/', '', "<p>x\u{200B}<strong>y</strong>\u{200B}z</p>\n"), $converter->convert("x\u{200B}*y*\u{200B}z\n"));
        self::assertSame(preg_replace('/\n\z/', '', "<p>x *\u{200D}y* z</p>\n"), $converter->convert("x *\u{200D}y* z\n"));
    }
}
