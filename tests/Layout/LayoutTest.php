<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Layout;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Layout\LayoutError;
use MarkupCarve\Carve\Test\Support\Corpus;
use MarkupCarve\Carve\Test\Support\Held;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function layouts(): array
    {
        $out = [];
        foreach (Corpus::file('trees/layouts.json')['layouts'] as $entry) {
            if (Held::has('layout/' . $entry['id'])) {
                continue;
            }
            $out[$entry['id']] = [$entry];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('layouts')]
    public function testC2L058LayoutCorpusIsReadOrRefused(array $entry): void
    {
        $json = (string)json_encode($entry['layout'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (isset($entry['refused'])) {
            try {
                Carve::validateLayout($json);
                $this->fail($entry['id'] . ' is read');
            } catch (LayoutError $e) {
                $this->assertNotSame('', $e->reason);

                return;
            }
        }
        $record = Carve::validateLayout($json);
        $this->assertSame($entry['layout']['nodes'], $record['nodes']);
        $input = isset($entry['input']) ? (string)hex2bin(str_replace(' ', '', $entry['input'])) : $entry['layout']['source'];
        $produced = Carve::parseWithLayout($input)->layout;
        $this->assertEquals($entry['layout'], $produced, 'the producer writes the record of this input');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function corpusInputs(): array
    {
        $out = [];
        foreach (Corpus::examples() as $entry) {
            if (isset($entry['input']) && $entry['kind'] !== 'include') {
                $out[$entry['id']] = [$entry['input']];
            }
        }

        return $out;
    }

    #[DataProvider('corpusInputs')]
    public function testC2L012ProducedRecordIsReadAndItsRangesArePositions(string $input): void
    {
        $result = Carve::parseWithLayout($input);
        $tree = (new TreeEncoder())->encode($result->tree, $input);
        $record = Carve::validateLayout((string)json_encode($result->layout), $tree);
        $this->assertSame($input, $record['source']);
        foreach ($record['nodes'] as $entry) {
            $node = self::at($tree, $entry['path']);
            $this->assertIsArray($node, $entry['path']);
            if (isset($node['pos'])) {
                $this->assertSame([$node['pos']['startOffset'], $node['pos']['endOffset']], [$entry['startByte'], $entry['endByte']]);
            }
        }
        $this->assertSame(json_decode(Carve::toJson($input), true), $tree, 'C2-L-003: the tree is the same');
    }

    public function testC2L016ToC2L023FactsTheWriterReads(): void
    {
        $facts = self::facts(". a\n. b\n");
        $this->assertTrue($facts['/children/0']['bare']);
        $this->assertFalse(self::facts("1. a\n2. b\n")['/children/0']['bare']);
        $this->assertTrue(self::facts("::: >\nq\n:::\n")['/children/0']['fenced']);
        $this->assertFalse(self::facts("> q\n")['/children/0']['fenced']);
        $this->assertTrue(self::facts("::: \\\na\n:::\n")['/children/0']['hardBreakOpener']);
        $this->assertFalse(self::facts("{.hardbreaks}\n:::\na\n:::\n")['/children/0']['hardBreakOpener']);
        $facts = self::facts("| a |\n+ b |\n");
        $this->assertSame('pipe', $facts['/children/0']['sourceFormat']);
        $this->assertSame(['startByte' => 0, 'endByte' => 11, 'continuationLines' => 1], $facts['/children/0/children/0/children/0']);
        $this->assertSame('tsv', self::facts("{table}\n``` tsv\na\n```\n")['/children/0']['sourceFormat']);
        $this->assertArrayNotHasKey('headerRows', self::facts("{table}\n```csv\na\n```\n")['/children/0']);
    }

    public function testC2L055IncludeOptionsKeepTheirSpelling(): void
    {
        $facts = self::facts("{{ a.crv @lines:\"1-2\" @shift:1 @code }}\n");
        $this->assertSame(['lines' => '"1-2"', 'shift' => '1'], $facts['/children/0']['optionSpelling']);
        $this->assertArrayNotHasKey('optionSpelling', self::facts("{{ a.crv @code }}\n")['/children/0']);
        $tree = json_decode(Carve::toJson("{{ a.crv @lines:\"1-2\" }}\n"), true);
        $this->assertSame(['lines' => '1-2'], $tree['children'][0]['options']);
    }

    public function testC2L049RawRefIsTheReferenceAsTyped(): void
    {
        $facts = self::facts("> [a\n> b][Key]{.c} and ![i][]\n\n[key]: /u\n[i]: /i\n");
        $this->assertSame("[a\nb][Key]", $facts['/children/0/children/0/children/0']['rawRef']);
        $this->assertSame('[i][]', $facts['/children/0/children/0/children/2']['rawRef']);
        $facts = self::facts("![a `]` b][k]\n\n[k]: /u\n");
        $this->assertSame('[a `]` b][k]', $facts['/children/0/children/0']['rawRef']);
    }

    public function testC2L047BlankLinesAroundBlocks(): void
    {
        $facts = self::facts("a\n\n\n- b\n\n  c\n- d\n");
        $this->assertSame([0, 2], [$facts['/children/0']['blankLinesBefore'], $facts['/children/0']['blankLinesAfter']]);
        $this->assertSame([2, 0], [$facts['/children/1']['blankLinesBefore'], $facts['/children/1']['blankLinesAfter']]);
        $this->assertSame(1, $facts['/children/1/children/0/children/0']['blankLinesAfter']);
        $this->assertSame(1, $facts['/children/1/children/0/children/1']['blankLinesBefore']);
        $this->assertArrayNotHasKey('/children/0/children/0', $facts, 'an inline node without a fact has no entry');
    }

    public function testC2L056ByteInputIsBase64(): void
    {
        $layout = Carve::parseWithLayout("a\xFFb")->layout;
        $this->assertSame(['base64', 'Yf9i'], [$layout['encoding'], $layout['source']]);
        $this->assertSame('crlf', Carve::parseWithLayout("a\r\nb")->layout['lineEndings']);
        $this->assertSame('mixed', Carve::parseWithLayout("a\r\nb\nc")->layout['lineEndings']);
        $this->assertSame('cr', Carve::parseWithLayout("a\rb")->layout['lineEndings']);
        $this->assertTrue(Carve::parseWithLayout("\u{FEFF}a")->layout['bom']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedOrders(): array
    {
        return [
            'index as number' => ['/children/10', '/children/2'],
            'children before title' => ['/children/0/children/0', '/children/0/title/0'],
            'caption before children' => ['/children/0/caption', '/children/0/children/0'],
            'inside before node' => ['/children/0/children/0', '/children/0'],
        ];
    }

    #[DataProvider('refusedOrders')]
    public function testC2L011EntriesStandInDocumentOrder(string $first, string $second): void
    {
        $record = [

            'version' => 2,
            'encoding' => 'utf-8',
            'source' => 'abc',
            'lineEndings' => 'none',
            'bom' => false,
            'nodes' => [
                ['path' => $first, 'startByte' => 0, 'endByte' => 1],
                ['path' => $second, 'startByte' => 0, 'endByte' => 1],
            ],
        ];
        $this->expectException(LayoutError::class);
        Carve::validateLayout((string)json_encode($record));
    }

    public function testC2L011ExtensionSlotsFollowTheirDeclaration(): void
    {
        $tree = [
            'type' => 'document',
            'extensions' => [['name' => 'x.y', 'version' => '1', 'required' => false, 'slots' => ['b', 'a']]],
            'children' => [['type' => 'block_extension', 'name' => 'x.y', 'slots' => ['b' => [], 'a' => []]]],
        ];
        $record = [

            'version' => 2,
            'encoding' => 'utf-8',
            'source' => 'abc',
            'lineEndings' => 'none',
            'bom' => false,
            'nodes' => [
                ['path' => '/children/0/caption', 'startByte' => 0, 'endByte' => 1],
                ['path' => '/children/0/slots/b/0', 'startByte' => 0, 'endByte' => 1],
                ['path' => '/children/0/slots/a/0', 'startByte' => 0, 'endByte' => 1],
            ],
        ];
        $this->assertCount(3, Carve::validateLayout((string)json_encode($record), $tree)['nodes']);
        [$record['nodes'][1], $record['nodes'][2]] = [$record['nodes'][2], $record['nodes'][1]];
        $this->expectException(LayoutError::class);
        Carve::validateLayout((string)json_encode($record), $tree);
    }

    public function testC2L011FallbackHasNoEntry(): void
    {
        $record = [

            'version' => 2,
            'encoding' => 'utf-8',
            'source' => 'abc',
            'lineEndings' => 'none',
            'bom' => false,
            'nodes' => [
                ['path' => '/children/0/fallback/0', 'startByte' => 0, 'endByte' => 1],
            ],
        ];
        $this->expectException(LayoutError::class);
        Carve::validateLayout((string)json_encode($record));
    }

    /**
     * @param string $source
     *
     * @return array<string, array<string, mixed>>
     */
    private static function facts(string $source): array
    {
        $out = [];
        foreach (Carve::parseWithLayout($source)->layout['nodes'] as $entry) {
            $path = $entry['path'];
            unset($entry['path']);
            $out[$path] = $entry;
        }

        return $out;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     *
     * @return mixed
     */
    private static function at(array $tree, string $path): mixed
    {
        $node = $tree;
        foreach (array_slice(explode('/', $path), 1) as $segment) {
            $node = is_array($node) ? $node[ctype_digit($segment) ? (int)$segment : $segment] ?? null : null;
        }

        return $node;
    }

    public function testC2L020WrapsOfACellJoinedFromContinuationRows(): void
    {
        $layout = Carve::parseWithLayout("|= A |= B |\n| 1 | 2 |\n+ a | b |\n")->layout;
        $cells = array_values(array_filter($layout['nodes'], static fn (array $n): bool => isset($n['wraps'])));
        $this->assertSame([
            [
                'path' => '/children/0/children/1/children/0/children/0',
                'startByte' => 13,
                'endByte' => 26,
                'wraps' => [['line' => 1, 'path' => '/children/0/children/1/children/0/children/0/children/0/children/0', 'offset' => 2]],
            ],
            [
                'path' => '/children/0/children/1/children/0/children/1',
                'startByte' => 17,
                'endByte' => 30,
                'wraps' => [['line' => 1, 'path' => '/children/0/children/1/children/0/children/1/children/0/children/0', 'offset' => 2]],
            ],
        ], $cells);
        $this->assertSame("|= A |= B |\n| 1 | 2 |\n+ a | b |\n", Carve::format("|= A |= B |\n| 1 | 2 |\n+ a | b |\n"));
    }

    public function testC2L048AWrapOfContentThatStartsInAContinuationRow(): void
    {
        $layout = Carve::parseWithLayout("| 1 | |\n+ a | b |\n")->layout;
        $cells = array_values(array_filter($layout['nodes'], static fn (array $n): bool => isset($n['wraps'])));
        $wraps = array_column($cells, 'wraps');
        $text = '/children/0/children/0/children/0/children/1/children/0/children/0';
        $this->assertSame([['line' => 1, 'path' => $text, 'offset' => 0]], $wraps[1]);
    }

    public function testC2L048ACellThatOnlyAContinuationRowHolds(): void
    {
        $source = "| 1 | 2 |\n+ a | b | c |\n";
        $nodes = Carve::parseWithLayout($source)->layout['nodes'];
        $entries = array_values(array_filter($nodes, static fn (array $n): bool => isset($n['wraps'])));
        $this->assertSame([19, 22], [$entries[2]['startByte'], $entries[2]['endByte']]);
        $this->assertSame(0, $entries[2]['wraps'][0]['offset']);
        $this->assertSame("| 1 | 2 | |\n+ a | b | c |\n", Carve::format($source));
    }
}
