<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5962: a definition line that reads back as another tree is reported, as carve-js reports it.
 */
final class WriterDefinitionReadBackTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string, array<array{string, string}>}>
     */
    public static function definitions(): array
    {
        $link = ['type' => 'link_reference_definition', 'key' => 'a b', 'namespace' => 'link'];
        $note = fn (string $key): array => [
            'type' => 'footnote',
            'key' => $key,
            'namespace' => 'footnote',
            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'b']]]],
        ];

        return [
            'href with a space' => [$link + ['href' => '/a b'], "[a b]: /a b\n", [['structure-unspellable', '/children/1']]],
            'href with a tab' => [$link + ['href' => "/a\tb"], "[a b]: /a\tb\n", [['structure-unspellable', '/children/1']]],
            'href with a leading space' => [$link + ['href' => ' /x'], "[a b]:  /x\n", [['field-unspellable', '/children/1/href']]],
            'plain href' => [$link + ['href' => '/a%20b', 'tooltip' => 't "q"'], "[a b]: /a%20b \"t \\\"q\\\"\"\n", []],
            'link key with a bracket' => [
                ['type' => 'link_reference_definition', 'key' => ']][', 'namespace' => 'link', 'href' => '/x'],
                "[]][]: /x\n",
                [['structure-unspellable', '/children/1']],
            ],
            'note key with an opening bracket' => [$note('['), "[^[]: b\n", [['structure-unspellable', '/children/1']]],
            'note key with a leading space' => [$note(' <'), "[^ <]: b\n", [['field-unspellable', '/children/1/key']]],
            'abbreviation key with a pipe' => [
                ['type' => 'abbreviation_def', 'key' => 'k%|}', 'expansion' => 'x', 'namespace' => 'abbr'],
                "*[k%|}]: x\n",
                [['structure-unspellable', '/children/1']],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param string $line
     * @param array<array{string, string}> $expected
     */
    #[DataProvider('definitions')]
    public function testDefinitionReadBack(array $definition, string $line, array $expected): void
    {
        $tree = [
            'astVersion' => '2.0',
            'type' => 'document',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'plain z']]],
                $definition,
            ],
        ];

        $result = Carve::writeWithDiagnostics($tree);

        $this->assertSame("plain z\n\n" . $line, $result->source);
        $this->assertSame($expected, array_map(fn ($d): array => [$d->code, (string)$d->path], $result->diagnostics));
    }
}
