<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * Reports Markdown habits that parse as valid Carve but render as something the
 * author did not intend.
 *
 * Carve diverges from Markdown deliberately: `*x*` is strong, `_x_` is
 * underline, and the doubled forms carry no meaning at all. A writer coming from
 * Markdown - or a language model, whose training makes the Markdown reading the
 * strong prior - produces `**bold**` and gets literal asterisks, with no error
 * anywhere. `getWarnings()` stays empty because the document IS valid; it just
 * says something else. A validity check cannot catch this, which is why it needs
 * its own pass.
 *
 * Only forms that are never meaningful Carve are reported. `*x*` and `_x_` are
 * NOT flagged: they are correct Carve for strong and underline, so warning on
 * them would punish authors writing the language properly. That leaves the
 * doubled delimiters, which always degrade to literal text.
 *
 * Verbatim spans are skipped - `` `**not bold**` `` is code, not a habit.
 */
class MarkdownHabitLinter
{
    /**
     * @var string
     */
    public const RULE_STRONG_ASTERISKS = 'markdown-strong-asterisks';

    /**
     * @var string
     */
    public const RULE_STRONG_UNDERSCORES = 'markdown-strong-underscores';

    /**
     * @var string
     */
    public const RULE_STRIKETHROUGH = 'markdown-strikethrough';

    /**
     * @var string
     */
    public const RULE_BIDI_CONTROL_IN_SOURCE = 'bidi-control-in-source';

    /**
     * @var string
     */
    public const RULE_PLATFORM_MENTION_TOKEN = 'platform-mention-token';

    /**
     * @var string
     */
    public const RULE_PLATFORM_ISSUE_REFERENCE = 'platform-issue-reference';

    /**
     * @var array<string, array{label: string, mention: string, issue: string}>
     */
    private const PLATFORMS = [
        'github' => [
            'label' => 'GitHub',
            'mention' => '/(?<![\w@.\-\/])@([A-Za-z0-9_][\w-]*(?:\.[A-Za-z0-9_][\w-]*)*)/',
            'issue' => '/(?<![\w#\/])#(\d+)(?![\w-])/',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function knownPlatforms(): array
    {
        return array_keys(self::PLATFORMS);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = []): array
    {
        $warnings = [];
        $lines = explode("\n", $source);
        $platforms = $this->selectedPlatforms($options['platforms'] ?? []);
        $platformSkipLines = $platforms === [] ? [] : $this->platformSkipLines($lines);
        $offset = 0;
        $inFence = false;
        $fenceMarker = '';

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            if (preg_match_all('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $line, $bidi, PREG_OFFSET_CAPTURE)) {
                foreach ($bidi[0] as [$control, $byteColumn]) {
                    $prefix = substr($line, 0, $byteColumn);
                    $column = preg_match_all('/./us', $prefix) + 1;
                    $warnings[] = new LintWarning(
                        $lineNumber,
                        $column,
                        self::RULE_BIDI_CONTROL_IN_SOURCE,
                        sprintf(
                            'Bidi override/isolate control U+%04X is preserved by canonical Carve but stripped from presentation output; remove it unless intentional.',
                            mb_ord($control, 'UTF-8'),
                        ),
                        $offset + $byteColumn,
                        $offset + $byteColumn + strlen($control),
                    );
                }
            }
            // Deliberately WITHOUT the container strip the platform pass uses.
            // This tracker decides where the Markdown-habit rules look, and
            // teaching it that `> ``` ` opens a fence would silently stop those
            // rules reporting inside a quoted or listed fence - a change to a
            // shipped rule that has nothing to do with the platform pass. The
            // platform pass keeps its own tracker in platformSkipLines(), which
            // is where the container-aware reading belongs.
            $fence = $this->fenceDelimiter($line);

            if ($fence !== null) {
                if (!$inFence) {
                    $inFence = true;
                    $fenceMarker = $fence;
                } elseif (str_starts_with($fence, $fenceMarker)) {
                    $inFence = false;
                    $fenceMarker = '';
                }

                $offset += strlen($line) + 1;

                continue;
            }

            if (!$inFence) {
                foreach ($this->inlineWarnings($line, $lineNumber, $offset) as $warning) {
                    $warnings[] = $warning;
                }
            }

            if ($platforms !== [] && !isset($platformSkipLines[$index])) {
                foreach ($this->platformWarnings($line, $lineNumber, $offset, $platforms) as $warning) {
                    $warnings[] = $warning;
                }
            }

            $offset += strlen($line) + 1;
        }

        // Only when the platform pass ran. The two passes append per line, so
        // without a sort a platform finding in column 3 would follow a habit
        // finding in column 20 of the same line. Sorting unconditionally would
        // also reorder the habit-only output a caller has had since before this
        // pass existed, and that ordering is not this ticket's to change.
        if ($platforms !== []) {
            usort(
                $warnings,
                static fn (LintWarning $a, LintWarning $b): int => [$a->line, $a->column] <=> [$b->line, $b->column],
            );
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function selectedPlatforms(mixed $platforms): array
    {
        if (!is_array($platforms)) {
            return [];
        }

        $selected = [];
        foreach ($platforms as $platform) {
            if (is_string($platform) && array_key_exists($platform, self::PLATFORMS)) {
                $selected[$platform] = true;
            }
        }

        return array_keys($selected);
    }

    /**
     * The fence delimiter opening or closing a verbatim block, if this line is one.
     */
    private function fenceDelimiter(string $line, bool $stripContainers = false): ?string
    {
        if ($stripContainers) {
            $line = $this->stripContainerPrefix($line);
        }

        return preg_match('/^\s*(`{3,}|~{3,})/', $line, $matches) === 1 ? $matches[1] : null;
    }

    private function stripContainerPrefix(string $line): string
    {
        do {
            $before = $line;
            $line = (string)preg_replace('/^\s*>\s?/', '', $line, 1);
            $line = (string)preg_replace('/^\s*(?:[-+*]|\d+[.)])\s+/', '', $line, 1);
        } while ($line !== $before);

        return $line;
    }

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private function inlineWarnings(string $line, int $lineNumber, int $offset): array
    {
        $masked = $this->maskVerbatim($line);
        $warnings = [];

        $rules = [
            self::RULE_STRONG_ASTERISKS => [
                '/\*\*(?!\s)((?:[^*]|\*(?!\*))+?)(?<!\s)\*\*/',
                'renders as literal asterisks, not bold. Carve writes strong as *%s*.',
            ],
            self::RULE_STRONG_UNDERSCORES => [
                '/__(?!\s)((?:[^_]|_(?!_))+?)(?<!\s)__/',
                'renders as literal underscores, not bold. Carve writes strong as *%s*.',
            ],
            self::RULE_STRIKETHROUGH => [
                '/~~(?!\s)((?:[^~]|~(?!~))+?)(?<!\s)~~/',
                'renders as literal tildes, not strikethrough. Carve writes strike as ~%s~.',
            ],
        ];

        foreach ($rules as $rule => [$pattern, $explanation]) {
            if (preg_match_all($pattern, $masked, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as $position => $match) {
                $inner = $this->truncate((string)$matches[1][$position][0]);
                $warnings[] = new LintWarning(
                    line: $lineNumber,
                    column: $match[1] + 1,
                    rule: $rule,
                    message: sprintf('`%s` ', $match[0]) . sprintf($explanation, $inner),
                    start: $offset + $match[1],
                    end: $offset + $match[1] + strlen((string)$match[0]),
                );
            }
        }

        usort($warnings, static fn (LintWarning $a, LintWarning $b): int => $a->column <=> $b->column);

        return $warnings;
    }

    /**
     * @param string $line
     * @param int $lineNumber
     * @param int $offset
     * @param list<string> $platforms
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private function platformWarnings(string $line, int $lineNumber, int $offset, array $platforms): array
    {
        $masked = $this->maskPlatformLine($line);
        $warnings = [];

        foreach ($platforms as $platform) {
            $config = self::PLATFORMS[$platform];
            foreach (
                [
                    self::RULE_PLATFORM_MENTION_TOKEN => [
                        $config['mention'],
                        'an at-prefixed word',
                        'move the example into a fenced code block, or strip the sigil and rephrase',
                    ],
                    self::RULE_PLATFORM_ISSUE_REFERENCE => [
                        $config['issue'],
                        'a hash-number',
                        'move the example into a fenced code block, or rewrite it as "item 1" / "point 1"',
                    ],
                ] as $rule => [$pattern, $what, $fix]
            ) {
                if (preg_match_all($pattern, $masked, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[0] as $match) {
                    $token = (string)$match[0];
                    $warnings[] = new LintWarning(
                        line: $lineNumber,
                        column: $match[1] + 1,
                        rule: $rule,
                        message: sprintf(
                            '%s re-linkifies %s in published output, so "%s" becomes a link that notifies or references something unrelated; %s.',
                            $config['label'],
                            $what,
                            $token,
                            $fix,
                        ),
                        start: $offset + $match[1],
                        end: $offset + $match[1] + strlen($token),
                    );
                }
            }
        }

        usort($warnings, static fn (LintWarning $a, LintWarning $b): int => $a->column <=> $b->column);

        return $warnings;
    }

    /**
     * @param list<string> $lines
     *
     * @return array<int, true>
     */
    private function platformSkipLines(array $lines): array
    {
        $skip = $this->unreferencedFootnoteLines($lines);
        $inFence = false;
        $fenceMarker = '';
        $inCommentFence = false;
        $commentFenceMarker = '';
        $inFrontmatter = false;

        foreach ($lines as $index => $line) {
            // The opener the frontmatter extension itself accepts, not a bare
            // `---`. A TYPED opener (`---yaml`, `--- toml`) is the canonical
            // spelling, so matching only the bare form reported every token in
            // a typed metadata block - text the renderer never puts in the body
            // at all. Raised by codex review.
            if ($index === 0 && preg_match('/^--- ?\w*\s*$/', $line) === 1) {
                $inFrontmatter = true;
                $skip[$index] = true;

                continue;
            }
            if ($inFrontmatter) {
                $skip[$index] = true;
                // The CLOSER is always bare, which is why this is not the same
                // pattern as the opener above.
                if (preg_match('/^---\s*$/', $line) === 1) {
                    $inFrontmatter = false;
                }

                continue;
            }

            $commentFence = $this->commentFenceDelimiter($line);
            if ($inCommentFence) {
                $skip[$index] = true;
                if ($commentFence === $commentFenceMarker) {
                    $inCommentFence = false;
                    $commentFenceMarker = '';
                }

                continue;
            }
            if ($commentFence !== null) {
                $inCommentFence = true;
                $commentFenceMarker = $commentFence;
                $skip[$index] = true;

                continue;
            }
            if (preg_match('/^\s*%%(?!%)/', $line) === 1) {
                $skip[$index] = true;

                continue;
            }

            $fence = $this->fenceDelimiter($line, true);
            if ($fence !== null) {
                if (!$inFence) {
                    $inFence = true;
                    $fenceMarker = $fence;
                } elseif (str_starts_with($fence, $fenceMarker)) {
                    $inFence = false;
                    $fenceMarker = '';
                }
                $skip[$index] = true;

                continue;
            }
            if ($inFence) {
                $skip[$index] = true;

                continue;
            }

            if (
                preg_match('/^\s*\[[^\]^][^\]]*\]:/', $line) === 1
                || preg_match('/^\s*\*\[[^\]]+\]:/', $line) === 1
            ) {
                $skip[$index] = true;
            }
        }

        return $skip;
    }

    private function commentFenceDelimiter(string $line): ?string
    {
        return preg_match('/^\s*(%{3,})\s*$/', $line, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param list<string> $lines
     *
     * @return array<int, true>
     */
    private function unreferencedFootnoteLines(array $lines): array
    {
        $definitions = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*\[\^([^\]]+)\]:/', $line, $matches) !== 1) {
                continue;
            }

            $end = $index;
            for ($cursor = $index + 1, $count = count($lines); $cursor < $count; $cursor++) {
                if ($lines[$cursor] !== '' && preg_match('/^[ \t]/', $lines[$cursor]) !== 1) {
                    break;
                }
                $end = $cursor;
            }
            $definitions[] = ['label' => $matches[1], 'start' => $index, 'end' => $end];
        }

        $skip = [];
        foreach ($definitions as $definition) {
            $referenced = false;
            $pattern = '/\[\^' . preg_quote((string)$definition['label'], '/') . '\]/';
            foreach ($lines as $index => $line) {
                if ($index === $definition['start']) {
                    continue;
                }
                if (preg_match($pattern, $line) === 1) {
                    $referenced = true;

                    break;
                }
            }
            if ($referenced) {
                continue;
            }
            for ($index = $definition['start']; $index <= $definition['end']; $index++) {
                $skip[$index] = true;
            }
        }

        return $skip;
    }

    private function maskPlatformLine(string $line): string
    {
        $masked = (string)preg_replace_callback(
            '/\b[a-zA-Z][a-zA-Z0-9+.-]*:\/\/\S+/',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $line,
        );

        return $this->maskInlineLinkDestinations($masked);
    }

    private function maskInlineLinkDestinations(string $line): string
    {
        $length = strlen($line);
        $mask = array_fill(0, $length, false);

        for ($i = 0; $i < $length - 1; $i++) {
            if ($line[$i] !== ']' || $line[$i + 1] !== '(' || $this->isEscaped($line, $i)) {
                continue;
            }
            if (!$this->hasUnescapedOpeningBracket($line, $i)) {
                continue;
            }

            $depth = 1;
            for ($j = $i + 2; $j < $length; $j++) {
                if ($line[$j] === '\\') {
                    $j++;

                    continue;
                }
                if ($line[$j] === '(') {
                    $depth++;

                    continue;
                }
                if ($line[$j] !== ')') {
                    continue;
                }
                $depth--;
                if ($depth === 0) {
                    for ($k = $i + 2; $k < $j; $k++) {
                        $mask[$k] = true;
                    }

                    break;
                }
            }
        }

        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $mask[$i] ? ' ' : $line[$i];
        }

        return $out;
    }

    private function hasUnescapedOpeningBracket(string $line, int $before): bool
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            if ($line[$i] === '[' && !$this->isEscaped($line, $i)) {
                return true;
            }
        }

        return false;
    }

    private function isEscaped(string $line, int $offset): bool
    {
        $slashes = 0;
        for ($i = $offset - 1; $i >= 0 && $line[$i] === '\\'; $i--) {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }

    /**
     * Blank out verbatim spans, keeping offsets intact, so code is never linted.
     */
    private function maskVerbatim(string $line): string
    {
        return (string)preg_replace_callback(
            '/(`+)(?:(?!\1).)*\1/',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $line,
        );
    }

    private function truncate(string $text, int $limit = 40): string
    {
        $text = trim($text);

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit - 1) . '…';
    }
}
