<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class FmtCommandTest extends TestCase
{
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

    public function testC2W100FmtWritesTheCanonicalForm(): void
    {
        $this->assertSame([0, "- a\n- b\n", ''], self::call(['fmt'], "-   a\n-   b"));
        $this->assertSame([0, "- a\n", ''], self::call(['format'], '-  a'));
    }

    public function testC2W100CheckPrintsTheFirstChangedLine(): void
    {
        $this->assertSame([1, "<stdin>:2\n- -  b\n+ - b\n", ''], self::call(['fmt', '--check'], "- a\n-  b\n"));
        $this->assertSame([0, '', ''], self::call(['fmt', '--check'], "- a\n"));
    }

    public function testC2W100DiffIsUnified(): void
    {
        [$status, $out] = self::call(['fmt', '--diff'], "a  \n");
        $this->assertSame(1, $status);
        $this->assertSame("--- a/<stdin>\n+++ b/<stdin>\n@@ -1,1 +1,1 @@\n-a  \n+a\n", $out);
    }

    public function testC2W100WriteInPlace(): void
    {
        $file = sys_get_temp_dir() . '/carve-fmt-' . bin2hex(random_bytes(6)) . '.crv';
        file_put_contents($file, "-   a\n");
        $this->assertSame([0, '', ''], self::call(['fmt', '-w', $file]));
        $this->assertSame("- a\n", file_get_contents($file));
        unlink($file);
    }

    public function testC2W100FromAst(): void
    {
        $this->assertSame([0, "a\n", ''], self::call(['fmt', '--from-ast'], Carve::toJson('a')));
    }

    public function testC2W101Failures(): void
    {
        $this->assertSame([2, '', "carve[usage]: unknown option --bogus\n"], self::call(['fmt', '--bogus']));
        $this->assertSame([2, '', "carve[usage]: unknown option --check\n"], self::call(['ast', '--check']));
        $this->assertSame([2, '', "carve[usage]: cannot write standard input in place\n"], self::call(['fmt', '-w']));
        $this->assertSame([2, '', "carve[ast-json-invalid]: the input is not AST JSON\n"], self::call(['fmt', '--from-ast'], '{'));
        [$status, , $err] = self::call(['fmt', '/nonexistent/x.crv']);
        $this->assertSame(2, $status);
        $this->assertStringStartsWith('carve[input-unreadable]:', $err);
    }
}
