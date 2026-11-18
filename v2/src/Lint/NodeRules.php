<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Lint;

use Closure;

/**
 * The node rules in the order of carve-js `NODE_RULES`, which decides the order of findings at one offset.
 */
final class NodeRules
{
    /**
     * @var array<array{0: string, 1: array<string>, 2: \Closure}>|null
     */
    private static ?array $all = null;

    private static function pair(string $mark, string $id): Closure
    {
        return static fn (array $node, string $path, Context $c) => NodeRulesA::textPair($node, $path, $c, $mark, $id);
    }

    /**
     * @return array<array{0: string, 1: array<string>, 2: \Closure}>
     */
    public static function all(): array
    {
        return self::$all ??= [
            ['include-attributes-unused', ['include'], NodeRulesB::includeAttributesUnused(...)],
            ['include-lines-table-rows-hint', ['include'], NodeRulesB::includeLinesTableRows(...)],
            ['deprecated-bare-underline', ['text'], self::pair('_', 'deprecated-bare-underline')],
            ['deprecated-bare-strike', ['text'], self::pair('~', 'deprecated-bare-strike')],
            ['deprecated-bare-highlight', ['text'], self::pair('=', 'deprecated-bare-highlight')],
            ['markdown-strong-double-star', ['text'], self::pair('**', 'markdown-strong-double-star')],
            ['markdown-strikethrough-double-tilde', ['text'], self::pair('~~', 'markdown-strikethrough-double-tilde')],
            ['djot-emphasis-underscore', ['text'], self::pair('_', 'djot-emphasis-underscore')],
            ['djot-subscript-tilde', ['text'], self::pair('~', 'djot-subscript-tilde')],
            ['djot-superscript-caret', ['text'], self::pair('^', 'djot-superscript-caret')],
            ['a11y/image-alt', ['image'], NodeRulesA::imageAlt(...)],
            ['empty-link-destination', ['link'], NodeRulesA::emptyDestination(...)],
            ['deprecated-loose-attribute', ['list'], NodeRulesA::looseAttribute(...)],
            ['deprecated-table-attribute', ['table'], NodeRulesA::tableAttribute(...)],
            ['heading-level-skip', ['heading'], NodeRulesA::headingLevel(...)],
            ['heading-marker-mixed', ['heading'], NodeRulesA::headingKind(...)],
            ['heading-closing-hashes', ['heading'], NodeRulesA::headingClosing(...)],
            ['heading-trailing-attribute', ['heading'], NodeRulesA::headingTrailing(...)],
            ['duplicate-reference-url', ['link_reference_definition'], NodeRulesA::duplicateReferenceUrl(...)],
            ['deprecated-separator-row', ['table_row'], NodeRulesA::separatorRow(...)],
            ['table-row-cell-count', ['table'], NodeRulesA::tableRows(...)],
            ['figure-group-empty', ['figure_group'], NodeRulesA::figureEmpty(...)],
            ['figure-group-single-panel', ['figure_group'], NodeRulesA::figureSingle(...)],
            ['deprecated-substitution-arrow', ['strike'], NodeRulesA::substitutionArrow(...)],
            ['frontmatter-shaped-thematic-break', ['frontmatter'], NodeRulesA::frontmatterBreak(...)],
            ['bidi-control-in-source', ['text', 'code', 'code_block', 'math'], NodeRulesA::bidiControl(...)],
            ['description-term-multi-line', ['definition_term'], NodeRulesA::multilineTerm(...)],
            ['deprecated-indented-line-under-heading', ['heading'], NodeRulesA::headingContinuation(...)],
            ['figure-group-nested', ['figure_group'], NodeRulesA::figureNested(...)],
            ['figure-group-panel-number', ['figure_group'], NodeRulesA::figurePanelNumber(...)],
            ['footnotes-placement-in-container', ['directive'], NodeRulesA::footnotePlacement(...)],
            ['references-placement-in-container', ['directive'], NodeRulesA::referencesPlacement(...)],
            ['semantic-attribute-outside-span', ['paragraph', 'heading', 'link', 'image', 'strong'], NodeRulesA::semanticOutside(...)],
            ['semantic-attribute-value-ignored', ['span'], NodeRulesA::semanticValue(...)],
            ['table-column-arity', ['table'], NodeRulesA::tableColumnArity(...)],
            ['table-attribute-invalid', ['table'], NodeRulesA::invalidTableAttribute(...)],
            ['table-row-count-clamped', ['table'], NodeRulesA::rowCountClamped(...)],
            ['comment-splits-table-row', ['table'], NodeRulesA::commentSplitsRow(...)],
            ['table-width-total', ['table'], NodeRulesA::tableWidth(...)],
            ['table-row-attribute-as-cell', ['table_row'], NodeRulesA::rowAttributeCell(...)],
            ['emphasis-as-heading', ['paragraph'], NodeRulesA::emphasizedHeading(...)],
            ['comment-shaped-emphasis', ['emphasis'], NodeRulesA::commentEmphasis(...)],
            ['glob-shaped-strong', ['strong'], NodeRulesA::globStrong(...)],
            ['djot-subscript-tilde-braced', ['strike'], NodeRulesA::djotBracedStrike(...)],
            ['djot-highlight-braces', ['highlight'], NodeRulesA::djotBracedHighlight(...)],
            ['unattached-inline-attribute', ['text'], NodeRulesA::unattachedInline(...)],
            ['deprecated-cell-alignment', ['table_cell'], NodeRulesA::deprecatedCellAlignment(...)],
            ['table-marker-run-padding', ['table_cell'], NodeRulesA::markerRunPadding(...)],
            ['fence-closer-indented', ['code_block'], NodeRulesA::fenceCloserIndent(...)],
            ['figure-group-opener-metadata', ['div'], NodeRulesA::figureOpenerMetadata(...)],
            ['comment-line-in-line-block', ['line'], NodeRulesA::commentInLineBlock(...)],
            ['broken-crossref', ['xref'], NodeRulesA::brokenCrossref(...)],
            ['broken-fragment-link', ['link'], NodeRulesA::collectFragment(...)],
            ['platform-issue-reference', ['text'], NodeRulesA::platformIssue(...)],
            ['platform-mention-token', ['text', 'mention'], NodeRulesA::platformMention(...)],
            ['delimited-table-wide-row', ['table'], NodeRulesB::delimitedWideRow(...)],
            ['djot-intraword-underscore', ['text'], NodeRulesB::intrawordUnderscore(...)],
            ['emphasis-marker-before-attributes', ['text'], NodeRulesB::emphasisBeforeAttrs(...)],
            ['carve-version-unsupported', ['frontmatter'], NodeRulesB::carveVersion(...)],
            ['comment-fence-closer-with-text', ['comment'], NodeRulesB::commentFenceCloserText(...)],
            ['comment-in-reference-label', ['comment'], NodeRulesB::commentInLabel(...)],
            ['footnote-labels-differ-only-in-whitespace', ['footnote'], NodeRulesB::footnoteWhitespace(...)],
            ['table-column-overlap', ['table'], NodeRulesB::tableColumnOverlap(...)],
            ['tight-list-item-with-blank-line', ['list'], NodeRulesB::tightBlankLine(...)],
            ['braced-comment-in-a-template-source', ['comment'], NodeRulesB::templateComment(...)],
            ['unclosed-braced-comment', ['text'], NodeRulesB::unclosedBracedComment(...)],
            ['unclosed-inline-emphasis', ['text'], NodeRulesB::unclosedEmphasis(...)],
            ['ordered-list-kind-split', ['document'], NodeRulesB::orderedKindSplit(...)],
            ['sigil-after-letter', ['text'], NodeRulesB::sigilAfterLetter(...)],
            ['list-item-body-below-content-column', ['list_item'], NodeRulesB::listItemBodyColumn(...)],
            ['list-item-block-overindented', ['list_item'], NodeRulesB::listItemBlockIndent(...)],
            ['list-marker-below-content-column', ['list'], NodeRulesB::listMarkerBelowColumn(...)],
            ['block-opener-overindented', ['document'], NodeRulesB::blockOverindent(...)],
            ['braced-span-opener-as-text', ['text'], NodeRulesB::bracedSpanAsText(...)],
            ['colon-fence-length-mismatch', ['div', 'admonition', 'figure_group'], NodeRulesB::colonFenceMismatch(...)],
            ['consumed-key-near-miss', ['table'], NodeRulesB::consumedNearMiss(...)],
            ['ordered-marker-right-aligned', ['list'], NodeRulesB::orderedRightAligned(...)],
            ['table-span-marker-blocked', ['table_cell'], NodeRulesB::spanMarkerBlocked(...)],
            ['table-span-marker-attribute', ['table_cell'], NodeRulesB::spanMarkerAttribute(...)],
            ['uneven-list-indent', ['list'], NodeRulesB::unevenListIndent(...)],
            ['non-descriptive-link-text', ['link'], NodeRulesB::nonDescriptiveLink(...)],
            ['unresolved-reference-link', ['link', 'image'], NodeRulesB::unresolvedReference(...)],
            ['unused-link-reference-definition', ['link', 'image', 'link_reference_definition'], NodeRulesB::unusedReference(...)],
            ['unresolved-footnote', ['footnote_ref'], NodeRulesB::unresolvedFootnote(...)],
            ['unused-footnote-definition', ['footnote', 'footnote_ref'], NodeRulesB::unusedFootnote(...)],
        ];
    }
}
