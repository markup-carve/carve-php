<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\FrontmatterExtension;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block matched on a first-character fast path spans every line it consumed.
 *
 * The block dispatcher has three fast paths - a line whose first non-blank
 * character is a letter, a `|`, or a `-` - that skip the probe cascade. Each
 * stamped the block it matched with the OPENING line only, because the stamp
 * was called without an end line. Every other path in the dispatcher passes
 * one.
 *
 * For a block whose own parser places it that is invisible: the stamp never
 * overwrites an existing span. It is visible exactly where the stamp is the
 * only span the node gets, and the two shapes below are that:
 *
 * -- a TAGGED frontmatter opener (`---json`) is matched by an extension
 *    registered through `addBlockPattern()`, which reports a line count and
 *    places nothing. A BARE `---` opener takes an earlier branch that already
 *    passed the end line, so one construct had two extents decided by whether
 *    the author wrote the format tag. PART 12 §4 has a container end after its
 *    explicit closer, and the closing `---` is that closer.
 * -- a `-` bullet list whose extent reaches past its own items - over a
 *    collected definition, a floating attribute block - was stamped at its
 *    first line, while the same document written with `*` or `1.` bullets took
 *    the probe cascade and was stamped whole. The marker does not change what
 *    the list consumed.
 *
 * Measured against carve-js and carve-rs, which agree with each other on every
 * document here and disagreed with this engine on 33 corpus documents before
 * the fix (markup-carve/carve#1451).
 */
class AFastPathBlockKeepsItsWholeExtentTest extends TestCase
{
    /**
     * @return array<string, mixed>|null
     */
    private function firstOfType(string $source, string $type, bool $frontmatter = false): ?array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        if ($frontmatter) {
            $converter->addExtension(new FrontmatterExtension());
        }
        $found = null;
        $walk = static function (array $node) use (&$walk, &$found, $type): void {
            if ($found === null && ($node['type'] ?? null) === $type) {
                $found = $node;
            }
            foreach (['children', 'items', 'rows', 'cells'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $walk($child);
                }
            }
        };
        $walk((new AstCodec())->encode($converter->parse($source)));

        return $found;
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function frontmatterProvider(): array
    {
        return [
            // The reported documents. `---json` and `--- yaml` both carry a
            // format tag, so both reach the `-` fast path; the extent runs to
            // the closing `---`, offsets 0..36 and 0..21.
            'a glued format tag' => ["---json\n{\"title\": \"My Document\"}\n---\n\nContent begins here.\n", 36],
            'a spaced format tag' => ["--- yaml\ntitle: T\n---\n\nbody\n", 21],
            // The control, which was already right and must stay right: a bare
            // opener takes the ambiguity branch above the fast paths.
            'a bare opener' => ["---\na: 1\n---\n\nbody\n", 12],
        ];
    }

    #[DataProvider('frontmatterProvider')]
    public function testFrontmatterReachesItsCloser(string $source, int $endOffset): void
    {
        $node = $this->firstOfType($source, 'frontmatter', frontmatter: true);

        $this->assertNotNull($node, 'the document has no frontmatter node');
        $this->assertSame(0, $node['pos']['startOffset']);
        $this->assertSame($endOffset, $node['pos']['endOffset']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function bulletProvider(): array
    {
        return [
            // A definition below the item is collected to document level, and
            // the list's extent reached over it in every engine. What differed
            // was the MARKER: `-` was stamped at line 1, `*` and `1.` whole.
            'a collected reference definition' => ["- a\n\n  [r]: /u\n", "* a\n\n  [r]: /u\n", 14],
            // A floating attribute block below the item, same shape.
            'a floating attribute block' => ["- a\n  {.x}\ntail\n", "* a\n  {.x}\ntail\n", 10],
        ];
    }

    #[DataProvider('bulletProvider')]
    public function testABulletDoesNotDecideAListExtent(string $dash, string $star, int $endOffset): void
    {
        foreach (['-' => $dash, '*' => $star] as $marker => $source) {
            $node = $this->firstOfType($source, 'list');

            $this->assertNotNull($node, "the {$marker} document has no list node");
            $this->assertSame(
                $endOffset,
                $node['pos']['endOffset'],
                "the {$marker} list ends somewhere else than the same document written with the other marker",
            );
        }
    }
}
