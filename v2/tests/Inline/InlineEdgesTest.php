<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Inline;

use MarkupCarve\Carve2\AttributeParser;
use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Inline\InlineContext;
use MarkupCarve\Carve2\Inline\InlineRunner;
use MarkupCarve\Carve2\Inline\Scanner;
use MarkupCarve\Carve2\Node;

/**
 * Clauses where the reference engine differs (defects-inline.md) or that need a block context.
 */
final class InlineEdgesTest extends InlineTestCase
{
    /**
     * @return void
     */
    public function testC2I002TrailingSpacesAreNoContent(): void
    {
        $this->assertSame('code@0-5(value="\n]")', self::inline("` \t\n]"));
        $this->assertSame('text@0-1(value="a") non_breaking_space@1-3', self::inline('a\\ '));
    }

    /**
     * @return void
     */
    public function testC2I098AbbreviationsInText(): void
    {
        $abbr = ['HTML' => 'Hyper'];
        $this->assertSame(
            'abbreviation@0-4(key="HTML" namespace="abbr" expansion="Hyper") text@4-11(value=" HTMLs ") '
            . 'strong@11-17{abbreviation@12-16(key="HTML" namespace="abbr" expansion="Hyper")} text@17-18(value=" ") code@18-24(value="HTML")',
            self::inline('HTML HTMLs *HTML* `HTML`', [], $abbr),
        );
    }

    /**
     * @return void
     */
    public function testC2I099AbbreviationNeedsWholeWord(): void
    {
        $this->assertSame('text@0-12(value="XHTML HTMLé")', self::inline('XHTML HTMLé', [], ['HTML' => 'x']));
    }

    /**
     * D-inline-1: eight hyphens are four en dashes by the clause.
     *
     * @return void
     */
    public function testC2I111EightHyphensAreEnDashes(): void
    {
        $this->assertSame(4, substr_count(self::inline('--------'), 'en_dash'));
    }

    /**
     * @return void
     */
    public function testC2I118DepthCap(): void
    {
        $out = self::inline(str_repeat('{^', 201) . 'x' . str_repeat('^}', 201));
        $this->assertSame(200, substr_count($out, 'superscript'));
        $this->assertStringContainsString('{text@400-403(value="{^x")}', $out);
        $this->assertStringEndsWith(' text@803-805(value="^}")', $out);
    }

    /**
     * @return void
     */
    public function testC2I120CellEndsEveryForm(): void
    {
        $this->assertSame('text@0-5(value="a ``b")', self::inline('a ``b', [], [], Scanner::CELL));
        $this->assertSame('text@0-2(value="a ") code@2-4(value="b")', self::inline('a `b', [], [], 0));
    }

    /**
     * @return void
     */
    public function testC2B150CaptionNumberIsTheFirstBareMark(): void
    {
        $this->assertSame(
            'text@0-7(value="Figure ") strong@7-10{text@8-9(value="#")} text@10-15(value=" and ") caption_number text@16-22(value=" more ") tag@22-24(name="t")',
            self::inline('Figure *#* and # more #t', [], [], Scanner::CAPTION),
        );
    }

    /**
     * @return void
     */
    public function testC2A068HardBreakBlock(): void
    {
        $this->assertSame(
            'text@0-1(value="a") hard_break@1-2 text@2-3(value="b")',
            self::inline("a\nb", [], [], Scanner::HARD_BREAKS),
        );
    }

    /**
     * @return void
     */
    public function testC2B144NoLineCommentInLineBlock(): void
    {
        $this->assertSame('text@0-6(value="a %% b")', self::inline('a %% b', [], [], Scanner::LINE_BLOCK));
    }

    /**
     * @return void
     */
    public function testC2I125DefinitionAfterTheUse(): void
    {
        $doc = Carve2::parse("[later]\n\n[later]: /u\n");
        $para = $doc->children[0] ?? null;
        $this->assertInstanceOf(Node::class, $para);
        $this->assertStringStartsWith('link@0-7(key="later" namespace="link" refForm="shortcut"', self::nodes($para->children ?? []));
    }

    /**
     * @return void
     */
    public function testC2I041AndC2I042AttributeOrderAndFolds(): void
    {
        $a = new Attrs();
        $end = AttributeParser::read('{k=v .b CLASS="c d" #x Id=y :en}', 0, 32, $a);
        $this->assertSame(32, $end);
        $this->assertSame('y', $a->id);
        $this->assertSame(['b', 'c', 'd'], $a->classes);
        $this->assertSame([['k', 'v']], $a->pairs);
        $this->assertSame('en', $a->lang);
    }

    /**
     * @return void
     */
    public function testC2I034InvalidPartMakesNoBlock(): void
    {
        $this->assertSame(-1, AttributeParser::read('{.a .}', 0, 6, new Attrs()));
        $this->assertSame(-1, AttributeParser::read('{.a.b}', 0, 6, new Attrs()));
        $this->assertSame(-1, AttributeParser::read('{.a', 0, 3, new Attrs()));
    }

    /**
     * @return void
     */
    public function testC2I040EmptyBlockInRun(): void
    {
        $a = new Attrs();
        $this->assertSame(-1, AttributeParser::readRun('{}{.c}', 0, 6, $a));
        $this->assertSame(6, AttributeParser::readRun('{}{.c}', 0, 6, $a, true));
        $this->assertSame(['c'], $a->classes);
    }

    /**
     * No input throws, invalid UTF-8 included.
     *
     * @return void
     */
    public function testNoExceptionOnBytes(): void
    {
        mt_srand(3);
        $alphabet = "*/{}[]()<>`\\!\$%:@#^=+-~_,.'\" \n\x80\xC3\xA9\xFFa";
        $ctx = new InlineContext();
        for ($k = 0; $k < 300; $k++) {
            $s = '';
            $len = mt_rand(1, 80);
            for ($c = 0; $c < $len; $c++) {
                $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            foreach (InlineRunner::parse($s, [0, strlen($s)], $ctx) as $node) {
                $this->assertGreaterThanOrEqual(-1, $node->start);
            }
        }
    }
}
