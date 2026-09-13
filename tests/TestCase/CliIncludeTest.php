<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use PHPUnit\Framework\TestCase;

/**
 * The CLI is a HOST, and inclusion is the one feature that needs one.
 *
 * The parser never opens a file, so `{{ chapter.crv }}` is literal text until
 * something supplies a resolver. `bin/carve` supplies one for a file input,
 * which is the behavior carve-js and carve-rs already had and this engine did
 * not: the same document rendered by three CLIs produced two different answers.
 */
class CliIncludeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/carve-cli-include-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/chapters', 0777, true);
        file_put_contents($this->root . '/main.crv', "Intro.\n\n{{ chapters/one.crv }}\n");
        file_put_contents($this->root . '/chapters/one.crv', "Chapter body.\n");
    }

    protected function tearDown(): void
    {
        foreach (['/chapters/one.crv', '/main.crv', '/chapters', ''] as $part) {
            $path = $this->root . $part;
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    /**
     * @param array<int, string> $args
     * @param string $stdin
     *
     * @return array{out: string, err: string, exit: int}
     */
    private function runCli(array $args, string $stdin = ''): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/carve';
        $process = proc_open(
            array_merge([PHP_BINARY, $bin], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return ['out' => $out, 'err' => $err, 'exit' => proc_close($process)];
    }

    public function testAFileInputExpandsAgainstItsOwnDirectory(): void
    {
        $result = $this->runCli([$this->root . '/main.crv']);

        $this->assertStringContainsString('<p>Chapter body.</p>', $result['out']);
        $this->assertStringNotContainsString('{{', $result['out']);
        $this->assertSame(0, $result['exit']);
    }

    public function testStdinHasNoRootSoTheDirectiveStaysLiteral(): void
    {
        // The process working directory is NOT a fallback: it is arbitrary with
        // respect to a document arriving on a pipe, so there is nothing to
        // contain against and nothing is read.
        $result = $this->runCli([], "{{ chapters/one.crv }}\n");

        $this->assertStringContainsString('{{ chapters/one.crv }}', $result['out']);
    }

    public function testStdinExpandsWhenTheRootIsNamed(): void
    {
        $result = $this->runCli(['--include-root', $this->root], "{{ chapters/one.crv }}\n");

        $this->assertStringContainsString('<p>Chapter body.</p>', $result['out']);
    }

    public function testAnUnresolvedTargetWarnsOnStderrAndLeavesTheDirective(): void
    {
        // NOT behind --warnings. A refused directive renders as the literal text
        // it is, which reads exactly like prose the author typed, so silence
        // would hide a real error behind something that looks normal (I7).
        file_put_contents($this->root . '/main.crv', "x\n\n{{ nope.crv }}\n");
        $result = $this->runCli([$this->root . '/main.crv']);

        $this->assertStringContainsString('{{ nope.crv }}', $result['out']);
        $this->assertStringContainsString('include-unresolved', $result['err']);
    }

    public function testTheCarveTargetKeepsTheDirectiveVerbatim(): void
    {
        // Writing the document back as Carve must return the author's document.
        // Expanding here would hand back a different one, with every child
        // inlined - which is also why the writer preserves the directive rather
        // than escaping it.
        $result = $this->runCli(['--carve', $this->root . '/main.crv']);

        $this->assertStringContainsString('{{ chapters/one.crv }}', $result['out']);
        $this->assertStringNotContainsString('Chapter body.', $result['out']);
    }

    public function testAnUnusableExplicitRootIsFatal(): void
    {
        // An explicit root is a user request, so a bad one fails loudly rather
        // than quietly rendering without includes.
        $result = $this->runCli(['--include-root', $this->root . '/nowhere', $this->root . '/main.crv']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Include root does not exist', $result['err']);
    }
}
