<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Conformance;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Json\SchemaValidator;
use MarkupCarve\Carve2\Json\TreeError;
use MarkupCarve\Carve2\Json\TreeReader;
use MarkupCarve\Carve2\Test\Support\Corpus;
use MarkupCarve\Carve2\Test\Support\PositionChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The tree gate (`ast.md`, "The tree gate"; `trees/README.md`), on the trees this engine builds.
 */
final class TreeGateTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, string>}>
     */
    public static function sources(): array
    {
        $out = [];
        foreach (['syntax-blocks', 'syntax-inline', 'html', 'writer'] as $chapter) {
            foreach (Corpus::file('trees/examples-' . $chapter . '.json')['trees'] as $entry) {
                $out[$entry['id']] = [$entry['source'], $entry['options'] ?? [], []];
            }
        }
        foreach (Corpus::clauseCases() as $id => $case) {
            if (
                isset($case['input']) && !isset($case['expected']['refusal'])
                && (!isset($case['kind']) || in_array($case['kind'], ['parse', 'render'], true))
            ) {
                $files = [];
                foreach ($case['files'] ?? [] as $name => $file) {
                    $files[$name] = $file['text'] ?? hex2bin(str_replace(' ', '', $file['bytes'] ?? ''));
                }
                $options = Corpus::parseOptions(Corpus::caseOptions($case));
                $out[$id] = [$case['input'], $options, $files];
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function refused(): array
    {
        $out = [];
        foreach (Corpus::refusedTrees() as $id => $entry) {
            $out[$id] = [$entry];
        }

        return $out;
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function supplied(): array
    {
        $out = [];
        foreach (Corpus::file('trees/nodes.json')['trees'] as $entry) {
            $out[$entry['clauses'][0] . '/' . $entry['id']] = [$entry['tree']];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tree
     */
    #[DataProvider('supplied')]
    public function testC2A017SuppliedPositiveTreeIsRead(array $tree): void
    {
        $this->assertNotNull(TreeReader::read(json_encode($tree, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function includeTrees(): array
    {
        $out = [];
        foreach (Corpus::file('trees/examples-includes.json')['trees'] as $entry) {
            if (isset(CorpusTest::HELD[$entry['id']])) {
                continue;
            }
            $out[$entry['id']] = [$entry];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('includeTrees')]
    public function testC2N019IncludeTreeMeetsFixtureAndPositions(array $entry): void
    {
        $files = $entry['files'];
        $resolver = static function (array $request) use ($files): array {
            $text = $files[$request['path']] ?? null;

            return $text === null ? ['error' => 'not_found'] : ['text' => $text, 'id' => $request['path']];
        };
        $options = $entry['options'] ?? [];
        $includes = is_array($options['includes'] ?? null) ? $options['includes'] : [];
        $options['includes'] = $includes + ['resolver' => $resolver];
        $json = Carve2::toJson($entry['source'], $options);
        $tree = json_decode($json, true, 4096, JSON_THROW_ON_ERROR);
        $this->assertSame(Corpus::sorted($entry['tree']), Corpus::sorted($tree));
        $this->assertSame([], (new PositionChecker($entry['source'], $files))->failures($tree));
        $this->assertNotNull(TreeReader::read($json));
    }

    /**
     * C2-A-021, C2-A-139 to C2-A-159: the engine's tree of every source meets the schema and the position invariants.
     *
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<string, string> $files
     *
     * @return void
     */
    #[DataProvider('sources')]
    public function testC2A139TreeMeetsSchemaAndPositions(string $source, array $options, array $files): void
    {
        $json = Carve2::toJson($source, $options);
        $schema = self::schema();
        $validator = new SchemaValidator($schema);
        $this->assertTrue($validator->valid(json_decode($json, false), $schema), implode("\n", array_slice($validator->errors, -3)));
        $tree = json_decode($json, true, 4096, JSON_THROW_ON_ERROR);
        $this->assertSame([], (new PositionChecker($source, $files))->failures($tree));
        $this->assertNotNull(TreeReader::read($json));
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return void
     */
    #[DataProvider('refused')]
    public function testC2A018RefusedTreeIsRefused(array $entry): void
    {
        try {
            TreeReader::read(json_encode($entry['tree'], JSON_THROW_ON_ERROR));
        } catch (TreeError $error) {
            $this->assertSame($entry['refusedBy'] === 'schema', $error->reason === 'schema', $error->getMessage());

            return;
        }
        $this->fail($entry['id'] . ' is accepted');
    }

    public function testC2L023EqualPairHasEqualTrees(): void
    {
        $plain = static function (string $id): mixed {
            $example = Corpus::examples()[$id];
            $tree = json_decode(Carve2::toJson($example['input'], $example['options']), true, 4096, JSON_THROW_ON_ERROR);
            unset($tree['srcByteLength']);

            return Corpus::sorted(Corpus::withoutPositions($tree));
        };
        $this->assertSame($plain('syntax-blocks-267'), $plain('syntax-blocks-268'));
    }

    private static function schema(): stdClass
    {
        static $schema = null;
        if (!$schema instanceof stdClass) {
            $schema = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/ast-schema.json'), false);
            assert($schema instanceof stdClass);
        }

        return $schema;
    }
}
