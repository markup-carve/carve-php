<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#6972, C2-X-003: a required extension that `--extensions` registers is known to the tree reader
 * of `render --from-ast`.
 */
final class FromAstRequiredExtensionTest extends TestCase
{
    /**
     * @var string
     */
    private const TREE = '{"astVersion":"2.0","children":[{"children":[{"type":"text","value":"See "},'
        . '{"children":[],"fallback":[{"type":"text","value":"Main"}],"name":"carve.wiki","props":{"target":"Main"},'
        . '"source":{"body":"Main","form":"inline","name":"wiki"},"type":"inline_extension","version":"1"}],"type":"paragraph"}],'
        . '"extensions":[{"name":"carve.wiki","required":true,"slots":[],"version":"1"}],"srcByteLength":16,'
        . '"stages":["includeExpansion","referenceResolution","tableAlignment"],"type":"document"}';

    /**
     * @return array<string, array{0: array<string>, 1: string}>
     */
    public static function registered(): array
    {
        return [
            'html' => [[], "<p>See <a href=\"main\" class=\"wikilink\" data-wikilink=\"Main\">Main</a></p>\n"],
            'plain' => [['--to', 'plain'], "See Main\n"],
            'markdown' => [['--to', 'markdown'], "See Main\n"],
        ];
    }

    /**
     * @param array<string> $args
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('registered')]
    public function testRendersWhenExtensionsRegistersIt(array $args, string $expected): void
    {
        [$status, $stdout, $stderr] = self::cli(['render', '--from-ast', '--extensions', '["carve.wiki"]', ...$args]);

        self::assertSame('', $stderr);
        self::assertSame($expected, $stdout);
        self::assertSame(0, $status);
    }

    /**
     * @return array<string, array{0: array<string>}>
     */
    public static function unregistered(): array
    {
        return [
            'no --extensions' => [[]],
            'another extension' => [['--extensions', '["carve.details"]']],
            'another extension, plain' => [['--extensions', '["carve.details"]', '--to', 'plain']],
        ];
    }

    /**
     * @param array<string> $args
     *
     * @return void
     */
    #[DataProvider('unregistered')]
    public function testRefusesWhenItIsNotRegistered(array $args): void
    {
        [$status, $stdout, $stderr] = self::cli(['render', '--from-ast', ...$args]);

        self::assertSame("carve[ast-json-invalid]: required-extension at /extensions/0\n", $stderr);
        self::assertSame('', $stdout);
        self::assertSame(2, $status);
    }

    /**
     * markup-carve/carve#6980, C2-A-188: `fmt --from-ast` reads the tree as `render --from-ast` does.
     *
     * @return array<string, array{0: array<string>}>
     */
    public static function unregisteredFmt(): array
    {
        return [
            'no --extensions' => [[]],
            'another extension' => [['--extensions', '["carve.details"]']],
            'another extension, check' => [['--extensions', '["carve.details"]', '--check']],
        ];
    }

    /**
     * @param array<string> $args
     *
     * @return void
     */
    #[DataProvider('unregisteredFmt')]
    public function testFmtRefusesWhenItIsNotRegistered(array $args): void
    {
        [$status, $stdout, $stderr] = self::cli(['fmt', '--from-ast', ...$args]);

        self::assertSame("carve[ast-json-invalid]: required-extension at /extensions/0\n", $stderr);
        self::assertSame('', $stdout);
        self::assertSame(2, $status);
    }

    public function testFmtWritesWhenExtensionsRegistersIt(): void
    {
        [$status, $stdout, $stderr] = self::cli(['fmt', '--from-ast', '--extensions', '["carve.wiki"]']);

        self::assertSame('', $stderr);
        self::assertSame("See :wiki[Main]\n", $stdout);
        self::assertSame(0, $status);
    }

    /**
     * @param array<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function cli(array $args): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, self::TREE);
        rewind($in);
        $status = Command::main([...$args, '-'], $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }
}
