<?php
declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Filter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\NodeType;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\TestCase;

/**
 * Empty-container cleanup must remove only what the FILTER emptied.
 *
 * The corpus ratchet in ProfileVocabularyTest covers this too, but as a broad
 * signal: a regression there points at 500 documents rather than at the
 * mechanism. These name the three properties directly.
 */
class EmptyContainerCleanupTest extends TestCase
{
    /**
     * A container the AUTHOR wrote empty is the author's, and reproducing it is
     * what makes a profile that denies nothing an identity.
     */
    public function testAnAlreadyEmptyContainerSurvivesAFullProfile(): void
    {
        $src = "A[^a].\n\n::: footnotes\n:::\n\n## After\n\n[^a]: n\n";

        $unfiltered = (new CarveConverter())->convert($src);
        $converter = new CarveConverter();
        $converter->setProfile(Profile::full());

        $this->assertSame($unfiltered, $converter->convert($src));
    }

    /**
     * A container the filter emptied is still pruned. carve-js renders this
     * case as the empty string, so dropping the cleanup entirely would have
     * broken parity in the other direction.
     */
    public function testAContainerTheFilterEmptiedIsStillPruned(): void
    {
        $converter = new CarveConverter();
        $converter->setProfile(
            Profile::full()->denyInline([NodeType::IMAGE])->onDisallowed(Profile::ACTION_STRIP),
        );

        $this->assertSame('', $converter->convert("> ![alt](x.png)\n"));
    }

    /**
     * The structural exemption for a placement carrier is NOT made redundant by
     * scoping the cleanup, and this is the case that proves it.
     *
     * A CHILDLESS `::: footnotes` is protected by the scoping alone - nothing
     * can be removed from it, so it is never marked as emptied. But a carrier
     * whose BODY the filter strips IS marked, and without the exemption it is
     * then pruned as structurally empty, silently moving the endnotes back to
     * the end of the document.
     */
    public function testAPlacementCarrierSurvivesHavingItsBodyStripped(): void
    {
        $src = "A[^a].\n\n::: footnotes\n![alt](x.png)\n:::\n\n## After\n\n[^a]: n\n";

        $converter = new CarveConverter();
        $converter->setProfile(
            Profile::full()->denyInline([NodeType::IMAGE])->onDisallowed(Profile::ACTION_STRIP),
        );
        $out = $converter->convert($src);

        $endnotes = strpos($out, 'doc-endnotes');
        $heading = strpos($out, '<h2>After');
        $this->assertNotFalse($endnotes, 'the endnotes section disappeared');
        $this->assertNotFalse($heading, 'the heading after the directive disappeared');
        $this->assertLessThan(
            $heading,
            $endnotes,
            'the endnotes relocated to the document end, so the placement directive was pruned',
        );
    }

    /**
     * The emptied-parent map must not leak between documents: a converter is
     * commonly reused, and a stale entry would prune a container in the NEXT
     * document that this filter never touched.
     */
    public function testTheEmptiedSetDoesNotLeakAcrossDocuments(): void
    {
        $converter = new CarveConverter();
        $converter->setProfile(
            Profile::full()->denyInline([NodeType::IMAGE])->onDisallowed(Profile::ACTION_STRIP),
        );

        $converter->convert("> ![alt](x.png)\n");

        $src = "A[^a].\n\n::: footnotes\n:::\n\n## After\n\n[^a]: n\n";
        $this->assertSame((new CarveConverter())->convert($src), $converter->convert($src));
    }
}
