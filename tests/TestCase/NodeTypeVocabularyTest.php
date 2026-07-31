<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\NodeType;
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

        $this->assertSame([], array_values(array_diff($spec, NodeType::allBlockTypes())), 'spec lists a block type NodeType cannot name');
        $this->assertSame([], array_values(array_diff(NodeType::allBlockTypes(), $spec)), 'NodeType names a block type the spec does not list');
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
