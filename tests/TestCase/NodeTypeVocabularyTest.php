<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\NodeType;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\TestCase;

/**
 * `NodeType` is the vocabulary a profile can name, and `docs/profiles.md`
 * calls that list normative. Anything the spec lists and NodeType omits is a
 * type a host cannot deny; anything NodeType carries that the spec omits is a
 * name the spec never promised.
 */
class NodeTypeVocabularyTest extends TestCase
{
    public function testTheInlineVocabularyMatchesTheSpec(): void
    {
        $spec = self::specVocabulary('Inline');

        $this->assertSame([], array_values(array_diff($spec, NodeType::allInlineTypes())), 'spec lists an inline type NodeType cannot name');
        $this->assertSame([], array_values(array_diff(NodeType::allInlineTypes(), $spec)), 'NodeType names an inline type the spec does not list');
    }

    public function testTheBlockVocabularyMatchesTheSpec(): void
    {
        $spec = self::specVocabulary('Block');

        // `caption` is the one name this engine adds, and it is not an invented
        // synonym: carve#2207 took the type out of profiles.md because a
        // caption is an inline array on its figure or table, which is true of
        // carve-js and carve-rs and not here, where the parse tree holds a
        // Caption block that a profile really does deny
        // (ProfileVocabularyConformanceTest pins that). Dropping the name
        // instead would make `isTypeAllowed('caption')` answer false under any
        // profile with an allow list. Whether profiles.md means to forbid an
        // engine naming a node it genuinely has is a question for the spec.
        $this->assertSame([], array_values(array_diff($spec, NodeType::allBlockTypes())), 'spec lists a block type NodeType cannot name');
        $this->assertSame(['caption'], array_values(array_diff(NodeType::allBlockTypes(), $spec)), 'NodeType names a block type the spec does not list');
    }

    /**
     * The control on the four assertions above, which an empty parse of
     * `profiles.md` would satisfy without comparing anything.
     */
    public function testTheSpecVocabularyWasActuallyRead(): void
    {
        $this->assertGreaterThan(20, count(self::specVocabulary('Block')));
        $this->assertGreaterThan(20, count(self::specVocabulary('Inline')));
    }

    /**
     * Membership in the list is not the point - being deniable is.
     *
     * The two APIs on one profile answered opposite things (carve#771): a type
     * outside `allBlockTypes()` fell through `isTypeAllowed()`'s
     * "outside the vocabulary" branch and reported allowed, while
     * `isNodeAllowed()` on the same profile reported denied. Comparing the two
     * lists alone would not have caught that - `abbreviation_def` was already
     * denied correctly on the node path while the string path said yes - so
     * this asks the behavioral question of every type the spec lists.
     */
    public function testEverySpecListedBlockTypeCanActuallyBeDenied(): void
    {
        foreach (self::specVocabulary('Block') as $type) {
            $profile = Profile::full()->denyBlock([$type]);
            $this->assertFalse($profile->isTypeAllowed($type), "denyBlock([{$type}]) left isTypeAllowed({$type}) true");
        }
    }

    public function testEverySpecListedInlineTypeCanActuallyBeDenied(): void
    {
        foreach (self::specVocabulary('Inline') as $type) {
            $profile = Profile::full()->denyInline([$type]);
            $this->assertFalse($profile->isTypeAllowed($type), "denyInline([{$type}]) left isTypeAllowed({$type}) true");
        }
    }

    /**
     * @return list<string>
     */
    private static function specVocabulary(string $axis): array
    {
        $md = (string)file_get_contents(__DIR__ . '/../spec/docs/profiles.md');
        if (!preg_match('/\*\*' . $axis . ':\*\*(.*?)\n\n/s', $md, $m)) {
            self::fail("could not find the {$axis} vocabulary in profiles.md");
        }
        preg_match_all('/`([a-z_]+)`/', $m[1], $found);

        return $found[1];
    }
}
