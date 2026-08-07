<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which containers put a heading in the implicit `[Heading][]` index.
 *
 * PART 11 R1 says all of them except a blockquote: a div, an admonition, a list
 * item and a definition are the author's own grouping inside their own
 * document, so the heading and its wording are theirs to reference. A
 * blockquote carries someone else's headings and is the one exclusion.
 *
 * This engine indexed by scanning source lines for a `#` at column 0, which
 * answered a different question - "is this line unindented" - and so was wrong
 * in both directions: a heading inside a list item was missed because it is
 * indented, and a `#` line inside a code fence was indexed because it is not
 * (carve-php#572). The corpus covers implicit references and covers headings in
 * list items, and never combines them, so every suite stayed green.
 */
class HeadingReferenceInContainersTest extends TestCase
{
    private function html(string $source): string
    {
        $converter = CarveConverter::create();

        return $converter->render($converter->parse($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function containersThatIndexProvider(): array
    {
        return [
            'a list item, heading on the marker line' => ["- # H\n\n  See [H][]."],
            'a list item, heading on a later line' => ["- item\n\n  # H\n\n  See [H][]."],
            'an ordered list item' => ["1. # H\n\n   See [H][]."],
            'a nested list item' => ["- - # H\n\n    See [H][]."],
            'a definition' => [":: T\n\n:  # H\n\n   See [H][]."],
            // Already worked: the heading sits at column 0 inside the fence, so
            // even the line scan saw it. Here to keep it that way.
            'a div' => ["::: note\n# H\n\nSee [H][].\n:::"],
        ];
    }

    #[DataProvider('containersThatIndexProvider')]
    public function testAHeadingInsideAContainerResolvesAReference(string $source): void
    {
        $html = $this->html($source);

        $this->assertStringContainsString('<a href="#H">H</a>', $html);
        $this->assertStringNotContainsString('[H][]', $html);
    }

    /**
     * The one exclusion, and the reason the rule is not simply "any ancestor".
     */
    public function testAHeadingInsideABlockquoteDoesNotIndex(): void
    {
        $html = $this->html("> # H\n>\n> See [H][].");

        $this->assertStringContainsString('[H][]', $html);
        $this->assertStringNotContainsString('<a href="#H">', $html);
        // It still gets an id, because it is a valid `</#id>` crossref target.
        $this->assertStringContainsString('id="H"', $html);
    }

    /**
     * A blockquote INSIDE a list item is still a blockquote.
     *
     * The exclusion is about the nearest question - whose words are these -
     * not about depth, so a container that indexes cannot re-admit one that
     * does not.
     */
    public function testABlockquoteNestedInAListItemStillDoesNotIndex(): void
    {
        $html = $this->html("- > # H\n\n  See [H][].");

        $this->assertStringContainsString('[H][]', $html);
        $this->assertStringNotContainsString('<a href="#H">', $html);
    }

    /**
     * The other direction, and the one no issue reported: a `#` line inside a
     * code fence is not a heading, and the line scan indexed it anyway - so
     * `[F][]` resolved to an id no element in the document carries.
     */
    public function testAHashLineInsideACodeFenceIsNotAHeading(): void
    {
        $html = $this->html("```\n# F\n```\n\nSee [F][].");

        $this->assertStringContainsString('[F][]', $html);
        $this->assertStringNotContainsString('href="#F"', $html);
    }

    /**
     * An indented `#` at top level is a paragraph, not a heading.
     *
     * This is the case that makes the line scan's column-0 rule look right, and
     * the reason a fix cannot simply accept indented markers: the difference
     * between this and a heading in a list item is block structure, nothing
     * else.
     */
    public function testAnIndentedHashAtTopLevelIsStillNotAHeading(): void
    {
        $html = $this->html("   # Indented\n\nSee [Indented][].");

        $this->assertStringContainsString('<p># Indented</p>', $html);
        $this->assertStringContainsString('[Indented][]', $html);
    }

    /**
     * Two false warnings came with the missed index, and both are gone.
     *
     * They are the visible half of the defect for anyone running the linter:
     * the reference was reported undefined, and a plain `#H` link to the very
     * same heading was reported broken.
     */
    public function testNeitherFalseWarningIsRaisedForAHeadingInAListItem(): void
    {
        $parser = new BlockParser();
        $parser->setCollectWarnings(true);
        $converter = CarveConverter::create($parser);

        $converter->parse("- # H\n\n  See [H][] and [x](#H).");

        $messages = array_map(static fn ($warning): string => $warning->getMessage(), $parser->getWarnings());
        $this->assertSame([], $messages);
    }

    /**
     * An anchor link to a heading nowhere in the document still warns.
     *
     * The fix registers ids from the parsed tree rather than from a line scan,
     * and a check that stopped failing would be worse than the bug it replaced.
     */
    public function testABrokenAnchorLinkStillWarns(): void
    {
        $parser = new BlockParser();
        $parser->setCollectWarnings(true);
        $converter = CarveConverter::create($parser);

        $converter->parse("- # H\n\n  See [x](#nowhere).");

        $messages = array_map(static fn ($warning): string => $warning->getMessage(), $parser->getWarnings());
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('#nowhere', $messages[0]);
    }

    /**
     * The warning half is reachable without any reference link.
     *
     * A document can contain `[x](#H)` and no `[…][…]` at all, and the index
     * still decides whether that anchor is called broken - so the gate that
     * chooses how to build it has to account for anchors too, not just
     * references.
     */
    public function testAnAnchorToAContainerHeadingDoesNotWarnWithoutAnyReferenceLink(): void
    {
        $parser = new BlockParser();
        $parser->setCollectWarnings(true);
        $converter = CarveConverter::create($parser);

        $converter->parse("- # H\n\n  See [x](#H).");

        $this->assertSame([], $parser->getWarnings());
    }

    /**
     * And the false positive the other way: an anchor to a `#` line that is
     * inside a code fence IS broken, and used to pass silently because the line
     * scan had registered the id.
     */
    public function testAnAnchorToAFencedHashLineWarns(): void
    {
        $parser = new BlockParser();
        $parser->setCollectWarnings(true);
        $converter = CarveConverter::create($parser);

        $converter->parse("```\n# F\n```\n\nSee [x](#F).");

        $messages = array_map(static fn ($warning): string => $warning->getMessage(), $parser->getWarnings());
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('#F', $messages[0]);
    }

    /**
     * A heading the RENDERER reaches, the index reaches too.
     *
     * The walk needs a depth bound, and any bound below the renderer's own
     * splits the two: the heading is emitted with an id while the reference to
     * it stays literal. Sixty nested list items is well inside what the parser
     * accepts (200 levels) and was enough to cross a 100-node bound, because a
     * nested list spends two nodes per level.
     */
    public function testADeeplyNestedHeadingIsStillIndexed(): void
    {
        $depth = 60;
        $source = '';
        for ($level = 0; $level < $depth; $level++) {
            $source .= str_repeat('  ', $level) . '- ';
        }
        $source = rtrim($source) . " # H\n\n" . str_repeat('  ', $depth) . 'See [H][].';

        $html = $this->html($source);

        $this->assertStringContainsString('id="H"', $html, 'the renderer reaches this heading');
        $this->assertStringContainsString('href="#H"', $html, 'so the index must too');
    }

    /**
     * The explicit form does NOT read this index (markup-carve/carve#742).
     *
     * R1's fallback is scoped to the collapsed `[text][]`. An explicit label is
     * an identifier the author wrote twice and can keep identical, and an
     * identifier that names nothing names nothing - it is not retried as prose.
     * The container question this file is about therefore never arises for the
     * explicit form: it does not resolve at any nesting.
     */
    public function testAnExplicitReferenceDoesNotResolveToAContainerHeading(): void
    {
        $html = $this->html("- # H\n\n  See [the heading][H].");

        $this->assertStringContainsString('See [the heading][H].', $html);
        $this->assertStringNotContainsString('<a href="#H">', $html);
    }

    /**
     * A document with no reference link takes the cheap path, and must land in
     * the same place.
     *
     * The structure pass only runs when the source could use the index, so this
     * is the branch every other document takes - worth one assertion so the
     * gate cannot silently swallow a top-level heading.
     */
    public function testATopLevelHeadingStillIndexesWithoutTheStructurePass(): void
    {
        $this->assertStringContainsString('<a href="#H">H</a>', $this->html("# H\n\nSee [H][]."));
    }
}
