<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Conformance;

use Closure;
use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Test\Support\Corpus;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared conformance corpus is the gate of this engine (`spec/0.2/conformance/README.md`).
 */
final class CorpusTest extends TestCase
{
    /**
     * Entries held by name, each with the clause sentence that shows the corpus wrong. A held entry must keep failing.
     *
     * @var array<string, string>
     */
    public const HELD = [];

    /**
     * Kinds outside this engine: the writer, lint and the HTML importer come in later stages.
     *
     * @var array<string, true>
     */
    private const LATER = ['import' => true, 'lint' => true];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function renderExamples(): array
    {
        return self::provide(Corpus::examples('render'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function writeExamples(): array
    {
        return self::provide(Corpus::examples('write'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function clauseCases(): array
    {
        return self::provide(Corpus::clauseCases());
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    private static function provide(array $entries): array
    {
        $out = [];
        foreach ($entries as $id => $entry) {
            $out[$id] = [$entry];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $example
     *
     * @return void
     */
    #[DataProvider('renderExamples')]
    public function testRenderExample(array $example): void
    {
        $this->gate($example['id'], fn () => $this->render($example));
    }

    /**
     * A `write` example states the parse tree of its input, which this engine checks; its canonical source waits for the writer.
     *
     * @param array<string, mixed> $example
     *
     * @return void
     */
    #[DataProvider('writeExamples')]
    public function testWriteExampleTree(array $example): void
    {
        $this->gate($example['id'], fn () => $this->assertTree($example['expected']['tree'], $example['input'], $example['options']));
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return void
     */
    #[DataProvider('clauseCases')]
    public function testClauseCase(array $case): void
    {
        $this->gate($case['id'], function () use ($case): void {
            $this->assertSame($case['expected']['html'], Carve2::toHtml($case['input'], $case['options']));
            $tree = json_decode(Carve2::toJson($case['input'], $case['options']), true, 4096, JSON_THROW_ON_ERROR);
            $this->assertSame(Corpus::sorted($case['expected']['children']), Corpus::sorted(Corpus::withoutPositions($tree['children'])));
        });
    }

    public function testEveryKindIsRunOrNamedLater(): void
    {
        $kinds = [];
        foreach (Corpus::examples() as $example) {
            $kinds[$example['kind']] = ($kinds[$example['kind']] ?? 0) + 1;
        }
        ksort($kinds);
        $this->assertSame(['import', 'include', 'lint', 'render', 'write'], array_keys($kinds));
        $this->assertSame(['import' => true, 'lint' => true], self::LATER);
        foreach (array_keys(self::HELD) as $id) {
            $this->assertTrue(isset(Corpus::examples()[$id]) || isset(Corpus::clauseCases()[$id]), $id);
        }
    }

    /**
     * @param array<string, mixed> $example
     *
     * @return void
     */
    private function render(array $example): void
    {
        $expected = $example['expected'];
        $html = Carve2::toHtml($example['input'], $example['options']);
        if (isset($expected['html'])) {
            $this->assertSame($expected['html'], $html);
        }
        if (isset($expected['htmlIncludes'])) {
            $this->assertStringContainsString($expected['htmlIncludes'], $html);
        }
        if (isset($expected['tree'])) {
            $this->assertTree($expected['tree'], $example['input'], $example['options']);
        }
    }

    /**
     * C2-A-165: this engine claims positions, so a tree is compared with them.
     *
     * @param array<string, mixed> $expected
     * @param string $input
     * @param array<string, mixed> $options
     *
     * @return void
     */
    private function assertTree(array $expected, string $input, array $options): void
    {
        $tree = json_decode(Carve2::toJson($input, $options), true, 4096, JSON_THROW_ON_ERROR);
        $this->assertSame(Corpus::sorted($expected), Corpus::sorted($tree));
    }

    /**
     * Runs one entry; a held entry passes only while it still fails.
     *
     * @param string $id
     * @param \Closure(): void $check
     *
     * @return void
     */
    private function gate(string $id, Closure $check): void
    {
        if (!isset(self::HELD[$id])) {
            $check();

            return;
        }
        try {
            $check();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail($id . ' is held (' . self::HELD[$id] . ') but passes: drop the hold.');
    }
}
