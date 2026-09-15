<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeExpander;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The rule is ABSOLUTENESS, not emptiness after trimming (markup-carve/carve#2004).
 * Blank and whitespace-only specs fall out of it rather than needing a rule of
 * their own, so they stay pinned here beside the relative ones.
 */
class ANonAbsoluteIncludeRootConfiguresNoRootTest extends TestCase
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

    /**
     * The vector a trim-and-compare spelling cannot pass: `.` is not blank
     * after trimming, and only an absoluteness test refuses it.
     *
     * Every row MATERIALIZES the target where the spec would land it, and runs
     * from a working directory the spec resolves against, so a resolver that
     * canonicalizes the spec instead of refusing it finds the child and the
     * marker appears. Without that the row would go green because the root did
     * not exist - the right answer for the wrong reason.
     *
     * @param string $spec
     * @param string $where
     *
     * @return void
     */
    #[DataProvider('nonAbsoluteRootSpecs')]
    public function testANonAbsoluteRootLeavesTheDirectiveLiteral(string $spec, string $where): void
    {
        $base = $this->tempDir();

        try {
            $dir = $base . ($where === '' ? '' : DIRECTORY_SEPARATOR . $where);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'child.crv', self::CHILD_MARKER . "\n");

            $result = $this->expandFrom($base, $spec);

            $this->assertSame("<p>{{ child.crv }}</p>\n", $result['html']);
            $this->assertStringNotContainsString(self::CHILD_MARKER, $result['html']);
            $this->assertSame([], $result['dependencies']);
            $this->assertSame([], $result['warnings']);
        } finally {
            $this->removeTree($base);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonAbsoluteRootSpecs(): array
    {
        return [
            'a single dot' => ['.', ''],
            'a bare relative name' => ['sub', 'sub'],
            'an explicitly relative name' => ['./sub', 'sub'],
            'a relative name with a trailing separator' => ['sub/', 'sub'],
        ];
    }

    /**
     * The refusal names absoluteness, because that is the rule the corpus
     * reads back as the `no-root` denial class.
     *
     * @return void
     */
    public function testTheRefusalNamesAbsoluteness(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The include root must be an absolute path');

        new FilesystemIncludeResolver('.');
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
