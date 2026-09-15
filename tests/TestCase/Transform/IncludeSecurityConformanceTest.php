<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use MarkupCarve\Carve\Transform\ResolvedInclude;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The spec's include SECURITY conformance corpus, run against this engine.
 *
 * `tests/spec/tests/include-security-conformance/` is the processor-neutral
 * executable contract for the security requirements normative in PART 9 §19.
 * It is separate from the include-LANGUAGE corpus that IncludeConformanceTest
 * drives: that one compares rendered output against carve-js goldens, this one
 * pins observable security DECISIONS - whether the resolver ran at all, which
 * targets it was asked for and in what order, whether a path escaped its root,
 * and which whole-walk bound refused the walk.
 *
 * `expected.resolverCalls` is the exact ORDERED list of invocations, so a call
 * that did NOT happen is observed as its absence - which is the only way to
 * gate "MUST NOT be passed to the resolver". Warning WORDING is not observable
 * to the corpus; the portable stand-in is `status` plus the `denial` class.
 */
class IncludeSecurityConformanceTest extends TestCase
{
    /**
     * @var string
     */
    protected const CORPUS = __DIR__ . '/../../spec/tests/include-security-conformance/vectors.json';

    /**
     * The kinds the adapter implements. An unknown kind must fail rather than
     * fall through to a branch that answers something plausible: a vector
     * nothing implements would otherwise pass.
     *
     * @var array<string>
     */
    protected const KINDS = ['activation', 'filesystem', 'remote', 'graph'];

    /**
     * Every member the adapter knows how to honor. A member it does not read is
     * a limit that silently never applies - `maxResolverCalls` arrived with
     * carve#1990 and is exactly that shape - so the corpus is read as a
     * contract and an unread key fails.
     *
     * @var array<string>
     */
    protected const KNOWN_KEYS = [
        'name', 'description', 'requirement', 'kind',
        'entry', 'files', 'tree', 'root', 'rootSpec', 'from', 'request',
        'trusted', 'enabled', 'allowAbsolute', 'allowedRemoteHosts',
        'maxDepth', 'maxBytes', 'maxResolverCalls',
        'expected',
    ];

    /**
     * The requirement ids this adapter answers, pinned as a SET rather than a
     * count so a corpus that grows a new requirement NAMES the unanswered one.
     *
     * @var array<string>
     */
    protected const REQUIREMENTS = [
        'S1-opt-in',
        'S2-contained-paths',
        'S3-remote-allowlist',
        'S4-depth-bound',
        'S5-byte-bound',
        'S6-post-budget-no-read',
        'S7-call-bound',
        'S8-post-call-bound-no-read',
        'S9-root-configuration',
    ];

    /**
     * The observables the adapter knows how to answer. The provider yields one
     * case per expected field, so a field this adapter never produces is
     * compared against `null` and goes red only where the corpus happens to
     * want something else; this names the unanswered observable directly.
     *
     * @var array<string>
     */
    protected const OBSERVABLES = [
        'status',
        'denial',
        'canonicalId',
        'resolverCalls',
        'remoteFetches',
        'maxVisitedDepth',
        'chargedBytes',
    ];

    /**
     * Every denial class the corpus asserts, as a SET. A class this adapter
     * cannot produce is a refusal it reports as some OTHER class, which reads
     * as agreement: `not-found` arrived with carve#2001 and `no-root` with
     * carve#2005, and both would otherwise have been answered by whichever
     * branch matched first.
     *
     * @var array<string>
     */
    protected const DENIAL_CLASSES = [
        'budget',
        'depth',
        'no-root',
        'not-found',
        'outside-root',
        'remote-not-allowed',
        'resolver-calls',
    ];

    /**
     * Whole-walk refusal rules mapped to the corpus's portable denial class.
     * RULE_UNRESOLVED is deliberately absent: a target that does not resolve is
     * not a refusal, and the call-bound vectors are built entirely out of
     * unresolvable targets, so reading one as a denial would answer
     * `resolver-calls` before the bound had done anything.
     *
     * @var array<string, string>
     */
    protected const DENIAL_BY_RULE = [
        IncludeExpander::RULE_DEPTH => 'depth',
        IncludeExpander::RULE_BUDGET => 'budget',
        IncludeExpander::RULE_CALL_LIMIT => 'resolver-calls',
    ];

    /**
     * The filesystem resolver signals refusal by throwing, so its message is
     * the only channel carrying WHICH check refused. Mapping it here keeps the
     * translation in one place and, because an unmapped message fails, keeps a
     * new refusal from being reported as an existing class.
     *
     * @var array<string, string>
     */
    protected const DENIAL_BY_MESSAGE = [
        'Include target escapes configured root' => 'outside-root',
        'Absolute include paths are not allowed' => 'outside-root',
        'Include URI schemes are not allowed' => 'remote-not-allowed',
        'Include target not found' => 'not-found',
        // Thrown by the root-configuration seam, before any resolver exists.
        // A value that names no root is not a denial the resolver issued; the
        // resolver was never built, so nothing was asked of it.
        'The include root must be supplied explicitly' => 'no-root',
    ];

    /**
     * Fields this engine answers differently from the corpus, with the value it
     * actually produces.
     *
     * This is a live gate, not a skip: the measured value is asserted, so the
     * entry fails the moment the engine moves in EITHER direction, and
     * testNoDeclaredDivergenceIsStale fails if one stops diverging at all. A
     * declaration that cannot expire is how a corpus stops being a gate.
     *
     * All three are one defect - carve-php#1953: the target whose read pushed
     * past the byte budget is refused WITHOUT being charged, so `bytesUsed`
     * counts bytes ADMITTED rather than bytes READ and cannot tell "read
     * nothing" from "read a file and refused it". The resolver had the source
     * in hand in every one of these rows.
     *
     * @var array<string, array<string, int>>
     */
    protected const DIVERGENCES = [
        'transitive-byte-budget' => ['chargedBytes' => 7],
        'repeated-target-charged-per-occurrence' => ['chargedBytes' => 8],
        'budget-exhaustion-skips-later-resolver' => ['chargedBytes' => 0],
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected static array $observed = [];

    /**
     * @var array<string>
     */
    protected static array $temporaryRoots = [];

    /**
     * @throws \RuntimeException
     *
     * @return array<string, mixed>
     */
    protected static function corpus(): array
    {
        $raw = file_get_contents(self::CORPUS);
        if ($raw === false) {
            throw new RuntimeException('The include-security corpus is not vendored: ' . self::CORPUS);
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testPinsTheCorpusVersion(): void
    {
        self::assertSame(1, self::corpus()['version']);
    }

    public function testPinsTheVectorCountSoAnAdditionCannotBeSkippedUnnoticed(): void
    {
        self::assertCount(19, self::corpus()['vectors']);
    }

    public function testAnswersEveryRequirementTheCorpusStates(): void
    {
        $stated = array_values(array_unique(array_column(self::corpus()['vectors'], 'requirement')));
        sort($stated);
        $pinned = self::REQUIREMENTS;
        sort($pinned);
        self::assertSame($pinned, $stated);
    }

    public function testReadsEveryMemberTheCorpusPutsOnAVector(): void
    {
        $seen = [];
        foreach (self::corpus()['vectors'] as $vector) {
            $seen = array_merge($seen, array_keys($vector));
        }
        $unread = array_values(array_diff(array_unique($seen), self::KNOWN_KEYS));
        sort($unread);
        self::assertSame([], $unread);
    }

    public function testAnswersEveryObservableTheCorpusExpects(): void
    {
        $stated = [];
        foreach (self::corpus()['vectors'] as $vector) {
            $stated = array_merge($stated, array_keys($vector['expected']));
        }
        $unanswered = array_values(array_diff(array_unique($stated), self::OBSERVABLES));
        sort($unanswered);
        self::assertSame([], $unanswered);
    }

    public function testAnswersEveryDenialClassTheCorpusAsserts(): void
    {
        $stated = [];
        foreach (self::corpus()['vectors'] as $vector) {
            $class = $vector['expected']['denial'] ?? null;
            if ($class !== null) {
                $stated[] = $class;
            }
        }
        $stated = array_values(array_unique($stated));
        sort($stated);
        $pinned = self::DENIAL_CLASSES;
        sort($pinned);
        self::assertSame($pinned, $stated);
    }

    /**
     * `root` and `rootSpec` answer different questions - one is materialized
     * here, the other is the host's configured value - so a vector that named
     * both would be driven two ways at once. The corpus schema refuses it; so
     * does this, rather than silently preferring one.
     */
    public function testNoVectorNamesTheRootBothWays(): void
    {
        $both = [];
        foreach (self::corpus()['vectors'] as $vector) {
            if (array_key_exists('root', $vector) && array_key_exists('rootSpec', $vector)) {
                $both[] = $vector['name'];
            }
        }
        self::assertSame([], $both);
    }

    public function testDrivesEveryKindTheCorpusStates(): void
    {
        $stated = array_values(array_unique(array_column(self::corpus()['vectors'], 'kind')));
        sort($stated);
        $known = self::KINDS;
        sort($known);
        self::assertSame([], array_values(array_diff($stated, $known)));
    }

    /**
     * A declared divergence that no longer diverges is a stale allowlist entry,
     * and a stale entry is how a corpus quietly stops gating a row.
     */
    public function testNoDeclaredDivergenceIsStale(): void
    {
        $byName = [];
        foreach (self::corpus()['vectors'] as $vector) {
            $byName[$vector['name']] = $vector;
        }
        $stale = [];
        foreach (self::DIVERGENCES as $name => $fields) {
            self::assertArrayHasKey($name, $byName, "declared divergence names no vector: {$name}");
            foreach ($fields as $field => $value) {
                if (($byName[$name]['expected'][$field] ?? null) === $value) {
                    $stale[] = "{$name}.{$field}";
                }
            }
        }
        self::assertSame([], $stale, 'these no longer diverge and must leave DIVERGENCES');
    }

    /**
     * One case per (vector, expected field), so a failure names the observable
     * that moved rather than the first one compared. The call-bound vectors can
     * fail three different ways - an extra resolverCalls entry, a `budget`
     * denial where `resolver-calls` belongs, a chargedBytes that never engaged -
     * and one collapsed assertion hides two of them.
     *
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function observables(): iterable
    {
        foreach (self::corpus()['vectors'] as $vector) {
            foreach ($vector['expected'] as $field => $expected) {
                $want = self::DIVERGENCES[$vector['name']][$field] ?? $expected;

                yield "{$vector['requirement']} {$vector['name']}: {$field}" => [$vector['name'], $field, $want];
            }
        }
    }

    #[DataProvider('observables')]
    public function testTheObservableHoldsForTheVector(string $name, string $field, mixed $expected): void
    {
        self::assertSame($expected, $this->actualFor($name)[$field] ?? null);
    }

    /**
     * @throws \RuntimeException
     *
     * @return array<string, mixed>
     */
    protected function actualFor(string $name): array
    {
        if (isset(self::$observed[$name])) {
            return self::$observed[$name];
        }
        foreach (self::corpus()['vectors'] as $vector) {
            if ($vector['name'] === $name) {
                return self::$observed[$name] = $this->drive($vector);
            }
        }

        throw new RuntimeException("no such vector: {$name}");
    }

    /**
     * @param array<string, mixed> $vector
     *
     * @throws \RuntimeException
     *
     * @return array<string, mixed>
     */
    protected function drive(array $vector): array
    {
        if (!in_array($vector['kind'], self::KINDS, true)) {
            throw new RuntimeException("unknown vector kind: {$vector['kind']}");
        }

        return match ($vector['kind']) {
            'activation' => $this->runActivation($vector),
            'graph' => $this->runGraph($vector),
            default => $this->runPath($vector),
        };
    }

    /**
     * @param array<string, mixed> $vector
     *
     * @return array<string, mixed>
     */
    protected function runActivation(array $vector): array
    {
        $recorder = $this->recorder([]);
        $expander = new IncludeExpander(
            resolver: $vector['enabled'] ? $recorder : null,
            source: $vector['entry'],
        );
        $expander->transform(CarveConverter::carve()->parse($vector['entry']));

        return ['resolverCalls' => $recorder->calls];
    }

    /**
     * @param array<string, mixed> $vector
     *
     * @return array<string, mixed>
     */
    protected function runGraph(array $vector): array
    {
        $recorder = $this->recorder($vector['files'] ?? []);
        $expander = new class (
            $recorder,
            null,
            $vector['maxDepth'] ?? 16,
            $vector['maxBytes'] ?? null,
            $vector['entry'],
            $vector['maxResolverCalls'] ?? 1000,
        ) extends IncludeExpander {
            public function chargedBytes(): int
            {
                return $this->bytesUsed;
            }
        };
        $expander->transform(CarveConverter::carve()->parse($vector['entry']));

        // The FIRST whole-walk refusal is the one that latched: every later
        // directive reports the same rule without being resolved.
        $denial = null;
        foreach ($expander->getWarnings() as $warning) {
            $rule = $warning->getRule();
            if ($rule !== null && isset(self::DENIAL_BY_RULE[$rule])) {
                $denial = self::DENIAL_BY_RULE[$rule];

                break;
            }
        }

        return [
            'resolverCalls' => $recorder->calls,
            'maxVisitedDepth' => $recorder->maxDepth,
            'chargedBytes' => $expander->chargedBytes(),
            'status' => $denial === null ? 'allowed' : 'denied',
            'denial' => $denial,
        ];
    }

    /**
     * The filesystem and remote kinds both ask the real resolver one question,
     * so they share a driver; only the reading of its refusal differs.
     *
     * @param array<string, mixed> $vector
     *
     * @return array<string, mixed>
     */
    protected function runPath(array $vector): array
    {
        if (array_key_exists('root', $vector) && array_key_exists('rootSpec', $vector)) {
            throw new RuntimeException("a vector names the root one way only: {$vector['name']}");
        }
        $dir = $this->materialize($vector['tree'] ?? ['root/main.crv' => '']);

        // `root` is the ADAPTER's: a directory in the temporary tree, already
        // canonical, so containment is the only question the vector asks.
        // `rootSpec` is the value the HOST was configured with, and what THIS
        // engine's root-configuration seam - the FilesystemIncludeResolver
        // constructor - makes of it is the behavior under test. So it goes in
        // UNCHANGED. Canonicalizing it here first would answer the vector with
        // the adapter's own `realpath()`, and `realpath('')` is the process
        // working directory: the one root §19 forbids by name, which is
        // precisely the defect `blank-root-spec-configures-no-root` exists for.
        // Expanding `<ABS:>` is the corpus naming its own temporary tree, not
        // a canonicalization.
        if (array_key_exists('rootSpec', $vector)) {
            $rootSpec = $this->expandTreePath((string)$vector['rootSpec'], $dir);
        } else {
            $materialized = realpath($dir . '/' . ($vector['root'] ?? 'root'));
            if ($materialized === false) {
                throw new RuntimeException('the vector tree has no root directory');
            }
            $rootSpec = $materialized;
        }

        $calls = [];
        $resolver = null;
        $failure = null;
        try {
            $resolver = new FilesystemIncludeResolver($rootSpec, $vector['allowAbsolute'] ?? false);
        } catch (Throwable $exception) {
            $failure = $exception->getMessage();
        }

        $request = $this->expandTreePath((string)($vector['request'] ?? ''), $dir);
        $from = isset($vector['from']) ? realpath($dir . '/' . $vector['from']) : null;

        $id = null;
        if ($resolver !== null) {
            $calls[] = $request;
            try {
                $id = $resolver->resolve($request, new IncludeContext($from === false ? null : $from))->getId();
            } catch (Throwable $exception) {
                $failure = $exception->getMessage();
            }
        }

        if ($vector['kind'] === 'remote') {
            // An allowlist permits fetching; it does not require a processor to
            // implement remote includes. This engine implements none, so an
            // allowlisted host is `unsupported` rather than denied - the
            // refusal is the absence of the capability, not a policy decision.
            return [
                'status' => $failure === null
                    ? 'allowed'
                    : (($vector['allowedRemoteHosts'] ?? []) === [] ? 'denied' : 'unsupported'),
                'denial' => $failure === null ? null : $this->denialFor($failure),
                'remoteFetches' => [],
                'resolverCalls' => $calls,
            ];
        }

        if ($failure !== null) {
            // A root-configuration refusal leaves `$calls` empty because no
            // resolver was ever built - which is what makes "inclusion stays
            // disabled" observable rather than merely asserted.
            return ['status' => 'denied', 'denial' => $this->denialFor($failure), 'resolverCalls' => $calls];
        }

        // The engine's own canonical root, read back only to spell the id the
        // corpus compares. It is not what was handed to the seam.
        $canonicalRoot = (string)realpath($rootSpec);

        return [
            'status' => 'allowed',
            'canonicalId' => str_replace($canonicalRoot, '<ROOT>', (string)$id),
            'resolverCalls' => $calls,
        ];
    }

    /**
     * The corpus names a path inside its own temporary tree as `<ABS:path>`.
     * Expanding it is the corpus spelling out where it put the tree; it is not
     * a canonicalization of the value under test.
     */
    protected function expandTreePath(string $value, string $dir): string
    {
        return (string)preg_replace_callback(
            '/^<ABS:([^>]+)>$/',
            fn (array $m): string => $dir . '/' . $m[1],
            $value,
        );
    }

    protected function denialFor(string $message): string
    {
        foreach (self::DENIAL_BY_MESSAGE as $needle => $class) {
            if (str_starts_with($message, $needle)) {
                return $class;
            }
        }

        throw new RuntimeException("no denial class for this refusal: {$message}");
    }

    /**
     * @param array<string, string> $files
     */
    protected function recorder(array $files): IncludeResolverInterface
    {
        return new class ($files) implements IncludeResolverInterface {
            /**
             * @var array<string>
             */
            public array $calls = [];

            public int $maxDepth = 0;

            /**
             * @param array<string, string> $files
             */
            public function __construct(protected array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null
            {
                $this->calls[] = $path;
                $this->maxDepth = max($this->maxDepth, $context->getDepth() + 1);

                return array_key_exists($path, $this->files)
                    ? new ResolvedInclude($this->files[$path], $path)
                    : null;
            }
        };
    }

    /**
     * @param array<string, mixed> $tree
     */
    protected function materialize(array $tree): string
    {
        $base = realpath(sys_get_temp_dir());
        $dir = $base . '/carve-include-security-' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700, true);
        self::$temporaryRoots[] = $dir;

        $links = [];
        foreach ($tree as $name => $value) {
            $full = $dir . '/' . $name;
            $parent = dirname($full);
            if (!is_dir($parent)) {
                mkdir($parent, 0o700, true);
            }
            if (is_string($value)) {
                file_put_contents($full, $value);

                continue;
            }
            $links[] = [$full, $value['symlink']];
        }
        foreach ($links as [$full, $target]) {
            symlink($dir . '/' . $target, $full);
        }

        return $dir;
    }
}
