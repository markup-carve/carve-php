<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §9/§10, the tier split. Core reserves three SPAN ATTRIBUTES; the four
 * other names and the `:name[...]` spelling are the SemanticSpan extension's.
 *
 * The pairs matter more than the rows: every assertion has a twin with the
 * extension off, because "renders <samp>" and "renders <samp> only when asked"
 * are different claims and a suite that always registers the extension cannot
 * tell them apart.
 */
class SemanticInlineExtensionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function coreNames(): array
    {
        return [
            'abbr' => ['abbr', 'abbr'],
            'time' => ['time', 'time'],
            'kbd' => ['kbd', 'kbd'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function extensionNames(): array
    {
        return [
            'samp' => ['samp'],
            'var' => ['var'],
            'cite' => ['cite'],
            'dfn' => ['dfn'],
        ];
    }

    #[DataProvider('coreNames')]
    public function testCoreNameIsConsumedWithNoExtension(string $name, string $tag): void
    {
        $html = (new CarveConverter())->convert('[x]{' . $name . '}');

        self::assertSame('<p><' . $tag . '>x</' . $tag . '></p>', trim($html));
    }

    #[DataProvider('extensionNames')]
    public function testExtensionNameStaysAnAttributeWithNoExtension(string $name): void
    {
        $html = (new CarveConverter())->convert('[x]{' . $name . '}');

        self::assertSame('<p><span ' . $name . '="">x</span></p>', trim($html));
    }

    #[DataProvider('extensionNames')]
    public function testExtensionNameIsConsumedWhenRegistered(string $name): void
    {
        $cv = new CarveConverter();
        $cv->addExtension(new SemanticSpanExtension());

        self::assertSame('<p><' . $name . '>x</' . $name . '></p>', trim($cv->convert('[x]{' . $name . '}')));
    }

    public function testValueMapsToTheAttributeItStandsFor(): void
    {
        $cv = new CarveConverter();

        self::assertSame(
            '<p><abbr title="HyperText Markup Language">HTML</abbr></p>',
            trim($cv->convert('[HTML]{abbr="HyperText Markup Language"}')),
        );
        self::assertSame(
            '<p><time datetime="2026-01-01">now</time></p>',
            trim($cv->convert('[now]{time="2026-01-01"}')),
        );
    }

    public function testDerivedAttributeYieldsToAnAuthoredOne(): void
    {
        self::assertSame(
            '<p><abbr title="authored">x</abbr></p>',
            trim((new CarveConverter())->convert('[x]{abbr="derived" title="authored"}')),
        );
    }

    public function testLeftoversRideTheOutermostElement(): void
    {
        $cv = new CarveConverter();

        self::assertSame(
            '<p><kbd id="k" class="key">Tab</kbd></p>',
            trim($cv->convert('[Tab]{#k .key kbd}')),
        );
        self::assertSame('<p><kbd>x</kbd></p>', trim($cv->convert('[x]{kbd onclick="alert(1)"}')));
    }

    public function testASpanWithNoSemanticNameIsUnchanged(): void
    {
        $cv = new CarveConverter();

        self::assertSame('<p><span>x</span></p>', trim($cv->convert('[x]{}')));
        self::assertSame('<p><span>x</span></p>', trim($cv->convert('[x]{onclick="alert(1)"}')));
    }

    public function testCoreRegistersNoExtensionSpellingAtAll(): void
    {
        $cv = new CarveConverter();

        foreach (['abbr', 'time', 'kbd', 'samp', 'var', 'cite', 'dfn', 'code', 'mark'] as $name) {
            self::assertSame(
                '<p><span class="ext-' . $name . '">x</span></p>',
                trim($cv->convert(':' . $name . '[x]')),
                'core must register no :' . $name . '[...] handler',
            );
        }
    }

    public function testTheExtensionAcceptsTheDeprecatedSpelling(): void
    {
        $cv = new CarveConverter();
        $cv->addExtension(new SemanticSpanExtension());

        self::assertSame('<p><kbd>Ctrl</kbd></p>', trim($cv->convert(':kbd[Ctrl]')));
        self::assertSame('<p><samp class="o">out</samp></p>', trim($cv->convert(':samp[out]{.o}')));
        self::assertSame('<p><span class="ext-code">x</span></p>', trim($cv->convert(':code[x]')));
    }

    public function testAttributesAreHardenedOnTheSemanticElement(): void
    {
        $html = (new CarveConverter())->convert('[*noon*]{#clock .local time="12:00" onclick="alert(1)"}');

        self::assertSame(
            '<p><time datetime="12:00" id="clock" class="local"><strong>noon</strong></time></p>',
            trim($html),
        );
    }
}
