<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Filter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Filter\ProfileFilter;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\TestCase;

/**
 * A denied footnote definition leaves no reference, no note and no body behind.
 *
 * Denying the footnote family produced three wrong things at once
 * (carve-php#849). The reference stayed a live link with a number; an endnotes
 * section was published holding an item with a backlink and NO body; and the
 * note's own text came back as a `[^a]: note` paragraph - the content the host
 * denied, still in the output and no longer even marked as a footnote.
 *
 * ONE CAUSE. `FootnoteRef::isUnresolved()` is decided at parse time from the
 * source, and the renderer reads it to choose between a link and the literal
 * `[^a]`. The filter changes which definitions exist and nothing re-derived
 * that, so the reference kept reporting resolved, took a number, and left the
 * renderer holding a label with no definition to render.
 *
 * The leaked paragraph is separate and is a deliberate exception to the to_text
 * rule, beside the one already made for comments: a denied note's body is
 * exactly what the host asked not to publish, so it is removed rather than
 * degraded. carve-js and carve-rs both do this.
 *
 * The numbering half was the same defect in the other two engines and is fixed
 * there (carve-js#698, carve-rs#641); every expectation below was measured
 * against them. The one place all three still differ is what happens to the
 * definition itself - see testTheDefinitionStillDegradesToText.
 *
 * THE RE-DERIVATION IS ITERATIVE, and that is not a style choice. Written
 * recursively it segfaulted the suite on `DeepNestingTest`'s programmatic tree
 * of 60000 nested spans - a host stack overflow, not an exception any caller
 * could catch. That test is the one that catches it, so no case here duplicates
 * it: a 400-quote document does not reach the depth, because the parser caps
 * nesting long before.
 */
class DeniedFootnoteLeavesNoTraceTest extends TestCase
{
    /**
     * @var string
     */
    protected const DENIED = "Text[^a].\n\n[^a]: note\n";

    /**
     * References and an inline note together - where clearing every number and
     * renumbering stop agreeing.
     *
     * @var string
     */
    protected const MIXED = "a[^x] b ^[inline] c[^x]\n\n[^x]: note\n";

    protected function deniedProfile(): Profile
    {
        return Profile::full()->denyBlock(['footnote']);
    }

    protected function render(string $source): string
    {
        return CarveConverter::create(profile: $this->deniedProfile())->convert($source);
    }

    /**
     * Every footnote-ish node as [type, number], in document order, from the
     * FILTERED tree - `parse()` alone does not run the filter.
     *
     * @return array<int, array{0: string, 1: int|null}>
     */
    protected function numbers(string $source): array
    {
        $document = (new CarveConverter())->parse($source);
        $filtered = (new ProfileFilter())->filter($document, $this->deniedProfile());
        $tree = (new AstCodec())->encode($filtered);

        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (is_array($node)) {
                $type = $node['type'] ?? null;
                if ($type === 'footnote_ref' || $type === 'inline_footnote') {
                    $found[] = [(string)$type, $node['number'] ?? null];
                }
                foreach ($node as $value) {
                    $walk($value);
                }
            }
        };
        $walk($tree);

        return $found;
    }

    public function testTheReferenceRendersAsItsLiteralSource(): void
    {
        // The reference is no longer a link. The definition below it is the
        // documented `to_text` degradation, NOT part of this defect - see
        // testTheDefinitionStillDegradesToText.
        $this->assertSame(
            "<p>Text[^a].</p>\n<p>[^a]: note</p>\n",
            $this->render(self::DENIED),
        );
    }

    public function testNoEmptyNoteIsPublished(): void
    {
        // Asserted separately because it is the symptom that would survive a fix
        // aimed only at the reference: a section whose only item held a backlink
        // and nothing to link back to.
        $html = $this->render(self::DENIED);

        $this->assertStringNotContainsString('doc-endnotes', $html);
        $this->assertStringNotContainsString('doc-backlink', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
    }

    public function testTheDefinitionStillDegradesToText(): void
    {
        // DELIBERATELY UNCHANGED, and pinned here so the reasoning is on record.
        //
        // The reported issue argued this republishes content the host denied and
        // that both other engines DELETE a denied definition instead. They do.
        // But docs/profiles.md defines `to_text` as "replace the node with its
        // rendered text content - non-destructive: the words survive", and
        // reserves removal for types that render NOTHING (`comment`,
        // `frontmatter`), which a footnote definition is not.
        //
        // So php follows the documented contract here and the divergence is
        // between engines, not inside this one. Which behavior is right is a
        // cross-engine decision and belongs upstream, not in a bug fix; a host
        // that wants the body gone has `strip` today.
        $html = $this->render(self::DENIED);

        $this->assertStringContainsString('[^a]: note', $html);
    }

    public function testTheReferencePublishesNoNumber(): void
    {
        $this->assertSame([['footnote_ref', null]], $this->numbers(self::DENIED));
    }

    public function testAnInlineNoteStillRendersAndRenumbersFromOne(): void
    {
        // The boundary that keeps this from being "delete all footnotes": an
        // inline note carries its own body, nothing denied it, and it takes the
        // number the removed references vacated.
        $this->assertSame(
            [['footnote_ref', null], ['inline_footnote', 1], ['footnote_ref', null]],
            $this->numbers(self::MIXED),
        );

        $html = $this->render(self::MIXED);
        $this->assertStringContainsString('<sup>1</sup>', $html);
        $this->assertStringNotContainsString('<sup>2</sup>', $html);
        // Its body is published, unlike the denied definition's.
        $this->assertStringContainsString('inline', $html);
    }

    public function testTheDefinitionIsGoneFromTheTree(): void
    {
        // The premise the re-derivation rests on: there is no `footnote` node
        // left for a reference to resolve against. It became the paragraph the
        // degradation produced, which is why the reference had to be told.
        $document = (new CarveConverter())->parse(self::DENIED);
        $filtered = (new ProfileFilter())->filter($document, $this->deniedProfile());

        $types = array_map(static fn (Node $child): string => $child->getType(), $filtered->getChildren());
        $this->assertSame(['paragraph', 'paragraph'], $types);
        $this->assertNotContains('footnote', $types);
    }

    public function testTheDenialIsStillRecorded(): void
    {
        // Removing rather than degrading must not make the denial silent - a
        // host in collect mode still has to learn that something was dropped.
        $document = (new CarveConverter())->parse(self::DENIED);
        $filter = new ProfileFilter();
        $filter->filter($document, $this->deniedProfile());

        $this->assertNotEmpty($filter->getViolations());
    }

    public function testStrippingTheDefinitionLeavesNoTraceAtAll(): void
    {
        // `strip` removes the definition outright, so unlike `to_text` there is
        // no paragraph either - this is the one action where all three engines
        // already agree on the whole output (carve-php#850).
        //
        // Two references on purpose: with the resolution stale, the second one
        // produced a `fnref1-2` backlink as well, so the endnote item ended up
        // holding two backlinks and no body.
        $profile = Profile::full()->denyBlock(['footnote'])->onDisallowed(Profile::ACTION_STRIP);
        $html = CarveConverter::create(profile: $profile)->convert("Text[^a] and[^a].\n\n[^a]: note\n");

        $this->assertSame("<p>Text[^a] and[^a].</p>\n", $html);
    }

    public function testNothingChangesWithoutAProfile(): void
    {
        // The boundary. Every assertion above would also pass if footnotes had
        // been broken outright.
        $html = (new CarveConverter())->convert(self::DENIED);

        $this->assertStringContainsString('<sup>1</sup>', $html);
        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringContainsString('note', $html);
    }

    public function testAnUnrelatedDenialLeavesTheFootnoteAlone(): void
    {
        // The re-derivation runs on every filtered document, so a profile that
        // denies something else must not disturb a resolved reference.
        $document = (new CarveConverter())->parse(self::DENIED);
        $filtered = (new ProfileFilter())->filter(
            $document,
            Profile::full()->denyBlock(['blockquote']),
        );
        $tree = (new AstCodec())->encode($filtered);

        $this->assertStringContainsString('"number":1', (string)json_encode($tree));
    }
}
