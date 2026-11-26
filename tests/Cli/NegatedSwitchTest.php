<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-T-008: a switch is `--name`, and `--no-name` turns it off.
 */
final class NegatedSwitchTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "# Title\n\n\"Quoted\" -- text\n\n| a | b |\n|---|---|\n| 1 | 2 |\n";

    /**
     * The commands whose switches the CLI table lists and this CLI reads a `.crv` file for.
     *
     * @var array<string>
     */
    private const COMMANDS = ['ast', 'diagnostics', 'fmt', 'lint', 'render'];

    /**
     * @var array<string>
     */
    private const ON_BY_DEFAULT = ['includes', 'positions', 'sections', 'smart-typography'];

    /**
     * Switches this CLI does not implement in either form.
     *
     * @var array<string>
     */
    private const ABSENT = ['help', 'version', 'envelope'];

    /**
     * Every switch of `data/cli.json` with each command that takes it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function switches(): array
    {
        $cli = json_decode((string)file_get_contents(__DIR__ . '/../../spec/data/cli.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($cli['flags'] as $flag) {
            if ($flag['kind'] !== 'switch' || in_array($flag['name'], self::ABSENT, true)) {
                continue;
            }
            foreach ($flag['commands'] as $command) {
                if (in_array($command, self::COMMANDS, true)) {
                    $cases[$command . ' --no-' . $flag['name']] = [$command, $flag['name']];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('switches')]
    public function testNoFormIsTheLastWord(string $command, string $name): void
    {
        $plain = self::runCommand([$command, 'a.crv']);
        $off = self::runCommand([$command, '--no-' . $name, 'a.crv']);
        self::assertNotSame(2, $off[0], $off[2]);
        self::assertSame($off, self::runCommand([$command, '--' . $name, '--no-' . $name, 'a.crv']));
        if (!in_array($name, self::ON_BY_DEFAULT, true)) {
            self::assertSame($plain, $off);
        }
        if ($name !== 'write') {
            $on = self::runCommand([$command, '--' . $name, 'a.crv']);
            self::assertSame($on, self::runCommand([$command, '--no-' . $name, '--' . $name, 'a.crv']));
        }
    }

    public function testRenderSwitchesTurnOff(): void
    {
        [, $html] = self::runCommand(['render', '--no-sections', '--no-smart-typography', 'a.crv']);
        self::assertStringNotContainsString('<section', $html);
        self::assertStringContainsString('"Quoted" -- text', $html);
        [, $html] = self::runCommand(['render', 'a.crv']);
        self::assertStringContainsString('<section id="Title">', $html);
        self::assertStringContainsString('“Quoted” – text', $html);
        [, $tree] = self::runCommand(['ast', '--no-positions', 'a.crv']);
        self::assertStringNotContainsString('"pos"', $tree);
        [, $tree] = self::runCommand(['ast', '--no-positions', '--positions', 'a.crv']);
        self::assertStringContainsString('"pos"', $tree);
    }

    /**
     * @return array<string, array{0: array<string>}>
     */
    public static function refused(): array
    {
        return [
            'a flag that takes a value' => [['render', '--no-mode', 'a.crv']],
            'a switch of another command' => [['ast', '--no-sections', 'a.crv']],
            'a value' => [['render', '--no-sections=1', 'a.crv']],
            'a negated no form' => [['lint', '--no-no-json', 'a.crv']],
            'an unknown name' => [['render', '--no-such-switch', 'a.crv']],
        ];
    }

    /**
     * @param array<string> $args
     */
    #[DataProvider('refused')]
    public function testRefusedWithUsage(array $args): void
    {
        [$status, $stdout, $stderr] = self::runCommand($args);
        self::assertSame(2, $status);
        self::assertSame('', $stdout);
        self::assertStringStartsWith('carve[usage]:', $stderr);
    }

    /**
     * @param array<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runCommand(array $args): array
    {
        $directory = sys_get_temp_dir() . '/carve-negated-switch-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory . '/a.crv', self::SOURCE);
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
