<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Parity;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\RenderError;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Candidate16Test extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function positionCases(): array
    {
        return self::casesOf('position');
    }

    /**
     * C2-T-041.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('positionCases')]
    public function testPositionOf(array $case): void
    {
        $source = isset($case['inputBytes']) ? (string)hex2bin(str_replace(' ', '', $case['inputBytes'])) : $case['input'];
        if (isset($case['expected']['refusal'])) {
            $this->expectException(OptionError::class);
            $this->expectExceptionMessage('offset');
        }
        self::assertSame(
            ['line' => $case['expected']['line'] ?? null, 'column' => $case['expected']['column'] ?? null],
            Carve::positionOf($source, $case['offset']),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function suppressedCases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $id => $case) {
            if (isset($case['expected']['suppressed'])) {
                $out[$id] = [$case];
            }
        }

        return $out;
    }

    /**
     * C2-H-205, C2-T-024: the count of the entries a cap held back.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('suppressedCases')]
    public function testSuppressedDiagnostics(array $case): void
    {
        $options = Corpus::caseOptions($case);
        $result = match ($case['kind']) {
            'import' => Carve::importHtml($case['html'], $options),
            'parse' => Carve::parseWithDiagnostics($case['input'], $options),
            default => Carve::renderWithDiagnostics($case['input'], $options),
        };
        self::assertSame($case['expected']['suppressed'], $result->suppressedDiagnostics);
        foreach ($case['expected']['diagnostics'] ?? [] as $item) {
            self::assertContains(is_string($item) ? $item : $item['code'], $result->codes());
        }
    }

    public function testAllowedImporterCodeDoesNotFailStrictImport(): void
    {
        $result = Carve::importHtml('<p>a<wbr>b</p>', ['allowLosses' => ['import-element-dropped'], 'strictLosses' => true]);
        self::assertSame("ab\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    public function testAllowedLossDoesNotFailStrictRender(): void
    {
        $options = ['allowLosses' => ['destination-denied'], 'strictLosses' => true];
        self::assertSame('<p><a href="">a</a></p>', Carve::toHtml("[a](javascript:x)\n", $options));
        $this->expectException(RenderError::class);
        Carve::toHtml("[a](javascript:x)\n\n``` =latex\nb\n```\n", $options);
    }

    public function testIncludeDiagnosticsCap(): void
    {
        $resolver = static fn (array $request): array => ['error' => 'not_found'];
        $source = "{{ \"a\" }}\n\n{{ \"b\" }}\n\n{{ \"c\" }}\n";
        $result = Carve::parseWithDiagnostics($source, ['includes' => ['resolver' => $resolver, 'maxDiagnostics' => 1]]);
        self::assertSame(['include-not-found'], $result->codes());
        self::assertSame(2, $result->suppressedDiagnostics);
        $this->expectException(OptionError::class);
        Carve::parse($source, ['includes' => ['resolver' => $resolver, 'maxDiagnostics' => 101]]);
    }

    public function testIncludeDiagnosticsCapHoldsForLayout(): void
    {
        $resolver = static fn (array $request): array => ['error' => 'not_found'];
        $result = Carve::parseWithLayout("{{ \"a\" }}\n\n{{ \"b\" }}\n", ['includes' => ['resolver' => $resolver, 'maxDiagnostics' => 1]]);
        self::assertCount(1, $result->diagnostics);
        self::assertSame(1, $result->suppressedDiagnostics);
    }

    public function testImportCommandTakesTheLossFlags(): void
    {
        [$status, $out, $err] = self::command(['import', '--from=html', '--max-render-losses', '1', '-'], '<p>a<wbr>b<wbr>c</p>');
        self::assertSame([0, "abc\n"], [$status, $out]);
        self::assertSame(1, substr_count($err, 'import-element-dropped'));
        [$status, $out, $err] = self::command(['import', '--from=html', '--allow-loss=import-element-dropped', '-'], '<p>a<wbr>b</p>');
        self::assertSame([0, "ab\n", ''], [$status, $out, $err]);
        self::assertSame(2, self::command(['import', '--from=html', '--allow-loss=nope', '-'], '<p>a</p>')[0]);
    }

    public function testCodeBlockRendererSeesTheNodeAndDefault(): void
    {
        $seen = [];
        $html = Carve::toHtml("- a\n\n  ``` ts\n  x\n  ```\n", [

            'codeBlockRenderer' => static function (Node $node, string $default) use (&$seen): ?string {
                $seen[] = [$node->type, $node->f['syntax'] ?? null, $default];

                return null;
            },
        ]);
        self::assertSame([['code_block', 'ts', "<pre><code class=\"language-ts\">x\n</code></pre>"]], $seen);
        self::assertStringContainsString('<pre><code class="language-ts">x', $html);
    }

    public function testCodeBlockRendererRefusesANonTextResult(): void
    {
        $this->expectException(OptionError::class);
        $this->expectExceptionMessage('codeBlockRenderer');
        Carve::toHtml("``` ts\nx\n```\n", ['codeBlockRenderer' => static fn (Node $node, string $default): int => 1]);
    }

    public function testSoftBreakFlag(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'crv');
        file_put_contents((string)$file, "a\nb\n");
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'r');
        self::assertIsResource($out);
        self::assertIsResource($err);
        self::assertIsResource($in);
        self::assertSame(0, Command::main(['render', '--soft-break', 'space', (string)$file], $in, $out, $err));
        rewind($out);
        self::assertSame("<p>a b</p>\n", stream_get_contents($out));
        unlink((string)$file);
    }

    /**
     * @param array<string> $args
     * @param string $stdin
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function command(array $args, string $stdin): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, $stdin);
        rewind($in);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    /**
     * @param string $kind
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    private static function casesOf(string $kind): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $id => $case) {
            if (($case['kind'] ?? null) === $kind) {
                $out[$id] = [$case];
            }
        }

        return $out;
    }
}
