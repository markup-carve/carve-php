<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §1 for reference links, which PART 12 §10 made satisfiable.
 *
 * The writer used to fold a resolved reference into an inline link. That kept
 * `toHtml(fmt(x)) == toHtml(x)` and broke `parse(fmt(x)) == parse(x)`: `ref` and
 * `rawRef` - which §3a keeps precisely so `[a][r]` and `[a](/u)` stay
 * distinguishable - were absent from the reparse. It also duplicated a
 * destination the definition form exists to write once, so one URL became N
 * after a single `fmt` pass (markup-carve/carve#642).
 */
class CarveWriterReferenceDefinitionTest extends TestCase
{
    private function carve(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    /**
     * The invariant itself, asserted on the tree rather than on the bytes: §1 is
     * about `parse(fmt(x))`, and comparing output text would pass for a writer
     * that produced a different document spelled tidily.
     */
    private function assertRoundTrips(string $source): void
    {
        $codec = new AstCodec();
        $strip = static function (array $node) use (&$strip): array {
            unset($node['pos']);
            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = array_map($strip, $node['children']);
            }

            return $node;
        };
        $parse = static fn (string $text): array => $strip(
            (new AstCodec())->encode((new BlockParser())->parse($text)),
        );
        unset($codec);

        $this->assertSame(
            $parse($source),
            $parse($this->carve($source)),
            'parse(fmt(x)) != parse(x) for: ' . json_encode($source),
        );
    }

    public function testAResolvedReferenceStaysAReference(): void
    {
        $this->assertSame("[a][r]\n\n[r]: /u\n", $this->carve("[a][r]\n\n[r]: /u\n"));
        $this->assertRoundTrips("[a][r]\n\n[r]: /u\n");
    }

    public function testTheDefinitionKeepsItsTitleAndAttributes(): void
    {
        $source = "[a][r]\n\n[r]: /u \"T\" {.x}\n";
        $this->assertSame("[a][r]\n\n[r]: /u \"T\" {.x}\n", $this->carve($source));
        $this->assertRoundTrips($source);
    }

    /**
     * The readable failure: a definition exists so a destination is written
     * ONCE. Inlining turned one URL into one per use site, so an author who ran
     * the formatter lost the ability to change it in one place.
     */
    public function testADefinitionUsedTwiceIsStillWrittenOnce(): void
    {
        $source = "[a][r] [b][r]\n\n[r]: /u\n";
        $formatted = $this->carve($source);

        $this->assertSame("[a][r] [b][r]\n\n[r]: /u\n", $formatted);
        $this->assertSame(1, substr_count($formatted, '/u'), 'the destination is written once');
        $this->assertRoundTrips($source);
    }

    /**
     * The gap §10a named: an unused definition survived neither the tree nor a
     * round trip, so `fmt` deleted the URL outright.
     */
    public function testAnUnusedDefinitionSurvives(): void
    {
        $this->assertSame("[r]: /u\n", $this->carve("[r]: /u\n"));
        $this->assertRoundTrips("[r]: /u\n");
    }

    /**
     * Attributes the author wrote AT the reference stay there, and the
     * definition's own are not repeated at the reference site: resolution copies
     * them onto every resolving link so HTML can render them, and writing them
     * in both places said the same thing twice.
     */
    public function testAttributesAreWrittenWhereTheAuthorPutThem(): void
    {
        $this->assertSame("[a][r]{.own}\n\n[r]: /u\n", $this->carve("[a][r]{.own}\n\n[r]: /u\n"));
        $this->assertRoundTrips("[a][r]{.own}\n\n[r]: /u\n");
        $this->assertRoundTrips("[a][r]{.own}\n\n[r]: /u {.fromdef}\n");
    }

    /**
     * A reference IMAGE resolves the same definition entry (markup-carve/carve
     * #641), so it writes the reference for the same reason. Inlining it while
     * the definition line was also emitted wrote the destination twice.
     */
    public function testAResolvedReferenceImageStaysAReference(): void
    {
        $this->assertSame("![a][r]\n\n[r]: /i.png\n", $this->carve("![a][r]\n\n[r]: /i.png\n"));
        $this->assertRoundTrips("![a][r]\n\n[r]: /i.png\n");
    }

    /**
     * PART 11 §1's second invariant: the writer is idempotent. A definition that
     * gained a line, or a reference that re-inlined on a later pass, would show
     * up here rather than in a corpus fixture.
     */
    public function testTheWriterIsIdempotent(): void
    {
        foreach (
            [
                "[a][r]\n\n[r]: /u\n",
                "[a][r] [b][r]\n\n[r]: /u \"T\" {.x}\n",
                "[r]: /u\n",
                "![a][r]\n\n[r]: /i.png\n",
            ] as $source
        ) {
            $once = $this->carve($source);
            $this->assertSame($once, $this->carve($once), 'not idempotent: ' . json_encode($source));
        }
    }

    /**
     * A heading-derived reference has no authored definition line, so there is
     * nothing to emit for it - and the reference still keeps its authored form
     * (PART 11 R1).
     */
    public function testAHeadingDerivedReferenceGetsNoDefinitionLine(): void
    {
        $source = "# Getting Started\n\nSee [getting started][].\n";

        $this->assertSame($source, $this->carve($source));
    }
}
