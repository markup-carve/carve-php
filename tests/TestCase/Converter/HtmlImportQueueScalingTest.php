<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMElement;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class HtmlImportQueueScalingTest extends TestCase
{
    use ScalingGuardTrait;

    #[Group('scaling')]
    public function testRubyWhitespaceLookaheadScalesLinearly(): void
    {
        $converter = new HtmlToCarve();
        $this->assertConversionScalesLinearly(
            static fn (string $source): array => $converter->convertToAst($source),
            '<ruby>x<rt>a</rt>' . str_repeat(' <rtc></rtc>', 2048) . '</ruby>',
            '<ruby>x<rt>a</rt>' . str_repeat(' <rtc></rtc>', 8192) . '</ruby>',
            'ruby whitespace lookahead',
            2048,
            8192,
        );
    }

    #[Group('scaling')]
    public function testShortBacklinkNamesBesideLongTermsScaleLinearly(): void
    {
        $build = static fn (int $n): string => '<ul class="index"><li>' . str_repeat('t', $n) . ' '
            . implode('', array_map(static fn (int $i): string => '<a class="index-backref" href="#idx-term-' . $i . '" aria-label="x">x</a> ', range(1, $n)))
            . '</li></ul>';
        $converter = new HtmlToCarve();
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $converter->convertWithReport($source),
            $build(1024),
            $build(4096),
            'index backlink names',
            1024,
            4096,
            maxPerByteRatio: 2.0,
        );
    }

    public function testDerivedNameOverridesStillRunAfterCacheWarmup(): void
    {
        $converter = new class extends HtmlToCarve {
            protected function derivedAccessibleName(DOMElement $node): ?string
            {
                return $node->getAttribute('aria-label') === 'authored'
                    ? 'authored'
                    : parent::derivedAccessibleName($node);
            }
        };
        $source = '<ul class="index"><li>long-term <a class="index-backref" href="#idx-term-1" aria-label="Back to long-term 1">x</a> <a class="index-backref" href="#idx-term-2" aria-label="authored">y</a></li></ul>';
        $result = $converter->convertWithReport($source);
        self::assertSame([], array_values(array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'attribute-dropped')));
    }

    public function testEarlierNamingFamiliesTakePrecedenceAfterCacheWarmup(): void
    {
        $source = '<ul class="index"><li>long-term <a class="index-backref" href="#idx-term-1" aria-label="Back to long-term 1">x</a> <a class="index-backref tabs" href="#idx-term-2" aria-label="Tabs">y</a></li></ul>';
        $result = (new HtmlToCarve())->convertWithReport($source);
        self::assertSame([], array_values(array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'attribute-dropped')));
    }

    public function testPanelNamesUseTheLastEarlierLabelAcrossOtherElements(): void
    {
        $source = '<div><label>First</label><span>gap</span><div class="tabs-panel" aria-label="First">one</div><div class="tabs-panel" aria-label="First">two</div><label>Second</label><span>gap</span><div class="code-group-panel" aria-label="Second">three</div></div>';
        $result = (new HtmlToCarve())->convertWithReport($source);
        self::assertSame([], array_values(array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'attribute-dropped' && str_contains($diagnostic->message, 'aria-label'))));
    }

    #[Group('scaling')]
    public function testPanelsWithoutLabelsScaleLinearly(): void
    {
        $converter = new HtmlToCarve();
        $fragment = '<div class="tabs-panel" aria-label="x">text</div>';
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $converter->convertWithReport($source),
            '<div>' . str_repeat($fragment, 2048) . '</div>',
            '<div>' . str_repeat($fragment, 8192) . '</div>',
            'panel label lookup',
            2048,
            8192,
            maxPerByteRatio: 2.0,
        );
    }

    #[Group('scaling')]
    public function testWideFigureCaptionsScaleLinearly(): void
    {
        $converter = new HtmlToCarve();
        $build = static fn (int $n): string => '<figure><img src="x.png"><figcaption>' . str_repeat('<h2>caption</h2>', $n) . '</figcaption></figure>';
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $converter->convertWithReport($source),
            $build(2048),
            $build(8192),
            'figure outcome in caption',
            2048,
            8192,
            maxPerByteRatio: 2.0,
        );
    }
}
