<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Output and report rows match carve-rs for the same input.
 */
final class AGithubAlertAfterMultibyteTextImportsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function alertProvider(): array
    {
        return [
            'an accented letter before the alert' => ["é\n\n> [!TIP]\n> y\n", "é\n\n::: tip\ny\n:::\n", 'paragraph:1 github-alert:3 paragraph:4'],
            'an emoji before the alert' => ["😀 x\n\n> [!NOTE]\n> y\n", "😀 x\n\n::: note\ny\n:::\n", 'paragraph:1 github-alert:3 paragraph:4'],
            'CJK before the alert' => ["漢字\n\n> [!WARNING]\n> y\n", "漢字\n\n::: warning\ny\n:::\n", 'paragraph:1 github-alert:3 paragraph:4'],
            'a multibyte heading before the alert' => ["# Ü\n\n> [!CAUTION]\n> 漢\n", "# Ü\n\n::: danger\n漢\n:::\n", 'atx-heading:1 github-alert:3 paragraph:4'],
            'multibyte text in an alert body before another alert' => [
                "> [!NOTE]\n> é 😀 漢字\n\n> [!TIP]\n> y\n",
                "::: note\né 😀 漢字\n:::\n\n::: tip\ny\n:::\n",
                'github-alert:1 paragraph:2 github-alert:4 paragraph:5',
            ],
            'multibyte text in the paragraph right above the label line' => [
                "a é\nb 😀\n> [!TIP]\n> y\n",
                "a é\nb 😀\n\n::: tip\ny\n:::\n",
                'paragraph:1 soft-break:1 github-alert:3 paragraph:4',
            ],
            'CRLF line endings' => ["é\r\n\r\n> [!TIP]\r\n> y\r\n", "é\n\n::: tip\ny\n:::\n", 'paragraph:1 github-alert:3 paragraph:4'],
            // carve-php reports any tab as unverified, so only the output is compared.
            'a tab before the alert' => ["\tx\n\n> [!TIP]\n> y\n", "```\nx\n```\n\n::: tip\ny\n:::\n", null],
        ];
    }

    #[DataProvider('alertProvider')]
    public function testTheAlertImportsAsAnAdmonition(string $markdown, string $carve, ?string $rows): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);

        $this->assertSame($carve, $result->value);
        if ($rows === null) {
            return;
        }
        $this->assertSame(
            array_map(static function (string $row): array {
                [$code, $line] = explode(':', $row);

                return ['markdown-' . $code, $code === 'github-alert' ? 'normalized' : 'preserved', 'exact', 'line:' . $line];
            }, explode(' ', $rows)),
            array_map(static fn (MigrationDiagnostic $row): array => [$row->code, $row->fidelity, $row->confidence, $row->path], $result->diagnostics),
        );
    }
}
