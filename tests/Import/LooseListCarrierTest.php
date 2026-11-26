<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-138, C2-H-186 (carve#6844): a loose list keeps its spacing through render with `roundtrip` and import,
 * whatever its items hold.
 */
final class LooseListCarrierTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'block images' => ["- ![x](j.png)\n\n- ![x](k.png)\n"],
            'quotes' => ["- > a\n\n- > b\n"],
            'paragraphs' => ["- a\n\n- b\n"],
            'code blocks' => ["- ```\n  a\n  ```\n\n- ```\n  b\n  ```\n"],
            'ordered quotes' => ["3. > a\n\n4. > b\n"],
            'task items with quotes' => ["- [ ] > a\n\n- [x] > b\n"],
            'nested in a quote' => ["> - > a\n>\n> - > b\n"],
            'nested in an item' => ["- a\n\n  - > b\n\n  - > c\n"],
            'three items' => ["- > a\n\n- > b\n\n- > c\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testRoundTripKeepsTheSpacing(string $source): void
    {
        $html = Carve::render($source, ['roundtrip' => true]);
        self::assertStringContainsString('data-carve-loose', $html);
        self::assertSame($html, Carve::render(Carve::importHtml($html)->source, ['roundtrip' => true]));
    }

    public function testOneItemListCarriesNoSpacingCarrier(): void
    {
        $html = Carve::render("- > a\n", ['roundtrip' => true]);
        self::assertStringNotContainsString('data-carve-loose', $html);
    }

    public function testTightListKeepsTheTightCarrier(): void
    {
        $html = Carve::render("- > a\n- > b\n", ['roundtrip' => true]);
        self::assertStringContainsString('data-carve-tight', $html);
        self::assertStringNotContainsString('data-carve-loose', $html);
    }

    public function testCarrierBesideABareRunIsIgnored(): void
    {
        $result = Carve::importHtml("<ul data-carve-loose>\n  <li>\n    <blockquote><p>a</p></blockquote>\n  </li>\n  <li>b</li>\n</ul>");
        self::assertSame("- > a\n- b\n", $result->source);
        self::assertSame(['import-carrier-ignored'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
    }
}
