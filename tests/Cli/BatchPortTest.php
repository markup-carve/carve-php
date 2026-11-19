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
        [$status, $source] = self::callCommand(['lint', '--fix'], "a ~s~ b\n");
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
}
