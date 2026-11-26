<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Extension\Standard;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every row of the pinned `data/extensions.json` is a standard extension (C2-T-010, C2-X-072, carve#6766).
 */
final class CatalogTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function rows(): array
    {
        $catalog = self::json('/spec/data/extensions.json');
        $rows = [];
        foreach ($catalog['extensions'] as $row) {
            $rows[$row['name']] = [$row];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('rows')]
    public function testEveryRowIsAStandardExtension(array $row): void
    {
        $definitions = Standard::definitions($row['name']);
        self::assertNotNull($definitions);
        $names = array_map(static fn ($definition): string => $definition->name, $definitions);
        self::assertContains($row['name'], $names);
        foreach ($definitions as $definition) {
            if ($definition->name === $row['name']) {
                self::assertSame($row['matcher'] ?? null, $definition->matcher);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('rows')]
    public function testEveryRowRendersFromTheCommandLine(array $row): void
    {
        [$status, , $stderr] = self::runCommand(['render', '--extensions', json_encode([$row['name']]), 'a.crv'], "a\n");
        self::assertSame([0, ''], [$status, $stderr]);
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('rows')]
    public function testRendererOptionValues(array $row): void
    {
        foreach ($row['rendererOptions'] ?? [] as $option => $spec) {
            if ($row['name'] === 'carve.cite') {
                continue;
            }
            foreach ($spec['values'] as $value) {
                $renderers = [$row['name'] => ['standard' => [$option => $value]]];
                Carve::toHtml("a\n", ['extensions' => [$row['name']], 'renderers' => $renderers]);
            }
            // An open option takes any string (C2-X-075, CC-2148) and still refuses another type (CC-2300).
            $refused = ($spec['open'] ?? false) === true ? 1 : 'xx';
            if ($refused === 1) {
                Carve::toHtml("a\n", ['extensions' => [$row['name']], 'renderers' => [$row['name'] => ['standard' => [$option => 'xx']]]]);
            }
            $this->expectException(OptionError::class);
            Carve::toHtml("a\n", ['extensions' => [$row['name']], 'renderers' => [$row['name'] => ['standard' => [$option => $refused]]]]);
        }
        $this->addToAssertionCount(1);
    }

    /**
     * The render cases of `conformance/extensions.json` that register catalog names only.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function extensionCases(): array
    {
        $cases = [];
        foreach (self::json('/spec/conformance/extensions.json')['cases'] as $case) {
            $names = $case['options']['extensions'] ?? [];
            if ($case['kind'] !== 'render' || array_filter($names, 'is_string') !== $names) {
                continue;
            }
            $cases[$case['id']] = [$case];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('extensionCases')]
    public function testExtensionCase(array $case): void
    {
        $html = Carve::toHtml($case['input'], $case['options']);
        if (isset($case['expected']['html'])) {
            self::assertSame($case['expected']['html'], $html);
        }
        if (isset($case['expected']['htmlIncludes'])) {
            self::assertStringContainsString($case['expected']['htmlIncludes'], $html);
        }
    }

    public function testGlossaryRegistersItsTermOnTheCommandLine(): void
    {
        $source = "See :term[API].\n\n::: glossary\n:: API\n: An interface.\n:::\n";
        [$status, $stdout] = self::runCommand(['render', '--extensions', '["carve.glossary"]', 'a.crv'], $source);
        self::assertSame(0, $status);
        self::assertStringStartsWith("<p>See <a href=\"#gloss-API\" class=\"term\">API</a>.</p>\n", $stdout);
    }

    public function testCodeCalloutsRenderOnTheCommandLine(): void
    {
        $source = "```\nfoo()  <1>\n```\n\n<1> Call.\n";
        [$status, $stdout] = self::runCommand(['render', '--extensions', '["carve.codeCallouts"]', 'a.crv'], $source);
        self::assertSame(0, $status);
        self::assertStringContainsString('<b class="callout" data-callout="1">1</b>', $stdout);
        self::assertStringContainsString('<ol class="callouts">', $stdout);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $path): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__, 2) . $path), true, 4096, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string> $args
     * @param string $source The text of `a.crv`.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runCommand(array $args, string $source): array
    {
        $directory = sys_get_temp_dir() . '/carve-catalog-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory . '/a.crv', $source);
        $previous = (string)getcwd();
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
            chdir($previous);
            unlink($directory . '/a.crv');
            rmdir($directory);
        }
    }
}
