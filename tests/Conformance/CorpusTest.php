<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Conformance;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared conformance corpus is the gate of this engine (`spec/0.2/conformance/README.md`).
 */
final class CorpusTest extends TestCase
{
    /**
     * Entries held by name, each with the clause sentence that shows the corpus wrong or the ruling this engine has not
     * implemented yet. A held entry must keep failing.
     *
     * @var array<string, string>
     */
    public const HELD = [
        'CC-2068' => 'markup-carve/carve#6664 (carve-php line): an empty body-rows with a footer count of all the rows',
    ];

    /**
     * @var array<string, true>
     */
    private const HELD_LINT = ['syntax-blocks-286' => true];

    /**
     * Kinds run outside this class: `import` by `Import\ImportCorpusTest`.
     *
     * @var array<string, true>
     */
    private const LATER = ['import' => true];

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
    public static function includeExamples(): array
    {
        return self::provide(Corpus::examples('include'));
    }

    /**
     * @param array<string, mixed> $example
     */
    #[DataProvider('includeExamples')]
    public function testIncludeExample(array $example): void
    {
        $this->gate($example['id'], function () use ($example): void {
            $files = $example['files'];
            $resolver = static function (array $request) use ($files): array {
                $file = $files[$request['path']] ?? null;
                if ($file === null) {
                    return ['error' => 'not_found'];
                }
                $text = $file['text'] ?? hex2bin(str_replace(' ', '', $file['bytes'] ?? ''));

                return ['text' => $text, 'id' => $request['path']];
            };
            $options = $example['options'];
            $includes = is_array($options['includes'] ?? null) ? $options['includes'] : [];
            $options['includes'] = $includes + ['resolver' => $resolver];
            $expected = $example['expected'];
            $result = Carve::renderWithDiagnostics($example['input'], $options);
            if (isset($expected['html'])) {
                $this->assertSame($expected['html'], $result->html);
            }
            if (isset($expected['tree'])) {
                $this->assertTree($expected['tree'], $example['input'], $options);
            }
            foreach ($expected['diagnostics'] ?? [] as $diagnostic) {
                $this->assertContains(is_string($diagnostic) ? $diagnostic : $diagnostic['code'], $result->codes());
            }
        });
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
    public static function lintExamples(): array
    {
        return self::provide(Corpus::examples('lint'));
    }

    /**
     * C2-W-103: a `lint` example states the tree of its input and the codes lint reports.
     *
     * @param array<string, mixed> $example
     *
     * @return void
     */
    #[DataProvider('lintExamples')]
    public function testLintExample(array $example): void
    {
        $this->gate($example['id'], function () use ($example): void {
            $this->assertTree($example['expected']['tree'], $example['input'], $example['options']);
            $codes = array_map(static fn (Diagnostic $d): string => $d->code, Carve::lint($example['input']));
            foreach ($example['expected']['diagnostics'] ?? [] as $diagnostic) {
                $this->assertContains(is_string($diagnostic) ? $diagnostic : $diagnostic['code'], $codes);
            }
        });
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function clauseCases(): array
    {
        return self::provide(array_filter(
            Corpus::clauseCases(),
            static fn (array $case): bool => !isset($case['kind']),
        ));
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
     * A `write` example states the parse tree of its input; its source is checked by `Writer\WriteExamplesTest`.
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
            $this->assertSame($case['expected']['html'], Carve::toHtml($case['input'], $case['options']));
            $tree = json_decode(
                Carve::toJson($case['input'], Corpus::parseOptions($case['options'])),
                true,
                4096,
                JSON_THROW_ON_ERROR,
            );
            $children = Corpus::hasPositions($case['expected']['children'])
                ? $tree['children'] : Corpus::withoutPositions($tree['children']);
            $this->assertSame(Corpus::sorted($case['expected']['children']), Corpus::sorted($children));
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
        $this->assertSame(['import' => true], self::LATER);
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
        $html = Carve::toHtml($example['input'], $example['options']);
        if (isset($expected['html'])) {
            $this->assertSame($expected['html'], $html);
        }
        if (isset($expected['htmlIncludes'])) {
            $this->assertStringContainsString($expected['htmlIncludes'], $html);
        }
        if (isset($expected['tree'])) {
            $this->assertTree($expected['tree'], $example['input'], $example['options']);
        }
        if (isset($expected['diagnostics']) && !isset(self::HELD_LINT[$example['id']])) {
            $codes = Carve::renderWithDiagnostics($example['input'], $example['options'])->codes();
            foreach (Carve::lint($example['input']) as $finding) {
                $codes[] = $finding->code;
            }
            $this->assertDiagnostics($expected['diagnostics'], $codes);
        }
    }

    /**
     * The expected codes are those of the example's `Reported:` line, not a complete list; lint codes included.
     *
     * @param array<string> $expected
     * @param array<string> $got
     *
     * @return void
     */
    private function assertDiagnostics(array $expected, array $got): void
    {
        foreach ($expected as $code) {
            $this->assertContains($code, $got);
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
        $tree = json_decode(Carve::toJson($input, Corpus::parseOptions($options)), true, 4096, JSON_THROW_ON_ERROR);
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
