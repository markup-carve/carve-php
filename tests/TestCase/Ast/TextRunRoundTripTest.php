<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §6: `parse(x)` serialized and deserialized MUST equal `parse(x)`.
 *
 * §1a (adjacent text runs are coalesced) and §6 can only both hold if the merge
 * is part of the parsed tree. Coalescing during serialization satisfies §1a and
 * breaks §6 on the same document: what comes back holds one node where the tree
 * held four (carve-php#623, and markup-carve/carve#488 for the spec statement).
 *
 * The existing AstCodec round-trip assertions compare RE-ENCODED JSON, which is
 * stable either way - encoding a merged tree and encoding a split tree both
 * produce the merged wire form. That is a weaker property than §6, and it is
 * why the split tree survived.
 */
class TextRunRoundTripTest extends TestCase
{
    protected CarveConverter $converter;

    protected AstCodec $codec;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->codec = new AstCodec();
    }

    public function testParsedTreeIsAlreadyCoalesced(): void
    {
        // Four runs, one per stretch where an underscore failed to open
        // emphasis. That is the parser's bookkeeping, not the document.
        $document = $this->converter->parse("foo_bar_baz and snake_case stay literal\n");
        $children = $document->getChildren()[0]->getChildren();

        $this->assertCount(1, $children, 'the parsed tree must already hold one text node');
        $this->assertInstanceOf(Text::class, $children[0]);
        $this->assertSame('foo_bar_baz and snake_case stay literal', $children[0]->getContent());
    }

    public function testDecodingReproducesTheParsedTree(): void
    {
        $source = "foo_bar_baz and snake_case stay literal\n";
        $document = $this->converter->parse($source);
        $decoded = $this->codec->decodeJson($this->codec->encodeJson($document));

        $this->assertSame(
            $this->shapeOf($document),
            $this->shapeOf($decoded),
            'PART 12 section 6: decode(encode(parse(x))) must equal parse(x)',
        );
    }

    public function testAnEscapeDoesNotMergeWithTheTextAroundIt(): void
    {
        // The rule is about `text` only. `escaped_text` carries authored form
        // and PART 12 section 5 keeps the two distinct on the wire.
        $document = $this->converter->parse("a \\* b\n");
        $types = array_map(
            static fn (Node $node): string => $node->getType(),
            $document->getChildren()[0]->getChildren(),
        );

        $this->assertSame(['text', 'escaped_text', 'text'], $types);
    }

    public function testACitationPrefixIsCoalesced(): void
    {
        // `prefix`, `locator` and `suffix` are inline arrays on a citation item,
        // NOT children, so a walk that follows only the child list never reaches
        // them. The rule then holds for the corpus and not for the vocabulary -
        // the same gap review found on carve-rs#441.
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension());
        $document = $converter->parse("[see [missing][nope] @a, p. 3].\n\n[@a]: A.\n");

        $group = $this->firstCitationGroup($document);
        $this->assertInstanceOf(CitationGroup::class, $group, 'the source should produce a citation group');

        foreach ($group->getItems() as $item) {
            foreach (['prefix', 'locator', 'suffix'] as $field) {
                if (!isset($item[$field])) {
                    continue;
                }
                $previousWasText = false;
                foreach ($item[$field] as $node) {
                    $isText = $node instanceof Text;
                    $this->assertFalse(
                        $previousWasText && $isText,
                        "adjacent text runs left in a citation {$field}",
                    );
                    $previousWasText = $isText;
                }
            }
        }
    }

    public function testARunJoinedAcrossAGapCarriesNoSpan(): void
    {
        // A cell rebuilt from two source lines is joined by a space the source
        // does not contain, so the merged value is not a slice of it at any
        // offset. PART 12 section 4 rates a span selecting the wrong text worse
        // than none, so the node carries none.
        $document = $this->converter->parse(
            "|= a |= b |\n| x | A long description |\n+     | that continues     |\n",
        );
        $texts = $this->collectText($document);
        $joined = null;
        foreach ($texts as $text) {
            if (str_contains($text->getContent(), 'that continues')) {
                $joined = $text;
            }
        }

        $this->assertNotNull($joined, 'the continued cell should be one text node');
        $this->assertSame('A long description that continues', $joined->getContent());
        $this->assertNull($joined->getPos(), 'a run joined across a gap must not claim a span');
    }

    protected function firstCitationGroup(Node $node): ?CitationGroup
    {
        if ($node instanceof CitationGroup) {
            return $node;
        }
        foreach ($node->getChildren() as $child) {
            $found = $this->firstCitationGroup($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Inline\Text>
     */
    protected function collectText(Node $node): array
    {
        $out = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $out[] = $child;
            }
            foreach ($this->collectText($child) as $nested) {
                $out[] = $nested;
            }
        }

        return $out;
    }

    public function testEveryCorpusDocumentRoundTripsToTheSameTree(): void
    {
        $dir = dirname(__DIR__, 3) . '/tests/spec/tests/corpus';
        $this->assertDirectoryExists($dir, 'spec corpus missing; run: git submodule update --init');

        $paths = glob($dir . '/*.crv') ?: [];
        $this->assertGreaterThan(400, count($paths), 'corpus looks truncated');

        $mismatched = [];
        foreach ($paths as $path) {
            $source = (string)file_get_contents($path);
            $document = $this->converter->parse($source);
            $decoded = $this->codec->decodeJson($this->codec->encodeJson($document));
            if ($this->shapeOf($document) !== $this->shapeOf($decoded)) {
                $mismatched[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            'every corpus document must survive PART 12 section 6: decode(encode(parse(x))) must equal parse(x)',
        );
    }

    public function testNoCorpusDocumentHoldsAnAdjacentTextRun(): void
    {
        $dir = dirname(__DIR__, 3) . '/tests/spec/tests/corpus';
        $this->assertDirectoryExists($dir, 'spec corpus missing; run: git submodule update --init');

        $paths = glob($dir . '/*.crv') ?: [];
        $this->assertGreaterThan(400, count($paths), 'corpus looks truncated');

        $offenders = [];
        foreach ($paths as $path) {
            $runs = $this->countAdjacentTextRuns($this->converter->parse((string)file_get_contents($path)));
            if ($runs > 0) {
                $offenders[] = basename($path) . ": {$runs}";
            }
        }

        $this->assertSame([], $offenders, 'nodes holding two adjacent text children');
    }

    /**
     * The node types and text values of a tree, ignoring positions.
     *
     * @return array<string, mixed>
     */
    protected function shapeOf(Node $node): array
    {
        $shape = ['type' => $node->getType()];
        if ($node instanceof Text) {
            $shape['value'] = $node->getContent();
        }
        $children = [];
        foreach ($node->getChildren() as $child) {
            $children[] = $this->shapeOf($child);
        }
        $shape['children'] = $children;

        return $shape;
    }

    protected function countAdjacentTextRuns(Node $node): int
    {
        $runs = 0;
        $previousWasText = false;
        foreach ($node->getChildren() as $child) {
            $isText = $child instanceof Text;
            if ($previousWasText && $isText) {
                $runs++;
            }
            $previousWasText = $isText;
            $runs += $this->countAdjacentTextRuns($child);
        }

        return $runs;
    }
}
