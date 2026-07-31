<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 section 3 pins field names as spec surface. The encoder used to omit
 * any field whose value equalled the node's property default, which made two
 * documents differ in FIELD SET rather than in value:
 *
 *   - a tight list dropped `tight`, a loose one kept it
 *   - `# H` dropped `level`, `## H` kept it
 *
 * A consumer then cannot tell "absent because it is the default" from "absent
 * because this engine does not support it" - the guess PART 12 section 3 exists
 * to remove.
 *
 * These pin the fields the reference publishes on every node of a type. The
 * second test is the general form: it sweeps the corpus rather than naming
 * cases, so a new node type cannot quietly reintroduce the hole.
 */
class AlwaysPublishedFieldsTest extends TestCase
{
    /**
     * Fields the reference emits on EVERY occurrence of the type, limited to
     * the ones this engine actually models. Kept short on purpose: the encoder
     * holds the full derived list, and this is the check that it is applied.
     *
     * @var array<string, array<string>>
     */
    private const EXPECTED = [
        'list' => ['tight', 'ordered', 'items'],
        'heading' => ['level', 'children'],
        'table_cell' => ['header', 'children'],
        'math' => ['content', 'display'],
    ];

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<int, array<string, mixed>>> $found
     */
    private function collect(array $node, array &$found): void
    {
        if (isset($node['type']) && is_string($node['type'])) {
            $found[$node['type']][] = $node;
        }
        foreach ($node as $key => $value) {
            if ($key === 'pos' || !is_array($value)) {
                continue;
            }
            if (isset($value['type'])) {
                $this->collect($value, $found);

                continue;
            }
            foreach ($value as $item) {
                if (is_array($item)) {
                    $this->collect($item, $found);
                }
            }
        }
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function nodesOf(string $source): array
    {
        $encoded = (new AstCodec())->encode((new BlockParser())->parse($source));
        $found = [];
        $this->collect($encoded, $found);

        return $found;
    }

    public function testATightListStillPublishesTight(): void
    {
        // `tight` defaults to true, so the default-suppression dropped it from
        // exactly the lists where it was true - and kept it where it was false.
        $tight = $this->nodesOf("- a\n- b\n")['list'][0];
        $loose = $this->nodesOf("- a\n\n- b\n")['list'][0];

        $this->assertArrayHasKey('tight', $tight);
        $this->assertArrayHasKey('tight', $loose);
        $this->assertTrue($tight['tight']);
        $this->assertFalse($loose['tight']);
    }

    public function testTheFieldSetDoesNotDependOnTheValue(): void
    {
        $tight = array_keys($this->nodesOf("- a\n- b\n")['list'][0]);
        $loose = array_keys($this->nodesOf("- a\n\n- b\n")['list'][0]);
        sort($tight);
        sort($loose);

        $this->assertSame(
            $tight,
            $loose,
            'a tight and a loose list must differ in the VALUE of `tight`, not in whether it is there',
        );
    }

    public function testEveryCorpusNodeCarriesItsTypesRequiredFields(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/tests/spec/tests/corpus/*.crv') ?: [];
        $this->assertNotEmpty($files, 'the spec corpus submodule must be checked out');

        $missing = [];
        foreach ($files as $file) {
            $found = [];
            $this->collect(
                (new AstCodec())->encode((new BlockParser())->parse((string)file_get_contents($file))),
                $found,
            );
            foreach (self::EXPECTED as $type => $fields) {
                foreach ($found[$type] ?? [] as $node) {
                    foreach ($fields as $field) {
                        if (!array_key_exists($field, $node)) {
                            $missing[] = basename($file) . ": {$type} is missing `{$field}`";
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_slice(array_unique($missing), 0, 10));
    }
}
