<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * An authored `[label]: /url "title" {attrs}` line.
 *
 * The definition renders nothing on the HTML target and is emitted as written on
 * the non-HTML ones (PART 11 §10a). It is a child of the DOCUMENT wherever it
 * was written, exactly as a footnote or abbreviation definition is
 * (PART 12 §7).
 *
 * Keeping it as a node is what lets a WRITER reproduce it. Without one, `fmt`
 * had no way to emit the definition, so a resolved reference was written back as
 * an inline link and `parse(fmt(x)) == parse(x)` was false for every one of them
 * (PART 11 §1, markup-carve/carve#642). The destinations alone are a flat map
 * with no position relative to the surrounding blocks, so a writer working from
 * that map cannot put the line back where the author had it - the same argument
 * that kept abbreviation definitions as nodes.
 *
 * The node is NOT the resolution table. Resolution still happens against the
 * collected definitions; this carries what the author wrote so it survives
 * serialization and a round trip.
 */
class LinkReferenceDefinition extends BlockNode
{
    public function __construct(
        protected string $label = '',
        protected string $href = '',
        protected ?string $title = null,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getHref(): string
    {
        return $this->href;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * A trailing attribute block on the definition line (PART 9 §15 A2b) rides
     * on the node's INHERITED attributes, which the codec publishes as `attrs`
     * (PART 12 §10). Deliberately not a second attribute channel: field names
     * are spec surface (§3), and the wire shape has exactly one `attrs` slot.
     *
     * They differ from most nodes' attributes in EFFECT rather than in
     * representation - they transfer to every link or image resolving the
     * label, rather than styling the definition line, which renders nothing.
     */
    public function getType(): string
    {
        return 'link_reference_definition';
    }
}
