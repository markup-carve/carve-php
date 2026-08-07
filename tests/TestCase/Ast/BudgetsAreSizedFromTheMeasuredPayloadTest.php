<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use Closure;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\PayloadSize;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\IndexExtension;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The expansion budgets are sized from what the payload cost, not from what it
 * claims to have cost (carve-php#1052).
 *
 * The abbreviation, table-of-contents and index budgets are all
 * `max(floor, factor x source length)`. On the parse path that length is
 * MEASURED - `BlockParser` takes `strlen($input)` - so a bigger budget costs a
 * bigger document. On the AST-ingest path the same number arrived inside the
 * payload as `srcByteLength`, which let the payload choose the size of the guard
 * that was supposed to bound it, with no ceiling at all.
 *
 * A cap has to be enforced against something the attacker does not supply.
 *
 * `srcByteLength` itself is still read exactly as written: PART 12 §7 makes it a
 * field of the payload, and a reader that rewrote it would have silently
 * repaired the record. It is the BUDGET that stops trusting it.
 */
class BudgetsAreSizedFromTheMeasuredPayloadTest extends TestCase
{
    /**
     * Headings each followed by a `::: toc` block: output is blocks x headings,
     * so the budget is the only thing standing between the payload and an
     * amplification that grows with it.
     *
     * @return array<string, mixed>
     */
    protected function tocPayload(int $headings): array
    {
        $source = '';
        for ($i = 0; $i < $headings; $i++) {
            $source .= "# Heading number $i is reasonably long so the toc entry costs bytes\n\n::: toc\n:::\n\n";
        }

        $payload = (new AstCodec())->encode((new CarveConverter())->parse($source));

        // The generator, checked before it is trusted: everything below rewrites
        // this one field, so a payload that did not carry it would make each
        // case pass for the wrong reason.
        $this->assertSame('document', $payload['type']);
        $this->assertArrayHasKey('srcByteLength', $payload);
        $this->assertSame(strlen($source), $payload['srcByteLength']);

        return $payload;
    }

    protected function renderWithToc(array $payload): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());

        return $converter->render((new AstCodec())->decode($payload));
    }

    public function testANineDigitClaimNoLongerBuysAnUnboundedBudget(): void
    {
        $payload = $this->tocPayload(800);
        $honest = $this->renderWithToc($payload);

        $payload['srcByteLength'] = 1000000000;
        $this->assertSame(1000000000, $payload['srcByteLength']);
        $spoofed = $this->renderWithToc($payload);

        $wire = strlen((string)json_encode($payload));

        // Before this fix the same rewrite took a 214 KB payload to 102 MB of
        // HTML, 478x. What remains is bounded by what the payload cost, which is
        // a price the sender has to pay in bytes.
        $this->assertLessThan(
            $wire * 16,
            strlen($spoofed),
            'the claimed length still sizes the budget',
        );
        // And the honest render is untouched by any of this.
        $this->assertGreaterThan(0, strlen($honest));
    }

    /**
     * Every renderer that sizes an abbreviation budget.
     *
     * @return array<string, array{0: \Closure(): \MarkupCarve\Carve\CarveConverter}>
     */
    public static function abbreviationRenderers(): array
    {
        return [
            'html' => [fn (): CarveConverter => new CarveConverter()],
            'markdown' => [fn (): CarveConverter => CarveConverter::markdown()],
            'ansi' => [fn (): CarveConverter => CarveConverter::ansi()],
        ];
    }

    #[DataProvider('abbreviationRenderers')]
    public function testTheAbbreviationBudgetIsSizedTheSameWay(Closure $make): void
    {
        // One rule, five spellings: three budgets, and the abbreviation one is
        // written out once per renderer. The abbreviation budget is also the one
        // that is always on - no extension has to be wired for it - so a fix
        // that only reached the table of contents would have left the most
        // reachable of them behind.
        //
        // Pinned at the seam rather than through an amplification, because this
        // engine has no abbreviation amplification to measure: the wire carries
        // `expansion` on every occurrence, so a payload that renders 104 MB of
        // expansions already cost 104 MB to send. What was defective there is
        // the BASIS, not the output, so the basis is what this asks about - the
        // ceiling is set by hand and the render has to notice.
        $expansion = str_repeat('HyperText Markup Language ', 6000);
        $source = "*[HTML]: $expansion\n\n" . str_repeat('HTML ', 20) . "\n";

        $document = (new CarveConverter())->parse($source);
        // Past the budget's 1 MB floor, or the ceiling would have nothing to
        // take away.
        $this->assertGreaterThan(125000, $document->getSourceLength());

        $withoutCeiling = strlen($make()->render($document));

        $document->setIngestPayloadLength(50000);
        $this->assertSame(50000, $document->getExpansionBudgetLength());
        $withCeiling = strlen($make()->render($document));

        $this->assertLessThan($withoutCeiling, $withCeiling);
    }

    public function testTheIndexBudgetIsSizedTheSameWay(): void
    {
        // The third spelling.
        $source = '';
        for ($i = 0; $i < 400; $i++) {
            $source .= ":index[term number $i in this document] ";
        }
        $source .= "\n\n";
        for ($i = 0; $i < 60; $i++) {
            $source .= "::: index\n:::\n\n";
        }

        $document = (new CarveConverter())->parse($source);
        $payload = (new AstCodec())->encode($document);
        $this->assertSame(strlen($source), $payload['srcByteLength']);

        $render = function (array $data): string {
            $converter = new CarveConverter();
            $converter->addExtension(new IndexExtension());

            return $converter->render((new AstCodec())->decode($data));
        };

        $honest = $render($payload);
        $payload['srcByteLength'] = 1000000000;
        $spoofed = $render($payload);

        // Identical: both land on the budget's 1 MB floor, where before the fix
        // the claim would have bought a budget nine digits wide.
        $this->assertSame($honest, $spoofed);
        $this->assertGreaterThan(0, strlen($honest));
    }

    public function testAnHonestPayloadRendersIdenticallyToTheParsedDocument(): void
    {
        // The invariant the ceiling must not break. An encoded tree is several
        // times the size of the source it came from, so the measured ceiling
        // never binds on a payload this engine produced, and ingest matches
        // parse byte for byte.
        $source = "*[HTML]: HyperText Markup Language\n\nHTML and HTML and HTML.\n";
        $document = (new CarveConverter())->parse($source);
        $payload = (new AstCodec())->encode($document);

        $converter = new CarveConverter();
        $this->assertSame(
            $converter->render($document),
            $converter->render((new AstCodec())->decode($payload)),
        );
    }

    public function testTheClaimIsStillReadExactlyAsWritten(): void
    {
        // The budget stops trusting `srcByteLength`; the codec does not rewrite
        // it. A reader that repaired the field would have changed the record.
        $payload = $this->tocPayload(2);
        $payload['srcByteLength'] = 1000000000;

        $decoded = (new AstCodec())->decode($payload);

        $this->assertSame(1000000000, $decoded->getSourceLength());
        $this->assertSame(1000000000, (new AstCodec())->encode($decoded)['srcByteLength']);
    }

    public function testTheBudgetBasisIsTheSmallerOfTheClaimAndTheMeasurement(): void
    {
        $payload = $this->tocPayload(2);

        $honest = (new AstCodec())->decode($payload);
        $this->assertSame(
            $honest->getSourceLength(),
            $honest->getExpansionBudgetLength(),
            'an honest payload is bounded by its own claim, which is the smaller one',
        );

        $payload['srcByteLength'] = 1000000000;
        // Measured on the payload as it now stands, nine digits and all: the
        // rewrite made the payload very slightly bigger, and the ceiling is
        // whatever the sender actually sends.
        $measured = PayloadSize::bytes($payload, AstCodec::MAX_JSON_DEPTH);
        $spoofed = (new AstCodec())->decode($payload);
        $this->assertSame($measured, $spoofed->getExpansionBudgetLength());
        $this->assertLessThan($spoofed->getSourceLength(), $spoofed->getExpansionBudgetLength());
    }

    public function testAParsedDocumentIsBoundedByItsOwnMeasuredSource(): void
    {
        // Nothing on the parse path changes: the parser measured the input, so
        // there is no second number and no ceiling to apply.
        $source = "# Title\n\nSome text.\n";
        $document = (new CarveConverter())->parse($source);

        $this->assertSame(strlen($source), $document->getSourceLength());
        $this->assertSame(strlen($source), $document->getExpansionBudgetLength());
    }

    public function testTheMeasurementGrowsWithThePayloadAndNotWithItsClaim(): void
    {
        $small = $this->tocPayload(2);
        $large = $this->tocPayload(40);

        $smallBytes = PayloadSize::bytes($small, AstCodec::MAX_JSON_DEPTH);
        $largeBytes = PayloadSize::bytes($large, AstCodec::MAX_JSON_DEPTH);
        $this->assertGreaterThan($smallBytes * 5, $largeBytes);

        // Rewriting the claim moves the measurement by the digits and nothing
        // more, which is the whole point: a bigger budget has to be bought.
        $before = PayloadSize::bytes($small, AstCodec::MAX_JSON_DEPTH);
        $small['srcByteLength'] = 1000000000;
        $after = PayloadSize::bytes($small, AstCodec::MAX_JSON_DEPTH);
        $this->assertLessThan($before + 16, $after);
    }

    public function testTheMeasurementCountsWhatTheTextActuallyCost(): void
    {
        // Text is most of what a sender sends, so a measurement that skipped it
        // would hand back almost the whole ceiling. Two payloads identical but
        // for one string, and the difference has to show up close to byte for
        // byte.
        $short = [

            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]],
            ],
        ];
        $long = $short;
        $long['children'][0]['children'][0]['value'] = str_repeat('x', 50000);

        $grew = PayloadSize::bytes($long, AstCodec::MAX_JSON_DEPTH)
            - PayloadSize::bytes($short, AstCodec::MAX_JSON_DEPTH);

        $this->assertSame(49999, $grew);
    }

    public function testTheMeasurementUnderstatesTheRealEncodedSize(): void
    {
        // It is an approximation on purpose, and it has to be the conservative
        // one: a measurement that overstated the payload would hand back part of
        // the budget the ceiling exists to take away.
        $payload = $this->tocPayload(20);

        $this->assertLessThanOrEqual(
            strlen((string)json_encode($payload)),
            PayloadSize::bytes($payload, AstCodec::MAX_JSON_DEPTH),
        );
    }

    public function testTheCeilingNeverBindsOnADocumentThisEngineProduced(): void
    {
        // The whole corpus rather than a handful of cases, because "it does not
        // affect legitimate input" is a claim about all of them. Two properties
        // at once: the measurement never OVERSTATES the real encoded size, so
        // the ceiling is always the conservative side of the true cost; and it
        // never falls below the source length, so ingest of an honestly encoded
        // document gets exactly the budget parsing it would have got.
        $directory = dirname(__DIR__, 3) . '/tests/spec/tests/corpus';
        $inputs = glob($directory . '/*.crv') ?: [];
        $this->assertGreaterThan(400, count($inputs), 'the corpus was not found');

        $codec = new AstCodec();
        $converter = new CarveConverter();
        $overstating = [];
        $binding = [];
        foreach ($inputs as $input) {
            $document = $converter->parse((string)file_get_contents($input));
            $payload = $codec->encode($document);
            $measured = PayloadSize::bytes($payload, AstCodec::MAX_JSON_DEPTH);

            if ($measured > strlen((string)json_encode($payload))) {
                $overstating[] = basename($input);
            }
            if ($measured < $document->getSourceLength()) {
                $binding[] = basename($input);
            }
        }

        $this->assertSame([], $overstating, sprintf(
            '%d corpus documents measure larger than they encode: %s',
            count($overstating),
            implode(', ', array_slice($overstating, 0, 8)),
        ));
        $this->assertSame([], $binding, sprintf(
            '%d corpus documents would have their budget cut on ingest: %s',
            count($binding),
            implode(', ', array_slice($binding, 0, 8)),
        ));
    }

    public function testASourceMuchLargerThanItsAstGetsASmallerBudgetOnIngest(): void
    {
        // The deliberate cost of the ceiling, pinned so nobody removes it by
        // mistake while calling it a regression.
        //
        // A payload cannot tell "this document really came from two megabytes of
        // blank lines" apart from "this document says it came from two megabytes
        // and did not" - the bytes that would settle it are exactly the bytes
        // the AST does not carry. Since one of the two has to lose, it is the
        // claim: sizing a budget from what the sender actually sent is the whole
        // point, and the ingested render degrades gracefully (a `::: toc` past
        // the budget emits an empty nav) rather than corrupting anything.
        //
        // The floor keeps this narrow. The budget is `max(1 MB, 8 x basis)`, so
        // the two paths can only differ once the claim is past ~125 KB AND the
        // encoded tree is smaller than the source it came from. No corpus
        // document is - the tightest is 2.25x larger - which the case above
        // checks over all 810 of them.
        $source = str_repeat("\n", 300000);
        for ($i = 0; $i < 150; $i++) {
            $source .= "# Heading number $i is reasonably long so the toc entry costs bytes\n\n::: toc\n:::\n\n";
        }

        $document = (new CarveConverter())->parse($source);
        $payload = (new AstCodec())->encode($document);
        $measured = PayloadSize::bytes($payload, AstCodec::MAX_JSON_DEPTH);

        // The generator: this case is only interesting if the AST really is
        // much smaller than the source, which is what makes the ceiling bind.
        $this->assertGreaterThan($measured * 5, $document->getSourceLength());

        $decoded = (new AstCodec())->decode($payload);
        $this->assertSame($measured, $decoded->getExpansionBudgetLength());
        $this->assertSame($document->getSourceLength(), $decoded->getSourceLength());

        $fromParse = (new CarveConverter())->addExtension(new TocPlacementExtension());
        $fromIngest = (new CarveConverter())->addExtension(new TocPlacementExtension());
        $this->assertGreaterThan(
            strlen($fromIngest->render($decoded)),
            strlen($fromParse->render($document)),
            'the ingested render is expected to be the smaller one here',
        );
    }

    public function testTheMeasurementIsTotalOnACyclicPayload(): void
    {
        // A cycle has no bottom, so the descent bound is what ends the walk.
        // `decode()` refuses such a payload for depth long before this, but the
        // helper must not be the thing that hangs.
        $cyclic = ['type' => 'document', 'srcByteLength' => 0];
        $cyclic['children'] = [&$cyclic];

        $this->assertGreaterThan(0, PayloadSize::bytes($cyclic, 8));
    }

    public function testTheMeasurementDoesNotRecurse(): void
    {
        // The payloads this is asked about include the ones deep enough to
        // crash a recursive walk.
        $payload = [];
        for ($i = 0; $i < 20000; $i++) {
            $payload = ['children' => [$payload]];
        }

        $this->assertGreaterThan(0, PayloadSize::bytes($payload, 20002));
    }
}
