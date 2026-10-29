<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rows match carve-rs for the same input.
 */
final class ABlockInALooseMarkdownItemIsAssessedTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function verifiedProvider(): array
    {
        return [
            'a quote in a loose item' => ["- Item.\n\n  > x\n", 'bullet-list:1 list-item:1 paragraph:1 block-quote:3 paragraph:3'],
            'a nested quote in a loose item' => ["- Item.\n\n  > x\n  > > y\n", 'bullet-list:1 list-item:1 paragraph:1 block-quote:3 paragraph:3 block-quote:4 paragraph:4'],
            'two loose items' => ["- a\n\n- b\n", 'bullet-list:1 list-item:1 paragraph:1 list-item:3 paragraph:3'],
            'a quote in a loose ordered item' => ["1. One.\n\n   > q\n2. Two.\n", 'ordered-list:1 list-item:1 paragraph:1 block-quote:3 paragraph:3 list-item:4 paragraph:4'],
            'a second paragraph in a loose item' => ["- a\n\n  b\n- c\n", 'bullet-list:1 list-item:1 paragraph:1 paragraph:3 list-item:4 paragraph:4'],
            'corpus-convert case 96' => ["> Outer quote.\n>\n> > [!NOTE]\n> > Inside a quote.\n\n- Item.\n\n  > [!TIP]\n  > Inside a list item.\n", 'block-quote:1 paragraph:1 block-quote:3 paragraph:3 soft-break:3 bullet-list:6 list-item:6 paragraph:6 block-quote:8 paragraph:8 soft-break:8'],
            'a different bullet after a blank starts a new list' => ["* a\n\n- b\n", 'bullet-list:1 list-item:1 bullet-list:3 list-item:3'],
        ];
    }

    #[DataProvider('verifiedProvider')]
    public function testTheReportIsVerified(string $markdown, string $expected): void
    {
        $rows = array_map(
            static fn (MigrationDiagnostic $row): array => [$row->code, $row->fidelity, $row->confidence, $row->path],
            (new MarkdownToCarve())->convertWithFidelityReport($markdown)->diagnostics,
        );

        $this->assertSame(
            array_map(static function (string $row): array {
                [$code, $line] = explode(':', $row);

                return ['markdown-' . $code, 'preserved', 'exact', 'line:' . $line];
            }, explode(' ', $expected)),
            $rows,
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unverifiedProvider(): array
    {
        return [
            'a quote in a tight item' => ["- Item.\n  > x\n"],
        ];
    }

    #[DataProvider('unverifiedProvider')]
    public function testAnUnmodeledItemStaysUnverified(string $markdown): void
    {
        $codes = array_map(
            static fn (MigrationDiagnostic $row): string => $row->code,
            (new MarkdownToCarve())->convertWithFidelityReport($markdown)->diagnostics,
        );

        $this->assertSame(['fidelity-unverified'], $codes);
    }
}
