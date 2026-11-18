<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Writer;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Test\Support\Held;
use MarkupCarve\Carve2\Test\Support\WriterGate;
use MarkupCarve\Carve2\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-001 to C2-W-135: every `write` entry of the corpus, byte for byte.
 */
final class WriteExamplesTest extends TestCase
{
    /**
     * Sidecar facts of the source layout (C2-L-046) that stand in for the producer, which is not in this package yet.
     *
     * @var array<string, array<string, mixed>>
     */
    private const LAYOUTS = [
        'writer-3' => ['nodes' => [['path' => '/children/0', 'bare' => false], ['path' => '/children/1', 'bare' => false]]],
        'writer-7' => [

            'nodes' => [
                [
                    'path' => '/children/0/children/0/children/1/children/0',
                    'wraps' => [
                        [
                            'line' => 1,
                            'path' => '/children/0/children/0/children/1/children/0/children/0/children/0',
                            'offset' => 4,
                        ],
                    ],
                ],
            ],
        ],
        'writer-13' => ['nodes' => [['path' => '/children/0', 'sourceFormat' => 'csv']]],
    ];

    /**
     * @return iterable<string, array<mixed>>
     */
    public static function examples(): iterable
    {
        $file = json_decode((string)file_get_contents(__DIR__ . '/../../spec/conformance/examples.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (is_array($file) ? $file['examples'] : [] as $example) {
            if ($example['kind'] === 'write' && !Held::has($example['id'])) {
                yield $example['id'] => [$example['id'], $example['options'] ?? [], $example['input'], $example['expected']['carve']];
            }
        }
    }

    /**
     * @param string $id
     * @param array<string, mixed> $options
     * @param string $input
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('examples')]
    public function testWrite(string $id, array $options, string $input, string $expected): void
    {
        $tree = WriterGate::tree($input);
        $result = Writer::writeWithDiagnostics($tree, self::LAYOUTS[$id] ?? null, $options);

        $this->assertSame($expected, $result->source);
        $this->assertSame($expected, Carve2::format($expected, $options, self::LAYOUTS[$id] ?? null));
    }
}
