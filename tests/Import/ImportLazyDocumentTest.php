<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Import\ImportInputError;
use MarkupCarve\Carve\Import\ImportResult;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * carve-php#5627: an import result holds one form of the tree; the document array is decoded when it is read.
 */
final class ImportLazyDocumentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function imports(): array
    {
        return [
            'html' => ['html', '<p><em>a</em> <a href="u">b</a></p><ul><li>c</li></ul>'],
            'markdown' => ['markdown', "*a* [b](u)\n\n- c\n"],
        ];
    }

    #[DataProvider('imports')]
    public function testTheDocumentIsDecodedOnFirstRead(string $from, string $input): void
    {
        $result = Carve::importDocument($input, ['from' => $from]);
        $property = new ReflectionProperty(ImportResult::class, 'document');

        $this->assertFalse($property->isInitialized($result));
        $this->assertTrue(isset($result->document));
        $document = $result->document;
        $this->assertTrue($property->isInitialized($result));
        $this->assertSame($document, $result->document);
        $this->assertSame('document', $document['type']);
        $this->assertSame(Corpus::sorted(json_decode(Carve::encodeTree($result->tree), true)), Corpus::sorted($document));
    }

    public function testTheDecodedDocumentIsTheImportedArray(): void
    {
        $result = HtmlImporter::import('<p lang="fr">a <strong>b</strong></p>');

        $this->assertSame([
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a '],
                        ['type' => 'strong', 'children' => [['type' => 'text', 'value' => 'b']]],
                    ],
                    'lang' => 'fr',
                ],
            ],
        ], $result->document);
    }

    public function testTheConstructorStillTakesAnArray(): void
    {
        $document = ['type' => 'document', 'astVersion' => '2.0', 'children' => []];
        $result = new ImportResult(new Node('document', []), $document, [], '', 0);

        $this->assertSame($document, $result->document);
        $this->assertTrue((new ReflectionProperty(ImportResult::class, 'document'))->isInitialized($result));
    }

    public function testASerializedResultKeepsItsDocument(): void
    {
        $html = '<p><em>a</em></p>';
        $expected = HtmlImporter::import($html)->document;

        $copy = unserialize(serialize(HtmlImporter::import($html)));
        $clone = clone HtmlImporter::import($html);

        $this->assertInstanceOf(ImportResult::class, $copy);
        $this->assertSame($expected, $copy->document);
        $this->assertSame('document', $copy->tree->type);
        $this->assertSame($expected, $clone->document);
    }

    public function testAPayloadWithTheUndecodedDocumentIsRead(): void
    {
        $result = HtmlImporter::import('<p><em>a</em></p>');
        $expected = $result->document;
        $json = (string)json_encode($expected);
        // The payload a lazy result gave before it had `__serialize()`: the private JSON field, no document.
        $payload = 'O:' . strlen(ImportResult::class) . ':"' . ImportResult::class . '":3:{'
            . 's:6:"source";' . serialize($result->source)
            . 's:' . (strlen(ImportResult::class) + 14) . ':"' . "\0" . ImportResult::class . "\0" . 'documentJson";' . serialize($json)
            . 's:4:"tree";' . serialize($result->tree) . '}';

        $copy = unserialize($payload);

        $this->assertInstanceOf(ImportResult::class, $copy);
        $this->assertSame($expected, $copy->document);
    }

    /**
     * carve-php#5965: without the tree check the tree is read from the JSON on its first read.
     */
    public function testTheTreeIsReadOnFirstReadWithoutTheCheck(): void
    {
        $html = '<p><em>a</em> <a href="u">[b] c</a></p>';
        $checked = HtmlImporter::import($html);
        $result = HtmlImporter::import($html, checksTree: false);
        $property = new ReflectionProperty(ImportResult::class, 'tree');

        $this->assertSame($checked->source, $result->source);
        $this->assertSame($checked->codes(), $result->codes());
        $this->assertFalse($property->isInitialized($result));
        $this->assertTrue(isset($result->tree));
        $tree = $result->tree;
        $this->assertTrue($property->isInitialized($result));
        $this->assertSame($tree, $result->tree);
        $this->assertSame(Carve::encodeTree($checked->tree), Carve::encodeTree($tree));
        $this->assertSame($checked->document, $result->document);
    }

    public function testAResultWithoutTheCheckSerializesItsTree(): void
    {
        $html = '<p><em>a</em></p>';
        $copy = unserialize(serialize(HtmlImporter::import($html, checksTree: false)));

        $this->assertInstanceOf(ImportResult::class, $copy);
        $this->assertSame(Carve::encodeTree(HtmlImporter::import($html)->tree), Carve::encodeTree($copy->tree));
    }

    public function testASerializedResultKeepsItsDiagnosticKeys(): void
    {
        $result = HtmlImporter::import('<p><em>a</em></p>');
        $keyed = new ImportResult($result->tree, [], ['first' => new Diagnostic('c', 'warning', 'import-loss', 'm', '')], '');

        $copy = unserialize(serialize($keyed));

        $this->assertInstanceOf(ImportResult::class, $copy);
        $this->assertSame(['first' => 'c'], $copy->codes());
    }

    public function testAnOverDeepInlineTreeIsRefusedBeforeTheWriter(): void
    {
        $this->expectException(ImportInputError::class);
        $this->expectExceptionMessage('The input exceeds the nesting limits of a tree (C2-A-171)');

        HtmlImporter::import(str_repeat('<em>', Tables::INLINE_DEPTH + 1) . 'a');
    }

    public function testTheDeepestInlineTreeStillImports(): void
    {
        $result = HtmlImporter::import(str_repeat('<em>', Tables::INLINE_DEPTH - 1) . 'a');

        $this->assertSame('paragraph', $result->tree->children[0]->type ?? null);
    }

    public function testTheArrayDepthCheckCountsAsTheReader(): void
    {
        $inline = ['type' => 'text', 'value' => 'a'];
        for ($i = 0; $i < Tables::INLINE_DEPTH; $i++) {
            $inline = ['type' => 'emphasis', 'children' => [$inline]];
        }
        $deepest = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => [$inline]]]];
        $over = $deepest;
        $over['children'][0]['children'][0] = ['type' => 'strong', 'children' => [$inline]];

        $this->assertFalse(TreeReader::exceedsDepth($deepest));
        $this->assertTrue(TreeReader::exceedsDepth($over));
    }

    /**
     * The ticket's reproduction: 576 KB of emphasis paragraphs, 96,001 nodes.
     */
    public function testThirtyTwoThousandEmphasisParagraphsImportUnder128Megabytes(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; '
            . '$result = \\MarkupCarve\\Carve\\Carve::importDocument(str_repeat("<p><em>a</em></p>\n", 32000)); '
            . '$first = $result->tree->children[0]; '
            . 'echo count($result->tree->children), ":", $first->type, ":", $first->children[0]->type, ":", strlen($result->source);';

        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $program], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('32000:paragraph:emphasis:159999', $stdout);
    }
}
