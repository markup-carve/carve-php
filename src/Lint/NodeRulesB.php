<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;

/**
 * The second half of the carve-js node rules (`lint/structure.ts`), one method per JS rule function, and the checks that run
 * after the walk.
 */
final class NodeRulesB
{
    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function intrawordUnderscore(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 1; $at < $length - 2; $at++) {
            if (
                $source[$at] !== '_' || !Support::isWord($source[$at - 1])
                || in_array($source[$at + 1] ?? '', [' ', "\t", "\n"], true)
            ) {
                continue;
            }
            $end = strpos($source, '_', $at + 2);
            if (
                $end === false || !Support::isWord($source[$end + 1] ?? null)
                || in_array($source[$end - 1] ?? '', [' ', "\t", "\n"], true)
            ) {
                continue;
            }
            $c->report('djot-intraword-underscore', $range[0] + $at, $range[0] + $end + 1, $path);
            $at = $end;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function emphasisBeforeAttrs(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 0; $at < $length - 2; $at++) {
            $ch = $source[$at];
            if (!str_contains('*_/~=', $ch) || $source[$at + 1] !== '{') {
                continue;
            }
            if ($at > 0 && $source[$at - 1] === '\\') {
                continue;
            }
            $end = strpos($source, '}', $at + 2);
            if ($end === false) {
                continue;
            }
            if (AttributeParser::read($source, $at + 1, $end + 1, new Attrs()) !== $end + 1) {
                continue;
            }
            // A glued run at the start of a cell belongs to the alignment rule; keep scanning after it.
            if (
                $at <= 1 && self::opensCell($c, $range[0])
                && strlen(NodeRulesA::oldAlignmentRun($source)['prefix'] ?? '') === $end + 1
            ) {
                $at = $end;

                continue;
            }
            $c->report('emphasis-marker-before-attributes', $range[0] + $at, $range[0] + $end + 1, $path, ['ch' => $ch], 0);
            $at = $end;
        }
    }

    private static function opensCell(Context $c, int $start): bool
    {
        $at = $start - 1;
        while (in_array($c->source[$at] ?? '', [' ', "\t"], true)) {
            $at--;
        }
        if (($c->source[$at] ?? '') === '=') {
            $at--;
        }

        return ($c->source[$at] ?? '') === '|';
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function emphasisBeforeAttrsSpan(array $node, string $path, Context $c): void
    {
        $type = $node['type'] ?? null;
        if ($type !== 'strong' && $type !== 'emphasis') {
            return;
        }
        $raw = Support::raw($node, $c);
        $marker = $type === 'strong' ? '*' : '/';
        if (preg_match('/' . preg_quote($marker, '/') . '\{[.#][^{}]*\}$/', $raw, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return;
        }
        $at = $match[0][1];
        if (!str_contains(substr($raw, 1, $at - 1), $marker)) {
            return;
        }
        $bounds = Support::bounds($node);
        $pos = Support::pos($node);
        if ($bounds === null || $pos === null) {
            return;
        }
        $c->report('emphasis-marker-before-attributes', $bounds[0] + $at, $bounds[0] + $at + 1, $path, [
            'marker' => $marker,
            'name' => $type,
            'line' => (string)$pos['startLine'],
            'column' => (string)$pos['startColumn'],
        ], 1);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function carveVersion(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'frontmatter') {
            return;
        }
        $pos = Support::pos($node);
        // C2-B-231: only the line-1 block declares the version.
        if ($pos === null || $pos['startLine'] !== 1) {
            return;
        }
        for ($number = $pos['startLine'] + 1; $number < $pos['endLine']; $number++) {
            $line = $c->lines[$number - 1] ?? null;
            if ($line === null) {
                continue;
            }
            if (preg_match('/^[ \t]*carve(?:-version)?[ \t]*:[ \t]*(\S+)[ \t]*$/', $line->text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            [$version, $offset] = $match[1];
            $numeric = preg_match('/^\d+(?:\.\d+)*$/', $version) === 1;
            $parts = $numeric ? array_map('intval', explode('.', $version)) : [];
            $major = $numeric ? $parts[0] : -1;
            $minor = count($parts) > 1 ? $parts[1] : 0;
            if (
                $major === 0 && ($minor < 2
                || ($minor === 2 && array_sum(array_slice($parts, 2)) === 0))
            ) {
                return;
            }
            $c->report('carve-version-unsupported', $line->start + $offset, $line->start + $offset + strlen($version), $path, [
                'version' => $version,
            ], $numeric ? 1 : 0);

            return;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function commentInLabel(array $node, string $path, Context $c): void
    {
        $pos = Support::pos($node);
        if (($node['type'] ?? null) !== 'comment' || ($node['form'] ?? null) !== 'line' || $pos === null) {
            return;
        }
        $parent = Support::parent($path);
        if ($parent !== null && ($c->nodeTypes[$parent] ?? null) === 'inline_footnote') {
            return;
        }
        $line = $c->lines[$pos['startLine'] - 1] ?? null;
        $range = Support::bounds($node);
        if ($line === null || $range === null) {
            return;
        }
        $before = substr($c->source, $line->start, max(0, $range[0] - $line->start));
        $open = strrpos($before, '[');
        if ($open === false || strpos($before, ']', $open) !== false) {
            return;
        }
        $c->report('comment-in-reference-label', $range[0], $range[1], $path);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unclosedBracedComment(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $at = strpos($source, '{%');
        if ($at !== false && strpos($source, '%}', $at + 2) === false) {
            $c->report('unclosed-braced-comment', $range[0] + $at, $range[1], $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function unclosedEmphasis(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 0; $at < $length - 1; $at++) {
            if (($source[$at] !== '*' && $source[$at] !== '/') || !Support::isWord($source[$at + 1])) {
                continue;
            }
            $backslashes = 0;
            for ($before = $at - 1; $before >= 0 && $source[$before] === '\\'; $before--) {
                $backslashes++;
            }
            if (
                $backslashes % 2 === 1 || ($at > 0 && ($source[$at - 1] === '{'
                || !str_contains(" \t\r\n([{", $source[$at - 1])))
            ) {
                continue;
            }
            if (strpos($source, $source[$at], $at + 1) !== false) {
                continue;
            }
            $c->report('unclosed-inline-emphasis', $range[0] + $at, $range[0] + $at + 1, $path, [
                'span' => $source[$at] === '*' ? 'strong' : 'emphasis',
            ]);

            return;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function sigilAfterLetter(array $node, string $path, Context $c): void
    {
        $range = self::textRange($node);
        if ($range === null) {
            return;
        }
        $source = Support::raw($node, $c);
        $length = strlen($source);
        for ($at = 1; $at < $length - 1; $at++) {
            $sigil = $source[$at];
            if ($sigil !== '@' && $sigil !== '#') {
                continue;
            }
            $before = $source[$at - 1];
            $prefix = substr($source, 0, $at);
            if (
                ord($before) < 128 || preg_match('/[\p{L}\p{N}]$/u', $prefix) !== 1
                || !Support::isWord($source[$at + 1])
            ) {
                continue;
            }
            if ($sigil !== '#' || preg_match('/^#[A-Za-z0-9_]*[A-Za-z_]/', substr($source, $at)) === 1) {
                $c->report('sigil-after-letter', $range[0] + $at, $range[0] + $at + 1, $path);
            }
        }
    }

    /**
     * Where the opening mark of a block stands: an attributed block starts at its attribute lines (C2-W-138).
     *
     * @param array<string, mixed> $node
     * @param int $start
     * @param int $quotes The quote prefixes of the block's container.
     * @param \MarkupCarve\Carve\Lint\Context $c
     */
    public static function openerBehindAttributes(array $node, int $start, int $quotes, Context $c): int
    {
        if (!isset($node['attrs'])) {
            return $start;
        }
        // Attribute ownership survives comment-only lines (C2-B-137).
        $s = $c->source;
        $at = $start;
        $sawAttribute = false;
        while (($s[$at] ?? '') === '{' || ($sawAttribute && substr($s, $at, 2) === '%%')) {
            $sawAttribute = true;
            $end = strpos($s, "\n", $at);
            if ($end === false) {
                return $start;
            }
            $at = $end + 1 + strspn($s, " \t", $end + 1);
            for ($left = $quotes; $left > 0 && ($s[$at] ?? '') === '>'; $left--) {
                $at++;
                $at += strspn($s, " \t", $at);
            }
        }

        return $at;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function bracedSpanAsText(array $node, string $path, Context $c): void
    {
        if (($node['type'] ?? null) !== 'text') {
            return;
        }
        $split = Support::parent($path);
        $parent = $split === null ? null : ($c->nodeTypes[$split] ?? null);
        if ($parent !== 'strong' && $parent !== 'emphasis') {
            return;
        }
        $raw = Support::raw($node, $c);
        $star = strpos($raw, '{*');
        $slash = strpos($raw, '{/');
        $at = $star === false ? $slash : ($slash === false ? $star : min($star, $slash));
        $range = Support::bounds($node);
        $lineEnd = $range === null ? false : strpos($c->source, "\n", $range[1]);
        $until = $lineEnd === false ? strlen($c->source) : $lineEnd;
        if (
            $at !== false && $range !== null && strpos($c->source, '}', $range[1]) !== false
            && (int)strpos($c->source, '}', $range[1]) < $until
        ) {
            $c->report('braced-span-opener-as-text', $range[0] + $at, $range[0] + $at + 2, $path);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function nonDescriptiveLink(array $node, string $path, Context $c): void
    {
        $children = Support::kids($node);
        if (($node['type'] ?? null) !== 'link' || count($children) !== 1) {
            return;
        }
        $child = $children[0] ?? [];
        if (($child['type'] ?? null) !== 'text') {
            return;
        }
        $value = Support::trim(strtolower((string)preg_replace('/[ \t\r\n]+/', ' ', Support::str($child, 'value') ?? '')));
        if ($value === 'here' || $value === 'click here' || $value === 'read more') {
            Support::reportNode('non-descriptive-link-text', $node, $path, $c);
        }
    }

    /**
     * The checks that need the whole tree (the JS `finishStructure`).
     *
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    public static function finish(Context $c): void
    {
        self::suppressUnreferencedFootnotePlatforms($c);
    }

    private static function suppressUnreferencedFootnotePlatforms(Context $c): void
    {
        $prefixes = [];
        foreach ($c->footnoteDefinitions as [$node, $path]) {
            $key = Support::str($node, 'key');
            if ($key !== null && !isset($c->usedFootnotes[$key])) {
                $prefixes[] = $path . '/';
            }
        }
        if ($prefixes === []) {
            return;
        }
        foreach ($c->diagnostics as $index => $entry) {
            if ($entry->code !== 'platform-issue-reference' && $entry->code !== 'platform-mention-token') {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string)$entry->path, $prefix)) {
                    unset($c->diagnostics[$index], $c->findingLines[$index]);

                    break;
                }
            }
        }
        $c->diagnostics = array_values($c->diagnostics);
        $c->findingLines = array_values($c->findingLines);
    }

    /**
     * The range of a text node, or null for another node or one without a position.
     *
     * @param array<string, mixed> $node
     *
     * @return array{0: int, 1: int}|null
     */
    private static function textRange(array $node): ?array
    {
        return ($node['type'] ?? null) === 'text' ? Support::bounds($node) : null;
    }
}
