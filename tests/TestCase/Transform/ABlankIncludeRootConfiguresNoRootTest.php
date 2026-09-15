<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeExpander;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ABlankIncludeRootConfiguresNoRootTest extends TestCase
{
    /**
     * @var string
     */
    protected const CHILD_MARKER = 'CHILD-CONTENT-MARKER';

    /**
     * @var string
     */
    protected const SOURCE = "{{ child.crv }}\n";

    public function testABlankRootLeavesTheDirectiveLiteral(): void
    {
        $base = $this->tempDir();

        try {
            file_put_contents($base . DIRECTORY_SEPARATOR . 'child.crv', self::CHILD_MARKER . "\n");

            $result = $this->expandFrom($base, '');

            $this->assertSame("<p>{{ child.crv }}</p>\n", $result['html']);
        } finally {
            $this->removeTree($base);
        }
    }

    public function testABlankRootReadsNothing(): void
    {
        $base = $this->tempDir();

        try {
            file_put_contents($base . DIRECTORY_SEPARATOR . 'child.crv', self::CHILD_MARKER . "\n");

            $result = $this->expandFrom($base, '');

            $this->assertStringNotContainsString(self::CHILD_MARKER, $result['html']);
            $this->assertSame([], $result['dependencies']);
            $this->assertSame([], $result['warnings']);
        } finally {
            $this->removeTree($base);
        }
    }

    public function testAWhitespaceOnlyRootIsUnsetEvenWhereItNamesARealDirectory(): void
    {
        $base = $this->tempDir();

        try {
            mkdir($base . DIRECTORY_SEPARATOR . '   ');
            file_put_contents($base . DIRECTORY_SEPARATOR . '   ' . DIRECTORY_SEPARATOR . 'child.crv', self::CHILD_MARKER . "\n");

            $result = $this->expandFrom($base, '   ');

            $this->assertSame("<p>{{ child.crv }}</p>\n", $result['html']);
            $this->assertStringNotContainsString(self::CHILD_MARKER, $result['html']);
            $this->assertSame([], $result['dependencies']);
        } finally {
            $this->removeTree($base);
        }
    }

    public function testAnAbsoluteRootIsStillHonoredFromAnUnrelatedWorkingDirectory(): void
    {
        $base = $this->tempDir();

        try {
            file_put_contents($base . DIRECTORY_SEPARATOR . 'child.crv', self::CHILD_MARKER . "\n");

            $result = $this->expandFrom(sys_get_temp_dir(), $base);

            $this->assertStringContainsString(self::CHILD_MARKER, $result['html']);
            $this->assertSame([], $result['warnings']);
        } finally {
            $this->removeTree($base);
        }
    }

    /**
     * Section 19 constrains the DOCUMENT, not the constructor: a host that
     * supplies no root leaves inclusion disabled and the directive literal. So
     * the resolver is built the way a host builds one - a refusal means no
     * resolver - and every assertion is made on the rendered document, from a
     * working directory that does contain the target.
     *
     * @param string $cwd
     * @param string $rootSpec
     *
     * @return array{html: string, dependencies: array<mixed>, warnings: array<mixed>}
     */
    protected function expandFrom(string $cwd, string $rootSpec): array
    {
        $previous = getcwd();
        if ($previous === false) {
            $previous = sys_get_temp_dir();
        }
        chdir($cwd);

        try {
            $resolver = null;
            try {
                $resolver = new FilesystemIncludeResolver($rootSpec);
            } catch (RuntimeException) {
                $resolver = null;
            }

            $converter = new CarveConverter();
            $expander = new IncludeExpander($resolver);
            $document = $converter->transform($converter->parse(self::SOURCE), $expander);

            return [
                'html' => $converter->render($document),
                'dependencies' => $expander->getDependencies(),
                'warnings' => $expander->getWarnings(),
            ];
        } finally {
            chdir($previous);
        }
    }

    /**
     * @return string
     */
    protected function tempDir(): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carve-blank-root-' . uniqid();
        mkdir($base, 0777, true);

        return $base;
    }

    /**
     * @param string $dir
     *
     * @return void
     */
    protected function removeTree(string $dir): void
    {
        foreach ((array)scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
