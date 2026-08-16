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

        // PIN LAG, not a name the spec never promised. `citation_definition`
        // is in profiles.md on spec main (861498b, PART 12 §18) and this
        // branch's submodule pin predates it. The allowance is read from the
        // PINNED file, so it evaporates the moment the pin moves rather than
        // becoming a standing exemption.
        $ahead = array_values(array_diff(NodeType::allBlockTypes(), $spec));
        $expectedAhead = in_array('citation_definition', $spec, true) ? [] : ['citation_definition'];

        $this->assertSame([], array_values(array_diff($spec, NodeType::allBlockTypes())), 'spec lists a block type NodeType cannot name');
        $this->assertSame($expectedAhead, $ahead, 'NodeType names a block type the spec does not list');
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
