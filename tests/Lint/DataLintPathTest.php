<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5531: the finding paths of the shared data rows, in data mode and with the handwritten rules. A pair finding
 * names the text run's inline parent, a line finding the source line's own path or its pipe table.
 */
final class DataLintPathTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function cases(): array
    {
        $out = [];
        $cases = [
            'strike in a term' => [":: key ~sub~\n", 'deprecated-bare-strike', '/children/0/children/0'],
            'underline in a paragraph' => ["a _x_ b\n", 'deprecated-bare-underline', '/children/0'],
            'highlight in a link' => ["a [=x=](u) z\n", 'deprecated-bare-highlight', '/children/0/children/1'],
            'colon closer line' => [":::\n::::\n", 'colon-fence-length-mismatch', ''],
            'quote mark without space' => [">a\n", 'blockquote-marker-without-space', '/children/0'],
            'item body below the content column' => ["- a\n\n b\n", 'list-item-body-below-content-column', '/children/0/children/0'],
            'comment inside a table row' => ["| a |\n%% x\n+ b |\n", 'comment-splits-table-row', '/children/0'],
        ];
        foreach (['hand', 'data'] as $mode) {
            foreach ($cases as $name => [$source, $rule, $path]) {
                $out[$mode . ': ' . $name] = [$mode, $source, $rule, $path];
            }
        }

        return $out;
    }

    /**
     * @param string $mode
     * @param string $source
     * @param string $rule
     * @param string $path
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testFindingPath(string $mode, string $source, string $rule, string $path): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv($mode === 'data' ? 'CARVE_LINT_RULES=data' : 'CARVE_LINT_RULES');
        try {
            $paths = [];
            foreach (Carve::lint($source) as $finding) {
                if ($finding->code === $rule) {
                    $paths[] = $finding->path;
                }
            }
            $this->assertSame([$path], $paths);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
