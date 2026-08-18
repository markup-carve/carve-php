<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\AutolinkExtension;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Extension\DetailsExtension;
use MarkupCarve\Carve\Extension\ListTableExtension;
use MarkupCarve\Carve\Extension\MentionsExtension;
use MarkupCarve\Carve\Extension\SmartQuotesExtension;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\RendererInterface;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function basename;
use function file_get_contents;
use function json_decode;

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
     * @throws \RuntimeException
     *
     * @return array<string, array{slug: string, feature: string, target: string, crv: string, expected: ?string}>
     */
    public static function optionalCorpusProvider(): array
    {
        $dir = __DIR__ . '/spec/tests/corpus-optional';
        $manifestPath = $dir . '/manifest.json';

        if (!file_exists($manifestPath)) {
            throw new RuntimeException(
                "Optional Tier-2 corpus manifest not found at {$manifestPath}.\n"
                . "Initialize the submodule:\n  git submodule update --init",
            );
        }

        $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];

        foreach ($manifest['cases'] ?? [] as $entry) {
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
            ];
        }

        return $cases;
    }

    #[DataProvider('optionalCorpusProvider')]
    public function testOptionalCorpus(string $slug, string $feature, string $target, ?string $crv, ?string $expected): void
    {
        // An unknown target is a corpus error, not an unsupported feature:
        // skipping it would read as "carve-php does not do that yet".
        $this->assertArrayHasKey(
            $target,
            self::TARGETS,
            "Unknown target '{$target}' for {$slug} - expected one of " . implode(', ', array_keys(self::TARGETS)),
        );

        $converter = $this->createConverterForFeature($feature, $target);
        if ($converter === null) {
            $this->markTestSkipped('Optional Tier-2 feature not supported by carve-php: ' . $feature);
        }

        $this->assertNotNull($crv, "Missing {$slug}.crv pair");
        $this->assertNotNull(
            $expected,
            "Missing {$slug}." . self::TARGETS[$target]['extension'] . ' pair',
        );

        $actual = $converter->convert($crv);

        $this->assertSame(
            $this->normalize($expected),
            $this->normalize($actual),
            'Optional Tier-2 corpus mismatch for ' . $slug,
        );
    }

    protected function createConverterForFeature(string $feature, string $target = 'html'): ?CarveConverter
    {
        $renderer = $this->rendererForTarget($target);
        $converter = new CarveConverter(renderer: $renderer);

        return match ($feature) {
            'social-link-templates' => $converter->addExtension(new MentionsExtension(
                mentionUrl: '/users/{name}',
                tagUrl: '/topics/{name}',
            )),
            'smart-quotes-locale-de' => $converter->addExtension(new SmartQuotesExtension(locale: 'de')),
            'bare-url-autolink' => $converter->addExtension(new AutolinkExtension()),
            'citations-numbered' => $converter->addExtension(new CitationsExtension()),
            'citations-author-date' => $converter->addExtension(new CitationsExtension('author-date')),
            'details' => $converter->addExtension(new DetailsExtension()),
            'list-table', 'list-table-columns-1344' => $converter->addExtension(new ListTableExtension()),
            'spoiler' => $converter->addExtension(new SpoilerExtension()),
            'tabs' => $converter->addExtension(new TabsExtension()),
            // The map is consumed by the HTML renderer, so on another target it
            // reaches nothing - which is what the Markdown case asserts: a
            // symbol keeps its `:name:` source spelling there.
            'symbol-map' => new CarveConverter(renderer: $renderer, symbols: [
                'rocket' => "\u{1F680}",
                'tada' => "\u{1F389}",
                '+1' => "\u{1F44D}",
                'UPPER' => "\u{2B06}\u{FE0F}",
            ]),
            // PART 9 §8 confines this one to the Markdown target, so it is the
            // renderer that offers it and no other renderer may. Returning null
            // on another target skips rather than quietly passing, which is what
            // an html-target case for this feature would deserve.
            'markdown-typography-source' => $renderer instanceof MarkdownRenderer
                ? new CarveConverter(renderer: $renderer->setSmartTypography(SmartTypographyMode::Source))
                : null,
            // The plain-text and ANSI targets carry the mode too (carve#560).
            // Each is confined to its own renderer for the same reason the
            // Markdown one is: the mode is a renderer setting, so a case named
            // for one target must not quietly pass on another.
            'plain-typography-source' => $renderer instanceof PlainTextRenderer
                ? new CarveConverter(renderer: $renderer->setSmartTypography(SmartTypographyMode::Source))
                : null,
            'ansi-typography-source' => $renderer instanceof AnsiRenderer
                ? new CarveConverter(renderer: $renderer->setSmartTypography(SmartTypographyMode::Source))
                : null,
            // The DEFAULT mode, named as a feature so a case can pin it. Its
            // job is to be the control a source-mode case needs: without it a
            // source-mode expectation also passes an engine that never applies
            // typography to that construct in either mode.
            'smart-typography-default' => new CarveConverter(renderer: $renderer),
            default => null,
        };
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
