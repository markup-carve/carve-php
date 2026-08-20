<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Performance;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\AsciiHeadingIdsExtension;
use MarkupCarve\Carve\Extension\AutolinkExtension;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Extension\CodeCalloutsExtension;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\ColorSwatchExtension;
use MarkupCarve\Carve\Extension\DetailsExtension;
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Extension\ExternalLinksExtension;
use MarkupCarve\Carve\Extension\GlossaryExtension;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use MarkupCarve\Carve\Extension\HeadingPermalinksExtension;
use MarkupCarve\Carve\Extension\IndexExtension;
use MarkupCarve\Carve\Extension\ListTableExtension;
use MarkupCarve\Carve\Extension\LowercaseHeadingIdsExtension;
use MarkupCarve\Carve\Extension\MathBlockExtension;
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Extension\TableOfContentsExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Extension\WikilinksExtension;
use MarkupCarve\Carve\Performance\BorrowedExtensionPlan;
use MarkupCarve\Carve\Performance\BorrowedHtmlLayout;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BorrowedExtensionPlanTest extends TestCase
{
    private const SOURCE = <<<'CRV'
# Hello World

[site]: https://example.com "Example"

Visit [site](https://example.com/x) and [reference][site].

## Next One

- [external][site]
- [local](/inside)

| Link | Local |
| --- | --- |
| [x](https://example.org) | [y](/inside) |
CRV;

    public function testInactiveTierTwoStackUsesExactBorrowedHtml(): void
    {
        $extensions = [
            new AutolinkExtension(),
            new CitationsExtension(),
            new CodeCalloutsExtension(),
            new SemanticSpanExtension(),
            new ListTableExtension(),
            new DetailsExtension(),
            new SpoilerExtension(),
            new TabsExtension(),
        ];
        $plan = BorrowedExtensionPlan::compile($extensions, self::SOURCE);
        self::assertNotNull($plan);
        self::assertSame([
            'headingNumbers' => null,
            'headingPermalinks' => null,
            'externalLinks' => null,
            'lowercaseIds' => false,
        ], $plan);

        $attempt = (new BorrowedHtmlLayout())->render(self::SOURCE, false, $plan);
        self::assertNotNull($attempt);
        self::assertSame($this->authoritative($extensions)->convert(self::SOURCE), $attempt['html']);
    }

    public function testActiveDefaultEventsRemainExactWithoutAnAst(): void
    {
        $extensions = [
            new HeadingNumbersExtension(),
            new HeadingPermalinksExtension(),
            new ExternalLinksExtension(),
            new LowercaseHeadingIdsExtension(),
        ];
        $plan = BorrowedExtensionPlan::compile($extensions, self::SOURCE);
        self::assertNotNull($plan);
        self::assertNotNull((new BorrowedHtmlLayout())->render(self::SOURCE, false, $plan));

        $fast = new CarveConverter();
        $fast->addExtensions($extensions);
        self::assertSame($this->authoritative($extensions)->convert(self::SOURCE), $fast->convert(self::SOURCE));
    }

    public function testCompleteReproducibleTierThreeStackRemainsExact(): void
    {
        $extensions = [
            ...$this->tierTwo(),
            new GlossaryExtension(), new IndexExtension(), new HeadingNumbersExtension(),
            new CodeGroupExtension(), new TableOfContentsExtension(), new HeadingPermalinksExtension(),
            new ExternalLinksExtension(), new WikilinksExtension(), new ColorSwatchExtension(),
            new LowercaseHeadingIdsExtension(), new AsciiHeadingIdsExtension(), new MathBlockExtension(),
        ];
        $plan = BorrowedExtensionPlan::compile($extensions, self::SOURCE);
        self::assertNotNull($plan);
        $attempt = (new BorrowedHtmlLayout())->render(self::SOURCE, false, $plan);
        self::assertNotNull($attempt);
        self::assertSame($this->authoritative($extensions)->convert(self::SOURCE), $attempt['html']);
    }

    public function testHeadingEventsPreserveNumberingAndCaseCollisionSemantics(): void
    {
        $source = "# A\n\n## Child\n\n# a\n\n### Gap\n";
        $extensions = [
            new HeadingNumbersExtension(),
            new HeadingPermalinksExtension(),
            new LowercaseHeadingIdsExtension(),
        ];
        $fast = new CarveConverter();
        $fast->addExtensions($extensions);

        self::assertSame($this->authoritative($extensions)->convert($source), $fast->convert($source));
    }

    public function testCustomEventConfigurationRemainsExact(): void
    {
        $extensions = [
            new HeadingNumbersExtension(minLevel: 2),
            new HeadingPermalinksExtension(
                symbol: '#',
                position: 'before',
                cssClass: 'heading-link',
                ariaLabel: 'Link',
                levels: [1],
                showOnHover: true,
                copyToClipboard: true,
            ),
            new ExternalLinksExtension(internalHosts: ['internal.test'], target: '_self', rel: 'external', nofollow: true),
            new LowercaseHeadingIdsExtension(),
        ];
        self::assertNotNull(BorrowedExtensionPlan::compile($extensions, self::SOURCE));

        $fast = new CarveConverter();
        $fast->addExtensions($extensions);
        self::assertSame($this->authoritative($extensions)->convert(self::SOURCE), $fast->convert(self::SOURCE));
    }

    public function testEveryAcceptedPinnedCorpusDocumentHasExactConfiguredShadowParity(): void
    {
        $paths = glob(__DIR__ . '/../../spec/tests/corpus/*.crv');
        self::assertIsArray($paths);
        $accepted = ['tier2' => 0, 'events' => 0];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            foreach (['tier2' => $this->tierTwo(), 'events' => $this->events()] as $profile => $extensions) {
                $plan = BorrowedExtensionPlan::compile($extensions, $source);
                if ($plan === null) {
                    continue;
                }
                $attempt = (new BorrowedHtmlLayout())->render($source, false, $plan);
                if ($attempt === null) {
                    continue;
                }
                $accepted[$profile]++;
                self::assertSame(
                    $this->authoritative($extensions)->convert($source),
                    $attempt['html'],
                    $profile . ': ' . basename($path),
                );
            }
        }

        self::assertSame(['tier2' => 47, 'events' => 47], $accepted, 'A configured fast-path routing change needs explicit review.');
    }

    #[DataProvider('activeUnsupportedExtension')]
    public function testActiveUnsupportedExtensionFallsBack(ExtensionInterface $extension, string $source): void
    {
        self::assertNull(BorrowedExtensionPlan::compile([$extension], $source));
    }

    /**
     * @return iterable<string, array{\MarkupCarve\Carve\Extension\ExtensionInterface, string}>
     */
    public static function activeUnsupportedExtension(): iterable
    {
        yield 'autolink' => [new AutolinkExtension(), 'Visit https://example.com now.'];
        yield 'citation' => [new CitationsExtension(), 'See [@doe].'];
        yield 'code callout' => [new CodeCalloutsExtension(), "```php\nx(); <1>\n```\n\n<1> explanation"];
        yield 'semantic span' => [new SemanticSpanExtension(), '[x]{samp}'];
        yield 'list table' => [new ListTableExtension(), "::: list-table\n- - x\n:::"];
        yield 'details' => [new DetailsExtension(), "::: details\nx\n:::"];
        yield 'spoiler' => [new SpoilerExtension(), ':spoiler[x]'];
        yield 'tabs' => [new TabsExtension(), "::: tabs\nx\n:::"];
    }

    /**
     * @param list<\MarkupCarve\Carve\Extension\ExtensionInterface> $extensions
     */
    private function authoritative(array $extensions): CarveConverter
    {
        $converter = new CarveConverter(renderer: new HtmlRenderer());
        $converter->addExtensions($extensions);

        return $converter;
    }

    /**
     * @return list<\MarkupCarve\Carve\Extension\ExtensionInterface>
     */
    private function tierTwo(): array
    {
        return [
            new AutolinkExtension(), new CitationsExtension(), new CodeCalloutsExtension(),
            new SemanticSpanExtension(), new ListTableExtension(), new DetailsExtension(),
            new SpoilerExtension(), new TabsExtension(),
        ];
    }

    /**
     * @return list<\MarkupCarve\Carve\Extension\ExtensionInterface>
     */
    private function events(): array
    {
        return [
            new HeadingNumbersExtension(), new HeadingPermalinksExtension(),
            new ExternalLinksExtension(), new LowercaseHeadingIdsExtension(),
        ];
    }
}
