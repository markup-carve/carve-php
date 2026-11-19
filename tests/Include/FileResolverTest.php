<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Include;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Include\FileResolver;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileResolverTest extends TestCase
{
    public function testC2N013ContainmentBeforeExistence(): void
    {
        $root = sys_get_temp_dir() . '/carve-include-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $resolver = FileResolver::closure($root);
            $one = $resolver(['path' => '../outside.crv', 'options' => []]);
            $two = $resolver(['path' => '../missing.crv', 'options' => []]);
            $this->assertSame(['error' => 'outside_root'], $one);
            $this->assertSame($one, $two);
            $this->assertSame(['error' => 'outside_root'], $resolver(['path' => '/etc/passwd', 'options' => []]));
            $this->assertSame(['error' => 'outside_root'], $resolver(['path' => 'https://host/a', 'options' => []]));
            $result = Carve::renderWithDiagnostics('{{ "' . $root . '/../secret.crv" }}', ['includes' => $resolver]);
            foreach ($result->diagnostics as $diagnostic) {
                $this->assertStringNotContainsString($root, $diagnostic->message);
            }
        } finally {
            rmdir($root);
        }
    }

    public function testC2N014RelativeRootIsRefused(): void
    {
        $this->expectException(OptionError::class);
        FileResolver::closure('relative/root');
    }

    public function testC2N066CycleAndResolverError(): void
    {
        $files = ['a.crv' => '{{ b.crv }}', 'b.crv' => '{{ c.crv }}', 'c.crv' => '{{ a.crv }}'];
        $resolver = static fn (array $request): array => [
            'text' => $files[$request['path']],
            'id' => $request['path'],
        ];
        $result = Carve::renderWithDiagnostics('{{ a.crv }}', ['includes' => $resolver]);
        $this->assertContains('include-cycle', $result->codes());
        $this->assertContains('include-unresolved', $result->codes());
        $throwing = static function (array $request): never {
            throw new RuntimeException('private path');
        };
        $result = Carve::renderWithDiagnostics('{{ a.crv }}', ['includes' => $throwing]);
        $this->assertContains('include-unreadable', $result->codes());
        foreach ($result->diagnostics as $diagnostic) {
            $this->assertStringNotContainsString('private path', $diagnostic->message);
        }
    }

    public function testC2N013SymlinkOutsideRootIsDenied(): void
    {
        $base = sys_get_temp_dir() . '/carve-include-' . bin2hex(random_bytes(8));
        mkdir($base);
        mkdir($base . '/root');
        mkdir($base . '/outside');
        file_put_contents($base . '/secret.crv', 'Secret');
        file_put_contents($base . '/outside/secret.crv', 'Secret');
        symlink($base . '/secret.crv', $base . '/root/link.crv');
        symlink($base . '/outside', $base . '/root/linkdir');
        try {
            $resolver = FileResolver::closure($base . '/root');
            $this->assertSame(['error' => 'outside_root'], $resolver(['path' => 'link.crv', 'options' => []]));
            $this->assertSame(['error' => 'outside_root'], $resolver(['path' => 'linkdir/secret.crv', 'options' => []]));
            $result = Carve::renderWithDiagnostics('{{ link.crv }}', ['includes' => $resolver]);
            $this->assertContains('include-outside-root', $result->codes());
            foreach ($result->diagnostics as $diagnostic) {
                $this->assertStringNotContainsString($base, $diagnostic->message);
            }
        } finally {
            unlink($base . '/root/link.crv');
            unlink($base . '/root/linkdir');
            unlink($base . '/secret.crv');
            unlink($base . '/outside/secret.crv');
            rmdir($base . '/outside');
            rmdir($base . '/root');
            rmdir($base);
        }
    }

    public function testC2N064DepthAndC2N065BudgetAndC2N011NonText(): void
    {
        $files = [];
        for ($i = 1; $i <= 17; $i++) {
            $files['f' . $i] = $i === 17 ? 'End.' : '{{ f' . ($i + 1) . ' }}';
        }
        $resolver = static fn (array $request): array => ['text' => $files[$request['path']], 'id' => $request['path']];
        $result = Carve::renderWithDiagnostics('{{ f1 }}', ['includes' => $resolver]);
        $this->assertContains('include-depth', $result->codes());
        $this->assertContains('include-unresolved', $result->codes());
        $result = Carve::renderWithDiagnostics('{{ big }}', [

            'includes' => [
                'resolver' => static fn (array $request): array => ['text' => '12345', 'id' => 'big'],
                'bytes' => 4,
            ],
        ]);
        $this->assertContains('include-budget', $result->codes());
        foreach (["\xFF", "a\0b"] as $binary) {
            $result = Carve::renderWithDiagnostics('{{ binary }}', [

                'includes' =>
                static fn (array $request): array => ['text' => $binary, 'id' => 'binary'],
            ]);
            $this->assertContains('include-non-text', $result->codes());
        }
    }

    public function testC2N012FilesystemLimitsAndCycle(): void
    {
        $root = sys_get_temp_dir() . '/carve-include-' . bin2hex(random_bytes(8));
        mkdir($root);
        $paths = [];
        try {
            for ($i = 1; $i <= 17; $i++) {
                $path = $root . '/f' . $i . '.crv';
                file_put_contents($path, $i === 17 ? 'End.' : '{{ f' . ($i + 1) . '.crv }}');
                $paths[] = $path;
            }
            foreach (
                [
                    'a.crv' => '{{ b.crv }}',
                    'b.crv' => '{{ c.crv }}',
                    'c.crv' => '{{ a.crv }}',
                    'big.crv' => '12345',
                    'bad.crv' => "\xFF",
                    'nul.crv' => "a\0b",
                ] as $name => $text
            ) {
                $path = $root . '/' . $name;
                file_put_contents($path, $text);
                $paths[] = $path;
            }
            $resolver = FileResolver::closure($root);
            $cases = [
                ['{{ f1.crv }}', ['resolver' => $resolver], 'include-depth'],
                ['{{ a.crv }}', ['resolver' => $resolver], 'include-cycle'],
                ['{{ big.crv }}', ['resolver' => $resolver, 'bytes' => 4], 'include-budget'],
                ['{{ bad.crv }}', ['resolver' => $resolver], 'include-non-text'],
                ['{{ nul.crv }}', ['resolver' => $resolver], 'include-non-text'],
            ];
            foreach ($cases as [$source, $include, $expected]) {
                $result = Carve::renderWithDiagnostics($source, ['includes' => $include]);
                $this->assertContains($expected, $result->codes());
                foreach ($result->diagnostics as $diagnostic) {
                    $this->assertStringNotContainsString($root, $diagnostic->message);
                }
            }
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
            rmdir($root);
        }
    }

    public function testC2N065FileOverTheBudgetIsRefusedBeforeItIsReadWhole(): void
    {
        $root = sys_get_temp_dir() . '/carve-include-' . bin2hex(random_bytes(8));
        mkdir($root);
        $files = ['big.txt' => str_repeat("é line\n", 1 << 20), 'bad.txt' => str_repeat('a', 9000) . "\xFF"];
        $files['self.crv'] = "x\n\n{{ self.crv }}\n";
        try {
            foreach ($files as $name => $text) {
                file_put_contents($root . '/' . $name, $text);
            }
            $resolver = FileResolver::closure($root);
            $before = memory_get_usage();
            $big = $resolver(['path' => 'big.txt', 'options' => [], 'budget' => 1000]);
            $this->assertSame(['error' => 'size_exceeded', 'id' => 'big.txt'], $big);
            $this->assertLessThan(1 << 20, memory_get_peak_usage() - $before);
            $this->assertSame(['error' => 'non_text'], $resolver(['path' => 'bad.txt', 'options' => [], 'budget' => 1000]));
            $this->assertSame('big.txt', $resolver(['path' => 'big.txt', 'options' => []])['id'] ?? null);
            $options = ['includes' => ['resolver' => $resolver, 'bytes' => 30]];
            $this->assertContains('include-budget', Carve::renderWithDiagnostics('{{ big.txt @code }}', $options)->codes());
            $this->assertContains('include-non-text', Carve::renderWithDiagnostics('{{ bad.txt @code }}', $options)->codes());
            $this->assertContains('include-cycle', Carve::renderWithDiagnostics('{{ self.crv }}', $options)->codes());
        } finally {
            foreach (array_keys($files) as $name) {
                unlink($root . '/' . $name);
            }
            rmdir($root);
        }
    }
}
