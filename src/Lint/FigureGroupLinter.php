<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Node;

/**
 * The five composite-figure rules of PART 9 §4c (markup-carve/carve#1122,
 * documented with trigger samples on the spec's validation page).
 *
 * Every one reports a shape that PARSES CLEANLY and renders less than it looks
 * like it does - the silent-failure class `carve lint` exists for. A nested
 * `::: figure` renders as a generic container; an opener carrying a quoted
 * title or `[label]` never matched the figure production at all; a `#` in a
 * PANEL caption stays a literal `#`; and a zero- or one-panel group is a
 * heavier spelling of something a plain figure or div already says.
 *
 * A tree-walking pass, because none of the five is visible in the source
 * lines: panel counts, nesting context and the demoted opener all exist only
 * in the parsed tree. The panel predicate is {@see FigureGroup::isPanel()},
 * the ONE spelling the numbering resolver also reads, so the lint cannot
 * drift from what the resolver registers. Message wording mirrors carve-js's
 * `lint.ts`, the parity reference, and all five report unconditionally - the
 * severity split (info in strict profiles for the count rules) is a consumer
 * policy this surface does not model, exactly as in carve-js.
 */
class FigureGroupLinter
{
    /**
     * @var string
     */
    public const RULE_FIGURE_GROUP_NESTED = 'figure-group-nested';

    /**
     * @var string
     */
    public const RULE_FIGURE_GROUP_OPENER_METADATA = 'figure-group-opener-metadata';

    /**
     * @var string
     */
    public const RULE_FIGURE_GROUP_PANEL_NUMBER = 'figure-group-panel-number';

    /**
     * @var string
     */
    public const RULE_FIGURE_GROUP_EMPTY = 'figure-group-empty';

    /**
     * @var string
     */
    public const RULE_FIGURE_GROUP_SINGLE_PANEL = 'figure-group-single-panel';

    /**
     * @var int
     */
    private const MAX_WALK_DEPTH = 512;

    /**
     * @param string $source
     * @param array<string, mixed> $options Accepted for signature parity with
     *   the other linters; this pass reads none.
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = []): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);

        $warnings = [];
        $this->collect($document, $this->byteOffsets($source), strlen($source), $warnings);

        return $warnings;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     * @param int $depth
     */
    private function collect(Node $node, ?array $byteAt, int $sourceLength, array &$warnings, int $depth = 0): void
    {
        if ($depth >= self::MAX_WALK_DEPTH) {
            return;
        }

        // A `::: figure` opener that did NOT open a group is a typed div whose
        // kind word is `figure`. One carrying a quoted title or a `[label]`
        // never matched the figure production at all (PART 9 §4c); a BARE one
        // only parses this way when an open group's body demoted it, because
        // groups do not nest. `{.figure}` on a bare `:::` is untyped and is
        // neither - the class came from an attribute line, not an opener.
        if ($node instanceof Div && $node->isTyped() && ($node->getClassList()[0] ?? null) === 'figure') {
            if ($node->getHeader() !== null || $node->getLabel() !== null) {
                $warnings[] = $this->warn(
                    $node,
                    $byteAt,
                    $sourceLength,
                    self::RULE_FIGURE_GROUP_OPENER_METADATA,
                    'A "::: figure" opener carrying a quoted title or [label] is not a composite figure; '
                        . 'it renders as a generic container. Drop the title/label to open a figure group.',
                );
            } else {
                $warnings[] = $this->warn(
                    $node,
                    $byteAt,
                    $sourceLength,
                    self::RULE_FIGURE_GROUP_NESTED,
                    'A "::: figure" inside a composite figure does not nest; '
                        . 'it renders as a generic container. Move it out of the enclosing group.',
                );
            }
        }

        if ($node instanceof FigureGroup) {
            $panels = $node->getPanels();
            if ($panels === []) {
                $warnings[] = $this->warn(
                    $node,
                    $byteAt,
                    $sourceLength,
                    self::RULE_FIGURE_GROUP_EMPTY,
                    'This "::: figure" group holds no captionable panel; '
                        . 'the panels wrapper renders around the preserved content only.',
                );
            } elseif (count($panels) === 1) {
                $warnings[] = $this->warn(
                    $node,
                    $byteAt,
                    $sourceLength,
                    self::RULE_FIGURE_GROUP_SINGLE_PANEL,
                    'This "::: figure" group holds a single panel; '
                        . 'a plain captioned figure renders the same content without the group wrapper.',
                );
            }

            foreach ($panels as $panel) {
                if ($this->captionHasNumber($this->panelCaption($panel))) {
                    $warnings[] = $this->warn(
                        $panel,
                        $byteAt,
                        $sourceLength,
                        self::RULE_FIGURE_GROUP_PANEL_NUMBER,
                        'A "#" placeholder in a panel caption stays literal: panels are not numbering units, '
                            . 'the group caption carries the number (and panel ids resolve with its letter).',
                    );
                }
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->collect($child, $byteAt, $sourceLength, $warnings, $depth + 1);
        }
    }

    /**
     * The caption a panel host carries: a Figure keeps it among its children,
     * a Table beside them - the same two homes the renderer reads.
     *
     * @param \MarkupCarve\Carve\Node\Node $panel
     */
    private function panelCaption(Node $panel): ?Caption
    {
        if ($panel instanceof Table) {
            return $panel->getCaption();
        }

        if ($panel instanceof Figure) {
            foreach ($panel->getChildren() as $child) {
                if ($child instanceof Caption) {
                    return $child;
                }
            }
        }

        return null;
    }

    /**
     * Whether a caption carries a `#` number placeholder. The parser turns the
     * first unescaped `#` of a caption into a CaptionNumber node, so presence
     * of the node IS the question - matching what the numbering resolver keys
     * on rather than re-scanning the text.
     *
     * @param \MarkupCarve\Carve\Node\Block\Caption|null $caption
     */
    private function captionHasNumber(?Caption $caption): bool
    {
        if ($caption === null) {
            return false;
        }

        return $this->holdsACaptionNumber($caption, 0);
    }

    private function holdsACaptionNumber(Node $node, int $depth): bool
    {
        if ($depth >= self::MAX_WALK_DEPTH) {
            return false;
        }

        foreach ($node->getChildren() as $child) {
            if ($child instanceof CaptionNumber) {
                return true;
            }
            if ($child->hasChildren() && $this->holdsACaptionNumber($child, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     * @param string $rule
     * @param string $message
     */
    private function warn(Node $node, ?array $byteAt, int $sourceLength, string $rule, string $message): LintWarning
    {
        $pos = $node->getPos();
        // A node the parser could not place carries NO span - PART 12 §4
        // forbids inventing one - so the finding falls back to the document
        // start rather than to a position it made up.
        if ($pos === null) {
            return new LintWarning(1, 1, $rule, $message, 0, 0);
        }

        return new LintWarning(
            $pos->startLine,
            $pos->startColumn,
            $rule,
            $message,
            SourceOffsets::toByte($pos->startOffset, $byteAt, $sourceLength),
            SourceOffsets::toByte($pos->endOffset, $byteAt, $sourceLength),
        );
    }

    /**
     * Byte offset of each codepoint, or null when the source is pure ASCII.
     *
     * @param string $source
     *
     * @return array<int, int>|null
     */
    private function byteOffsets(string $source): ?array
    {
        return SourceOffsets::map($source);
    }
}
