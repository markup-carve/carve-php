<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A destination the PART 9 section 25 sink blanks is not written: the element
 * imports like one with an empty destination (markup-carve/carve#2254).
 */
class ADeniedSchemeDestinationIsImportedAsContentTest extends TestCase
{
    /**
     * @var string
     */
    private const HTML = '<p><a href="javascript:alert(1)">click here</a> and <a href="Java&#9;Script:alert(1)" id="k">a named one</a></p>'
        . "\n" . '<img src="data:text/html;base64,PHNjcmlwdD4=" alt="logo">' . "\n";

    public function testTheSharedCaseWritesOnlyTheContent(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(self::HTML);

        $this->assertSame("click here and [a named one]{#k}\n\nlogo\n", $result->value);
        $this->assertSame('safe', $result->mode);
        $this->assertSame('generic', $result->adapter);
        $href = ['attribute-dropped', 'Dropped href with a denied URL scheme on <a>', 'warning', 'dropped', 'exact'];
        $this->assertSame(
            [$href, $href, ['attribute-dropped', 'Dropped src with a denied URL scheme on <img>', 'warning', 'dropped', 'exact']],
            array_map(
                static fn ($d): array => [$d->code, $d->message, $d->severity, $d->fidelity(), $d->confidence()],
                $result->diagnostics,
            ),
        );
    }

    public function testTheSharedCaseAst(): void
    {
        $this->assertSame(
            [
                'type' => 'document',
                'children' => [
                    [
                        'type' => 'paragraph',
                        'children' => [
                            ['type' => 'text', 'value' => 'click here and '],
                            [
                                'type' => 'span',
                                'children' => [['type' => 'text', 'value' => 'a named one']],
                                'attrs' => ['id' => 'k'],
                            ],
                        ],
                    ],
                    ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'logo']]],
                ],
            ],
            self::withoutBookkeeping((new HtmlToCarve())->convertToAst(self::HTML)),
        );
    }

    /**
     * Drops what the shared fixture does not state: offsets and attribute order.
     */
    private static function withoutBookkeeping(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        unset($value['srcByteLength'], $value['pos'], $value['order']);

        return array_map(self::withoutBookkeeping(...), $value);
    }

    /**
     * @param string $mode
     */
    #[DataProvider('modeProvider')]
    public function testEveryModeDeniesTheDestination(string $mode): void
    {
        $result = (new HtmlToCarve(importMode: $mode))->convertWithReport(
            '<p><a href="vbscript:x">t</a> <img src="file:///etc/passwd" alt="i"></p>',
        );

        $this->assertSame("t i\n", $result->value);
        $this->assertSame(
            ['Dropped href with a denied URL scheme on <a>', 'Dropped src with a denied URL scheme on <img>'],
            array_map(static fn ($d): string => $d->message, $result->diagnostics),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function modeProvider(): array
    {
        return ['safe' => ['safe'], 'semantic' => ['semantic'], 'roundtrip' => ['roundtrip']];
    }

    /**
     * The `[x]` alternative text has no `![...]()` spelling, so the writer keeps
     * the surviving image as a raw HTML span. That row belongs to the list: the
     * bytes really are raw in the output, and the report used to leave the keep
     * unsaid (markup-carve/carve#2261).
     *
     * @param string $html
     * @param array<string> $messages
     */
    #[DataProvider('rawLinkProvider')]
    public function testRoundtripDoesNotKeepADeniedDestinationAsRawHtml(string $html, array $messages): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $this->assertStringNotContainsString('javascript', $result->value);
        $this->assertSame($messages, array_map(static fn ($d): string => $d->message, $result->diagnostics));
    }

    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function rawLinkProvider(): array
    {
        return [
            'denied href' => [
                '<p><a href="javascript:x"><img src="a.png" alt="[x]"></a></p>',
                [
                    'Dropped href with a denied URL scheme on <a>',
                    'Preserved unsupported <img> element as raw HTML',
                ],
            ],
            'denied nested src' => [
                '<p><a href="/ok"><img src="javascript:x" alt="[x]"></a></p>',
                ['Dropped src with a denied URL scheme on <img>'],
            ],
        ];
    }

    public function testAnOsHandlerSchemeTheSinkBlanksIsDeniedToo(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><a href="ms-msdt:/id PCWDiagnostic">t</a></p>');

        $this->assertSame("t\n", $result->value);
        $this->assertSame(
            ['Dropped href with a denied URL scheme on <a>'],
            array_map(static fn ($d): string => $d->message, $result->diagnostics),
        );
    }

    public function testRoundtripDoesNotKeepAFigureWithADeniedImageAsRawHtml(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport(
            '<figure><img src="javascript:x" alt="logo"><figcaption>cap</figcaption></figure>',
        );

        $this->assertSame("logo\n\ncap\n", $result->value);
        $this->assertContains(
            'Dropped src with a denied URL scheme on <img>',
            array_map(static fn ($d): string => $d->message, $result->diagnostics),
        );
    }
}
