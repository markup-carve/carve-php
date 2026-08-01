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
     * A ratchet, not an allowance. It is EMPTY, and a full profile is now a
     * true identity across every corpus document.
     *
     * Getting here took three passes. The vocabulary entries went first (a
     * profile that denies nothing now denies nothing). Then the `::: footnotes`
     * placement directive, which a structural exemption kept from being pruned.
     *
     * The last was `cleanupEmptyContainers` itself, which ran as a blanket pass
     * over the whole tree and so pruned containers that were already empty in
     * the SOURCE, not only ones the filter emptied. That is the general form of
     * the placement-directive bug: `::: footnotes` is empty BY DEFINITION - its
     * emptiness is the syntax - so it could never survive a pass that treats
     * empty as meaningless, and neither could the other six. Cleanup is now
     * scoped to the parents the filter actually removed a child from, which
     * also matches carve-js: it prunes an emptied blockquote to nothing and
     * leaves an already-empty container alone.
     *
     * The list may shrink but must never grow. An entry appearing here means a
     * profile that denies nothing started changing output again.
     *
     * @var array<string>
     */
    private const KNOWN_LOSSY_UNDER_A_FULL_PROFILE = [];

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
