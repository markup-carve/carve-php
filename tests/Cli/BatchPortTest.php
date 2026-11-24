<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class BatchPortTest extends TestCase
{
    /**
     * @param array<string> $args
     * @param string $input
     *
     * @return array{int, string, string}
     */
    private static function callCommand(array $args, string $input = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $input);
        rewind($in);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function testFixUsesCatalogAndLeavesConflictingRanges(): void
    {
        $this->assertSame([0, "a *b* {~c~}\n\n- x\n", ''], self::callCommand(['fix'], "a **b** ~~c~~\n\n+ x\n"));
        [$status, $source] = self::callCommand(['lint', '--fix', '--upgrade'], "a ~s~ b\n");
        $this->assertSame(1, $status);
        $this->assertSame("a ~s~ b\n", $source);
        $this->assertSame([0, "a {~s~} b\n", ''], self::callCommand(['fix', '--rules', '{"djot-subscript-tilde":"off"}'], "a ~s~ b\n"));
        $this->assertSame("text\n+ item\n", self::callCommand(['fix'], "text\n+ item\n")[1]);
    }

    public function testFlattenExpandsAndReportsUnresolved(): void
    {
        $dir = sys_get_temp_dir() . '/carve-batch-' . bin2hex(random_bytes(5));
        mkdir($dir);
        file_put_contents($dir . '/a.crv', "{{ b.crv }}\n\nend\n");
        file_put_contents($dir . '/b.crv', "= B\n\nbody\n");
        try {
            $this->assertSame([0, "= B\n\nbody\n\nend\n", ''], self::callCommand(['flatten', $dir . '/a.crv']));
            file_put_contents($dir . '/a.crv', "{{ missing.crv }}\n");
            [$status, $output, $error] = self::callCommand(['flatten', $dir . '/a.crv']);
            $this->assertSame(1, $status);
            $this->assertSame('', $output);
            $this->assertStringStartsWith('carve[include-unresolved]:', $error);
            $this->assertStringContainsString('include-not-found', $error);
        } finally {
            unlink($dir . '/a.crv');
            unlink($dir . '/b.crv');
            rmdir($dir);
        }
    }

    public function testStrictRenderAndContainerRules(): void
    {
        [$status, $html, $error] = self::callCommand(['render', '--strict'], "[a](javascript:x)\n");
        $this->assertSame(1, $status);
        $this->assertSame("<p><a href=\"\">a</a></p>\n", $html);
        $this->assertStringContainsString('<stdin>:1:5: error destination-denied', $error);
        $this->assertSame(
            "<blockquote>\n  <blockquote><p>after</p></blockquote>\n</blockquote>",
            Carve::toHtml("> > %%%\n> > hidden\n> %%%\n> > after\n"),
        );
        $entries = Carve::lint("> + x\n", ['rules' => ['djot-plus-bullet' => 'on']]);
        $plus = array_values(array_filter($entries, static fn ($entry): bool => $entry->code === 'djot-plus-bullet'));
        $this->assertCount(1, $plus);
        $this->assertSame(2, $plus[0]->startOffset);
    }

    public function testOutputCollectsAllFilesAndNonHtmlTargets(): void
    {
        $dir = sys_get_temp_dir() . '/carve-output-' . bin2hex(random_bytes(5));
        mkdir($dir);
        $one = $dir . '/one.crv';
        $two = $dir . '/two.crv';
        $product = $dir . '/out.html';
        file_put_contents($one, "one\n");
        file_put_contents($two, "two\n");
        try {
            $this->assertSame([0, '', ''], self::callCommand(['render', '-o', $product, $one, $two]));
            $this->assertSame("<p>one</p>\n<p>two</p>\n", file_get_contents($product));
            $this->assertSame([0, '', ''], self::callCommand(['render', '--to', 'plain', '-o', $product, $one]));
            $this->assertSame("one\n", file_get_contents($product));
        } finally {
            unlink($one);
            unlink($two);
            unlink($product);
            rmdir($dir);
        }
    }

    public function testFixWriteReportsRemainingFindingsOnStdout(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'carve-fix-');
        self::assertNotFalse($file);
        file_put_contents($file, "a ~s~ b\n");
        try {
            [$status, $out, $err] = self::callCommand(['fix', '--write', '--upgrade', $file]);
            $this->assertSame(1, $status);
            $this->assertStringContainsString('deprecated-bare-strike', $out);
            $this->assertSame('', $err);
            $this->assertSame("a ~s~ b\n", file_get_contents($file));
        } finally {
            unlink($file);
        }
    }
}
