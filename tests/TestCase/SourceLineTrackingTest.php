<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class SourceLineTrackingTest extends TestCase
{
    public function testDisabledByDefaultEmitsNoSourceLineAttribute(): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert("> Quote\n> - item\n>   - nested\n");

        $this->assertStringNotContainsString('data-source-line', $html);
    }

    public function testEnabledStampsTopLevelBlocksWithOneBasedSourceLine(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert("# Heading\n\nParagraph one.\n\nParagraph two.\n");

        $this->assertTagLine('h1', 1, $html);
        $this->assertTagLine('p', 3, $html);
        $this->assertTagLine('p', 5, $html);
    }

    public function testBlockquoteNestedParagraphsAreStamped(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert("> Quote one\n>\n> Quote two\n");

        $this->assertTagLine('blockquote', 1, $html);
        $this->assertMatchesRegularExpression('/<p[^>]*data-source-line="1"[^>]*>Quote one<\/p>/', $html);
        $this->assertMatchesRegularExpression('/<p[^>]*data-source-line="3"[^>]*>Quote two<\/p>/', $html);
    }

    public function testBlockquoteLazyContinuationKeepsOriginalStartLine(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert("> Quote\nlazy continuation\n");

        $this->assertTagLine('blockquote', 1, $html);
        $this->assertMatchesRegularExpression('/<p[^>]*data-source-line="1"[^>]*>Quote\nlazy continuation<\/p>/', $html);
    }

    public function testDivNestedParagraphAndCodeFenceAreStamped(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert("::: note\nInside\n\n```\ncode\n```\n:::\n");

        $this->assertTagLine('aside', 1, $html);
        $this->assertMatchesRegularExpression('/<p[^>]*data-source-line="2"[^>]*>Inside<\/p>/', $html);
        $this->assertTagLine('pre', 4, $html);
    }

    public function testListItemsLooseParagraphsAndNestedSublistsAreStamped(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert("- Alpha\n\n  Alpha detail\n\n  - Beta\n    - Gamma\n- Delta\n");

        $this->assertTagLine('ul', 1, $html);
        $this->assertMatchesRegularExpression('/<li[^>]*data-source-line="1"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<p[^>]*data-source-line="3"[^>]*>Alpha detail<\/p>/', $html);
        $this->assertMatchesRegularExpression('/<li[^>]*data-source-line="5"[^>]*><p[^>]*data-source-line="5"[^>]*>Beta/', $html);
        $this->assertMatchesRegularExpression('/<li[^>]*data-source-line="6"[^>]*><p[^>]*data-source-line="6"[^>]*>Gamma/', $html);
        $this->assertMatchesRegularExpression('/<li[^>]*data-source-line="7"[^>]*><p[^>]*data-source-line="7"[^>]*>Delta/', $html);
    }

    public function testListInsideBlockquoteComposesSourceLineMaps(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert("> Intro\n>\n> - Quoted item\n>   - Nested quoted item\n");

        $this->assertTagLine('blockquote', 1, $html);
        $this->assertTagLine('ul', 3, $html);
        $this->assertMatchesRegularExpression('/<li[^>]*data-source-line="3"[^>]*><p[^>]*data-source-line="3"[^>]*>Quoted item/', $html);
        $this->assertMatchesRegularExpression('/<li[^>]*data-source-line="4"[^>]*><p[^>]*data-source-line="4"[^>]*>Nested quoted item/', $html);
    }

    public function testFootnoteContentBlocksAreStamped(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert("Use [^a].\n\n[^a]: First\n\n  Second\n");

        $this->assertMatchesRegularExpression('/<p[^>]*data-source-line="3"[^>]*>First<\/p>/', $html);
        $this->assertMatchesRegularExpression('/<p[^>]*data-source-line="5"[^>]*>Second<a /', $html);
    }

    public function testDefinitionTermsAndDescriptionsAreStamped(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert(":: Term\n:  Definition\n\n:: Empty\n");

        $this->assertTagLine('dl', 1, $html);
        $this->assertMatchesRegularExpression('/<dt[^>]*data-source-line="1"[^>]*>Term<\/dt>/', $html);
        $this->assertMatchesRegularExpression('/<dd[^>]*data-source-line="2"[^>]*>Definition<\/dd>/', $html);
        // A term without a definition body renders no <dd> at all in Carve
        // (cross-implementation behavior) - the term itself is the anchor.
        $this->assertMatchesRegularExpression('/<dt[^>]*data-source-line="4"[^>]*>Empty<\/dt>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<dt[^>]*>Empty<\/dt>\s*<dd/', $html);
    }

    public function testAuthorSourceLineAttributeOnNestedBlockIsNotOverwritten(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert("> {data-source-line=99}\n> Nested\n");

        $this->assertTagLine('blockquote', 1, $html);
        $this->assertMatchesRegularExpression('/<p[^>]*data-source-line="99"[^>]*>Nested<\/p>/', $html);
    }

    public function testParseBlockContentDoesNotStampExtractedNestedContent(): void
    {
        $parser = new BlockParser(trackSourceLines: true);
        $parser->addBlockPattern('/^!!!$/', function (array $lines, int $start, Node $parent, BlockParser $parser): ?int {
            $content = [];
            $i = $start + 1;
            $count = count($lines);
            while ($i < $count && trim($lines[$i]) !== '!!!') {
                $content[] = $lines[$i];
                $i++;
            }

            $div = new Div();
            $div->addClass('custom');
            $parser->parseBlockContent($div, $content);
            $parent->appendChild($div);

            return $i - $start + 1;
        });
        $converter = new CarveConverter(parser: $parser);

        $html = $converter->convert("Intro\n\n!!!\nNested\n\nStill nested\n!!!\n");

        $this->assertTagLine('p', 1, $html);
        $this->assertTagLine('div', 3, $html);
        $this->assertMatchesRegularExpression('/<div[^>]*data-source-line="3"[^>]*>\n  <p>Nested<\/p>\n  <p>Still nested<\/p>\n<\/div>/', $html);
    }

    public function testCrlfInputUsesOriginalLineNumbers(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        $html = $converter->convert("- One\r\n\r\n  Two\r\n");

        $this->assertMatchesRegularExpression('/<li[^>]*data-source-line="1"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<p[^>]*data-source-line="3"[^>]*>Two<\/p>/', $html);
    }

    private function assertTagLine(string $tag, int $line, string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/<' . preg_quote($tag, '/') . '\b[^>]*data-source-line="' . $line . '"[^>]*>/',
            $html,
        );
    }
}
