<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Test\Support\Held;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixLintDefaultCasesTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $ids = ['CC-1352', 'CC-1354'];
        for ($n = 1926; $n <= 1951; $n++) {
            $ids[] = 'CC-' . $n;
        }
        $cases = [];
        foreach ($spec['cases'] as $case) {
            if (in_array($case['id'] ?? null, $ids, true)) {
                $cases[$case['id']] = [$case];
            }
        }
        self::assertCount(count($ids), $cases);

        return $cases;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testPinnedFixCase(array $case): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $case['stdin']);
        rewind($in);
        $status = Command::main($case['args'], $in, $out, $err);
        rewind($out);
        $expected = $case['expected'];
        $actual = [(string)stream_get_contents($out), $status];
        if (Held::has($case['id'])) {
            // A held case must keep failing, so its hold goes the moment the spec case is fixed.
            $this->assertNotSame([$expected['stdout'], $expected['status']], $actual, $case['id'] . ' passes: remove its HELD.md line');

            return;
        }
        $this->assertSame($expected['stdout'], $actual[0]);
        $this->assertSame($expected['status'], $actual[1]);
    }
}
