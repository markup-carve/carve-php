<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2ParityTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function trees(): array
    {
        $t = static fn (string $value): array => ['type' => 'text', 'value' => $value];

        return [
            'lone star behind a highlight' => [
                [$t('x '), ['type' => 'highlight', 'children' => [$t('h*')]], $t(' y*')],
                "x {=h*=} y*\n",
            ],
            'star whose later mark cannot close' => [[$t('x()*1*x')], "x()*1*x\n"],
            'editorial comment body is opaque' => [
                [$t('a {='), ['type' => 'critic_comment', 'value' => 'x=}']],
                "a {={#x=}#}\n",
            ],
            'mark inside emphasis cannot close outside' => [
                [$t('a {#'), ['type' => 'emphasis', 'children' => [$t('#x')]], $t('b')],
                "a {#{/\\#x/}b\n",
            ],
            'balanced brackets in citation' => [
                [['type' => 'inline_extension', 'name' => 'cite', 'children' => [$t('a [b] c')]]],
                ":cite[a [b] c]\n",
            ],
            'balanced brackets across emphasis in citation' => [
                [
                    [
                        'type' => 'inline_extension',
                        'name' => 'cite',
                        'children' => [
                            $t('a ['),
                            ['type' => 'emphasis', 'children' => [$t('b')]],
                            $t('] c'),
                        ],
                    ],
                ],
                ":cite[a [/b/] c]\n",
            ],
        ];
    }

    /**
     * @param array<mixed> $nodes
     * @param string $expected
     */
    #[DataProvider('trees')]
    public function testWriterMatchesNext2Source(array $nodes, string $expected): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $nodes]]];
        $result = Carve::writeWithDiagnostics($tree);
        $this->assertSame($expected, $result->source);
        $this->assertSame([], $result->codes());
    }
}
