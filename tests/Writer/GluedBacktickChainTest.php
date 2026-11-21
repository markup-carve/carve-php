<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-117, C2-W-124: a glued backtick run goes on through every code span behind it and ends at the first raw span
 * or attribute block, so every node of the run but the one that reads back is reported.
 */
final class GluedBacktickChainTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function cases(): array
    {
        $math = ['type' => 'math', 'display' => false, 'value' => 'm'];
        $code = static fn (string $v): array => ['type' => 'code', 'value' => $v];
        $raw = ['type' => 'raw_inline', 'format' => 'html', 'value' => 'r'];
        $paragraph = static fn (array $kids): array => ['type' => 'paragraph', 'children' => $kids];

        return [
            'two code spans then raw inline' => [
                [$paragraph([$code('a'), $code('b'), $raw])],
                "`ab``r`{=html}\n",
                ['structure-unspellable /children/0/children/0', 'structure-unspellable /children/0/children/1'],
            ],
            'math, code, raw inline' => [
                [$paragraph([$math, $code('c'), $raw])],
                "$`m``c``r`{=html}\n",
                [
                    'field-unspellable /children/0/children/0/value',
                    'structure-unspellable /children/0/children/1',
                    'structure-unspellable /children/0/children/2',
                ],
            ],
            'math, two code spans' => [
                [$paragraph([$math, $code('c'), $code('d')])],
                "$`m``cd`\n",
                [
                    'field-unspellable /children/0/children/0/value',
                    'structure-unspellable /children/0/children/1',
                    'structure-unspellable /children/0/children/2',
                ],
            ],
            'code with attributes ends the run' => [
                [$paragraph([$code('a'), $code('b') + ['attrs' => ['classes' => ['k']]], $raw])],
                "`ab`{.k}`r`{=html}\n",
                ['structure-unspellable /children/0/children/1'],
            ],
        ];
    }

    /**
     * @param array<mixed> $children
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testEveryLostNodeOfTheRunIsReported(array $children, string $source, array $losses): void
    {
        $result = Carve::writeWithDiagnostics(['type' => 'document', 'astVersion' => '2.0', 'children' => $children]);
        $found = array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics);
        sort($found);
        $this->assertSame($source, $result->source);
        $this->assertSame($losses, $found);
    }
}
