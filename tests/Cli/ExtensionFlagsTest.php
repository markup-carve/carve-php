<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-T-010: `--extensions` and `--extension-options` (carve#6436).
 */
final class ExtensionFlagsTest extends TestCase
{
    /**
     * @var string
     */
    private const TABS = ":::: tabs\n::: tab \"A\"\na\n:::\n::::\n";

    public function testPinnedClauseCases(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $ids = ['CC-533', 'CC-1717', 'CC-1718', 'CC-1719', 'CC-1720', 'CC-1721', 'CC-1722'];
        $seen = [];
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], $ids, true)) {
                continue;
            }
            $seen[] = $case['id'];
            [$status, $stdout, $stderr] = self::runCommand($case['args'], $case['files']['a.crv']['text']);
            $expected = $case['expected'];
            self::assertSame($expected['status'], $status, $case['id'] . ' status');
            if (isset($expected['stdout'])) {
                self::assertSame($expected['stdout'], $stdout, $case['id'] . ' output');
            }
            if (isset($expected['stderrCategory'])) {
                self::assertStringStartsWith('carve[' . $expected['stderrCategory'] . ']:', $stderr, $case['id'] . ' error');
            }
        }
        self::assertCount(count($ids), $seen);
    }

    /**
     * @return array<string, array{0: array<string>}>
     */
    public static function refused(): array
    {
        return [
            'options for an extension the list does not register' => [
                ['render', '--extension-options', '{"carve.tabs":{"mode":"aria"}}', 'a.crv'],
            ],
            'options beside another listed extension' => [
                ['render', '--extensions', '["carve.details"]', '--extension-options', '{"carve.tabs":{}}', 'a.crv'],
            ],
            'not a list' => [['render', '--extensions', '"carve.tabs"', 'a.crv']],
            'a list item that is no string' => [['render', '--extensions', '[1]', 'a.crv']],
            'bad JSON' => [['render', '--extensions', '[', 'a.crv']],
            'options not an object' => [['render', '--extensions', '["carve.tabs"]', '--extension-options', '[]', 'a.crv']],
            'options of an extension not an object' => [
                ['render', '--extensions', '["carve.tabs"]', '--extension-options', '{"carve.tabs":"aria"}', 'a.crv'],
            ],
            'an unknown tabs mode' => [
                ['render', '--extensions', '["carve.tabs"]', '--extension-options', '{"carve.tabs":{"mode":"x"}}', 'a.crv'],
            ],
            'a command without the flag' => [['diagnostics', '--extensions', '[]', 'a.crv']],
        ];
    }

    /**
     * @param array<string> $args
     */
    #[DataProvider('refused')]
    public function testRefusedWithUsage(array $args): void
    {
        [$status, $stdout, $stderr] = self::runCommand($args, self::TABS);
        self::assertSame(2, $status);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('carve[usage]:', $stderr);
    }

    public function testAstRegistersTheExtension(): void
    {
        [$status, $stdout] = self::runCommand(['ast', '--no-positions', '--extensions', '["carve.tabs"]', 'a.crv'], self::TABS);
        self::assertSame(0, $status);
        $tree = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('carve.tabs', $tree['children'][0]['name']);
    }

    public function testStandardRendererOfAFence(): void
    {
        $args = ['render', '--extensions', '["carve.fencedRender.mermaid"]', 'a.crv'];
        [$status, $stdout] = self::runCommand($args, "```mermaid\ngraph TD\n```\n");
        self::assertSame(0, $status);
        self::assertSame("<pre class=\"mermaid\" role=\"img\" aria-label=\"mermaid\">graph TD</pre>\n", $stdout);
    }

    /**
     * @param array<string> $args
     * @param string $source The text of `a.crv`.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runCommand(array $args, string $source): array
    {
        $directory = sys_get_temp_dir() . '/carve-extension-flags-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory . '/a.crv', $source);
        $previous = getcwd();
        chdir($directory);
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        try {
            $status = Command::main($args, $in, $out, $err);
            rewind($out);
            rewind($err);

            return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
        } finally {
            chdir((string)$previous);
            unlink($directory . '/a.crv');
            rmdir($directory);
        }
    }
}
