<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * An editorial comment is its own node type.
 *
 * It used to be a `span` carrying a `critic-comment` class, which meant this
 * engine's tree disagreed with the reference for the same document and nothing
 * keyed by node type - a profile, a schema bridge - could name it. The type is
 * `critic_comment` in the spec vocabulary (docs/profiles.md).
 */
class CriticCommentNodeTest extends TestCase
{
    protected BlockParser $parser;

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->parser = new BlockParser();
        $this->converter = new CarveConverter();
    }

    public function testTheParserEmitsADedicatedNode(): void
    {
        $document = $this->parser->parse("a {# note #} b\n");
        $comment = $this->firstCriticComment($document->getChildren()[0]->getChildren());

        $this->assertInstanceOf(CriticComment::class, $comment);
        $this->assertSame('critic_comment', $comment->getType());
        // Literal content: the spaces survive and the asterisks are not markup.
        $this->assertSame(' note ', $comment->getContent());
        $this->assertSame([], $comment->getChildren());
    }

    public function testNoSpanCarriesTheClassAnyMore(): void
    {
        $document = $this->parser->parse("a {# note #} b\n");
        foreach ($document->getChildren()[0]->getChildren() as $node) {
            $this->assertFalse(
                $node instanceof Span && $node->hasClass('critic-comment'),
                'the class-on-a-span representation should be gone from the tree',
            );
        }
    }

    public function testTheRenderedClassIsUnchanged(): void
    {
        // The node type moved; the CSS class deliberately did not. It is
        // user-visible styling that stylesheets and syntax themes select on, so
        // it does not follow the AST vocabulary.
        $this->assertStringContainsString(
            '<span class="critic-comment"> note </span>',
            $this->converter->convert("a {# note #} b\n"),
        );
    }

    public function testTheEncodedFieldIsTextNotContent(): void
    {
        // `text` is what the reference calls it, and PART 12 section 3 makes
        // field names spec surface rather than an engine's internal naming.
        $encoded = (new AstCodec())->encode($this->parser->parse("a {# note #} b\n"));
        $inline = $encoded['children'][0]['children'][1];

        $this->assertSame('critic_comment', $inline['type']);
        $this->assertSame(' note ', $inline['text']);
        $this->assertArrayNotHasKey('content', $inline);
        $this->assertArrayNotHasKey('children', $inline);
    }

    public function testItSurvivesAJsonRoundTrip(): void
    {
        $codec = new AstCodec();
        $source = "a {# keep *this* #} b\n";
        $decoded = $codec->decode($codec->encode($this->parser->parse($source)));

        $this->assertSame(
            $this->converter->convert($source),
            $this->converter->render($decoded),
        );
    }

    public function testAdjacentCommentsSurviveTheProseMirrorBridge(): void
    {
        // They arrive as two text nodes carrying the same mark, and the generic
        // merge for adjacent same-marks appends CHILDREN - which a comment has
        // none of, so the second one's text was dropped and `{#one#}{#two#}`
        // came back as `{#one#}`. Merging would be wrong even if it were
        // lossless: that is two comments, not one.
        $source = "a {#one#}{#two#} b\n";
        $bridged = (new ProseMirrorToCarve())->convert(
            (new ProseMirrorRenderer())->render($this->parser->parse($source)),
        );

        $this->assertSame($this->converter->convert($source), $this->converter->render($bridged));
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    private function firstCriticComment(array $nodes): ?CriticComment
    {
        foreach ($nodes as $node) {
            if ($node instanceof CriticComment) {
                return $node;
            }
        }

        return null;
    }
}
