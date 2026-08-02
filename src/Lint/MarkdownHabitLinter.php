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
 * doubled delimiters, which always degrade to literal text, and a heading that
 * swallows the line beneath it.
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
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $warnings = [];
        $lines = explode("\n", $source);
        $offset = 0;
        $inFence = false;
        $fenceMarker = '';

        foreach ($lines as $index => $line) {
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
                foreach ($this->inlineWarnings($line, $index + 1, $offset) as $warning) {
                    $warnings[] = $warning;
                }
            }

            $offset += strlen($line) + 1;
        }

        return $warnings;
    }

    /**
     * The fence delimiter opening or closing a verbatim block, if this line is one.
     */
    private function fenceDelimiter(string $line): ?string
    {
        return preg_match('/^\s*(`{3,}|~{3,})/', $line, $matches) === 1 ? $matches[1] : null;
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
