<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Block;

use PHPUnit\Framework\TestCase;

final class ExampleBlockTreesTest extends TestCase
{
    /**
     * @var array<string, int>
     */
    private const INLINE_LEAVES = [
        'paragraph' => 1,
        'heading' => 1,
        'definition_term' => 1,
        'cell_paragraph' => 1,
        'line' => 1,
        'citation_definition' => 1,
    ];

    /**
     * @var array<string, int>
     */
    private const DROPPED = ['generatedId' => 1, 'resolvedAlign' => 1, 'stages' => 1, 'srcByteLength' => 1, 'astVersion' => 1];

    /**
     * @param mixed $v
     *
     * @return mixed
     */
    private static function blockOnly(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map(self::blockOnly(...), $v);
        }
        $type = $v['type'] ?? null;
        $out = [];
        foreach ($v as $k => $x) {
            if (isset(self::DROPPED[$k])) {
                continue;
            }
            if ($k === 'children' && is_string($type) && isset(self::INLINE_LEAVES[$type])) {
                continue;
            }
            if (($k === 'title' || $k === 'short') && is_string($type) && $type !== 'document') {
                $out[$k] = 'INLINE';

                continue;
            }
            $out[$k] = self::blockOnly($x);
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * @return void
     */
    public function testEveryExampleBlockTreeMatchesThePinnedFixture(): void
    {
        $fixtureFile = dirname(__DIR__) . '/fixtures/example-trees.jsonl';
        $examples = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/examples.json'), true);
        $this->assertIsArray($examples);
        $lines = file($fixtureFile, FILE_IGNORE_NEW_LINES) ?: [];
        $source = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/example-trees-source.json'), true);
        $pinned = (string)file_get_contents(dirname(__DIR__, 2) . '/spec/PINNED');
        $this->assertIsArray($source);
        $this->assertStringContainsString('carve ' . $source['specCommit'] . ' spec/0.2', $pinned);
        $this->assertStringContainsString('carve-rs ' . $source['rustCommit'] . ' v2', $pinned);
        $this->assertCount(count($examples), $lines);
        $failed = [];
        foreach ($examples as $i => $ex) {
            $this->assertIsArray($ex);
            $input = $ex['input'] ?? null;
            $this->assertIsString($input);
            $fixture = json_decode($lines[$i] ?? 'null', true);
            $want = json_encode(self::blockOnly(is_array($fixture) ? $fixture['tree'] ?? null : null));
            $got = json_encode(self::blockOnly(BlockClausesTest::encode($input)));
            if ($want !== $got) {
                $failed[] = $i;
            }
        }
        $this->assertSame([], $failed);
    }
}
