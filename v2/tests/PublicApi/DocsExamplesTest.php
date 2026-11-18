<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\PublicApi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs every `php` block of docs/v2/README.md; its `assert()` calls are checked whatever `zend.assertions` says.
 */
final class DocsExamplesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function examples(): array
    {
        $text = (string)file_get_contents(dirname(__DIR__, 3) . '/docs/v2/README.md');
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
        $code = (string)preg_replace('~^assert\(~m', '\\' . self::class . '::check(', $code);
        eval($code);
        $this->addToAssertionCount(1);
    }

    public function testThereAreExamples(): void
    {
        $this->assertGreaterThanOrEqual(8, count(self::examples()));
    }
}
