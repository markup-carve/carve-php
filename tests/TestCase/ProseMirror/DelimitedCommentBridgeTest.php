<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A delimited `{% x %}` comment survives the ProseMirror bridge.
 *
 * PART 9 section 21a gives a comment a second spelling, and the two are NOT
 * interchangeable: `%%` runs to the end of the line, `{% x %}` ends at its own
 * closer. The bridge carried the text but not which spelling it was, so every
 * delimited comment came back as `%%` - and with it the rest of the paragraph.
 *
 * Written:
 *
 *     foo {% bar %} baz
 *
 * came back from the bridge with the comment respelled `%%`, which runs to the
 * end of the line - so it swallowed ` baz` and the paragraph rendered as
 * `<p>foo</p>`. The renderer reported nothing dropped and nothing degraded,
 * because from its side nothing was: the loss happens on the way back, in the
 * spelling. The exact bytes are in the assertions below.
 *
 * These assert on canonical CARVE, deliberately. A comment renders to nothing,
 * so the document that lost its tail and the document that kept it differ in
 * HTML only by the tail - and for a comment in a table cell, not even by that.
 * An HTML comparison is structurally incapable of seeing the re-spelling that
 * causes the loss, which is why the corpus gate compares Carve too.
 */
class DelimitedCommentBridgeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function payloadFor(string $carve): array
    {
        return (new ProseMirrorRenderer())->render((new CarveConverter())->parse($carve));
    }

    protected function roundTrip(string $carve): string
    {
        return CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($this->payloadFor($carve)));
    }

    protected function canonical(string $carve): string
    {
        return CarveConverter::carve()->render((new CarveConverter())->parse($carve));
    }

    protected function assertRoundTrips(string $carve): void
    {
        $this->assertSame($this->canonical($carve), $this->roundTrip($carve));
    }

    public function testADelimitedCommentMidParagraphKeepsItsSpelling(): void
    {
        $this->assertRoundTrips("foo {% bar %} baz\n");
    }

    /**
     * The symptom a user reports is not "my comment changed spelling", it is
     * "the end of my paragraph is gone". Asserted on its own so a regression
     * says so in those words.
     */
    public function testTheTextAfterADelimitedCommentSurvives(): void
    {
        $written = $this->roundTrip("foo {% bar %} baz\n");

        $this->assertStringContainsString(' baz', $written);
        $this->assertSame(
            "<p>foo  baz</p>\n",
            (new CarveConverter())->convert($written),
            'the paragraph tail was swallowed by a comment that runs to end of line',
        );
    }

    public function testADelimitedCommentOpeningAnInlineRunKeepsItsSpelling(): void
    {
        $carve = "{% note %} visible tail\n";

        $this->assertRoundTrips($carve);
        // Worst case of the same defect: with the comment first, the `%%`
        // spelling eats the WHOLE paragraph and the document renders empty.
        $this->assertSame("<p> visible tail</p>\n", (new CarveConverter())->convert($this->roundTrip($carve)));
    }

    public function testTwoDelimitedCommentsInOneParagraphBothKeepTheirSpelling(): void
    {
        $carve = "a {% one %} b {% two %} c\n";

        $this->assertRoundTrips($carve);
        $this->assertSame("<p>a  b  c</p>\n", (new CarveConverter())->convert($this->roundTrip($carve)));
    }

    /**
     * The control. A `%%` comment DOES run to end of line, so its round trip
     * must keep doing exactly that - the fix carries a flag, it does not change
     * what the unflagged spelling means.
     */
    public function testALineCommentStillRunsToEndOfLine(): void
    {
        $carve = "foo %% bar\n";

        $this->assertRoundTrips($carve);
        $this->assertSame("<p>foo</p>\n", (new CarveConverter())->convert($this->roundTrip($carve)));
    }

    /**
     * The flag itself, on the payload. The bridge carried the comment's text
     * all along; what it never carried was which spelling produced it, and no
     * test ever looked at the payload to notice.
     */
    public function testThePayloadCarriesTheDelimitedFlag(): void
    {
        $delimited = $this->payloadFor("foo {% bar %} baz\n");
        $line = $this->payloadFor("foo %% bar\n");

        $this->assertSame(
            ['content' => 'bar', 'delimited' => true],
            $delimited['content'][0]['content'][1]['attrs'],
        );
        $this->assertSame(
            ['content' => 'bar', 'delimited' => false],
            $line['content'][0]['content'][1]['attrs'],
        );
    }

    /**
     * A comment inside a table cell went down the cell-lifting path, which asks
     * whether a payload is a block - and a comment's node class is filed under
     * blocks for BOTH spellings, so the inline atom was lifted as a block,
     * recursed into for children it does not have, and dropped entirely. Both
     * spellings were affected, and the HTML is identical either way, so nothing
     * could see it.
     */
    public function testACommentInsideATableCellSurvivesInBothSpellings(): void
    {
        $this->assertRoundTrips("| a {% hidden %} b | c |\n|---|---|\n| d %% tail | e |\n");
    }

    /**
     * No parser puts a delimited comment at block level, but an ingested PART 12
     * payload can, and the Carve writer honors it there - so the block spelling
     * has to carry the flag as well.
     */
    public function testAnIngestedBlockLevelDelimitedCommentKeepsItsSpelling(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 8,
            'children' => [
                ['type' => 'comment', 'content' => 'x', 'delimited' => true, 'block' => false],
            ],
        ]);

        $payload = (new ProseMirrorRenderer())->render($document);

        $this->assertSame(['block' => false, 'delimited' => true], $payload['content'][0]['attrs']);
        $this->assertSame(
            "{% x %}\n",
            CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($payload)),
        );
    }
}
