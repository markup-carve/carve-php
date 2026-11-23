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
            ['platform-issue-reference', ['text'], NodeRulesA::platformIssue(...)],
            ['platform-mention-token', ['text', 'mention'], NodeRulesA::platformMention(...)],
            ['comment-in-reference-label', ['comment'], NodeRulesB::commentInLabel(...)],
            ['braced-span-opener-as-text', ['text'], NodeRulesB::bracedSpanAsText(...)],
            ['non-descriptive-link-text', ['link'], NodeRulesB::nonDescriptiveLink(...)],
        ];
    }
}
