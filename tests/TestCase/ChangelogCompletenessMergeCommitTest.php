<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The changelog gate must see a pull request merged with a merge commit, whose
 * subject is `Merge pull request #N from ...` rather than a trailing `(#N)`.
 */
class ChangelogCompletenessMergeCommitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/carve-changelog-gate-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/repo/src', 0777, true);
        mkdir($this->dir . '/bin');
        // closingIssues() asks GitHub; an empty answer means "closes nothing".
        file_put_contents($this->dir . '/bin/gh', "#!/bin/sh\ncat >/dev/null\necho '{\"data\":{\"repository\":{}}}'\n");
        chmod($this->dir . '/bin/gh', 0755);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    public function testAPullRequestMergedWithAMergeCommitMustBeCited(): void
    {
        $this->git('init', '-q', '-b', 'main');
        $this->commitFile('CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n- A fix (#5)\n", 'base');
        $this->git('tag', 'v0.0.1');
        $this->commitFile('src/a.php', "<?php\n", 'A fix (#5)');
        $this->git('switch', '-q', '-c', 'wave');
        $this->commitFile('src/b.php', "<?php\n", 'One ticket of the wave');
        $this->git('switch', '-q', 'main');
        $this->git('merge', '-q', '--no-ff', 'wave', '-m', "Merge pull request #7 from o/wave\n\nThe wave title");

        [$status, $output] = $this->gate();

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('::error::#7 is not cited in the Unreleased section: The wave title', $output);
        $this->assertStringNotContainsString('::notice::', $output);
        $this->assertStringNotContainsString('#5 is not cited', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function gate(): array
    {
        $script = dirname(__DIR__, 2) . '/scripts/changelog-completeness.php';
        $command = sprintf(
            'PATH=%s GITHUB_REPOSITORY=o/n %s %s 9.9.9 --root %s --at HEAD --previous v0.0.1 --section Unreleased 2>&1',
            escapeshellarg($this->dir . '/bin:' . getenv('PATH')),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($this->dir . '/repo'),
        );
        exec($command, $lines, $status);

        return [$status, implode("\n", $lines)];
    }

    private function commitFile(string $path, string $content, string $message): void
    {
        file_put_contents($this->dir . '/repo/' . $path, $content);
        $this->git('add', $path);
        $this->git('commit', '-q', '-m', $message);
    }

    private function git(string ...$args): void
    {
        $command = 'git -C ' . escapeshellarg($this->dir . '/repo')
            . ' -c user.name=Gate -c user.email=gate@example.invalid -c commit.gpgsign=false '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($command, $out, $status);
        $this->assertSame(0, $status, implode("\n", $out));
    }
}
