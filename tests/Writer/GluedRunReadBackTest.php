<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-117, C2-W-124: a span whose closer a backtick run joins reads on to the next run of its own width, which may
 * lie past several nodes. The written source is read back, and every node it takes is reported (carve#6341).
 */
final class GluedRunReadBackTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function cases(): array
    {
        $code = static fn (string $v): array => ['type' => 'code', 'value' => $v];
        $math = static fn (string $v): array => ['type' => 'math', 'display' => false, 'value' => $v];
        $raw = static fn (string $v): array => ['type' => 'raw_inline', 'format' => 'html', 'value' => $v];

        return [
            'two-backtick fence reads past a raw and a math span' => [
                [$code('`q'), $raw('r'), $math('m'), $raw('r')],
                "`` `q ```r`{=html}\$`m``r`{=html}\n",
                ['field-unspellable 0/value', 'structure-unspellable 1', 'structure-unspellable 2', 'structure-unspellable 3'],
            ],
            'an unclosed math opener reads as text and its neighbor as wider code' => [
                [$math('q`'), $code('m')],
                "\$`` q` ```m`\n",
                ['structure-unspellable 0', 'structure-unspellable 1'],
            ],
            'math taken into a code span behind the run' => [
                [$math('`'), $math('a``b'), $math('`q'), $code('a``b'), $math('r')],
                "\$`` ` ``\$`a``b`\$`` `q ```a``b`\$`r`\n",
                ['field-unspellable 2/value', 'structure-unspellable 3', 'structure-unspellable 4'],
            ],
            'spans behind the taken ones read back' => [
                [$code('a'), $raw('` `'), $math('r'), $math('`'), $math('r'), $math('r')],
                "`a``` ` ` ``{=html}\$`r`\$`` ` ``\$`r`\$`r`\n",
                ['field-unspellable 0/value', 'structure-unspellable 1', 'structure-unspellable 2', 'structure-unspellable 3'],
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
    public function testEveryTakenNodeIsReported(array $children, string $source, array $losses): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $children]]];
        $result = Carve::writeWithDiagnostics($tree);
        $found = array_map(
            static fn ($d): string => $d->code . ' ' . str_replace('/children/0/children/', '', (string)$d->path),
            $result->diagnostics,
        );
        sort($found);

        $this->assertSame($source, $result->source);
        $this->assertSame($losses, $found);
    }
}
