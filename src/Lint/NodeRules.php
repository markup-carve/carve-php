<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * The node rules in the order of carve-js `NODE_RULES`, which decides the order of findings at one offset.
 */
final class NodeRules
{
    /**
     * @var array<array{0: string, 1: array<string>, 2: \Closure}>|null
     */
    private static ?array $all = null;

    /**
     * @return array<array{0: string, 1: array<string>, 2: \Closure}>
     */
    public static function all(): array
    {
        return self::$all ??= [
            ['deprecated-table-attribute', ['table'], NodeRulesA::tableAttribute(...)],
            ['deprecated-substitution-arrow', ['strike'], NodeRulesA::substitutionArrow(...)],
            ['bidi-control-in-source', ['text', 'code', 'code_block', 'math'], NodeRulesA::bidiControl(...)],
            ['deprecated-indented-line-under-heading', ['heading'], NodeRulesA::headingContinuation(...)],
            ['figure-group-panel-number', ['figure_group'], NodeRulesA::figurePanelNumber(...)],
            ['references-placement-in-container', ['directive'], NodeRulesA::referencesPlacement(...)],
            [
                'semantic-attribute-outside-span', [
                    'paragraph', 'heading', 'link', 'image', 'strong', 'emphasis', 'list', 'list_item', 'block_quote',
                    'table', 'code', 'code_block', 'raw_block', 'div', 'admonition',
                ], NodeRulesA::semanticOutside(...),
            ],
            ['semantic-attribute-value-ignored', ['span'], NodeRulesA::semanticValue(...)],
            ['table-column-arity', ['table'], NodeRulesA::tableColumnArity(...)],
            ['table-attribute-invalid', ['table'], NodeRulesA::invalidTableAttribute(...)],
            ['table-row-count-clamped', ['table'], NodeRulesA::rowCountClamped(...)],
            ['table-width-total', ['table'], NodeRulesA::tableWidth(...)],
            ['unattached-inline-attribute', ['text'], NodeRulesA::unattachedInline(...)],
            ['platform-issue-reference', ['text'], NodeRulesA::platformIssue(...)],
            ['platform-mention-token', ['text', 'mention'], NodeRulesA::platformMention(...)],
            ['djot-intraword-underscore', ['text'], NodeRulesB::intrawordUnderscore(...)],
            ['emphasis-marker-before-attributes', ['text'], NodeRulesB::emphasisBeforeAttrs(...)],
            ['emphasis-marker-before-attributes', ['strong', 'emphasis'], NodeRulesB::emphasisBeforeAttrsSpan(...)],
            ['carve-version-unsupported', ['frontmatter'], NodeRulesB::carveVersion(...)],
            ['comment-in-reference-label', ['comment'], NodeRulesB::commentInLabel(...)],
            ['table-column-overlap', ['table'], NodeRulesB::tableColumnOverlap(...)],
            ['unclosed-braced-comment', ['text'], NodeRulesB::unclosedBracedComment(...)],
            ['unclosed-inline-emphasis', ['text'], NodeRulesB::unclosedEmphasis(...)],
            ['sigil-after-letter', ['text'], NodeRulesB::sigilAfterLetter(...)],
            ['braced-span-opener-as-text', ['text'], NodeRulesB::bracedSpanAsText(...)],
            ['consumed-key-near-miss', ['code_block'], NodeRulesB::consumedNearMiss(...)],
            ['non-descriptive-link-text', ['link'], NodeRulesB::nonDescriptiveLink(...)],
        ];
    }
}
