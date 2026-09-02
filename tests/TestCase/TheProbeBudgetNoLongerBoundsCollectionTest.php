<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1853, and the measurement behind markup-carve/carve#1895.
 *
 * A one-kind document used to take a line pre-pass whose only way to ask "is a
 * paragraph open here" was to reparse the run once per candidate. That cost
 * grows quadratically with the run while its budget grew linearly with the
 * source, so the budget ran out after nine marker-led definitions and, per
 * PART 9R R1a's fallback, collected nothing from every candidate after it.
 *
 * The effect was that a list of ten link definitions stopped resolving while a
 * list of nine resolved, which made a document's meaning depend on its size.
 * The structural walk never asks, because a definition line only reaches it
 * when no paragraph is open.
 */
class TheProbeBudgetNoLongerBoundsCollectionTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * n marker-led definitions under a heading, each with its own reference.
     */
    private function markerDefinitions(int $count): string
    {
        $source = "# H\n";
        for ($i = 0; $i < $count; $i++) {
            $source .= "- [d{$i}]: /u{$i}\n";
        }
        $source .= "\n";
        for ($i = 0; $i < $count; $i++) {
            $source .= "[go{$i}][d{$i}]\n";
        }

        return $source;
    }

    /**
     * @return array<string, array{int}>
     */
    public static function sizes(): array
    {
        // NINE was the last size that worked and TEN was the first that did
        // not, so a fix that merely moved the boundary fails at one of these.
        return ['nine' => [9], 'ten' => [10], 'fifty' => [50], 'two hundred' => [200]];
    }

    #[DataProvider('sizes')]
    public function testEveryDefinitionRegistersWhateverTheDocumentSize(int $count): void
    {
        $html = $this->converter->convert($this->markerDefinitions($count));

        for ($i = 0; $i < $count; $i++) {
            $this->assertStringContainsString("href=\"/u{$i}\"", $html);
        }
    }

    public function testALazyDefinitionStillRegistersNothingAtAnySize(): void
    {
        // The direction that must NOT move. These lines fold into an open
        // paragraph, so PART 9R R1a says they define nothing - at nine lines
        // and at two hundred alike.
        $source = "intro paragraph\n";
        for ($i = 0; $i < 200; $i++) {
            $source .= "- [d{$i}]: /u{$i}\n";
        }
        $source .= "\n[go][d199]\n";

        $html = $this->converter->convert($source);

        $this->assertStringNotContainsString('href="/u199"', $html);
        $this->assertStringContainsString('[go][d199]', $html);
    }
}
