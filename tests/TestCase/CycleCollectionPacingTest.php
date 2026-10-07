<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use DOMDocument;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Util\CycleCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * PHP's cycle collector walks the whole node tree on every run, and a large
 * document used to trigger dozens of runs that freed nothing (O(n^1.5)).
 * Run counts are asserted instead of wall-clock time, so the guard holds on a
 * busy runner and under coverage.
 */
class CycleCollectionPacingTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function importMethods(): array
    {
        return [
            'source' => ['convert'],
            'source-report' => ['convertWithReport'],
            'ast-report' => ['convertToAstWithReport'],
        ];
    }

    #[DataProvider('importMethods')]
    #[RunInSeparateProcess]
    public function testHtmlImportPacesCollection(string $method): void
    {
        ini_set('memory_limit', '-1');
        $runs = gc_status()['runs'];
        $result = (new HtmlToCarve())->$method('<dl>' . str_repeat('<dt>Term</dt><dd><p>Definition</p></dd>', 6000) . '</dl>');

        if ($method === 'convertToAstWithReport') {
            self::assertSame('document', $result->value['type']);
            self::assertNotEmpty($result->value['children']);
        } else {
            self::assertStringContainsString('Definition', is_string($result) ? $result : $result->value);
        }
        self::assertLessThanOrEqual(10, gc_status()['runs'] - $runs);
        self::assertTrue(gc_enabled());
    }

    #[RunInSeparateProcess]
    public function testImportsRespectDisabledCollection(): void
    {
        gc_disable();
        $converter = new HtmlToCarve();
        foreach (['<p>text</p>', '<p>' . str_repeat('x', 128 << 10) . '</p>'] as $html) {
            $converter->convert($html);
            $converter->convertWithReport($html);
            $converter->convertToAstWithReport($html);
        }
        self::assertFalse(gc_enabled());
    }

    #[RunInSeparateProcess]
    public function testThrowingImportRestoresCollection(): void
    {
        $converter = new class extends HtmlToCarve {
            public function convert(string $html): string
            {
                throw new RuntimeException('import failed');
            }
        };
        try {
            $converter->convertWithReport('<p>text</p>' . str_repeat('x', 128 << 10));
            self::fail('Expected the import exception');
        } catch (RuntimeException $exception) {
            self::assertSame('import failed', $exception->getMessage());
        }
        self::assertTrue(gc_enabled());
        CycleCollection::paused(static function (): void {
            self::assertFalse(gc_enabled());
        });
        self::assertTrue(gc_enabled());
    }

    #[RunInSeparateProcess]
    public function testThrowingImportPipelinesRestoreCollection(): void
    {
        $converter = new class (importAdapter: 'word') extends HtmlToCarve {
            protected function normalizeAdapterFootnotes(DOMDocument $doc): void
            {
                throw new RuntimeException('normalization failed');
            }
        };
        foreach (['convert', 'convertWithReport', 'convertToAstWithReport'] as $method) {
            try {
                $converter->$method('<p>text</p>' . str_repeat('x', 128 << 10));
                self::fail('Expected the normalization exception');
            } catch (RuntimeException $exception) {
                self::assertSame('normalization failed', $exception->getMessage());
            }
            self::assertTrue(gc_enabled());
            CycleCollection::paused(static function (): void {
                self::assertFalse(gc_enabled());
            });
            self::assertTrue(gc_enabled());
        }
    }

    #[DataProvider('importMethods')]
    #[RunInSeparateProcess]
    public function testSmallNestedImportsKeepCollectionPaced(string $method): void
    {
        ini_set('memory_limit', '-1');
        $document = (new CarveConverter())->parse(str_repeat("paragraph\n\n", 10000));
        gc_collect_cycles();
        $runs = gc_status()['runs'];
        CycleCollection::paused(static function () use ($document, $method): void {
            $converter = new HtmlToCarve();
            for ($i = 0; $i < 200; $i++) {
                $converter->$method('<section id="title"><h1>title</h1></section>');
                $converter->$method('<p title="x"><code>int[][]</code></p>');
            }
            self::assertCount(10000, $document->getChildren());
        });

        self::assertLessThanOrEqual(10, gc_status()['runs'] - $runs);
        self::assertTrue(gc_enabled());
    }

    #[RunInSeparateProcess]
    public function testALargeTableDoesNotRunTheCollectorRepeatedly(): void
    {
        ini_set('memory_limit', '-1');
        $converter = new CarveConverter();
        $runs = gc_status()['runs'];

        $converter->convert(str_repeat("|x|y|\n", 30000));

        self::assertLessThanOrEqual(6, gc_status()['runs'] - $runs);
        self::assertTrue(gc_enabled());
    }

    public function testACallerThatDisabledTheCollectorKeepsItDisabled(): void
    {
        gc_disable();
        try {
            (new CarveConverter())->convert("|x|\n\n*[HTML]: Hyper\n");

            self::assertFalse(gc_enabled());
        } finally {
            gc_enable();
        }
    }

    /**
     * A fresh process, so the collection headroom is not sized by the suite's heap.
     */
    #[RunInSeparateProcess]
    public function testRepeatedSmallConversionsStillCollect(): void
    {
        $converter = new CarveConverter();
        $converter->setProfile(Profile::full());
        gc_collect_cycles();
        $before = memory_get_usage();
        $runs = gc_status()['runs'];

        for ($i = 0; $i < 8000; $i++) {
            $converter->convert("hello *world*\n");
        }

        self::assertGreaterThan($runs, gc_status()['runs']);
        self::assertLessThan(16 << 20, memory_get_usage() - $before);
    }

    public function testLateAbbreviationExpansionLeavesNoCycles(): void
    {
        $converter = new CarveConverter();
        $source = str_repeat("plain words here\n\n", 2000) . "*[HTML]: Hyper\n";
        gc_collect_cycles();

        gc_disable();
        try {
            $document = $converter->parse($source);
        } finally {
            gc_enable();
        }

        self::assertLessThan(100, gc_collect_cycles());
        self::assertNotEmpty($document->getChildren());
    }

    #[RunInSeparateProcess]
    public function testDirectParserUsePacesCollection(): void
    {
        ini_set('memory_limit', '-1');
        $runs = gc_status()['runs'];
        $document = (new BlockParser())->parse(str_repeat("|x|y|\n", 30000));

        self::assertNotEmpty($document->getChildren());
        self::assertLessThanOrEqual(6, gc_status()['runs'] - $runs);
        self::assertTrue(gc_enabled());
    }

    #[RunInSeparateProcess]
    public function testThrowingDestructorRestoresCollectionScope(): void
    {
        ini_set('memory_limit', '-1');
        try {
            CycleCollection::paused(static function (): void {
                $garbage = new class {
                    public ?self $cycle = null;

                    public string $payload = '';

                    public function __destruct()
                    {
                        throw new RuntimeException('destructor failed');
                    }
                };
                $garbage->cycle = $garbage;
                $garbage->payload = str_repeat('x', max(8 << 20, memory_get_usage()));
            });
            self::fail('Expected collection to propagate the destructor exception');
        } catch (RuntimeException $exception) {
            self::assertSame('destructor failed', $exception->getMessage());
        }
        self::assertTrue(gc_enabled());
        CycleCollection::paused(static function (): void {
            self::assertFalse(gc_enabled());
        });
        self::assertTrue(gc_enabled());
    }

    #[RunInSeparateProcess]
    public function testHeapShrinkLowersRetainedGarbage(): void
    {
        ini_set('memory_limit', '-1');
        $ballast = str_repeat('x', max(64 << 20, memory_get_usage() * 2));
        CycleCollection::paused(static function (): void {
        });
        unset($ballast);
        $before = memory_get_usage();
        $payloadBytes = max(64 << 10, intdiv($before, 100));
        for ($i = 0; $i < 200; $i++) {
            CycleCollection::paused(static function () use ($payloadBytes): void {
                $garbage = new stdClass();
                $garbage->cycle = $garbage;
                $garbage->payload = str_repeat('x', $payloadBytes);
            });
        }
        self::assertLessThan(max(8 << 20, $before), memory_get_usage() - $before);
    }

    #[RunInSeparateProcess]
    public function testCollectionFitsTheConfiguredMemoryLimit(): void
    {
        $oldLimit = ini_get('memory_limit');
        ini_set('memory_limit', (string)(memory_get_usage(true) + (128 << 20)));
        try {
            $ballast = str_repeat('x', 88 << 20);
            $runs = gc_status()['runs'];
            for ($i = 0; $i < 800; $i++) {
                CycleCollection::paused(static function (): void {
                    $garbage = new stdClass();
                    $garbage->cycle = $garbage;
                    $garbage->payload = str_repeat('x', 64 << 10);
                });
            }
            self::assertSame(88 << 20, strlen($ballast));
            self::assertGreaterThan($runs, gc_status()['runs']);
        } finally {
            ini_set('memory_limit', $oldLimit === false ? '-1' : $oldLimit);
        }
    }
}
