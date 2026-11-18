<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Import;

use MarkupCarve\Carve2\Import\HtmlParser;
use MarkupCarve\Carve2\Test\Support\Corpus;
use MarkupCarve\Carve2\Test\Support\DocGenerator;
use MarkupCarve\Carve2\Test\Support\Held;
use MarkupCarve\Carve2\Test\Support\RoundTrip;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-188, C2-H-196: a render with `roundtrip` imports back to the parsed tree, less the losses the clauses name, on
 * every render input of the corpus and on generated documents. `v2/scripts/roundtrip.php` runs more seeds.
 */
final class RoundTripTest extends TestCase
{
    /**
     * @var int
     */
    private const SEEDS = 600;

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function renderExamples(): array
    {
        $out = [];
        foreach (Corpus::examples('render') as $id => $example) {
            if (Held::has('import-roundtrip/' . $id)) {
                continue;
            }
            $out[$id] = [$example['input'], $example['options']];
        }

        return $out;
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     */
    #[DataProvider('renderExamples')]
    public function testCorpusRenderInput(string $source, array $options): void
    {
        $this->requireHtml5();
        [$verdict, $expected, $got] = RoundTrip::check($source, $options);

        $this->assertNotSame('differs', $verdict);
        $this->assertSame($expected, $got);
    }

    public function testGeneratedDocuments(): void
    {
        if (Held::has('import-roundtrip/generated')) {
            $this->markTestSkipped('HTML importer round trip held at candidate 12');
        }
        $this->requireHtml5();
        $differs = [];
        for ($seed = 1; $seed <= self::SEEDS; $seed++) {
            [$verdict] = RoundTrip::check((new DocGenerator($seed))->document());
            if ($verdict === 'differs') {
                $differs[] = $seed;
            }
        }

        $this->assertSame([], $differs, 'Seeds whose round trip differs');
    }

    private function requireHtml5(): void
    {
        if (!HtmlParser::html5()) {
            $this->markTestSkipped('The gate reads with Dom\HTMLDocument (PHP 8.4)');
        }
    }
}
