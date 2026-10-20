<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownAssessmentAutolinksTest extends TestCase
{
    #[DataProvider('completeSources')]
    public function testShapeIsAssessedCompletely(string $source, string $code): void
    {
        $codes = array_column((new MarkdownToCarve())->convertWithFidelityReport($source)->report()['diagnostics'], 'code');
        self::assertNotContains('fidelity-unverified', $codes);
        self::assertContains($code, $codes);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function completeSources(): iterable
    {
        yield 'triple backslash pipe in a table autolink' => ["| <https://example.com/a\\\\\\|b> | z |\n|---|---|\n", 'markdown-autolink'];
        yield 'mailto autolink in a table cell' => ["| <mailto:a@b.co> | z |\n|---|---|\n", 'markdown-autolink'];
        yield 'mailto autolink in a paragraph' => ["x <mailto:a@b.co> y\n", 'markdown-autolink'];
        yield 'bare URL with an escaped pipe in a table cell' => ["| https://e.com/a\\|b | z |\n|---|---|\n", 'markdown-bare-autolink'];
        yield 'space inside an angle autolink in a table cell' => ["| <https://e.com/a b\\|c> | z |\n|---|---|\n", 'markdown-bare-autolink'];
        yield 'space inside an angle autolink in a paragraph' => ["x <https://e.com/a b> y\n", 'markdown-bare-autolink'];
    }

    #[DataProvider('backslashRuns')]
    public function testTableAutolinkKeepsBackslashesBeforeAnEscapedPipe(int $slashes): void
    {
        $source = '| <https://e.com/a' . str_repeat('\\', $slashes) . "|b> | z |\n|---|---|\n";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($source));
        // GFM drops one backslash at row level, so the link text keeps the rest.
        $text = 'https://e.com/a' . str_repeat('\\', $slashes - 1) . '|b';
        self::assertStringContainsString('>' . $text . '</a>', $html);
        self::assertStringContainsString('<a href="', $html);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function backslashRuns(): iterable
    {
        foreach ([1, 2, 3, 4, 5] as $slashes) {
            yield $slashes . ' backslashes' => [$slashes];
        }
    }
}
