<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\AutolinkExtension;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Extension\CodeCalloutsExtension;
use MarkupCarve\Carve\Extension\DetailsExtension;
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Extension\ListTableExtension;
use MarkupCarve\Carve\Extension\MentionsExtension;
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use MarkupCarve\Carve\Extension\SmartQuotesExtension;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\RendererInterface;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function array_keys;
use function basename;
use function class_exists;
use function file_get_contents;
use function implode;
use function json_decode;
use function sort;
use function str_replace;
use function ucwords;

#[Group('optional-corpus')]
class OptionalCorpusTest extends TestCase
{
    /**
     * Expected-file extension per target, and how to render it.
     *
     * A case pins the HTML target unless its manifest entry names another one
     * (carve#360). The extension is the pairing rule rather than a label: a
     * case is located from its slug and its target alone.
     *
     * `carve` is absent by design - Carve-source expectations live in the
     * spec's corpus-roundtrip, which has its own runner.
     *
     * @var array<string, array{extension: string, renderer: ?class-string<\MarkupCarve\Carve\Renderer\RendererInterface>}>
     */
    private const TARGETS = [
        // Null renderer: the converter builds the HTML renderer itself, which
        // is also what carries the symbol map and safe-mode configuration.
        'html' => ['extension' => 'html', 'renderer' => null],
        'markdown' => ['extension' => 'md', 'renderer' => MarkdownRenderer::class],
        'plain' => ['extension' => 'txt', 'renderer' => PlainTextRenderer::class],
        'ansi' => ['extension' => 'ansi', 'renderer' => AnsiRenderer::class],
    ];

    /**
     * Optional-corpus cases the pinned corpus states and this engine does not
     * yet produce, keyed `slug` or `slug (target)`.
     *
     * Same contract as CarveCorpusTest::KNOWN_GAPS: a deferral names the rule
     * it waits on, so the list reads as work rather than as noise.
     *
     * @var array<string, string>
     */
    protected const KNOWN_GAPS = [
        '44-list-table-columns-and-foot (html)' => 'carve#1344: a list-table `<tfoot>` breaks its rows onto their own lines; this engine emits the row inline',
    ];

    /**
     * Features this engine genuinely does not implement, each with the reason.
     *
     * A skip listed here is a statement about the ENGINE. A skip not listed
     * here would be a statement about THIS FILE, and fails instead - which is
     * the whole of #1517: the missing-runner branch used to be a bare
     * `default => null` reaching `markTestSkipped()`, so six features and nine
     * of the forty-five cases never ran and the file still reported a clean
     * pass. All nine matched their committed fixture the first time a runner
     * was written for them, so nine expected files were being verified by
     * nothing.
     *
     * Empty is the correct state. An entry here silences a comparison whether
     * or not the engine would have passed it, so one goes in only with the
     * reason it cannot be a runner instead.
     *
     * @var array<string, string>
     */
    protected const DECLARED_UNIMPLEMENTED = [];

    /**
     * Cases this engine has DELIBERATELY moved PAST the pinned corpus on - the
     * same window CarveCorpusTest keeps for the core corpus, and the mirror of
     * the spec repo's `resources/engine-pin-drift.txt`. A rule that lands here
     * between two pin bumps leaves the fixture behind by design.
     *
     * Keyed by slug. Each entry FAILS IN BOTH DIRECTIONS:
     *
     *  - the output must equal what this engine now states, so a regression is
     *    caught exactly as the corpus would have caught it;
     *  - and it must still DIFFER from the pinned fixture, so an entry the pin
     *    has caught up on fails and is deleted in the commit that moves the pin.
     *
     * @var array<string, array{reason: string, expected: string}>
     */
    protected const AHEAD_OF_PIN = [
        '28-tabs-panel-title' => [
            'reason' => 'markup-carve/carve#1468: the tab set names itself; the pinned corpus predates it and spec main already states the named form',
            'expected' => "<div class=\"tabs\" role=\"group\" aria-label=\"Tabs\">\n"
                . "<input type=\"radio\" name=\"tabset-1\" id=\"tabset-1-tab-1\" class=\"tabs-radio\" checked>\n"
                . "<label for=\"tabset-1-tab-1\" class=\"tabs-label\">First</label>\n"
                . "<div class=\"tabs-panel\">\n"
                . "<p class=\"admonition-title\">Inner <strong>Title</strong></p>\n"
                . "<p>Content one.</p>\n"
                . "</div>\n"
                . "</div>\n",
        ],
    ];

    /**
     * How each feature the manifest states is configured on this engine.
     *
     * One entry serves a feature pinned on more than one target: the closure
     * receives the target's renderer (null for HTML, where the converter builds
     * its own) and the target name, and answers with the converter to run.
     *
     * The configurations are the ones the spec's own runner uses
     * (`spec/tests/optional-corpus.test.mjs`), deliberately spelled the same
     * way, because a feature id means one thing and two files disagreeing about
     * what it configures is a divergence nothing would report.
     *
     * A closure MAY answer null, and that means one thing only: the feature is
     * confined to another target, so the case is a corpus error rather than an
     * unsupported feature. The test fails on it - it never skips.
     *
     * @return array<string, \Closure(?\MarkupCarve\Carve\Renderer\RendererInterface): ?\MarkupCarve\Carve\CarveConverter>
     */
    protected static function featureRunners(): array
    {
        return [
            'social-link-templates' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new MentionsExtension(mentionUrl: '/users/{name}', tagUrl: '/topics/{name}')),
            'smart-quotes-locale-de' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new SmartQuotesExtension(locale: 'de')),
            'bare-url-autolink' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new AutolinkExtension()),
            'citations-numbered' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new CitationsExtension()),
            'citations-author-date' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new CitationsExtension('author-date')),
            'code-callouts' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new CodeCalloutsExtension()),
            'details' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new DetailsExtension()),
            'list-table' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new ListTableExtension()),
            'list-table-columns-1344' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new ListTableExtension()),
            'list-table-local-headers-1248' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new ListTableExtension()),
            'semantic-span' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new SemanticSpanExtension()),
            'spoiler' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new SpoilerExtension()),
            'tabs' => static fn (?RendererInterface $r): CarveConverter => self::withExtension($r, new TabsExtension()),
            // The map is consumed by the HTML renderer, so on another target it
            // reaches nothing - which is what the Markdown case asserts: a
            // symbol keeps its `:name:` source spelling there.
            'symbol-map' => static function (?RendererInterface $r): CarveConverter {
                return new CarveConverter(renderer: $r, symbols: [
                    'rocket' => "\u{1F680}",
                    'tada' => "\u{1F389}",
                    '+1' => "\u{1F44D}",
                    'UPPER' => "\u{2B06}\u{FE0F}",
                ]);
            },
            /*
             * Features that are a RENDER OPTION rather than an extension: no
             * instance to pass, just the switch. They live in the same table so
             * that an engine without the option shows up as a case nobody
             * compared, rather than silently passing on differently-configured
             * output.
             *
             * PART 9 §8 confines the typography-source ones to one target each,
             * because the mode is a RENDERER setting: a case named for one
             * target must not quietly pass on another. Answering null there is
             * a corpus error the test reports, not a skip.
             */
            'markdown-typography-source' => static function (?RendererInterface $r): ?CarveConverter {
                return $r instanceof MarkdownRenderer
                    ? new CarveConverter(renderer: $r->setSmartTypography(SmartTypographyMode::Source))
                    : null;
            },
            // The plain-text and ANSI targets carry the mode too (carve#560).
            'plain-typography-source' => static function (?RendererInterface $r): ?CarveConverter {
                return $r instanceof PlainTextRenderer
                    ? new CarveConverter(renderer: $r->setSmartTypography(SmartTypographyMode::Source))
                    : null;
            },
            'ansi-typography-source' => static function (?RendererInterface $r): ?CarveConverter {
                return $r instanceof AnsiRenderer
                    ? new CarveConverter(renderer: $r->setSmartTypography(SmartTypographyMode::Source))
                    : null;
            },
            'smart-typography-off' => static fn (?RendererInterface $r): CarveConverter => new CarveConverter(renderer: $r, smartTypography: false),
            // The DEFAULT mode, named as a feature so a case can pin it. Its
            // job is to be the control a source-mode case needs: without it a
            // source-mode expectation also passes an engine that never applies
            // typography to that construct in either mode.
            'smart-typography-default' => static fn (?RendererInterface $r): CarveConverter => new CarveConverter(renderer: $r),
            /*
             * Section wrapping is on by default here (PART 9 §13) and is a
             * property of the HTML renderer, so switching it off means building
             * that renderer rather than letting the converter build one - which
             * confines both of these to the HTML target.
             */
            'section-wrapper-off' => static function (?RendererInterface $r): ?CarveConverter {
                return $r === null
                    ? new CarveConverter(renderer: (new HtmlRenderer())->setSectionWrapping(false))
                    : null;
            },
            'source-line-after-generated-id' => static function (?RendererInterface $r): ?CarveConverter {
                return $r === null
                    ? new CarveConverter(
                        renderer: (new HtmlRenderer())->setSectionWrapping(false),
                        sourceLines: true,
                    )
                    : null;
            },
        ];
    }

    protected static function withExtension(?RendererInterface $renderer, ExtensionInterface $extension): CarveConverter
    {
        return (new CarveConverter(renderer: $renderer))->addExtension($extension);
    }

    /**
     * @return array<string, array{slug: string, feature: string, target: string, crv: string, expected: ?string, disposition: string}>
     */
    public static function optionalCorpusProvider(): array
    {
        $dir = self::corpusDir();
        $cases = [];

        foreach (self::manifestCases() as $entry) {
            $slug = basename($entry['slug']);
            $target = $entry['target'] ?? 'html';
            $crvPath = $dir . '/' . $slug . '.crv';

            $extension = self::TARGETS[$target]['extension'] ?? null;
            $expectedPath = $extension === null ? null : $dir . '/' . $slug . '.' . $extension;

            // A missing pair is reported by the test rather than dropped here.
            // Skipping it in the provider made the case vanish from the run
            // entirely, so a corpus this runner cannot pair looked the same as
            // one that passed.
            $cases[$slug . ' (' . $target . ')'] = [
                'slug' => $slug,
                'feature' => $entry['feature'],
                'target' => $target,
                'crv' => file_exists($crvPath) ? (string)file_get_contents($crvPath) : null,
                'expected' => $expectedPath !== null && file_exists($expectedPath)
                    ? (string)file_get_contents($expectedPath)
                    : null,
                'disposition' => self::dispositionFor($slug, $entry['feature'], $target),
            ];
        }

        return $cases;
    }

    /**
     * What the run will DO with a case, decided once and read by both the case
     * itself and the reconciliation below.
     *
     * Every disposition but `compare`, `known-gap`, `declared` and `ahead` is a
     * case nobody compares, which is why the identity counts only those four:
     * `undeclared` and `unknown-target` are reached and checked by nobody, and
     * the arithmetic is what says so.
     */
    protected static function dispositionFor(string $slug, string $feature, string $target): string
    {
        // The target is checked FIRST, and deliberately: a slug-only KNOWN_GAPS
        // key matches whatever target the manifest later moves that case to, so
        // deferring to the gap here would let a corpus error read as a known
        // gap and still reconcile - the exact shape of concealment #1517 is
        // about.
        if (!isset(self::TARGETS[$target])) {
            return 'unknown-target';
        }

        foreach ([$slug . ' (' . $target . ')', $slug] as $key) {
            if (isset(self::KNOWN_GAPS[$key])) {
                return 'known-gap';
            }
        }

        if (!isset(self::featureRunners()[$feature])) {
            return isset(self::DECLARED_UNIMPLEMENTED[$feature]) ? 'declared' : 'undeclared';
        }

        return isset(self::AHEAD_OF_PIN[$slug]) ? 'ahead' : 'compare';
    }

    #[DataProvider('optionalCorpusProvider')]
    public function testOptionalCorpus(
        string $slug,
        string $feature,
        string $target,
        ?string $crv,
        ?string $expected,
        string $disposition,
    ): void {
        // An unknown target is a corpus error, not an unsupported feature:
        // skipping it would read as "carve-php does not do that yet". It is
        // asserted before any deferral, so nothing here can mask it.
        $this->assertArrayHasKey(
            $target,
            self::TARGETS,
            "Unknown target '{$target}' for {$slug} - expected one of " . implode(', ', array_keys(self::TARGETS)),
        );

        if ($disposition === 'known-gap') {
            foreach ([$slug . ' (' . $target . ')', $slug] as $key) {
                if (isset(self::KNOWN_GAPS[$key])) {
                    $this->markTestIncomplete(self::KNOWN_GAPS[$key]);
                }
            }
        }

        if ($disposition === 'declared') {
            $this->markTestSkipped(
                'Optional Tier-2 feature not supported by carve-php: '
                . $feature . ' - ' . self::DECLARED_UNIMPLEMENTED[$feature],
            );
        }

        // THE POINT OF #1517. A feature with no runner used to reach
        // markTestSkipped() with a message about the ENGINE, and a suite that
        // compared thirty-six of forty-five cases exited 0. Now it fails, and
        // the only way to make it stop is to write the runner or to say, in
        // DECLARED_UNIMPLEMENTED, why this engine cannot have one.
        $this->assertNotSame(
            'undeclared',
            $disposition,
            "No runner for '{$feature}' and no entry in DECLARED_UNIMPLEMENTED. Either write the "
            . 'runner, or say why this engine cannot do it - an undeclared skip reads as coverage.',
        );

        $renderer = $this->rendererForTarget($target);
        $converter = self::featureRunners()[$feature]($renderer);

        // Null is confinement, not absence: the feature exists and this is the
        // wrong target for it, so the manifest entry is what is wrong.
        $this->assertNotNull(
            $converter,
            "Feature '{$feature}' is confined to another target and the manifest pins {$slug} on "
            . "'{$target}'",
        );

        $this->assertNotNull($crv, "Missing {$slug}.crv pair");
        $this->assertNotNull(
            $expected,
            "Missing {$slug}." . self::TARGETS[$target]['extension'] . ' pair',
        );

        $actual = $this->normalize($converter->convert($crv));

        if ($disposition === 'ahead') {
            $ahead = self::AHEAD_OF_PIN[$slug];
            $this->assertSame($this->normalize($ahead['expected']), $actual, $ahead['reason']);
            // The staleness half: when the pin moves past this rule the fixture
            // is rewritten to exactly this value, and the entry must be deleted.
            $this->assertNotSame(
                $this->normalize($ahead['expected']),
                $this->normalize($expected),
                "{$slug} now matches the pinned corpus: delete its AHEAD_OF_PIN entry",
            );

            return;
        }

        $this->assertSame(
            $this->normalize($expected),
            $actual,
            'Optional Tier-2 corpus mismatch for ' . $slug,
        );
    }

    /**
     * THE RATCHET ON THE EXCUSE, because a DECLARED_UNIMPLEMENTED entry can
     * only ever turn a comparison into a skip. The condition such an entry
     * carries is usually checkable, so it is checked: a feature this file has a
     * runner for, or whose extension class this build ships, is implemented,
     * whatever the map says. A feature that is a render option rather than an
     * extension ships no class and passes here - correct, because an option's
     * absence is not something a class name can report.
     */
    public function testDeclaredUnimplementedNamesNoFeatureThisBuildSupports(): void
    {
        $runners = self::featureRunners();
        $stale = [];

        foreach (array_keys(self::DECLARED_UNIMPLEMENTED) as $feature) {
            $class = 'MarkupCarve\\Carve\\Extension\\'
                . str_replace(' ', '', ucwords(str_replace('-', ' ', $feature)))
                . 'Extension';
            if (isset($runners[$feature]) || class_exists($class)) {
                $stale[] = $feature;
            }
        }

        sort($stale);
        $this->assertSame(
            [],
            $stale,
            'this build supports the feature now - give it a runner in featureRunners() and delete '
            . 'its DECLARED_UNIMPLEMENTED entry',
        );
    }

    /**
     * An entry naming nothing the manifest states excuses and asserts nothing
     * while still reading as live - renamed upstream, or already retired.
     */
    public function testDeclarationsNameOnlyCasesTheManifestStates(): void
    {
        $features = [];
        $slugs = [];
        $keys = [];

        foreach (self::manifestCases() as $entry) {
            $slug = basename($entry['slug']);
            $features[$entry['feature']] = true;
            $slugs[$slug] = true;
            $keys[$slug] = true;
            $keys[$slug . ' (' . ($entry['target'] ?? 'html') . ')'] = true;
        }

        $orphans = [];

        foreach (array_keys(self::DECLARED_UNIMPLEMENTED) as $feature) {
            if (!isset($features[$feature])) {
                $orphans[] = 'DECLARED_UNIMPLEMENTED: ' . $feature;
            }
        }

        foreach (array_keys(self::AHEAD_OF_PIN) as $slug) {
            if (!isset($slugs[$slug])) {
                $orphans[] = 'AHEAD_OF_PIN: ' . $slug;
            }
        }

        foreach (array_keys(self::KNOWN_GAPS) as $key) {
            if (!isset($keys[$key])) {
                $orphans[] = 'KNOWN_GAPS: ' . $key;
            }
        }

        sort($orphans);
        $this->assertSame([], $orphans, 'renamed upstream, or already retired - either way the entry asserts nothing');
    }

    /**
     * The floor is what a manifest emptied or halved cannot get past. It sits
     * under the count today for the same reason the other floors in this repo
     * do: the optional corpus is append-only, so a number below it can only be
     * reached by loss.
     */
    public function testRegistersAtLeastTheFloorOfCases(): void
    {
        $compared = 0;

        foreach (self::optionalCorpusProvider() as $case) {
            if ($case['disposition'] === 'compare') {
                $compared++;
            }
        }

        $this->assertGreaterThanOrEqual(
            35,
            $compared,
            'spec/tests/corpus-optional/manifest.json is the population; a run over fewer of it '
            . 'registers fewer tests and still exits 0',
        );
    }

    /**
     * And a floor cannot see a case the run REACHED and dropped, which is the
     * hole #1517 came through. Every case the manifest states either compares,
     * or is one of the declarations above - stated as an IDENTITY, not a floor,
     * so the two sides cannot drift.
     */
    public function testReconcilesEveryCaseItReached(): void
    {
        $reached = count(self::manifestCases());
        $counts = ['compare' => 0, 'declared' => 0, 'known-gap' => 0, 'ahead' => 0];

        foreach (self::optionalCorpusProvider() as $case) {
            if (isset($counts[$case['disposition']])) {
                $counts[$case['disposition']]++;
            }
        }

        $accounted = $counts['compare'] + $counts['declared'] + $counts['known-gap'] + $counts['ahead'];

        $this->assertSame(
            $reached,
            $accounted,
            "{$reached} case(s) reached, but {$counts['compare']} compared + {$counts['declared']} declared "
            . "unimplemented + {$counts['known-gap']} known gap(s) + {$counts['ahead']} ahead of the pin "
            . '- the difference is cases nobody checked',
        );
    }

    protected static function corpusDir(): string
    {
        return __DIR__ . '/spec/tests/corpus-optional';
    }

    /**
     * @throws \RuntimeException
     *
     * @return array<int, array{slug: string, feature: string, target?: string}>
     */
    protected static function manifestCases(): array
    {
        $manifestPath = self::corpusDir() . '/manifest.json';

        if (!file_exists($manifestPath)) {
            throw new RuntimeException(
                "Optional Tier-2 corpus manifest not found at {$manifestPath}.\n"
                . "Initialize the submodule:\n  git submodule update --init",
            );
        }

        $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        return $manifest['cases'] ?? [];
    }

    protected function rendererForTarget(string $target): ?RendererInterface
    {
        $class = self::TARGETS[$target]['renderer'] ?? null;
        if ($class === null) {
            return null;
        }

        return new $class();
    }

    protected function normalize(string $s): string
    {
        $s = (string)preg_replace('/[ \t]+$/m', '', $s);

        return rtrim($s, "\n");
    }
}
