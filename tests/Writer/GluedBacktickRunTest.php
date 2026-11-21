<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5540, C2-W-117, C2-W-124: a backtick run directly behind a code or math span reads back as part of that
 * span, and the writer reports the loss at the nodes that do not read back.
 */
final class GluedBacktickRunTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function cases(): array
    {
        $math = ['type' => 'math', 'display' => false, 'value' => '.'];
        $code = ['type' => 'code', 'value' => 'c'];
        $raw = ['type' => 'raw_inline', 'format' => 'html', 'value' => '<b>'];
        $text = static fn (string $v): array => ['type' => 'text', 'value' => $v];
        $paragraph = static fn (array $kids): array => ['type' => 'paragraph', 'children' => $kids];
        $line = ['type' => 'line', 'children' => [['type' => 'emphasis', 'children' => [$math, $code]]]];
        $lineBlock = ['type' => 'line_block', 'children' => [$line]];

        return [
            'math then code' => [
                [$paragraph([$math, $code])],
                "$`.``c`\n",
                ['field-unspellable /children/0/children/0/value', 'structure-unspellable /children/0/children/1'],
            ],
            'math then code inside text' => [
                [$paragraph([$text('a '), $math, $code, $text(' b')])],
                "a $`.``c` b\n",
                ['field-unspellable /children/0/children/1/value', 'structure-unspellable /children/0/children/2'],
            ],
            'math then raw inline' => [
                [$paragraph([$math, $raw])],
                "$`.``<b>`{=html}\n",
                ['field-unspellable /children/0/children/0/value', 'structure-unspellable /children/0/children/1'],
            ],
            'code then raw inline' => [
                [$paragraph([$code, $raw])],
                "`c``<b>`{=html}\n",
                ['structure-unspellable /children/0/children/0'],
            ],
            'math in emphasis in a line block' => [
                [$lineBlock],
                "::: |\n/$`.``c`/\n:::\n",
                [
                    'field-unspellable /children/0/children/0/children/0/children/0/value',
                    'structure-unspellable /children/0/children/0/children/0/children/1',
                ],
            ],
            'math with attributes then code' => [
                [$paragraph([$math + ['attrs' => ['classes' => ['k']]], $code])],
                "$`.`{.k}`c`\n",
                [],
            ],
            'code then math' => [
                [$paragraph([$code, $math])],
                "`c`$`.`\n",
                [],
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
    public function testGluedRun(array $children, string $source, array $losses): void
    {
        $result = Carve::writeWithDiagnostics(['type' => 'document', 'astVersion' => '2.0', 'children' => $children]);
        $found = array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics);
        sort($found);
        $this->assertSame($source, $result->source);
        $this->assertSame($losses, $found);
    }
}
