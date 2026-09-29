<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommentPayloadColumnsTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function cases(): array
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/fixtures/comment-payload-columns.json'), true, flags: JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($cases as $case) {
            $rows[$case['name']] = [$case['source'], $case['expected']];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function payloads(string $source): array
    {
        $found = [];
        $walk = function (Node $node) use (&$walk, &$found): void {
            if ($node instanceof Comment) {
                $found[] = $node->getContent();
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk((new CarveConverter())->parse($source));

        return $found;
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testPayloadAndFormat(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->payloads($source));
        $formatted = CarveConverter::toCarve($source);
        $this->assertSame($expected, $this->payloads($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testNestedTermKeepsTheCanonicalItemColumn(): void
    {
        $this->assertSame(
            "- a\n  :: t\n   %%%\n  x\n   %%%\n  : d\n",
            CarveConverter::toCarve("- a\n  :: t\n    %%%\n  x\n    %%%\n  :  d\n"),
        );
    }
}
