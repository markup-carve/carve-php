<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/carve-cli-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/main.crv', "# A\n\n### B\n\n{{ child.crv }}\n");
        file_put_contents($this->dir . '/child.crv', "One.\n\n[x]()\n");
        file_put_contents($this->dir . '/clean.crv', "ok\n");
    }

    protected function tearDown(): void
    {
        foreach (['main.crv', 'child.crv', 'clean.crv'] as $name) {
            unlink($this->dir . '/' . $name);
        }
        rmdir($this->dir);
    }

    /**
     * @param array<string> $args
     * @param string $stdin
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function call(array $args, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $stdin);
        rewind($in);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function testC2W103LintPrintsFindingsAndExitsOne(): void
    {
        [$status, $out] = self::call(['lint', $this->dir . '/main.crv']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString($this->dir . '/main.crv:3:1: warning heading-level-skip This heading skips', $out);
        // C2-N-087: a finding of an included file is placed in that file.
        $this->assertStringContainsString("child.crv:3:1: warning empty-link-destination This link has an empty destination.\n", $out);
        $this->assertSame([0, '', ''], self::call(['lint', $this->dir . '/clean.crv']));
    }

    public function testC2W109LintOptionsAndJson(): void
    {
        $rules = '{"heading-level-skip":"off"}';
        [$status, $out] = self::call(['lint', '--json', '--no-includes', '--rules', $rules, $this->dir . '/main.crv']);
        $this->assertSame(1, $status);
        $data = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($this->dir . '/main.crv', $data['files'][0]['file']);
        $this->assertSame(['include-no-resolver'], array_column($data['files'][0]['diagnostics'], 'code'));
        $this->assertStringStartsWith("{\n  \"files\": [", $out);
        [$status, , $err] = self::call(['lint', '--rules', '{"nope":"on"}', $this->dir . '/main.crv']);
        $this->assertSame(2, $status);
        $this->assertSame("carve[usage]: option rules: invalid rule nope\n", $err);
    }

    public function testDiagnosticsAndErrors(): void
    {
        [$status, $out] = self::call(['diagnostics'], "```js\nx");
        $this->assertSame(0, $status);
        $this->assertStringStartsWith('<stdin>:1:1: error unclosed-code-fence The ``` fence opened here', $out);
        $this->assertSame([2, '', "carve[input-unreadable]: nope.crv: cannot be read\n"], self::call(['lint', 'nope.crv']));
        $this->assertSame([2, '', "carve[usage]: unknown option --bogus\n"], self::call(['lint', '--bogus']));
        $this->assertSame([0, "<p>a</p>\n", ''], self::call([], 'a'));
    }
}
