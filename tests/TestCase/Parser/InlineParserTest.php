<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Subscript;
use MarkupCarve\Carve\Node\Inline\Superscript;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use PHPUnit\Framework\TestCase;
use function str_contains;

class InlineParserTest extends TestCase
{
    protected InlineParser $parser;

    protected BlockParser $blockParser;

    protected function setUp(): void
    {
        $this->blockParser = new BlockParser();
        $this->parser = new InlineParser($this->blockParser);
    }

    protected function parseInline(string $text): Paragraph
    {
        $para = new Paragraph();
        $this->parser->parse($para, $text);

        return $para;
    }

    protected function getFirstChild(Paragraph $para): mixed
    {
        $children = $para->getChildren();

        return $children[0] ?? null;
    }

    protected function containsNodeOfType(mixed $node, string $className): bool
    {
        if ($node instanceof $className) {
            return true;
        }

        if (!$node instanceof Node) {
            return false;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->containsNodeOfType($child, $className)) {
                return true;
            }
        }

        return false;
    }

    public function testParseText(): void
    {
        $para = $this->parseInline('Hello world');

        $this->assertCount(1, $para->getChildren());
        $text = $this->getFirstChild($para);
        $this->assertInstanceOf(Text::class, $text);
        $this->assertSame('Hello world', $text->getContent());
    }

    public function testParseEmphasis(): void
    {
        $para = $this->parseInline('/emphasized/');

        $this->assertCount(1, $para->getChildren());
        $em = $this->getFirstChild($para);
        $this->assertInstanceOf(Emphasis::class, $em);
    }

    public function testParseStrong(): void
    {
        $para = $this->parseInline('*strong*');

        $this->assertCount(1, $para->getChildren());
        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);
    }

    public function testParseCode(): void
    {
        $para = $this->parseInline('`code`');

        $this->assertCount(1, $para->getChildren());
        $code = $this->getFirstChild($para);
        $this->assertInstanceOf(Code::class, $code);
        $this->assertSame('code', $code->getContent());
    }

    public function testParseCodeWithBackticks(): void
    {
        $para = $this->parseInline('`` `code` ``');

        $code = $this->getFirstChild($para);
        $this->assertInstanceOf(Code::class, $code);
        $this->assertSame('`code`', $code->getContent());
    }

    public function testClosedCodeSpanStripsOneSpaceAtEachEnd(): void
    {
        $para = $this->parseInline('`  a  `');

        $code = $this->getFirstChild($para);
        $this->assertInstanceOf(Code::class, $code);
        $this->assertSame(' a ', $code->getContent());
    }

    public function testParseLink(): void
    {
        $para = $this->parseInline('[Example](https://example.com)');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('https://example.com', $link->getDestination());
    }

    /**
     * The angle form is a destination now, not literal text. It is the only
     * spelling that can carry a parenthesis or a space, which is what a URL
     * like `https://x/Foo_(bar)` needs - formatting one used to truncate the
     * href and leak the rest into the text (carve#377).
     */
    public function testAngleBracketWrappedInlineLinkDestinationIsALink(): void
    {
        $para = $this->parseInline('[a](<u v>)');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('u v', $link->getDestination());

        $para = $this->parseInline('[a](<https://x/Foo_(bar)>)');
        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('https://x/Foo_(bar)', $link->getDestination());
    }

    public function testUnclosedAngleDestinationStaysLiteral(): void
    {
        // No closing `>`, so the bare scan runs and stops at the `)`; the `<`
        // is ordinary content.
        $para = $this->parseInline('[a](<https://x/plain)');
        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('<https://x/plain', $link->getDestination());
    }

    public function testEmptyInlineLinkDestinationStaysLiteral(): void
    {
        $para = $this->parseInline('[]( )');

        $this->assertCount(1, $para->getChildren());
        $text = $this->getFirstChild($para);
        $this->assertInstanceOf(Text::class, $text);
        $this->assertSame('[]( )', $text->getContent());
    }

    public function testParseImage(): void
    {
        $para = $this->parseInline('![Alt text](image.jpg)');

        $image = $this->getFirstChild($para);
        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame('image.jpg', $image->getSource());
        $this->assertSame('Alt text', $image->getAlt());
    }

    public function testParseAutolink(): void
    {
        $para = $this->parseInline('<https://example.com>');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('https://example.com', $link->getDestination());
    }

    public function testParseEmailAutolink(): void
    {
        $para = $this->parseInline('<test@example.com>');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('mailto:test@example.com', $link->getDestination());
    }

    public function testParseSuperscript(): void
    {
        $para = $this->parseInline('x{^2^}');

        $children = $para->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Superscript::class, $children[1]);
    }

    public function testParseSubscript(): void
    {
        $para = $this->parseInline('H{,2,}O');

        $children = $para->getChildren();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(Subscript::class, $children[1]);
    }

    public function testParseSymbol(): void
    {
        $para = $this->parseInline(':smile:');

        $symbol = $this->getFirstChild($para);
        $this->assertInstanceOf(Symbol::class, $symbol);
        $this->assertSame('smile', $symbol->getName());
    }

    public function testSymbolRequiresLeftBoundaryAndCanonicalNameShape(): void
    {
        foreach (['a:b:c', '10:30: x', 'word:rocket:', 'word:+-:', ':_x:'] as $source) {
            $para = $this->parseInline($source);

            $this->assertFalse($this->containsNodeOfType($para, Symbol::class), $source);
        }

        foreach (['(:tada:)' => 'tada', 'start :rocket:' => 'rocket', ':1up:' => '1up', ':+1:' => '+1', ':-1:' => '-1', ':+-:' => '+-'] as $source => $name) {
            $para = $this->parseInline($source);

            $this->assertTrue($this->containsNodeOfType($para, Symbol::class), $source);
            $symbols = $this->collectSymbols($para);
            $this->assertSame($name, $symbols[0]->getName());
        }
    }

    public function testInlineExtensionsStayAheadOfSymbolBoundaryGuard(): void
    {
        $para = $this->parseInline(':kbd[Ctrl]');
        $children = $para->getChildren();
        $this->assertInstanceOf(InlineExtension::class, $children[0]);
        $this->assertSame('kbd', $children[0]->getExtensionType());

        $para = $this->parseInline('a:kbd[x]');
        $children = $para->getChildren();
        $this->assertInstanceOf(Text::class, $children[0]);
        $this->assertInstanceOf(InlineExtension::class, $children[1]);
        $this->assertSame('kbd', $children[1]->getExtensionType());
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Inline\Symbol>
     */
    protected function collectSymbols(Node $node): array
    {
        $symbols = [];
        if ($node instanceof Symbol) {
            $symbols[] = $node;
        }

        foreach ($node->getChildren() as $child) {
            $symbols = [...$symbols, ...$this->collectSymbols($child)];
        }

        return $symbols;
    }

    public function testParseMath(): void
    {
        $para = $this->parseInline('$`x^2`');

        $math = $this->getFirstChild($para);
        $this->assertInstanceOf(Math::class, $math);
        $this->assertSame('x^2', $math->getContent());
        $this->assertFalse($math->isDisplay());
    }

    public function testParseDisplayMath(): void
    {
        $para = $this->parseInline('$$`x^2`');

        $math = $this->getFirstChild($para);
        $this->assertInstanceOf(Math::class, $math);
        $this->assertTrue($math->isDisplay());
    }

    public function testParseSoftBreak(): void
    {
        $para = $this->parseInline("Line 1\nLine 2");

        $children = $para->getChildren();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(SoftBreak::class, $children[1]);
    }

    public function testParseHardBreak(): void
    {
        $para = $this->parseInline("Line 1\\\nLine 2");

        $children = $para->getChildren();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(HardBreak::class, $children[1]);
    }

    public function testParseEscape(): void
    {
        $para = $this->parseInline('\*not strong\*');

        $children = $para->getChildren();
        $this->assertCount(3, $children);

        // First child is escaped asterisk
        $this->assertInstanceOf(EscapedText::class, $children[0]);
        $this->assertSame('*', $children[0]->getContent());

        // Second child is text
        $this->assertInstanceOf(Text::class, $children[1]);
        $this->assertSame('not strong', $children[1]->getContent());

        // Third child is escaped asterisk
        $this->assertInstanceOf(EscapedText::class, $children[2]);
        $this->assertSame('*', $children[2]->getContent());
    }

    public function testParseInlineAttributes(): void
    {
        $para = $this->parseInline('[styled]{.highlight}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $class = $span->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'highlight'));
    }

    public function testBareWordAttributeBlockIsLiteral(): void
    {
        $para = $this->parseInline('word{.class}');

        $content = '';
        foreach ($para->getChildren() as $child) {
            if ($child instanceof Text) {
                $content .= $child->getContent();
            }
        }

        $this->assertSame('word{.class}', $content);
    }

    public function testParseLinkWithAttributes(): void
    {
        $para = $this->parseInline('[Link](url){.external}');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $class = $link->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'external'));
    }

    public function testParseSmartDoubleQuotes(): void
    {
        $para = $this->parseInline('"Hello"');

        // Each quote is its own node holding the resolved glyph and the source
        // character, so the Carve renderer can emit the straight quote back.
        $children = $para->getChildren();

        $open = $children[0];
        $this->assertInstanceOf(SmartPunctuation::class, $open);
        $this->assertSame('left_double_quote', $open->getKind());
        $this->assertSame("\u{201C}", $open->getGlyph());
        $this->assertSame('"', $open->getContent());

        $close = $children[2];
        $this->assertInstanceOf(SmartPunctuation::class, $close);
        $this->assertSame('right_double_quote', $close->getKind());
        $this->assertSame("\u{201D}", $close->getGlyph());
        $this->assertSame('"', $close->getContent());
    }

    public function testParseSmartSingleQuotes(): void
    {
        $para = $this->parseInline("'Hello'");

        $children = $para->getChildren();

        $open = $children[0];
        $this->assertInstanceOf(SmartPunctuation::class, $open);
        $this->assertSame('left_single_quote', $open->getKind());
        $this->assertSame("\u{2018}", $open->getGlyph());
        $this->assertSame("'", $open->getContent());

        $close = $children[2];
        $this->assertInstanceOf(SmartPunctuation::class, $close);
        $this->assertSame('right_single_quote', $close->getKind());
        $this->assertSame("\u{2019}", $close->getGlyph());
        $this->assertSame("'", $close->getContent());
    }

    public function testParseEmDash(): void
    {
        $para = $this->parseInline('word---word');

        $dash = $para->getChildren()[1];
        $this->assertInstanceOf(SmartPunctuation::class, $dash);
        $this->assertSame('em_dash', $dash->getKind());
        // The node carries the three hyphens it was built from, so the Carve
        // renderer reproduces them.
        $this->assertSame('---', $dash->getContent());
        $this->assertSame("\u{2014}", SmartPunctuation::GLYPHS[$dash->getKind()]);
    }

    public function testParseEnDash(): void
    {
        $para = $this->parseInline('word--word');

        $dash = $para->getChildren()[1];
        $this->assertInstanceOf(SmartPunctuation::class, $dash);
        $this->assertSame('en_dash', $dash->getKind());
        $this->assertSame('--', $dash->getContent());
        $this->assertSame("\u{2013}", SmartPunctuation::GLYPHS[$dash->getKind()]);
    }

    public function testDashRunDecomposesIntoOneNodePerGlyph(): void
    {
        // Four hyphens resolve to two en dashes, so the run partitions into two
        // nodes of two hyphens each - together reproducing the original run.
        $para = $this->parseInline('word----word');

        $first = $para->getChildren()[1];
        $second = $para->getChildren()[2];
        $this->assertInstanceOf(SmartPunctuation::class, $first);
        $this->assertInstanceOf(SmartPunctuation::class, $second);
        $this->assertSame('en_dash', $first->getKind());
        $this->assertSame('en_dash', $second->getKind());
        $this->assertSame('--', $first->getContent());
        $this->assertSame('--', $second->getContent());
    }

    public function testSmartSymbolCarriesItsSourceRun(): void
    {
        $para = $this->parseInline('a -> b');

        $arrow = $para->getChildren()[1];
        $this->assertInstanceOf(SmartPunctuation::class, $arrow);
        $this->assertSame('rightwards_arrow', $arrow->getKind());
        $this->assertSame('->', $arrow->getContent());
    }

    public function testParseEllipsis(): void
    {
        $para = $this->parseInline('wait...');

        // The ellipsis is its own node carrying the author's source run, so the
        // Carve renderer can reproduce `...`. The leading text node keeps only
        // the prose before it.
        $children = $para->getChildren();
        $this->assertCount(2, $children);

        $text = $children[0];
        $this->assertInstanceOf(Text::class, $text);
        $this->assertSame('wait', $text->getContent());

        $ellipsis = $children[1];
        $this->assertInstanceOf(SmartPunctuation::class, $ellipsis);
        $this->assertSame('smart_punctuation', $ellipsis->getType());
        $this->assertSame('ellipsis', $ellipsis->getKind());
        $this->assertSame('...', $ellipsis->getContent());
    }

    public function testEllipsisRendersTheGlyphButFormatsBackToSource(): void
    {
        $this->assertSame(
            "<p>wait\u{2026}</p>\n",
            (new CarveConverter())->convert('wait...'),
        );
        $this->assertSame('wait...', trim(CarveConverter::carve()->convert('wait...')));
    }

    public function testEmptyEmphasisIsLiteral(): void
    {
        $para = $this->parseInline('__');

        // May be split into multiple text nodes
        $content = '';
        foreach ($para->getChildren() as $child) {
            if ($child instanceof Text) {
                $content .= $child->getContent();
            }
        }
        $this->assertSame('__', $content);
    }

    public function testCodeSpanInsideStrong(): void
    {
        $para = $this->parseInline('*foo `*`*');

        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);

        // Should contain code span
        $hasCode = false;
        foreach ($strong->getChildren() as $child) {
            if ($child instanceof Code) {
                $hasCode = true;
                $this->assertSame('*', $child->getContent());
            }
        }
        $this->assertTrue($hasCode, 'Strong should contain code span');
    }

    public function testAttributesInsideEmphasis(): void
    {
        $para = $this->parseInline('*b{#id key="*"}*');

        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);

        $content = '';
        foreach ($strong->getChildren() as $child) {
            $this->assertNotInstanceOf(Span::class, $child);
            if ($child instanceof Text) {
                $content .= $child->getContent();
            } elseif ($child instanceof SmartPunctuation) {
                // Smart quotes are their own nodes now; collect their glyph so
                // this still asserts on the visible text.
                $content .= $child->getGlyph() ?? SmartPunctuation::GLYPHS[$child->getKind()];
            }
        }
        $this->assertSame('b{#id key=“*”}', $content);
    }

    public function testNestedEmphasis(): void
    {
        $para = $this->parseInline('*/nested/*');

        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);

        $em = $strong->getChildren()[0] ?? null;
        $this->assertInstanceOf(Emphasis::class, $em);
    }

    public function testAutolinkPrecedenceOverEmphasis(): void
    {
        // Autolinks should be protected from emphasis delimiter matching
        // The _ in the URL should not be treated as an emphasis closer
        $para = $this->parseInline('_<http://example.com/a_b>');

        $children = $para->getChildren();
        $this->assertCount(2, $children);

        // First should be literal underscore
        $this->assertInstanceOf(Text::class, $children[0]);
        $this->assertSame('_', $children[0]->getContent());

        // Second should be the autolink
        $this->assertInstanceOf(Link::class, $children[1]);
        $this->assertSame('http://example.com/a_b', $children[1]->getDestination());
    }

    /**
     * Test: Underscore in link URL should not break emphasis
     *
     * This is the main issue from https://github.com/jgm/djot/issues/375
     * _[link](http://example.com?foo_bar=1), more text_
     * Should produce emphasis around the entire content, with a working link.
     */
    public function testEmphasisWithUnderscoreInLinkDestination(): void
    {
        $para = $this->parseInline('/[link](http://example.com?foo_bar=1), more text/');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        // Should contain a link node followed by text
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        $this->assertSame('http://example.com?foo_bar=1', $emChildren[0]->getDestination());

        // Verify link text
        $linkChildren = $emChildren[0]->getChildren();
        $this->assertInstanceOf(Text::class, $linkChildren[0]);
        $this->assertSame('link', $linkChildren[0]->getContent());
    }

    /**
     * Test: Simple underscore in path should not break emphasis
     */
    public function testEmphasisWithUnderscoreInSimplePath(): void
    {
        $para = $this->parseInline('/hello [link](a_b) world/');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Text::class, $emChildren[0]);
        $this->assertSame('hello ', $emChildren[0]->getContent());
        $this->assertInstanceOf(Link::class, $emChildren[1]);
        $this->assertSame('a_b', $emChildren[1]->getDestination());
        $this->assertInstanceOf(Text::class, $emChildren[2]);
        $this->assertSame(' world', $emChildren[2]->getContent());
    }

    /**
     * Test: Star in link URL should not break strong
     */
    public function testStrongWithStarInLinkDestination(): void
    {
        $para = $this->parseInline('*[link](http://example.com?q=a*b) text*');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Strong::class, $children[0]);

        $strongChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $strongChildren[0]);
        $this->assertSame('http://example.com?q=a*b', $strongChildren[0]->getDestination());
    }

    /**
     * Test: Multiple underscores in URL
     */
    public function testEmphasisWithMultipleUnderscoresInDestination(): void
    {
        $para = $this->parseInline('/[link](path/to_file_name_here)/');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        $this->assertSame('path/to_file_name_here', $emChildren[0]->getDestination());
    }

    /**
     * Test: Nested parentheses in URL should be handled correctly
     */
    public function testEmphasisWithNestedParensInDestination(): void
    {
        $para = $this->parseInline('/[wiki](http://en.wikipedia.org/wiki/Foo_(bar))/');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        $this->assertSame('http://en.wikipedia.org/wiki/Foo_(bar', $emChildren[0]->getDestination());
        $this->assertInstanceOf(Text::class, $emChildren[1]);
        $this->assertSame(')', $emChildren[1]->getContent());
    }

    /**
     * Test: Backslash in URL is a literal character (no destination escapes)
     */
    public function testBackslashInDestinationIsLiteral(): void
    {
        $para = $this->parseInline('/[link](path/to\_file)/');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        // A link destination has no backslash escapes; the backslash is kept
        // verbatim (grammar url_char), matching carve-js / carve-rs.
        $this->assertSame('path/to\_file', $emChildren[0]->getDestination());
    }

    /**
     * Test: Image with underscore in URL should also work
     */
    public function testEmphasisWithUnderscoreInImageDestination(): void
    {
        $para = $this->parseInline('/![alt](image_file.png)/');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Image::class, $emChildren[0]);
        $this->assertSame('image_file.png', $emChildren[0]->getSource());
    }

    /**
     * Test: Multiple links with underscores in URLs
     */
    public function testEmphasisWithMultipleLinksWithUnderscores(): void
    {
        $para = $this->parseInline('/[a](x_y) and [b](p_q)/');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        $this->assertSame('x_y', $emChildren[0]->getDestination());
        $this->assertInstanceOf(Text::class, $emChildren[1]);
        $this->assertSame(' and ', $emChildren[1]->getContent());
        $this->assertInstanceOf(Link::class, $emChildren[2]);
        $this->assertSame('p_q', $emChildren[2]->getDestination());
    }

    /**
     * Test: Underscore in link text still triggers emphasis (bracket-text case)
     *
     * This is intentionally NOT fixed by the destination-only approach.
     * _[foo_](url) should still produce emphasis [foo then ](url) as text.
     */
    public function testUnderscoreInLinkTextStillTriggersEmphasis(): void
    {
        $para = $this->parseInline('/[bar/](url)');

        $children = $para->getChildren();
        // The _ inside [bar_] closes the emphasis started at the beginning
        // Result: <em>[bar</em>](url)
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Text::class, $emChildren[0]);
        $this->assertSame('[bar', $emChildren[0]->getContent());

        // Remaining text after emphasis
        $this->assertInstanceOf(Text::class, $children[1]);
        $this->assertSame('](url)', $children[1]->getContent());
    }

    /**
     * Test: Unclosed link destination doesn't break emphasis
     */
    public function testEmphasisWithUnclosedLinkDestination(): void
    {
        // [foo](_bar is not a complete link, so emphasis should still work
        $para = $this->parseInline('/text [foo](/bar more/');

        $children = $para->getChildren();
        // This is complex - the [foo]( triggers unclosed link handling
        // The emphasis should close on the last _
        $this->assertInstanceOf(Emphasis::class, $children[0]);
    }

    /**
     * Test: an inner italic delimiter between alphanumerics is not a closer
     *
     * In Carve the intraword rule applies to the italic delimiter: a '/'
     * immediately followed by an alphanumeric cannot close emphasis, so
     * the span runs to the final '/' and the inner slash stays literal.
     */
    public function testEmphasisInnerSlashBetweenAlnumIsLiteral(): void
    {
        $para = $this->parseInline('/foo (a/b) bar/');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $text = '';
        foreach ($children[0]->getChildren() as $child) {
            $this->assertInstanceOf(Text::class, $child);
            $text .= $child->getContent();
        }
        $this->assertSame('foo (a/b) bar', $text);
    }

    /**
     * Test: Complex real-world URL with query params and underscores
     */
    public function testEmphasisWithComplexQueryString(): void
    {
        $para = $this->parseInline('/Check [this API](https://api.example.com/v1/users?sort_by=name&filter_type=active) for details/');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $found = false;
        foreach ($emChildren as $child) {
            if ($child instanceof Link) {
                $this->assertSame('https://api.example.com/v1/users?sort_by=name&filter_type=active', $child->getDestination());
                $found = true;
            }
        }
        $this->assertTrue($found, 'Link should be found within emphasis');
    }

    /**
     * Test: Strong (star) with complex URL
     */
    public function testStrongWithComplexUrl(): void
    {
        $para = $this->parseInline('*Visit [the page](http://example.com/path*with*stars) now*');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Strong::class, $children[0]);

        $strongChildren = $children[0]->getChildren();
        $foundLink = false;
        foreach ($strongChildren as $child) {
            if ($child instanceof Link) {
                $this->assertSame('http://example.com/path*with*stars', $child->getDestination());
                $foundLink = true;
            }
        }
        $this->assertTrue($foundLink, 'Link should be found within strong');
    }

    public function testEmphasisFollowedByCloseBrace(): void
    {
        // Emphasis opener cannot be followed by } (closer marker)
        $para = $this->parseInline('_}b_');

        // Should all be literal text
        $content = '';
        foreach ($para->getChildren() as $child) {
            if ($child instanceof Text) {
                $content .= $child->getContent();
            }
        }
        $this->assertSame('_}b_', $content);
    }

    public function testParseBooleanAttribute(): void
    {
        // {disabled} should create a boolean attribute with empty value
        $para = $this->parseInline('[Click me]{disabled}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('', $span->getAttribute('disabled'));
    }

    public function testParseBooleanAttributeWithClass(): void
    {
        // Boolean attr combined with class
        $para = $this->parseInline('[Submit]{disabled .btn}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('', $span->getAttribute('disabled'));
        $class = $span->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'btn'));
    }

    public function testParseBooleanAttributeWithIdAndClass(): void
    {
        // Boolean attr combined with class and id
        $para = $this->parseInline('[Submit]{.btn disabled #submit}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('', $span->getAttribute('disabled'));
        $this->assertSame('submit', $span->getAttribute('id'));
        $class = $span->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'btn'));
    }

    public function testParseMultipleBooleanAttributes(): void
    {
        // Multiple boolean attrs
        $para = $this->parseInline('[Secret]{hidden readonly}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('', $span->getAttribute('hidden'));
        $this->assertSame('', $span->getAttribute('readonly'));
    }

    public function testParseBooleanAttributeOnLink(): void
    {
        // Boolean attr on a link
        $para = $this->parseInline('[Download](file.zip){download}');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('', $link->getAttribute('download'));
    }

    public function testBooleanAttributeNotMatchedInsideQuotedValue(): void
    {
        // Words inside quoted values should NOT be treated as boolean attributes
        $para = $this->parseInline('[CSS]{abbr="Cascading Style Sheets"}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('Cascading Style Sheets', $span->getAttribute('abbr'));
        // These words should NOT exist as attributes
        $this->assertNull($span->getAttribute('Cascading'));
        $this->assertNull($span->getAttribute('Style'));
        $this->assertNull($span->getAttribute('Sheets'));
    }

    public function testBooleanAttributeWithQuotedValueAndClass(): void
    {
        // Boolean + quoted value + class should all work correctly
        $para = $this->parseInline('[Get it](file.zip){download title="Download file" .btn}');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('', $link->getAttribute('download'));
        $this->assertSame('Download file', $link->getAttribute('title'));
        $class = $link->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'btn'));
        // "Download" and "file" should NOT be boolean attributes
        $this->assertNull($link->getAttribute('Download'));
        $this->assertNull($link->getAttribute('file'));
    }

    // ===== Trailing Attributes for Inline Elements =====

    public function testEmphasisWithTrailingAttributes(): void
    {
        $para = $this->parseInline('/emphasized text/{.highlight}');

        $em = $this->getFirstChild($para);
        $this->assertInstanceOf(Emphasis::class, $em);
        $this->assertSame('highlight', $em->getAttribute('class'));
    }

    public function testStrongWithTrailingAttributes(): void
    {
        $para = $this->parseInline('*strong text*{.important #main}');

        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);
        $this->assertSame('important', $strong->getAttribute('class'));
        $this->assertSame('main', $strong->getAttribute('id'));
    }

    public function testCodeSpanWithTrailingAttributes(): void
    {
        $para = $this->parseInline('`code`{.lang-js}');

        $code = $this->getFirstChild($para);
        $this->assertInstanceOf(Code::class, $code);
        $this->assertSame('lang-js', $code->getAttribute('class'));
    }

    public function testCodeSpanWithMultipleAttributes(): void
    {
        $para = $this->parseInline('`const x = 1`{.javascript data-line="5"}');

        $code = $this->getFirstChild($para);
        $this->assertInstanceOf(Code::class, $code);
        $this->assertSame('javascript', $code->getAttribute('class'));
        $this->assertSame('5', $code->getAttribute('data-line'));
    }

    public function testSuperscriptWithTrailingAttributes(): void
    {
        // Superscript is the braced form only; a bare `^2^` is literal text.
        $para = $this->parseInline('{^2^}{.exponent}');

        $sup = $this->getFirstChild($para);
        $this->assertInstanceOf(Superscript::class, $sup);
        $this->assertSame('exponent', $sup->getAttribute('class'));
    }

    public function testBareSuperscriptStaysLiteral(): void
    {
        $para = $this->parseInline('^2^ end');

        $this->assertInstanceOf(Text::class, $this->getFirstChild($para));
    }

    public function testSubscriptWithTrailingAttributes(): void
    {
        // Subscript is the braced form only; a bare `,2,` is literal text.
        $para = $this->parseInline('{,2,}{.chemical}');

        $sub = $this->getFirstChild($para);
        $this->assertInstanceOf(Subscript::class, $sub);
        $this->assertSame('chemical', $sub->getAttribute('class'));
    }

    public function testBareSubscriptStaysLiteral(): void
    {
        $para = $this->parseInline(',2, end');

        $this->assertInstanceOf(Text::class, $this->getFirstChild($para));
    }

    public function testBracedSuperscriptWithTrailingAttributes(): void
    {
        $para = $this->parseInline('{^text^}{.ref}');

        $sup = $this->getFirstChild($para);
        $this->assertInstanceOf(Superscript::class, $sup);
        $this->assertSame('ref', $sup->getAttribute('class'));
    }

    public function testBracedSubscriptWithTrailingAttributes(): void
    {
        $para = $this->parseInline('{,text,}{.formula}');

        $sub = $this->getFirstChild($para);
        $this->assertInstanceOf(Subscript::class, $sub);
        $this->assertSame('formula', $sub->getAttribute('class'));
    }

    public function testHighlightWithTrailingAttributes(): void
    {
        $para = $this->parseInline('=highlighted={.match}');

        $mark = $this->getFirstChild($para);
        $this->assertInstanceOf(Highlight::class, $mark);
        $this->assertSame('match', $mark->getAttribute('class'));
    }

    public function testInsertWithTrailingAttributes(): void
    {
        $para = $this->parseInline('{+inserted+}{.added}');

        $ins = $this->getFirstChild($para);
        $this->assertInstanceOf(Insert::class, $ins);
        $this->assertSame('added', $ins->getAttribute('class'));
    }

    public function testDeleteWithTrailingAttributes(): void
    {
        $para = $this->parseInline('{-deleted-}{.removed}');

        $del = $this->getFirstChild($para);
        $this->assertInstanceOf(Delete::class, $del);
        $this->assertSame('removed', $del->getAttribute('class'));
    }

    public function testConsecutiveBracedInlines(): void
    {
        $para = $this->parseInline('{+a+}{+b+}');

        $children = $para->getChildren();
        $this->assertCount(2, $children);

        $this->assertInstanceOf(Insert::class, $children[0]);
        $this->assertInstanceOf(Insert::class, $children[1]);
    }

    public function testConsecutiveDifferentBracedInlines(): void
    {
        $para = $this->parseInline('{-del-}{+ins+}');

        $children = $para->getChildren();
        $this->assertCount(2, $children);

        $this->assertInstanceOf(Delete::class, $children[0]);
        $this->assertInstanceOf(Insert::class, $children[1]);
    }

    public function testSymbolWithTrailingAttributes(): void
    {
        $para = $this->parseInline(':emoji:{.large}');

        $symbol = $this->getFirstChild($para);
        $this->assertInstanceOf(Symbol::class, $symbol);
        $this->assertSame('emoji', $symbol->getName());
        $this->assertSame('large', $symbol->getAttribute('class'));
    }

    public function testTrailingAttributesDoNotAffectFollowingText(): void
    {
        $para = $this->parseInline('/text/{.cls} more text');

        $children = $para->getChildren();
        $this->assertCount(2, $children);

        $em = $children[0];
        $this->assertInstanceOf(Emphasis::class, $em);
        $this->assertSame('cls', $em->getAttribute('class'));

        $text = $children[1];
        $this->assertInstanceOf(Text::class, $text);
        $this->assertSame(' more text', $text->getContent());
    }

    public function testMultipleInlineElementsWithTrailingAttributes(): void
    {
        $para = $this->parseInline('/em/{.a} and *strong*{.b}');

        $children = $para->getChildren();
        $this->assertCount(3, $children);

        $this->assertInstanceOf(Emphasis::class, $children[0]);
        $this->assertSame('a', $children[0]->getAttribute('class'));

        $this->assertInstanceOf(Text::class, $children[1]);

        $this->assertInstanceOf(Strong::class, $children[2]);
        $this->assertSame('b', $children[2]->getAttribute('class'));
    }

    public function testNestedEmphasisWithTrailingAttributes(): void
    {
        $para = $this->parseInline('/outer *inner*/{.outer-class}');

        $em = $this->getFirstChild($para);
        $this->assertInstanceOf(Emphasis::class, $em);
        $this->assertSame('outer-class', $em->getAttribute('class'));
    }

    public function testInlineElementWithoutTrailingAttributesStillWorks(): void
    {
        $para = $this->parseInline('/plain emphasis/ text');

        $em = $this->getFirstChild($para);
        $this->assertInstanceOf(Emphasis::class, $em);
        $this->assertEmpty($em->getAttributes());
    }

    public function testInlineLinkDestinationEndsAtFirstClosingParen(): void
    {
        $para = $this->parseInline('[x](http://a/b(c))');
        $children = $para->getChildren();

        $this->assertCount(2, $children);
        $this->assertInstanceOf(Link::class, $children[0]);
        $this->assertSame('http://a/b(c', $children[0]->getDestination());
        $this->assertInstanceOf(Text::class, $children[1]);
        $this->assertSame(')', $children[1]->getContent());
    }

    public function testInlineLinkTitleAllowsBackslashEscapedQuote(): void
    {
        // An INLINE-link title may contain a backslash-escaped delimiter,
        // kept as a literal quote (CommonMark-style; grammar.ebnf link_title,
        // decision D). The ref-def title slot deliberately does NOT honor it.
        $para = $this->parseInline('[t](/url "ti\\"tle")');
        $link = $this->getFirstChild($para);

        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('/url', $link->getDestination());
        $this->assertSame('ti"tle', $link->getTitle());
    }
}
