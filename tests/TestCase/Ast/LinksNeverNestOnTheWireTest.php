<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Links never nest - on the WIRE, not only in the HTML.
 *
 * `CrossReferenceResolver::resolve()` ends with `enforceLinksNeverNest`, so the
 * rendered document has never carried an anchor inside an anchor. But
 * `AstCodec::encode()` calls the resolver's passes individually and did not call
 * that one, so the published tree kept the inner node: a `link` inside a `link`
 * for `[[x](y)](z)`, and an `autolink` inside the label for
 * `[pre <http://h> post](/u)`.
 *
 * Both are corpus documents, and carve-js and carve-rs publish one flat link for
 * each. The three-way panel added in markup-carve/carve#760 named this engine as
 * the one standing alone on exactly these two documents.
 *
 * A consumer reading the tree therefore saw a structure the rule forbids and no
 * renderer would ever produce.
 */
class LinksNeverNestOnTheWireTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function ast(string $source): array
    {
        return (new AstCodec())->encode((new CarveConverter())->parse($source));
    }

    /**
     * Every node type in the tree, in document order.
     *
     * @param array<string, mixed> $tree
     *
     * @return array<int, string>
     */
    protected function types(array $tree): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['type']) && is_string($node['type'])) {
                $found[] = $node['type'];
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        $walk($tree);

        return $found;
    }

    public function testAnInnerLinkDoesNotReachTheWire(): void
    {
        // 03-links-11.crv. The outermost destination is the only one that
        // applies, so the inner link contributes its text and nothing else.
        $this->assertSame(
            ['document', 'paragraph', 'link', 'text'],
            $this->types($this->ast("[[x](y)](z)\n")),
        );
    }

    public function testAnInnerAutolinkDoesNotReachTheWire(): void
    {
        // 03-links-12.crv. The autolink's display text is spliced in, and the
        // three text runs around it coalesce into one - three adjacent text
        // nodes would break PART 12 §1a, which ast:check fails the run over.
        $this->assertSame(
            ['document', 'paragraph', 'link', 'text'],
            $this->types($this->ast("[pre <http://h> post](/u)\n")),
        );
    }

    public function testTheLabelKeepsTheAutolinkText(): void
    {
        // Unwrapping must not lose the text: an email autolink drops its
        // `mailto:` scheme in the display, which is why the rule splices the
        // display rather than the destination.
        $tree = $this->ast("[pre <http://h> post](/u)\n");
        $link = $tree['children'][0]['children'][0];

        $this->assertSame('link', $link['type']);
        $this->assertSame('pre http://h post', $link['children'][0]['value']);
    }

    public function testAnUnresolvedReferenceInsideALabelIsKept(): void
    {
        // The one case that must NOT be unwrapped: an unresolved reference is a
        // link node per PART 12 §3a but renders as its literal source, so
        // unwrapping it would discard that source.
        $types = $this->types($this->ast("[[x][missing]](/z)\n"));

        $this->assertSame(2, count(array_filter($types, fn (string $t): bool => $t === 'link')));
    }

    public function testTheHtmlIsUnchanged(): void
    {
        // The renderer already enforced this; the tree is what was wrong. If
        // this moves, the fix went in the wrong place.
        $converter = new CarveConverter();

        $this->assertSame(
            "<p><a href=\"z\">x</a></p>\n",
            $converter->convert("[[x](y)](z)\n"),
        );
        $this->assertSame(
            "<p><a href=\"/u\">pre http://h post</a></p>\n",
            $converter->convert("[pre <http://h> post](/u)\n"),
        );
    }
}
