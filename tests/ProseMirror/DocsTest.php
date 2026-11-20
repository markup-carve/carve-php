<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs every `php` block of docs/prosemirror.md; its `assert()` calls are checked whatever `zend.assertions` says.
 */
final class DocsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function examples(): array
    {
        $text = (string)file_get_contents(dirname(__DIR__, 2) . '/docs/prosemirror.md');
        preg_match_all('~^```php\n(.*?)^```$~ms', $text, $m);
        $out = [];
        foreach ($m[1] as $i => $code) {
            $out['block ' . ($i + 1)] = [$code];
        }

        return $out;
    }

    public static function check(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('a documented assertion does not hold');
        }
    }

    #[DataProvider('examples')]
    public function testTheExampleRuns(string $code): void
    {
        // Each block is its own file in the docs; its `use` lines need a namespace of their own here.
        eval('namespace DocsTest' . md5($code) . ';' . (string)preg_replace('~^assert\(~m', '\\' . self::class . '::check(', $code));
        $this->addToAssertionCount(1);
    }

    public function testThereAreExamples(): void
    {
        $this->assertCount(5, self::examples());
    }
}
