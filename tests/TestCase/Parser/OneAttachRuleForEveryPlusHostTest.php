<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#4409 (one attach rule), carve#4396 (a refused `+` is a letter), carve#4408 (a filled description stays open).
 * The fixture holds the Expected of each ruling and Appendix A of the #4409 report, rendered by the merged spec model.
 */
final class OneAttachRuleForEveryPlusHostTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/../../Fixture/plus-one-attach-rule.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function attachRows(): array
    {
        $rows = [];
        foreach (self::fixture()['attach'] as $id => $row) {
            $rows[$id] = [$row['source'], $row['html']];
        }

        return $rows;
    }

    #[DataProvider('attachRows')]
    public function testTheModelsReading(string $source, string $html): void
    {
        $this->assertSame($html, self::normalized((new CarveConverter())->convert($source)));
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    public static function refusedRows(): array
    {
        return array_map(static fn (array $row): array => [$row['source'], $row['plusLine']], self::fixture()['refused']);
    }

    /**
     * The #4396 property: replacing a kept `+` with a letter changes the output in that character only.
     */
    #[DataProvider('refusedRows')]
    public function testAKeptPlusChangesOneCharacter(string $source, int $plusLine): void
    {
        $html = (new CarveConverter())->convert($source);
        if (!str_contains($html, '+')) {
            $this->addToAssertionCount(1);

            return;
        }
        $lines = explode("\n", $source);
        $lines[$plusLine] = str_replace('+', 'x', $lines[$plusLine]);

        $this->assertSame((new CarveConverter())->convert(implode("\n", $lines)), str_replace('+', 'x', $html));
    }

    public function testTheLetterLeavesNoPrivateByteInTheTree(): void
    {
        $html = (new CarveConverter())->convert("- +\n\n`a\n+\nb`\n");

        $this->assertSame(0, preg_match('/[\x01-\x08\x0E-\x1F]/', $html));
    }

    public function testAControlByteInTheSourceIsNotTheLetter(): void
    {
        $this->assertStringContainsString("\x01", (new CarveConverter())->convert("a\x01\n\n- +\n"));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function refusals(): array
    {
        return [
            'marker line over a blank' => ["- +\n\npara\n", ['blank line']],
            'marker line at the end' => ["- +\n", ['end of the document']],
            'over a sibling' => ["- +\n- b\n", ['sibling']],
            'content column' => ["- +\n  x\n", ['right']],
            'closer' => ["::: d\n- +\n:::\n", ['closer']],
            'separate off column' => ["- a\n+\n  x\n", ['right']],
            'unclaimed before a heading' => ["q\n+\n# h\n", ['no open container claims']],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $parts
     */
    #[DataProvider('refusals')]
    public function testARefusedPlusIsReported(string $source, array $parts): void
    {
        $messages = [];
        foreach ((new DocumentLinter())->lint($source) as $warning) {
            if ($warning->rule === 'continuation-marker-refused') {
                $messages[] = $warning->message;
            }
        }

        $this->assertCount(1, $messages);
        foreach ($parts as $part) {
            $this->assertStringContainsString($part, $messages[0]);
        }
    }

    public function testAnAttachedPlusIsNotReported(): void
    {
        $rules = array_map(static fn ($warning): string => $warning->rule, (new DocumentLinter())->lint(":: t\n: +\n- l\n"));

        $this->assertNotContains('continuation-marker-refused', $rules);
    }

    private static function normalized(string $html): string
    {
        $parts = preg_split('/(<pre[ >][\s\S]*?<\/pre>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            $part = (string)preg_replace('/^\s*\n\s*|\n\s*$/', '', $part);
            $part = (string)preg_replace('/>\s*\n\s*</', '><', $part);
            $part = (string)preg_replace('/\n\s*/', ' ', $part);
            $parts[$i] = (string)preg_replace('/ +<\//', '</', $part);
        }
        $html = implode('', $parts);
        $html = (string)preg_replace('/<a href="#fnref[^>]*>.*?<\/a>/', '', $html);
        $html = (string)preg_replace('/ role="doc-[a-z]*"| aria-label="[^"]*"/', '', $html);
        $html = (string)preg_replace('/<section><hr><ol>/', ' NOTES: ', $html, 1);
        $html = (string)preg_replace('/<\/ol><\/section>/', '', $html, 1);

        return trim(str_replace('<p></p>', '', $html));
    }
}
