<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5691, C2-W-088: a bracket pair at the end of a text is continued by the node behind it only where that
 * node is written starting with `(`, `[` or `{`; before a line comment it stays bare.
 */
final class BracketBeforeNodeTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unchanged(): array
    {
        return [
            'line comment' => ["a [x] %% c\n"],
            'empty task box shape' => ["a - [ ] %% c\n"],
            'in a quote' => ["> a -[x] %% c\n"],
        ];
    }

    #[DataProvider('unchanged')]
    public function testWritesBackUnchanged(string $input): void
    {
        $sidecar = Carve::formatWithDiagnostics($input);
        $this->assertSame($input, $sidecar->source);
        $this->assertSame([], $sidecar->diagnostics);
        $this->assertSame($input, Carve::format($input, ['normalize' => true]));
        $this->assertSame($input, Carve::write(Carve::parse($input)));
    }

    public function testBracketBeforeASpanReadsBack(): void
    {
        $text = ['type' => 'text', 'value' => 'a [x]'];
        $span = ['type' => 'span', 'children' => [['type' => 'text', 'value' => 'b']], 'attrs' => ['class' => 'c']];
        $tree = ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [$text, $span]]]];

        $result = Carve::writeWithDiagnostics($tree);
        $this->assertSame([], $result->diagnostics);
        $read = json_decode(Carve::toJson($result->source), true)['children'][0]['children'];
        $this->assertSame(['text', 'span'], array_column($read, 'type'));
        $this->assertSame('a [x]', $read[0]['value']);
    }
}
