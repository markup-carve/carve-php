<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\NodeType;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A node type the profile vocabulary does not know is DENIED, including by
 * `Profile::full()` - so a type missing from NodeType is silent data loss the
 * moment any profile is configured, not merely an incomplete list.
 *
 * Both cases below were real: `substitution` was never registered, so
 * `{~old~>new~}` rendered as nothing under a full profile and lost both texts;
 * `critic_comment` hit the same hole the day it stopped being a `span`.
 *
 * The corpus sweep asserts the property rather than a curated list of types,
 * because a list is exactly what was incomplete. It also avoids having to
 * restate which types are deliberately NOT deniable (`smart_punctuation` and
 * `literal_inline` fold into the text trust class, `caption_number` is a
 * resolution artifact) - those pass because denying them changes nothing.
 */
class ProfileVocabularyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function editorialMarkupProvider(): array
    {
        return [
            'insert' => ["a {+added+} b\n"],
            'delete' => ["a {-removed-} b\n"],
            'substitution' => ["a {~old~>new~} b\n"],
            'critic comment' => ["a {# note #} b\n"],
            'highlight' => ["a {=marked=} b\n"],
        ];
    }

    #[DataProvider('editorialMarkupProvider')]
    public function testAFullProfileChangesNothing(string $source): void
    {
        $this->assertSame(
            (new CarveConverter())->convert($source),
            $this->withFullProfile()->convert($source),
            'a full profile denied a construct instead of allowing it',
        );
    }

    /**
     * Corpus documents a full profile still changes, which it should not.
     *
     * A ratchet, not an allowance. The vocabulary entries are gone - a profile
     * that denies nothing now denies nothing. What remains has two other
     * causes, and the list may shrink but must never grow.
     *
     * `120-footnotes-placement` is the first: a `::: footnotes` placement
     * directive is lost under any configured profile, so the notes relocate to
     * the end of the document instead of rendering where the directive sits.
     *
     * The other six are all `cleanupEmptyContainers` / `isEmptyContainer`
     * (Filter/ProfileFilter.php) pruning a container the unfiltered render
     * emits. The container differs, which is why this is not a one-shape bug:
     * a genuinely empty `<blockquote>` in `16-reference-link-3` and
     * `115-...-is-collected`; an `<aside>` or a `<ul><li>` in
     * `16-reference-link-4`, `115-...-is-collected-2` and
     * `114-fence-opener-...` (which loses its whole rendered output); and in
     * `83-blockquote-lazy-continuation-...` a blockquote that is NOT visually
     * empty, wrapping a `CodeBlock` whose content is `''` - treated as an
     * empty leaf, cascading up to prune the blockquote.
     *
     * Whether the pruning is a defect or an improvement is undecided: removing
     * genuinely empty markup may well be the better output. It is pinned here
     * because a full profile must not change output at all, not because the
     * new output is known to be wrong.
     *
     * @var array<string>
     */
    private const KNOWN_LOSSY_UNDER_A_FULL_PROFILE = [
        '114-fence-opener-with-a-nested-list-body-inside-a-list-item-7.crv',
        '115-footnote-definition-inside-a-container-is-collected-2.crv',
        '115-footnote-definition-inside-a-container-is-collected.crv',
        '120-footnotes-placement.crv',
        '16-reference-link-3.crv',
        '16-reference-link-4.crv',
        '83-blockquote-lazy-continuation-stops-at-a-fenced-block-3.crv',
    ];

    public function testAFullProfileChangesNothingAcrossTheCorpus(): void
    {
        $unfiltered = new CarveConverter();
        $filtered = $this->withFullProfile();
        $offenders = [];
        $checked = 0;

        foreach (glob(__DIR__ . '/../spec/tests/corpus/*.crv') ?: [] as $path) {
            $source = (string)file_get_contents($path);
            $checked++;
            if ($unfiltered->convert($source) !== $filtered->convert($source)) {
                $offenders[] = basename($path);
            }
        }

        $this->assertGreaterThan(400, $checked, 'the corpus was not found');

        $new = array_values(array_diff($offenders, self::KNOWN_LOSSY_UNDER_A_FULL_PROFILE));
        $this->assertSame([], $new, 'a full profile started denying a construct it used to allow');

        $fixed = array_values(array_diff(self::KNOWN_LOSSY_UNDER_A_FULL_PROFILE, $offenders));
        $this->assertSame([], $fixed, 'these are lossless now - drop them from the list so it keeps ratcheting');
    }

    public function testAFullProfileAllowsATypeTheVocabularyDoesNotKnow(): void
    {
        $source = "---\ntitle: x\n---\n\nBody with \"smart quotes\" and an em dash -- here.\n";

        $this->assertSame(
            (new CarveConverter())->convert($source),
            $this->withFullProfile()->convert($source),
            'a full profile denied a type outside the vocabulary instead of allowing it',
        );
    }

    public function testAnAllowlistProfileStillDeniesATypeItDoesNotKnow(): void
    {
        $converter = new CarveConverter();
        $converter->setProfile(Profile::comment());
        $converter->convert("Body with a {~old~>new~} substitution.\n");

        $this->assertNotSame([], $converter->getProfileViolations(), 'an allowlist profile must keep excluding types it does not list');
    }

    public function testANodeResolvesOnItsOwnAxisNotTheOtherOne(): void
    {
        $inline = new class extends InlineNode {
            public function getType(): string
            {
                return 'wibble_inline';
            }
        };

        // Restricts the BLOCK axis only; the inline axis stays "all".
        $profile = Profile::full()->allowBlock([NodeType::PARAGRAPH]);

        $this->assertTrue(
            $profile->isNodeAllowed($inline),
            'an inline node was resolved against the block allow list, so a restriction on one axis silently denied the other',
        );
    }

    private function withFullProfile(): CarveConverter
    {
        $converter = new CarveConverter();
        $converter->setProfile(Profile::full());

        return $converter;
    }
}
