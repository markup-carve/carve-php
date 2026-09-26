<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\CarveCorpusTest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * THE GUARD IS WATCHED FAILING HERE (carve-php#2460).
 *
 * `corpusProvider()` used to walk past a `.crv` with no `.html`, and no other
 * check counts complete pairs, so deleting one golden left all 31312 tests
 * green with that document uncompared. A guard nobody has seen fire is the
 * defect, so these rows drive it from both directions over a directory this
 * test builds, rather than asserting on the vendored corpus, which is complete.
 */
class AnIncompleteCorpusPairIsReportedTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/carve-corpus-pair-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dir = $dir;
        $this->write('01-complete.crv', "a\n");
        $this->write('01-complete.html', "<p>a</p>\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->dir);
    }

    private function write(string $name, string $body): void
    {
        file_put_contents($this->dir . '/' . $name, $body);
    }

    public function testACompleteDirectoryIsCollected(): void
    {
        $pairs = CarveCorpusTest::collectCorpusPairs($this->dir);

        $this->assertSame(['01-complete'], array_keys($pairs));
    }

    public function testASourceWithNoGoldenIsNamed(): void
    {
        $this->write('02-orphan.crv', "b\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/02-orphan\.html/');

        CarveCorpusTest::collectCorpusPairs($this->dir);
    }

    public function testAGoldenWithNoSourceIsNamed(): void
    {
        $this->write('03-orphan.html', "<p>c</p>\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/03-orphan\.crv/');

        CarveCorpusTest::collectCorpusPairs($this->dir);
    }

    public function testEverySuchHalfIsNamedAtOnce(): void
    {
        $this->write('02-orphan.crv', "b\n");
        $this->write('03-orphan.html', "<p>c</p>\n");

        try {
            CarveCorpusTest::collectCorpusPairs($this->dir);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('02-orphan.html', $e->getMessage());
            $this->assertStringContainsString('03-orphan.crv', $e->getMessage());

            return;
        }

        $this->fail('two incomplete pairs went unreported');
    }

    public function testTheVendoredCorpusIsComplete(): void
    {
        $pairs = CarveCorpusTest::corpusProvider();

        $this->assertGreaterThan(1000, count($pairs));
    }
}
