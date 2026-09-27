<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `blockNode` and `inlineNode` unions are validated through a type lookup
 * rather than one `if`/`then` per admitted type. These pin the first violation
 * the generic evaluation reported, including the `non_breaking_space` branch
 * whose `if` carries no `required`.
 */
class TheNodeDispatchReportsTheSameFirstViolationTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function inlineNodes(): array
    {
        $at = '$.children[0].children[0]';

        return [
            'valid text' => [['type' => 'text', 'value' => 'x'], null],
            'text missing its value' => [['type' => 'text'], "{$at} is missing `value`, which the schema requires"],
            'text with a wrong value type' => [['type' => 'text', 'value' => 3], "{$at}.value is the number 3 where the schema requires string"],
            'unlisted type' => [['type' => 'bogus'], "{$at}.type is the string \"bogus\", which the schema does not list"],
            'non-string type' => [['type' => 7], "{$at}.type is the number 7, which the schema does not list"],
            'no type' => [['value' => 'x'], "{$at} is missing `type`, which the schema requires"],
            'not an object' => ['str', "{$at} is the string \"str\" where the schema requires object"],
            'required-less branch' => [['type' => 'non_breaking_space', 'value' => 1], "{$at} carries `value`, which the schema does not name"],
        ];
    }

    #[DataProvider('inlineNodes')]
    public function testTheFirstViolationIsUnchanged(mixed $inline, ?string $expected): void
    {
        $this->assertSame($expected, AstSchema::firstViolation($this->document($inline)));
    }

    public function testAnExemptTypeSkipsTheDispatch(): void
    {
        $this->assertNull(AstSchema::firstViolation($this->document(['type' => 'bogus']), ['bogus']));
    }

    public function testABlockNodeIsDispatchedToo(): void
    {
        $payload = ['type' => 'document', 'srcByteLength' => 0, 'children' => [['type' => 'heading', 'children' => []]]];

        $this->assertSame('$.children[0] is missing `level`, which the schema requires', AstSchema::firstViolation($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(mixed $inline): array
    {
        return ['type' => 'document', 'srcByteLength' => 0, 'children' => [['type' => 'paragraph', 'children' => [$inline]]]];
    }
}
