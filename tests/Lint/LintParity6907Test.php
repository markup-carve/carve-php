<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#6907 section 3 and the carve-php findings of #6905 that remained: lint parity with carve-js.
 */
final class LintParity6907Test extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<array<int|string>>}>
     */
    public static function upgradeRows(): array
    {
        return [
            // C2-B-149: 0.1 already wrote `^ second` as a paragraph, so no reading changed.
            'U-107' => ["| a | b |\n^ first\n^ second\n", ['upgrade' => true], [['unattached-caption', '', 18, 26]]],
            // C2-I-069: a title with a line break is no tail, so `/u` opens an emphasis that nothing closes.
            'U-221' => ["[a](/u \"t\nu\")\n", [], [['unclosed-inline-emphasis', '', 4, 5]]],
            'U-221 upgrade' => ["[a](/u \"t\nu\")\n", ['upgrade' => true], [['unclosed-inline-emphasis', '', 4, 5]]],
        ];
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<array{0: string, 1: string, 2: int, 3: int}> $expected
     *
     * @return void
     */
    #[DataProvider('upgradeRows')]
    public function testUpgradeRows(string $source, array $options, array $expected): void
    {
        self::assertSame($expected, $this->findings($source, [], $options));
    }

    /**
     * @return array<string, array{0: string, 1: array<array{0: string, 1: string, 2: int, 3: int}>}>
     */
    public static function emphasis(): array
    {
        return [
            'quoted title across lines, no bracket' => ["(/u \"t\nu\")\n", [['unclosed-inline-emphasis', '', 1, 2]]],
            'closed quotes, then a line' => ["x /u \"t\"\ny\n", [['unclosed-inline-emphasis', '', 2, 3]]],
            // The later line holds the delimiter, so the earlier one is not reported (C2-I-051).
            'delimiter on a later line' => ["x /u\ny /v\n", [['unclosed-inline-emphasis', '', 7, 8]]],
            'closer on a later line' => ["x /u\ny/\n", []],
        ];
    }

    /**
     * @param string $source
     * @param array<array{0: string, 1: string, 2: int, 3: int}> $expected
     *
     * @return void
     */
    #[DataProvider('emphasis')]
    public function testUnclosedEmphasisReadsTheRestOfItsParagraph(string $source, array $expected): void
    {
        self::assertSame($expected, $this->findings($source));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function fenceMessages(): array
    {
        $further = 'These further words are not read as a title, label or attributes. The block opens without them. Use "';

        return [
            // C2-B-117: a title holds one pair of quotes; a half-quoted title gets them both.
            'open quote' => ["```js \"t\nx\n```", 3, 'Write ``` js "t".'],
            'close quote' => ["```js t\"\nx\n```", 3, 'Write ``` js "t".'],
            // The catalog's message 4: a spaced brace block, a glued character that opens no attribute block, a title
            // after a label.
            'brace block after a space' => ["```js {.a}\nx\n```", 4, $further . '``` js"'],
            'glued brace' => ["```js{x\nx\n```", 4, $further . '``` js"'],
            'title after the label' => ["``` js [l] \"t\"\nx\n```", 4, $further . '``` js [l]"'],
            // C2-B-122: a raw fence takes no title; the opener is suggested with one space behind the run.
            'raw format, glued' => ["```=html foo\nx\n```", 4, $further . '``` =html"'],
            'raw format, tab' => ["```=html\tfoo bar\nx\n```", 4, $further . '``` =html"'],
            'words behind a valid title' => ["``` js \"t\" [l] x\nx\n```", 1, 'These words follow a valid title'],
        ];
    }

    /**
     * @param string $source
     * @param int $variant
     * @param string $text
     *
     * @return void
     */
    #[DataProvider('fenceMessages')]
    public function testFenceTitleMessage(string $source, int $variant, string $text): void
    {
        $found = array_values(array_filter(Carve::lint($source), static fn (Diagnostic $d): bool => $d->code === 'fence-title-syntax'));

        self::assertCount(1, $found);
        self::assertStringContainsString($text, $found[0]->message, 'message ' . $variant);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<array{0: string, 1: string, 2: int, 3: int}>}>
     */
    public static function selections(): array
    {
        $notes = "x\n\n[^n]: b\n\n[^n]: c\n";

        return [
            // C2-N-086: only the selected lines are linted; the appended definitions (C2-N-020) are not.
            'lines before the definitions' => ['{{ c.crv @lines:1-1 }}', $notes, []],
            'lines taking the later definition' => ['{{ c.crv @lines:5-5 }}', $notes, [['unused-footnote-definition', 'c.crv', 12, 16]]],
            'link definitions' => ['{{ c.crv @lines:1-1 }}', "x\n\n[a]: /u\n\n[a]: /v\n", []],
            'section before the duplicate' => ['{{ c.crv #H }}', $notes . "\n# H\n\ny[^n]\n", []],
        ];
    }

    /**
     * @param string $source
     * @param string $child
     * @param array<array{0: string, 1: string, 2: int, 3: int}> $expected
     *
     * @return void
     */
    #[DataProvider('selections')]
    public function testParseEntriesOutsideTheLintedPart(string $source, string $child, array $expected): void
    {
        $found = $this->findings($source, ['c.crv' => $child]);

        self::assertSame($expected, $found);
    }

    public function testParseEntryInsideTheSelectionStays(): void
    {
        $found = $this->findings('{{ c.crv @lines:3-5 }}', ['c.crv' => "x\n\n[^n]: b\n\n[^n]: c\n"]);

        self::assertContains(['duplicate-footnote-definition', 'c.crv', 12, 17], $found);
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param array<string, mixed> $options
     *
     * @return array<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function findings(string $source, array $files = [], array $options = []): array
    {
        if ($files !== []) {
            $options['includes'] = static fn (array $request): ?array => isset($files[$request['path']])
                ? ['text' => $files[$request['path']], 'id' => $request['path']]
                : null;
        }

        return array_map(
            static fn (Diagnostic $d): array => [$d->code, $d->file ?? '', $d->startOffset, $d->endOffset],
            Carve::lint($source, $options),
        );
    }
}
