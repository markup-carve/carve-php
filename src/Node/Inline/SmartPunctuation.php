<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\ContentNodeInterface;

/**
 * A smart-typography substitution, carrying both what it resolved to and what
 * the author wrote.
 *
 * Smart typography used to be applied as character substitution into the text
 * buffer, which discarded the source spelling: by the time the AST existed,
 * `...` was indistinguishable from a literal U+2026 and the canonical-source
 * renderer could not reproduce the author's input. Keeping both halves lets a
 * presentation renderer emit the glyph while the Carve renderer emits the
 * source, so `fmt` reproduces the document instead of normalizing it.
 *
 * The type is the resolved kind (`ellipsis`, `rightwards_arrow`,
 * `left_double_quote`, …); a renderer maps it to a glyph, which is also where
 * locale-specific quote glyphs are selected. For profiles this folds into the
 * `text` trust class - it is ordinary visible prose, not a distinct capability.
 */
class SmartPunctuation extends InlineNode implements ContentNodeInterface
{
    /**
     * Canonical glyph per kind.
     *
     * Presentation renderers resolve a kind through this map; the Carve
     * renderer ignores it and emits the author's source run instead. Quote
     * kinds are deliberately absent: their glyph is locale-dependent and is
     * resolved by the smart-quotes configuration, not by this table.
     *
     * @var array<string, string>
     */
    public const GLYPHS = [
        'ellipsis' => "\u{2026}",
        'em_dash' => "\u{2014}",
        'en_dash' => "\u{2013}",
        'left_right_arrow' => "\u{2194}",
        'rightwards_arrow' => "\u{2192}",
        'leftwards_arrow' => "\u{2190}",
        'rightwards_double_arrow' => "\u{21D2}",
        'leftwards_double_arrow' => "\u{21D0}",
        'left_right_double_arrow' => "\u{21D4}",
        'less_than_or_equal' => "\u{2264}",
        'greater_than_or_equal' => "\u{2265}",
        'not_equal' => "\u{2260}",
        'plus_minus' => "\u{00B1}",
        'copyright' => "\u{00A9}",
        'registered' => "\u{00AE}",
        'trademark' => "\u{2122}",
    ];

    public function __construct(
        protected string $kind = '',
        protected string $content = '',
        protected ?string $glyph = null,
    ) {
    }

    /**
     * The resolved glyph, when the parser fixed it rather than leaving it to
     * the kind lookup.
     *
     * Quote glyphs are locale-dependent (the smart-quotes configuration selects
     * German low-9 or Swiss guillemets), and that choice is made during
     * parsing, so a quote node carries its resolved character. Every other kind
     * leaves this null and resolves through the GLYPHS table.
     */
    public function getGlyph(): ?string
    {
        return $this->glyph;
    }

    /**
     * The resolved kind, e.g. `ellipsis`.
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * The author's source run, e.g. `...`.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    public function getType(): string
    {
        return 'smart_punctuation';
    }
}
