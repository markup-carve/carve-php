<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Schema;

require_once dirname(__DIR__, 2) . '/src/Json/SchemaValidator.php';

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\SchemaValidator;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Every tree the engine makes for a specification example is valid against ast-schema.json
 * (C2-A-021) and keeps nodes only in the slots node-slots.json lists (C2-A-024 to C2-A-026).
 */
final class AstSchemaTest extends TestCase
{
    private static ?stdClass $schema = null;

    /**
     * @var array<string, array<string>>|null
     */
    private static ?array $slots = null;

    /**
     * @return array<string, array{0: string}>
     */
    public static function examples(): array
    {
        $out = [];
        foreach (Corpus::examples() as $id => $ex) {
            if (isset($ex['input']) && $ex['kind'] !== 'include') {
                $out[$id] = [$ex['input']];
            }
        }

        return $out;
    }

    /**
     * @param string $input
     *
     * @return void
     */
    #[DataProvider('examples')]
    public function testC2A021TreeMatchesSchema(string $input): void
    {
        $schema = self::schema();
        $tree = json_decode(Carve::toJson($input), false);
        $validator = new SchemaValidator($schema);
        $ok = $validator->valid($tree, $schema);
        $this->assertTrue($ok, implode("\n", array_slice($validator->errors, -5)));
    }

    /**
     * @param string $input
     *
     * @return void
     */
    #[DataProvider('examples')]
    public function testC2A024NodesStandInListedSlots(string $input): void
    {
        $tree = json_decode(Carve::toJson($input), false);
        $this->assertInstanceOf(stdClass::class, $tree);
        $bad = [];
        $this->walk($tree, $bad);
        $this->assertSame([], $bad);
    }

    /**
     * @param \stdClass $node
     * @param array<string> $bad
     *
     * @return void
     */
    private function walk(stdClass $node, array &$bad): void
    {
        $slots = self::slots()[$node->type] ?? [];
        foreach (get_object_vars($node) as $key => $value) {
            $key = (string)$key;
            $nodes = $this->nodesIn($value);
            if ($nodes === []) {
                continue;
            }
            if (!in_array($key, $slots, true) && !in_array($key . '.*', $slots, true)) {
                $bad[] = $node->type . '.' . $key;
            }
            foreach ($nodes as $child) {
                $this->walk($child, $bad);
            }
        }
    }

    /**
     * @param mixed $value
     *
     * @return array<\stdClass>
     */
    private function nodesIn(mixed $value): array
    {
        if ($value instanceof stdClass) {
            if (isset($value->type) && is_string($value->type)) {
                return [$value];
            }

            $children = array_map(fn ($v) => $this->nodesIn($v), array_values(get_object_vars($value)));

            return array_merge(...$children ?: [[]]);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                if ($v instanceof stdClass && isset($v->type) && is_string($v->type)) {
                    $out[] = $v;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * @return \stdClass
     */
    private static function schema(): stdClass
    {
        if (self::$schema === null) {
            $schema = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/ast-schema.json'), false);
            assert($schema instanceof stdClass);
            self::$schema = $schema;
        }

        return self::$schema;
    }

    /**
     * @return array<string, array<string>>
     */
    private static function slots(): array
    {
        if (self::$slots === null) {
            $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/node-slots.json'), true);
            self::$slots = $data['nodes'];
        }

        return self::$slots;
    }
}
