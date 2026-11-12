<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use PHPUnit\Framework\TestCase;

/**
 * `scripts/check-spec-pin-is-tag.sh` passes only a spec pin that a `0.x.y` tag
 * on the spec remote names, and reports an unreachable remote as exit 2.
 */
class TheReleaseRequiresASpecReleasePinTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/spec-pin-tag-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    public function testAPinOnASpecReleaseTagPasses(): void
    {
        [$spec, $released] = $this->spec('0.1.14');
        [$status, $text] = $this->check($this->engine($released), $spec);

        $this->assertSame(0, $status, $text);
        $this->assertStringContainsString('is spec release 0.1.14', $text);
    }

    public function testAPinBetweenTwoSpecReleasesIsRefused(): void
    {
        [$spec, , $after] = $this->spec('0.1.14');
        [$status, $text] = $this->check($this->engine($after), $spec);

        $this->assertSame(1, $status, $text);
        $this->assertStringContainsString('is not a published spec release tag', $text);
    }

    public function testAnUnreachableRemoteCannotCheck(): void
    {
        [, $released] = $this->spec('0.1.14');
        [$status, $text] = $this->check($this->engine($released), $this->root . '/no-such.git');

        $this->assertSame(2, $status, $text);
        $this->assertStringContainsString('CANNOT CHECK', $text);
    }

    public function testTheReleaseWorkflowRunsTheCheck(): void
    {
        $workflow = (string)file_get_contents(dirname(__DIR__) . '/.github/workflows/release.yml');

        $this->assertStringContainsString('./scripts/check-spec-pin-is-tag.sh tests/spec', $workflow);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function spec(string $tag): array
    {
        $bare = $this->root . '/spec.git';
        $work = $this->root . '/spec-work';
        $this->git($this->root, 'init', '-q', '--bare', $bare);
        $this->git($this->root, 'init', '-q', '-b', 'main', $work);
        $this->git($work, 'commit', '-q', '--allow-empty', '-m', 'released');
        $released = $this->git($work, 'rev-parse', 'HEAD');
        $this->git($work, 'tag', '-a', $tag, '-m', $tag);
        $this->git($work, 'commit', '-q', '--allow-empty', '-m', 'after the release');
        $after = $this->git($work, 'rev-parse', 'HEAD');
        $this->git($work, 'push', '-q', $bare, 'main', '--tags');

        return [$bare, $released, $after];
    }

    private function engine(string $pin): string
    {
        $dir = $this->root . '/engine-' . substr($pin, 0, 8);
        mkdir($dir);
        $this->git($dir, 'init', '-q', '-b', 'main');
        file_put_contents(
            $dir . '/.gitmodules',
            "[submodule \"tests/spec\"]\n\tpath = tests/spec\n\turl = https://example.invalid/carve.git\n",
        );
        $this->git($dir, 'add', '.gitmodules');
        $this->git($dir, 'update-index', '--add', '--cacheinfo', '160000,' . $pin . ',tests/spec');
        $this->git($dir, 'commit', '-q', '-m', 'pin');

        return $dir;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function check(string $dir, string $remote): array
    {
        $command = sprintf(
            'cd %s && SPEC_REMOTE=%s bash %s tests/spec 2>&1',
            escapeshellarg($dir),
            escapeshellarg($remote),
            escapeshellarg(dirname(__DIR__) . '/scripts/check-spec-pin-is-tag.sh'),
        );
        $output = [];
        $status = 0;
        exec($command, $output, $status);

        return [$status, implode("\n", $output)];
    }

    private function git(string $cwd, string ...$args): string
    {
        $command = 'git -C ' . escapeshellarg($cwd)
            . ' -c user.email=t@example.invalid -c user.name=t '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $output = [];
        $status = 0;
        exec($command, $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
