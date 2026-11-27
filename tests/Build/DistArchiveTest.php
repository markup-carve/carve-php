<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Build;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `.gitattributes` export-ignores spec/data except the files src/ reads at runtime; a new runtime read must be
 * listed there, or the Composer dist ships without it.
 */
final class DistArchiveTest extends TestCase
{
    public function testEverySpecDataFileSrcReadsShips(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
            $this->markTestSkipped('not a git checkout');
        }

        $read = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains($file->getPathname(), '/Generated/')) {
                continue;
            }
            preg_match_all('~/spec/data/([A-Za-z0-9_./-]+\.json)~', (string)file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $name) {
                $read['spec/data/' . $name] = true;
            }
        }
        $this->assertNotSame([], $read);

        $ignored = [];
        foreach (array_keys($read) as $path) {
            $this->assertFileExists($root . '/' . $path);
            $out = (string)shell_exec('git -C ' . escapeshellarg($root) . ' check-attr export-ignore -- ' . escapeshellarg($path));
            if (str_ends_with(trim($out), ': set')) {
                $ignored[] = $path;
            }
        }
        $this->assertSame([], $ignored, 'src/ reads these at runtime; unset export-ignore for them in .gitattributes');
    }
}
