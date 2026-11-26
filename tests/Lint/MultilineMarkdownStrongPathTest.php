<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Markdown strong pair across a line is reported on the paragraph in both lint modes, as carve-js reports it.
 */
final class MultilineMarkdownStrongPathTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'paragraph' => ["**a\nb**\n", '/children/0'],
            'after text' => ["x **a\nb** y\n", '/children/0'],
            'in a quote' => ["> **a\nb**\n", '/children/0/children/0'],
            'in a list item' => ["- **a\nb**\n", '/children/0/children/0/children/0'],
        ];
    }

    /**
     * @param string $source
     * @param string $path
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testBothModesReportTheParagraph(string $source, string $path): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        try {
            foreach (['CARVE_LINT_RULES', 'CARVE_LINT_RULES=data'] as $mode) {
                putenv($mode);
                $paths = [];
                foreach (Carve::lint($source) as $finding) {
                    if ($finding->code === 'markdown-strong-double-star') {
                        $paths[] = $finding->path;
                    }
                }
                $this->assertSame([$path], $paths, $mode);
            }
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
