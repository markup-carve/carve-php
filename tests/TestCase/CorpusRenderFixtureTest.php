<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function basename;
use function dirname;
use function file_exists;
use function file_get_contents;
use function glob;
use function in_array;
use function preg_match;

/**
 * Every reviewed non-HTML spec fixture is checked in this repository's CI.
 */
class CorpusRenderFixtureTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const AHEAD_OF_PIN = [
        '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace',
        '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace-2',
        '279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-3',
        '407-one-consumed-boolean-spells-the-looseness-no-blank-line-can-2',
    ];

    /**
     * @throws \RuntimeException
     *
     * @return array<string, array{slug: string, target: string, source: string, expected: string}>
     */
    public static function fixtureProvider(): array
    {
        $dir = dirname(__DIR__) . '/spec/tests/corpus';
        $cases = [];
        foreach (['md', 'txt', 'ansi', 'fmt'] as $target) {
            foreach (glob($dir . '/*.' . $target) ?: [] as $fixturePath) {
                $slug = basename($fixturePath, '.' . $target);
                if (preg_match('/^\d+-/', $slug) !== 1) {
                    continue;
                }
                $sourcePath = $dir . '/' . $slug . '.crv';
                if (!file_exists($sourcePath)) {
                    throw new RuntimeException($fixturePath . ' has no .crv source pair');
                }
                $cases[$target . ': ' . $slug] = [
                    'slug' => $slug,
                    'target' => $target,
                    'source' => (string)file_get_contents($sourcePath),
                    'expected' => (string)file_get_contents($fixturePath),
                ];
            }
        }
        if ($cases === []) {
            throw new RuntimeException('No non-HTML render fixtures found under ' . $dir);
        }

        return $cases;
    }

    #[DataProvider('fixtureProvider')]
    public function testRenderFixture(string $slug, string $target, string $source, string $expected): void
    {
        $actual = match ($target) {
            'md' => CarveConverter::markdown()->convert($source),
            'txt' => CarveConverter::plainText()->convert($source),
            'ansi' => CarveConverter::ansi()->convert($source),
            'fmt' => CarveConverter::toCarve($source),
            default => throw new RuntimeException('Unknown corpus render target: ' . $target),
        };

        if ($target === 'fmt' && in_array($slug, self::AHEAD_OF_PIN, true)) {
            $this->assertNotSame($expected, $actual, 'the ahead-of-pin declaration is stale for ' . $slug);

            return;
        }

        $this->assertSame($expected, $actual, $target . ' fixture mismatch for ' . $slug);
    }
}
