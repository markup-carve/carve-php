<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5735: importers read only `data-carve-*` carriers and lint accepts only `heading-level-skip`.
 *
 * The footnote and acceptance rows mirror what carve#5738 wrote into the spec fixtures, so they hold
 * while the pin predates that change.
 */
class RetiredInputAliasesTest extends TestCase
{
    public static function pinPredatesAliasRetirement(): bool
    {
        $fixtures = (string)file_get_contents(dirname(__DIR__, 2) . '/spec/resources/roundtrip-export-acceptance-fixtures.json');

        return str_contains($fixtures, '"canonical-carrier-beats-deprecated"');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function footnoteCarriers(): iterable
    {
        $cases = [
            'former-inline' => ['data-djot-inline-footnote="1"', "[^1]\n\n[^1]: body\n"],
            'canonical-label-former-inline' => ['data-carve-footnote-label="named" data-djot-inline-footnote="1"', "[^named]\n\n[^named]: body\n"],
            'former-label' => ['data-djot-footnote-label="named"', "[^1]\n\n[^1]: body\n"],
            'former-label-canonical-inline' => ['data-djot-footnote-label="named" data-carve-inline-footnote="1"', "^[body]\n"],
            'former-both' => ['data-djot-footnote-label="named" data-djot-inline-footnote="1"', "[^1]\n\n[^1]: body\n"],
            'former-uppercase-label' => ['DATA-DJOT-FOOTNOTE-LABEL="named"', "[^1]\n\n[^1]: body\n"],
        ];
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach ($cases as $name => [$attributes, $source]) {
                $html = '<p><a href="#fn1" role="doc-noteref">1</a></p><section role="doc-endnotes"><ol><li id="fn1" '
                    . $attributes . '><p>body</p></li></ol></section>';

                yield $mode . '-' . $name => [$mode, $html, $source];
            }
        }
    }

    #[DataProvider('footnoteCarriers')]
    public function testAFormerFootnoteCarrierRestoresNothing(string $mode, string $html, string $source): void
    {
        $converter = new HtmlToCarve(importMode: $mode);
        $result = $converter->convertWithReport($html);
        self::assertSame($source, $result->value);
        $ast = json_encode($converter->convertToAst($html), JSON_THROW_ON_ERROR);
        foreach (['data-carve-footnote-label', 'data-carve-inline-footnote'] as $name) {
            self::assertStringNotContainsString($name, $result->value);
            self::assertStringNotContainsString($name, $ast);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function modesAndSpellings(): iterable
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-djot-src', 'DATA-DJOT-SRC'] as $name) {
                yield $mode . ' ' . $name => [$mode, $name];
            }
        }
    }

    #[DataProvider('modesAndSpellings')]
    public function testTheFormerSourceAliasIsAnOrdinaryAttribute(string $mode, string $name): void
    {
        $result = (new HtmlToCarve(importMode: $mode))->convertToAstWithReport('<p ' . $name . '="Z">text</p>');
        $paragraph = $result->value['children'][0];
        self::assertSame('paragraph', $paragraph['type']);
        self::assertSame('Z', $paragraph['attrs']['keyValues']['data-djot-src']);
        $carriers = (new HtmlToCarve(importMode: $mode))->convertToAstWithReport('<p data-carve-src="Z">text</p>')->value['children'][0];
        self::assertArrayNotHasKey('attrs', $carriers);
    }

    public function testRoundtripExportKeepsTheFormerAliasName(): void
    {
        $result = (new CarveConverter(roundTripMode: true))->convertWithReport("[a](/u){data-djot-ref=x}\n");
        self::assertSame('<p><a href="/u" data-djot-ref="x">a</a></p>', trim($result->value));
        self::assertSame([], $result->losses);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function suppressions(): iterable
    {
        yield 'former alias block' => ["%% carve-lint-disable a11y/heading-jump\n# One\n\n### Three\n", true];
        yield 'former alias same line' => ["# One\n\n### Three %% carve-lint-disable-line a11y/heading-jump\n", true];
        yield 'former alias next line' => ["# One\n%% carve-lint-disable-next-line a11y/heading-jump\n### Three\n", true];
        yield 'canonical block' => ["%% carve-lint-disable heading-level-skip\n# One\n\n### Three\n", false];
        yield 'canonical same line' => ["# One\n\n### Three %% carve-lint-disable-line heading-level-skip\n", false];
    }

    #[DataProvider('suppressions')]
    public function testOnlyTheCanonicalRuleNameSuppresses(string $source, bool $reported): void
    {
        $warnings = array_values(array_filter((new DocumentLinter())->lint($source), static fn ($warning): bool => $warning->rule === 'heading-level-skip'));
        self::assertCount($reported ? 1 : 0, $warnings);
        if ($reported) {
            self::assertSame(substr_count(strstr($source, '### Three', true) ?: '', "\n") + 1, $warnings[0]->line);
        }
    }
}
