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
            ['deprecated-substitution-arrow', ['strike'], NodeRulesA::substitutionArrow(...)],
            ['bidi-control-in-source', ['text', 'code', 'code_block', 'math'], NodeRulesA::bidiControl(...)],
            ['deprecated-indented-line-under-heading', ['heading'], NodeRulesA::headingContinuation(...)],
            ['figure-group-panel-number', ['figure_group'], NodeRulesA::figurePanelNumber(...)],
            ['unattached-inline-attribute', ['text'], NodeRulesA::unattachedInline(...)],
            ['platform-issue-reference', ['text'], NodeRulesA::platformIssue(...)],
            ['platform-mention-token', ['text', 'mention'], NodeRulesA::platformMention(...)],
            ['djot-intraword-underscore', ['text'], NodeRulesB::intrawordUnderscore(...)],
            ['emphasis-marker-before-attributes', ['text'], NodeRulesB::emphasisBeforeAttrs(...)],
            ['emphasis-marker-before-attributes', ['strong', 'emphasis'], NodeRulesB::emphasisBeforeAttrsSpan(...)],
            ['carve-version-unsupported', ['frontmatter'], NodeRulesB::carveVersion(...)],
            ['comment-in-reference-label', ['comment'], NodeRulesB::commentInLabel(...)],
            ['unclosed-braced-comment', ['text'], NodeRulesB::unclosedBracedComment(...)],
            ['unclosed-inline-emphasis', ['text'], NodeRulesB::unclosedEmphasis(...)],
            ['sigil-after-letter', ['text'], NodeRulesB::sigilAfterLetter(...)],
            ['braced-span-opener-as-text', ['text'], NodeRulesB::bracedSpanAsText(...)],
            ['non-descriptive-link-text', ['link'], NodeRulesB::nonDescriptiveLink(...)],
        ];
    }
}
