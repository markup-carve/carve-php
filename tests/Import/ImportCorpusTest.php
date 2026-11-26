<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use ImportTableGenerator;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Import\HtmlParser;
use MarkupCarve\Carve\Test\Support\Corpus;
use MarkupCarve\Carve\Test\Support\Held;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `import` entries of the conformance corpus: the children of the document and the codes of the `Reported:` line.
 */
final class ImportCorpusTest extends TestCase
{
    /**
     * Where the libxml parser builds another tree than the HTML Standard's: it knows no `<wbr>` as a void element, so the
     * text after it becomes its content and is dropped with it.
     *
     * @var array<string, string>
     */
    public const LEGACY_DIFFERS = [
        'import-html-39' => 'libxml reads `<wbr>` as a container, so `b` is its content',
    ];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function importExamples(): array
    {
        $out = [];
        foreach (Corpus::examples('import') as $id => $example) {
            if (Held::has($id)) {
                continue;
            }
            $out[$id] = [$example];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $example
     *
     * @return void
     */
    #[DataProvider('importExamples')]
    public function testImportExample(array $example): void
    {
        if (!HtmlParser::html5() && isset(self::LEGACY_DIFFERS[$example['id']])) {
            $this->markTestSkipped(self::LEGACY_DIFFERS[$example['id']]);
        }
        $result = HtmlImporter::import($example['html'], $example['options']);
        $this->assertSame(Corpus::sorted($example['expected']['children']), Corpus::sorted($result->document['children']));
        foreach ($example['expected']['diagnostics'] ?? [] as $code) {
            $this->assertContains($code, $result->codes());
        }
        $this->assertSame($example['expected']['carve'], $result->source);
    }

    /**
     * The libxml fallback gives the same trees, except the entries of `LEGACY_DIFFERS`.
     *
     * @param array<string, mixed> $example
     *
     * @return void
     */
    #[DataProvider('importExamples')]
    public function testLegacyParserGivesTheSameTree(array $example): void
    {
        if (!HtmlParser::html5()) {
            $this->markTestSkipped('only the libxml parser exists on this PHP');
        }
        $html5 = HtmlImporter::import($example['html'], $example['options']);
        $legacy = HtmlImporter::import($example['html'], $example['options'], true);
        if (isset(self::LEGACY_DIFFERS[$example['id']])) {
            $this->assertNotSame(Corpus::sorted($html5->document), Corpus::sorted($legacy->document), self::LEGACY_DIFFERS[$example['id']]);

            return;
        }
        $this->assertSame(Corpus::sorted($html5->document), Corpus::sorted($legacy->document));
        $this->assertSame($html5->codes(), $legacy->codes());
    }

    public function testParserIsTheHtmlStandardOneWherePhpHasIt(): void
    {
        $this->assertSame(class_exists('Dom\HTMLDocument'), HtmlParser::html5());
        $this->assertTrue(HtmlParser::available());
    }

    public function testImportTablesMatchSpecData(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/scripts/ImportTableGenerator.php';
        $this->assertSame(
            ImportTableGenerator::generate($root . '/spec/data'),
            (string)file_get_contents($root . '/src/Generated/ImportTables.php'),
            'ImportTables.php is out of date: run php scripts/generate-tables.php',
        );
    }
}
