<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use MarkupCarve\Carve\Transform\ResolvedInclude;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cross-engine include-conformance GATE (PART 9 §19, Phase 2).
 *
 * The carve spec repo owns a shared golden-vector corpus at
 * tests/spec/tests/include-conformance/, vendored the same way the HTML corpus
 * is. Each vector describes an in-memory (or on-disk) fixture and FOUR golden
 * outputs generated from the reference engine (carve-js): `html`, `fmt` (the
 * serializer output of the PRE-expansion document), `warnings` and
 * `dependencies`. This runner drives php's REAL pipeline - IncludeExpander +
 * CarveConverter's HTML renderer + the Carve serializer - and asserts all four
 * against the golden under the exact normalization the spec README documents.
 * Passing therefore means php == js on §19 semantics.
 *
 * The normalization contract (reproduced from the reference driver
 * scripts/include-conformance-lib.mjs):
 *  - warnings -> ordered list of `{ rule, file? }`; message/detail/offsets are
 *    dropped (host-worded / host-dependent).
 *  - dependencies -> `{ id, resolved }` in first-encounter order (I11).
 *  - filesystem paths -> the whole materialized tree base folds to `<TMP>`.
 *  - `forbiddenSubstrings` -> no raw message may contain any of them (I7 no-leak).
 *  - `checkFmtExpandEquivalence` -> expanding the FORMATTED entry yields the same
 *    html + dependency set as expanding the original (I12 stronger invariant).
 */
class IncludeConformanceTest extends TestCase
{
    /**
     * Legitimate, already-known cross-engine differences that are NOT include
     * bugs and must not be papered over by editing php output. Keyed by vector
     * name, each entry carries the reason it is expected to differ. Empty: php
     * matches the reference on every vector.
     *
     * A row here is skipped by testVector and re-run by
     * testEveryKnownDifferenceStillDiffers(), which fails the moment the vector
     * stops differing, so the entry cannot outlive the difference it excuses.
     *
     * @var array<string, string>
     */
    protected const KNOWN_DIFFERENCES = [];

    /**
     * @throws \RuntimeException
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function vectorProvider(): array
    {
        $dir = self::vectorDir();
        $files = glob($dir . '/*.json') ?: [];
        if ($files === []) {
            throw new RuntimeException(
                "Include-conformance vectors not found at {$dir}.\n"
                . "Initialize the submodule:\n  git submodule update --init --recursive",
            );
        }

        sort($files);
        $cases = [];
        foreach ($files as $path) {
            /** @var array<string, mixed> $vector */
            $vector = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $rules = implode(', ', (array)($vector['rules'] ?? []));
            $cases["{$vector['name']} [{$rules}]"] = [$vector];
        }

        return $cases;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function vectorsByName(): array
    {
        $byName = [];
        foreach (self::vectorProvider() as [$vector]) {
            $byName[(string)$vector['name']] = $vector;
        }

        return $byName;
    }

    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('vectorProvider')]
    public function testVector(array $vector): void
    {
        $name = (string)$vector['name'];
        if (isset(self::KNOWN_DIFFERENCES[$name])) {
            $this->markTestSkipped('Known cross-engine difference: ' . self::KNOWN_DIFFERENCES[$name]);
        }

        foreach ($this->comparisons($vector, $this->runVector($vector)) as $comparison) {
            $this->assertSame($comparison['expected'], $comparison['actual'], "{$name}: {$comparison['label']}");
        }
    }

    /**
     * A declared difference that no longer describes this engine hides a vector
     * that passes, so the row expires with the difference: this fails naming the
     * entry the moment every golden on its vector agrees again.
     */
    public function testEveryKnownDifferenceStillDiffers(): void
    {
        $this->addToAssertionCount(1);
        $vectors = self::vectorsByName();
        foreach (self::KNOWN_DIFFERENCES as $name => $reason) {
            $this->assertArrayHasKey($name, $vectors, "Known difference names no vector: {$name}");
            $differing = [];
            foreach ($this->comparisons($vectors[$name], $this->runVector($vectors[$name])) as $comparison) {
                if ($comparison['expected'] !== $comparison['actual']) {
                    $differing[] = $comparison['label'];
                }
            }
            $this->assertNotSame(
                [],
                $differing,
                "{$name} now matches the reference on every golden - delete its KNOWN_DIFFERENCES entry ({$reason})",
            );
        }
    }

    /**
     * Every golden comparison one vector states, as label/expected/actual
     * triples. Both halves of the guard read this one list, so the staleness
     * half cannot judge a row against less than testVector asserts.
     *
     * @param array<string, mixed> $vector
     * @param array<string, mixed> $result
     *
     * @return list<array{label: string, expected: mixed, actual: mixed}>
     */
    protected function comparisons(array $vector, array $result): array
    {
        /** @var array<string, mixed> $expected */
        $expected = $vector['expected'];

        $out = [
            [
                'label' => 'html mismatch',
                'expected' => $this->normalizeHtml((string)$expected['html']),
                'actual' => $this->normalizeHtml((string)$result['html']),
            ],
            ['label' => 'fmt mismatch', 'expected' => $expected['fmt'], 'actual' => $result['fmt']],
        ];
        if (isset($expected['flattened'])) {
            $out[] = [
                'label' => 'flattened mismatch',
                'expected' => $expected['flattened'],
                'actual' => $result['flattened'] ?? null,
            ];
        }
        if (isset($expected['carveTarget'])) {
            $out[] = [
                'label' => 'the carve target expanded (I15)',
                'expected' => $expected['carveTarget'],
                'actual' => $result['carveTarget'] ?? null,
            ];
        }
        $out[] = ['label' => 'warnings mismatch', 'expected' => $expected['warnings'], 'actual' => $result['warnings']];
        $out[] = [
            'label' => 'dependencies mismatch',
            'expected' => $expected['dependencies'],
            'actual' => $result['dependencies'],
        ];

        // I7 no-leak: a raw resolver error (or absolute path) must never reach a
        // warning message, regardless of wording.
        $forbidden = (array)($vector['forbiddenSubstrings'] ?? []);
        if ($forbidden !== []) {
            $leaked = [];
            foreach ($forbidden as $substring) {
                foreach ((array)$result['rawWarningMessages'] as $message) {
                    if (str_contains((string)$message, (string)$substring)) {
                        $leaked[] = (string)$substring;
                    }
                }
            }
            $out[] = ['label' => 'warning messages leaked (I7)', 'expected' => [], 'actual' => $leaked];
        }

        // I12 stronger invariant: expanding the formatted document matches.
        if (!empty($vector['checkFmtExpandEquivalence'])) {
            /** @var array<string, mixed>|null $formattedRun */
            $formattedRun = is_array($result['formattedRun'] ?? null) ? $result['formattedRun'] : null;
            $out[] = [
                'label' => 'fmt-expand html drift',
                'expected' => $this->normalizeHtml((string)$result['html']),
                'actual' => $formattedRun === null ? null : $this->normalizeHtml((string)$formattedRun['html']),
            ];
            $out[] = [
                'label' => 'fmt-expand dependency drift',
                'expected' => $result['dependencies'],
                'actual' => $formattedRun === null ? null : $formattedRun['dependencies'],
            ];
        }

        return $out;
    }

    /**
     * Run one vector through php's real pipeline and return the normalized
     * four-field result plus the raw warning messages (for the I7 assertion).
     *
     * @param array<string, mixed> $vector
     *
     * @return array<string, mixed>
     */
    protected function runVector(array $vector): array
    {
        $base = null;
        try {
            /** @var array<string, mixed> $options */
            $options = (array)($vector['options'] ?? []);

            if (($vector['mode'] ?? 'virtual') === 'filesystem') {
                $base = $this->materializeTree((array)$vector['tree']);
                $baseReal = (string)realpath($base);
                $rootReal = (string)realpath($base . '/' . (string)$vector['root']);
                $entry = $this->readEntry($base, (string)$vector['entryPath'], $baseReal);
                $resolver = new FilesystemIncludeResolver($rootReal, !empty($options['allowAbsolute']));
                $sourcePath = $options['sourcePath'] ?? '<ENTRY>';
                $currentPath = $sourcePath === '<ENTRY>'
                    ? (string)realpath($base . '/' . (string)$vector['entryPath'])
                    : (string)$sourcePath;
            } else {
                $baseReal = null;
                $entry = (string)$vector['entry'];
                $resolver = ($vector['resolver'] ?? 'none') === 'none'
                    ? null
                    : $this->virtualResolver($vector);
                $currentPath = isset($options['sourcePath']) ? (string)$options['sourcePath'] : null;
            }

            $run = $this->expand($entry, $resolver, $currentPath, $options, $baseReal);

            $out = [
                'html' => $run['html'],
                'fmt' => $this->foldTmp($this->serialize($entry), $baseReal),
                'warnings' => $run['warnings'],
                'dependencies' => $run['dependencies'],
                'rawWarningMessages' => $run['rawWarningMessages'],
            ];

            if (!empty($vector['checkFlattened'])) {
                // The EXPANDED document through the writer - what `flatten`
                // emits. The html golden cannot see the assembled tree's shape:
                // footnotes are collected globally at render time either way.
                $out['flattened'] = $this->foldTmp(
                    CarveConverter::carve()->render(
                        $this->expandDocument($entry, $resolver, $currentPath, $options),
                    ),
                    $baseReal,
                );
            }

            if (!empty($vector['checkCarveTarget'])) {
                // I15, routed through the predicate the CLI reads rather than
                // spelled here. Serializing the entry directly would assert the
                // writer twice and never see the pipeline decision, which is
                // the half this vector exists for.
                $document = IncludeExpander::expandsForFormat('carve')
                    ? $this->expandDocument($entry, $resolver, $currentPath, $options)
                    : (new CarveConverter())->parse($entry);
                $out['carveTarget'] = $this->foldTmp(
                    CarveConverter::carve()->render($document),
                    $baseReal,
                );
            }

            if (!empty($vector['checkFmtExpandEquivalence']) && ($vector['mode'] ?? 'virtual') !== 'filesystem') {
                $formatted = $out['fmt'];
                $frun = $this->expand($formatted, $resolver, $currentPath, $options, $baseReal);
                $out['formattedRun'] = [
                    'html' => $frun['html'],
                    'dependencies' => $frun['dependencies'],
                ];
            }

            return $out;
        } finally {
            if ($base !== null) {
                $this->removeTree($base);
            }
        }
    }

    /**
     * Drive parse -> expand -> render for one source string and normalize the
     * warning / dependency structures to the cross-engine contract.
     *
     * @param string $entry
     * @param \MarkupCarve\Carve\Transform\IncludeResolverInterface|null $resolver
     * @param string|null $currentPath
     * @param array<string, mixed> $options
     * @param string|null $baseReal
     *
     * @return array{html: string, warnings: list<array{rule: string, file?: string}>, dependencies: list<array{id: string, resolved: bool}>, rawWarningMessages: list<string>}
     */

    /**
     * The expanded DOCUMENT for one entry, with the same options `expand()`
     * uses. Split out so the I15 check can ask for the tree rather than the
     * rendered HTML.
     *
     * @param string $entry
     * @param \MarkupCarve\Carve\Transform\IncludeResolverInterface|null $resolver
     * @param string|null $currentPath
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\Node\Document
     */
    protected function expandDocument(
        string $entry,
        ?IncludeResolverInterface $resolver,
        ?string $currentPath,
        array $options,
    ): Document {
        $depthLimit = isset($options['maxDepth']) ? (int)$options['maxDepth'] : 16;
        $byteBudget = isset($options['maxBytes']) ? (int)$options['maxBytes'] : null;

        // POSITIONS ON, like the reference runner: the writer orders collected
        // definitions by source position, so a document parsed without them
        // publishes a merged child's footnote definitions in label order.
        $converter = CarveConverter::carve();
        $expander = new IncludeExpander($resolver, $currentPath, $depthLimit, $byteBudget, $entry);

        return $converter->transform($converter->parse($entry), $expander);
    }

    protected function expand(
        string $entry,
        ?IncludeResolverInterface $resolver,
        ?string $currentPath,
        array $options,
        ?string $baseReal,
    ): array {
        $depthLimit = isset($options['maxDepth']) ? (int)$options['maxDepth'] : 16;
        $byteBudget = isset($options['maxBytes']) ? (int)$options['maxBytes'] : null;

        $converter = CarveConverter::create();
        $document = $converter->parse($entry);
        $expander = new IncludeExpander($resolver, $currentPath, $depthLimit, $byteBudget, $entry);
        $html = $this->foldTmp($converter->render($converter->transform($document, $expander)), $baseReal);

        $warnings = [];
        $rawMessages = [];
        foreach ($expander->getWarnings() as $warning) {
            $entryOut = ['rule' => (string)$warning->getRule()];
            if ($warning->getFile() !== null) {
                $entryOut['file'] = $this->normalizeFsPath($warning->getFile(), $baseReal);
            }
            $warnings[] = $entryOut;
            $rawMessages[] = $warning->getMessage();
        }

        $dependencies = [];
        foreach ($expander->getDependencies() as $dependency) {
            $dependencies[] = [
                'id' => $this->normalizeFsPath($dependency->getTarget(), $baseReal),
                'resolved' => $dependency->isResolved(),
            ];
        }

        return [
            'html' => $html,
            'warnings' => $warnings,
            'dependencies' => $dependencies,
            'rawWarningMessages' => $rawMessages,
        ];
    }

    /**
     * The `fmt` golden is the serializer output of the PRE-expansion document,
     * which is what pins I12/I14 (the directive survives formatting). It is
     * deliberately not the serialization of the expanded document.
     */
    protected function serialize(string $entry): string
    {
        $converter = CarveConverter::carve();

        return $converter->render($converter->parse($entry));
    }

    /**
     * Build the resolver a virtual-mode vector describes: a plain source map, a
     * throwing resolver (I7), or a canonical-id resolver that folds `./x` and
     * `x` to one id (I6/I11 identity).
     *
     * @param array<string, mixed> $vector
     *
     * @throws \RuntimeException
     */
    protected function virtualResolver(array $vector): IncludeResolverInterface
    {
        /** @var array<string, string> $files */
        $files = (array)($vector['files'] ?? []);
        /** @var array<string, mixed> $options */
        $options = (array)($vector['options'] ?? []);

        if (isset($options['resolverThrows'])) {
            $message = (string)$options['resolverThrows'];

            return new class ($message) implements IncludeResolverInterface {
                public function __construct(protected string $message)
                {
                }

                public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null
                {
                    throw new RuntimeException($this->message);
                }
            };
        }

        if (!empty($options['resolverIds'])) {
            return new class ($files) implements IncludeResolverInterface {
                /**
                 * @param array<string, string> $files
                 */
                public function __construct(protected array $files)
                {
                }

                public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null
                {
                    $id = preg_replace('#^\./#', '', $path) ?? $path;

                    return isset($this->files[$id]) ? new ResolvedInclude($this->files[$id], $id) : null;
                }
            };
        }

        return new class ($files) implements IncludeResolverInterface {
            /**
             * @param array<string, string> $files
             */
            public function __construct(protected array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null
            {
                return $this->files[$path] ?? null;
            }
        };
    }

    /**
     * Read the entry file and bind any `<ABS:rel>` sentinel to the real
     * absolute path of a tree file, so the absolute-containment case (I10) is
     * expressed without a machine-specific literal in the committed vector.
     */
    protected function readEntry(string $base, string $entryPath, string $baseReal): string
    {
        $entry = (string)file_get_contents($base . '/' . $entryPath);

        return (string)preg_replace_callback(
            '/<ABS:([^>]+)>/',
            static fn (array $m): string => $baseReal . '/' . $m[1],
            $entry,
        );
    }

    /**
     * Materialize a filesystem vector's tree under a fresh tmp base; the caller
     * removes it. A string value is file content; a `{ "symlink": target }`
     * object is a symbolic link whose target is resolved against the base.
     *
     * @param array<string, mixed> $tree
     */
    protected function materializeTree(array $tree): string
    {
        $base = (string)tempnam(sys_get_temp_dir(), 'carve-ic-');
        unlink($base);
        mkdir($base, 0o777, true);

        $symlinks = [];
        foreach ($tree as $rel => $content) {
            $abs = $base . '/' . $rel;
            if (is_array($content) && isset($content['symlink'])) {
                $symlinks[$abs] = (string)$content['symlink'];

                continue;
            }
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            file_put_contents($abs, (string)$content);
        }

        foreach ($symlinks as $abs => $target) {
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            symlink($base . '/' . $target, $abs);
        }

        return $base;
    }

    protected function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * Fold a path/id under the materialized tree base to the `<TMP>` sentinel
     * with forward slashes. Non-absolute values (a directive path as written)
     * are returned untouched. Mirrors the reference driver's normalizeFsPath.
     */
    protected function normalizeFsPath(?string $value, ?string $baseReal): ?string
    {
        if ($value === null || $baseReal === null) {
            return $value;
        }
        if ($value === $baseReal) {
            return '<TMP>';
        }
        if (str_starts_with($value, $baseReal . '/')) {
            return '<TMP>/' . substr($value, strlen($baseReal) + 1);
        }

        return $value;
    }

    /**
     * Fold every occurrence of the tree base inside a larger string (html / fmt
     * that embeds a real absolute path). A no-op outside filesystem mode.
     */
    protected function foldTmp(string $text, ?string $baseReal): string
    {
        if ($baseReal === null) {
            return $text;
        }

        return str_replace($baseReal, '<TMP>', $text);
    }

    /**
     * Fold trailing per-line whitespace and a trailing newline, so php's
     * newline-terminated HTML compares equal to the reference `renderHtml`
     * output the goldens carry. The same normalization the HTML corpus runner
     * uses, applied to BOTH sides.
     */
    protected function normalizeHtml(string $html): string
    {
        $html = (string)preg_replace('/[ \t]+$/m', '', $html);

        return rtrim($html, "\n");
    }

    protected static function vectorDir(): string
    {
        return __DIR__ . '/../../spec/tests/include-conformance/vectors';
    }
}
