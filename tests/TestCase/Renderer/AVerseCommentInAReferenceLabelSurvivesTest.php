<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A verse comment inside a reference label: kept by `fmt`, published by nobody.
 *
 * TWO CONSUMERS, TWO CONTRACTS. PART 12 section 3a says `rawRef` is the
 * authored source VERBATIM, and the canonical writer emits it unchanged so the
 * collapsed `[a][]` is not rewritten to `[a][a]` and an attribute block written
 * at the reference is not emitted twice. PART 9 section 23 says a comment LINE
 * publishes nothing, so every other target has to empty it again.
 *
 * carve-php had NEITHER half right and they failed in opposite directions. The
 * snapshot was taken by the INLINE parse, which reads a stanza whose comment
 * lines the block layer has already emptied - so `carve fmt` wrote a bare `%%`
 * where the author wrote `%% secret` (carve-php#1417), while every renderer
 * looked clean for the same reason. carve-js stored the authored source and
 * published it into the HTML instead. Changing what `rawRef` HOLDS can only fix
 * one of the two, which is why the split is at the CONSUMER.
 *
 * NEITHER GATE SAW THE `fmt` HALF. The comment publishes nothing, so the HTML
 * agreed; the bare `%%` reparses to a valid tree, so the round trip held on
 * SHAPE while the content differed. So the assertion is on the comment's
 * CONTENT after a round trip.
 */
class AVerseCommentInAReferenceLabelSurvivesTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function referenceProvider(): array
    {
        return [
            'an undefined reference' => ["::: |\n[a\n%% secret\nc][missing]\n:::\n"],
            'a defined reference' => ["::: |\n[a\n%% secret\nc][r]\n:::\n\n[r]: /u\n"],
            'the collapsed form' => ["::: |\n[a\n%% secret\nc][]\n:::\n\n[a c]: /u\n"],
            'a comment under emphasis inside the label' => ["::: |\n[a /b\n%% secret\nd/][missing]\n:::\n"],
            'two comments in one label' => ["::: |\n[a\n%% first\nb\n%% second\nc][missing]\n:::\n"],
            // A reference nested in another reference's LABEL gives TWO
            // snapshots that both contain the emptied line, and the writer
            // emits the outer one as a whole - so repairing only the nearest
            // left the author's text out of the output anyway.
            'a reference inside a reference label' => ["::: |\n[x [y\n%% secret\nz][inner] w][outer]\n:::\n"],
        ];
    }

    #[DataProvider('referenceProvider')]
    public function testTheContentSurvivesTheCanonicalWriter(string $source): void
    {
        $before = $this->commentContents($source);

        $this->assertNotSame([], $before);
        $this->assertSame($before, $this->commentContents((new CarveConverter())->toCarve($source)));
    }

    /**
     * NO TARGET PUBLISHES IT. The `fmt` half above is only correct if this
     * holds: the stored source now HAS the author's text in it, so a renderer
     * that wrote the string through would put private text into the output.
     * That is the half carve-js had wrong.
     *
     * @return array<string, array{0: string}>
     */
    public static function targetProvider(): array
    {
        return [
            'html' => ['html'],
            'markdown' => ['markdown'],
            'plain text' => ['plainText'],
            'ansi' => ['ansi'],
        ];
    }

    /**
     * THE OUTER SNAPSHOT IS THE ONE THE WRITER EMITS, so it is asserted on the
     * BYTES rather than only on the reparsed tree: the inner reference's own
     * repair would satisfy a comment-content check while the outer string the
     * writer actually emits still held a bare `%%`.
     */
    public function testEveryEnclosingReferenceSnapshotIsRepaired(): void
    {
        $source = "::: |\n[x [y\n%% secret\nz][inner] w][outer]\n:::\n";

        $this->assertSame($source, (new CarveConverter())->toCarve($source));
    }

    #[DataProvider('targetProvider')]
    public function testNoPublicationTargetEmitsTheCommentText(string $target): void
    {
        $source = "::: |\n[a\n%% secret\nc][missing]\n:::\n";

        $this->assertStringNotContainsString('secret', $this->renderTo($target, $source));
        $this->assertStringNotContainsString('%%', $this->renderTo($target, $source));
    }

    /**
     * And the canonical writer is the one that does keep it, so the assertion
     * above is not passing because every target lost it again.
     */
    public function testTheCanonicalWriterIsTheOneThatKeepsIt(): void
    {
        $source = "::: |\n[a\n%% secret\nc][missing]\n:::\n";

        $this->assertStringContainsString('%% secret', $this->renderTo('carve', $source));
    }

    /**
     * The shapes that already walked their children. Without these the fix
     * could be "empty every comment everywhere" and nothing would say so.
     *
     * @return array<string, array{0: string}>
     */
    public static function controlProvider(): array
    {
        return [
            'an inline link' => ["::: |\n[a\n%% secret\nc](/u)\n:::\n"],
            'a span' => ["::: |\n[a\n%% secret\nc]{.k}\n:::\n"],
            'an inline footnote' => ["::: |\n^[a\n%% secret\nc]\n:::\n"],
            'the same reference in an ordinary paragraph' => ["[a\n%% secret\nc][missing]\n"],
        ];
    }

    #[DataProvider('controlProvider')]
    public function testTheShapesThatAlreadyWorkedStillDo(string $source): void
    {
        $this->assertSame(['secret'], $this->commentContents((new CarveConverter())->toCarve($source)));
    }

    /**
     * AN INDENTED `%%` IS CONTENT, not a comment: leading whitespace is content
     * in verse, so the block layer never empties that line and no renderer may
     * either. This is the control on the emptying test - without it, "drop any
     * line holding `%%`" passes every assertion above.
     */
    public function testAnIndentedPercentLineIsContentAndSurvivesEveryTarget(): void
    {
        $source = "::: |\n[a\n  %% kept\nc][missing]\n:::\n";

        foreach (['html', 'markdown', 'plainText', 'ansi', 'carve'] as $target) {
            $this->assertStringContainsString('kept', $this->renderTo($target, $source), $target);
        }
    }

    /**
     * THE EMPTYING TEST IS THE BLOCK LAYER'S, asserted on an INGESTED snapshot.
     *
     * A parse writes an indented verse line's spaces as sentinels, so a
     * `ltrim()` in the renderer would not reach them and the authored spelling
     * cannot tell the two readings apart. `rawRef` is a PART 12 wire field,
     * though, so an ingested tree carries whatever it says - and there the
     * difference is visible: an indented `%% x` is CONTENT and every target
     * keeps it.
     */
    public function testAnIngestedIndentedPercentLineIsContent(): void
    {
        $codec = new AstCodec();
        $payload = [
            'type' => 'document',
            'srcByteLength' => 10,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'link',
                            'ref' => 'r',
                            'rawRef' => "[a\n  %% x\nc][r]",
                            'href' => '',
                            'children' => [['type' => 'text', 'value' => 'a']],
                        ],
                    ],
                ],
            ],
        ];

        $document = $codec->decodeJson((string)json_encode($payload));

        $this->assertStringContainsString('  %% x', (new CarveConverter())->getHtmlRenderer()->render($document));
        $this->assertStringContainsString('  %% x', CarveConverter::carve()->getRenderer()->render($document));
    }

    /**
     * And the un-indented spelling of the same ingested snapshot IS emptied, so
     * the row above is not passing because nothing is emptied at all.
     */
    public function testAnIngestedFlushLeftPercentLineIsEmptied(): void
    {
        $codec = new AstCodec();
        $payload = [
            'type' => 'document',
            'srcByteLength' => 10,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'link',
                            'ref' => 'r',
                            'rawRef' => "[a\n%% x\nc][r]",
                            'href' => '',
                            'children' => [['type' => 'text', 'value' => 'a']],
                        ],
                    ],
                ],
            ],
        ];

        $document = $codec->decodeJson((string)json_encode($payload));

        $this->assertStringNotContainsString('%% x', (new CarveConverter())->getHtmlRenderer()->render($document));
        $this->assertStringContainsString('%% x', CarveConverter::carve()->getRenderer()->render($document));
    }

    /**
     * THE REFERENCE HALF IS STILL THE AUTHOR'S, which is what the snapshot is
     * for: the collapsed form stays collapsed rather than expanding to `[a][a]`.
     */
    public function testTheCollapsedFormIsNotExpanded(): void
    {
        $formatted = (new CarveConverter())->toCarve("[a][]\n\n[a]: /u\n");

        $this->assertStringContainsString('[a][]', $formatted);
        $this->assertStringNotContainsString('[a][a]', $formatted);
    }

    /**
     * And an attribute block written AT the reference is inside the snapshot
     * already, so it is written once.
     */
    public function testAnAttributeAtTheReferenceIsWrittenOnce(): void
    {
        $formatted = (new CarveConverter())->toCarve("[a][r]{.own}\n\n[r]: /u\n");

        $this->assertSame(1, substr_count($formatted, '{.own}'));
    }

    /**
     * A reference with no comment near it is byte-identical, so the repair is
     * not quietly rewriting every document.
     */
    public function testAnOrdinaryReferenceIsByteIdentical(): void
    {
        $source = "[a /b/ `c`][r]\n\n[r]: /u\n";

        $this->assertSame($source, (new CarveConverter())->toCarve($source));
    }

    private function renderTo(string $target, string $source): string
    {
        if ($target === 'html') {
            return (new CarveConverter())->convert($source);
        }

        /** @var \MarkupCarve\Carve\CarveConverter $converter */
        $converter = CarveConverter::$target();

        return $converter->getRenderer()->render($converter->parse($source));
    }

    /**
     * @return array<string>
     */
    private function commentContents(string $source): array
    {
        $found = [];
        $this->collectComments((new CarveConverter())->parse($source), $found);

        return $found;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string> $found
     */
    private function collectComments(Node $node, array &$found): void
    {
        if ($node instanceof Comment) {
            $found[] = $node->getContent();
        }

        foreach ($node->getChildren() as $child) {
            $this->collectComments($child, $found);
        }
    }
}
