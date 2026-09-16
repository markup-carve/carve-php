<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeExpander;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PART 9 §19 I11: an unresolved target's id is resolved against the including
 * file like a resolved one, and a path escaping the containment root keeps the
 * directive's spelling.
 *
 * The spec suite pins both halves as
 * `i11-fs-missing-target-below-the-root-names-where-it-would-appear` and
 * `i11-fs-escaping-target-below-the-root-keeps-its-spelling`. This is the same
 * pair against a real tree, so the behavior is gated whatever the spec pin is
 * at.
 */
class AnUnresolvedIncludeNamesWhereTheFileWouldBeTest extends TestCase
{
    /**
     * @var string
     */
    protected const ENTRY = "{{ sub/frag.crv }}\n";

    protected ?string $base = null;

    protected function tearDown(): void
    {
        if ($this->base !== null) {
            $this->removeTree($this->base);
            $this->base = null;
        }

        parent::tearDown();
    }

    public function testNamesAMissingTargetBelowTheRootByWhereItWouldAppear(): void
    {
        $dependencies = $this->expand(
            ['main.crv' => self::ENTRY, 'sub/frag.crv' => "{{ missing.crv }}\n"],
            '.',
        );

        $this->assertSame([
            ['id' => '<TMP>/sub/frag.crv', 'resolved' => true],
            ['id' => '<TMP>/sub/missing.crv', 'resolved' => false],
        ], $dependencies);
    }

    /**
     * The scope limit. Resolving the id against the including file must not
     * turn an out-of-root refusal into one that reads as if it were inside.
     */
    public function testKeepsTheDirectiveSpellingForATargetThatEscapesTheRoot(): void
    {
        $dependencies = $this->expand([
            'root/main.crv' => self::ENTRY,
            'root/sub/frag.crv' => "{{ ../../secret.crv }}\n",
            'secret.crv' => "TOP SECRET\n",
        ], 'root');

        $this->assertSame([
            ['id' => '<TMP>/root/sub/frag.crv', 'resolved' => true],
            ['id' => '../../secret.crv', 'resolved' => false],
        ], $dependencies);
    }

    /**
     * A target that is not there but would land outside the root is refused
     * the same way.
     */
    public function testKeepsTheDirectiveSpellingForAMissingTargetOutsideTheRoot(): void
    {
        $dependencies = $this->expand([
            'root/main.crv' => self::ENTRY,
            'root/sub/frag.crv' => "{{ ../../gone.crv }}\n",
        ], 'root');

        $this->assertSame([
            ['id' => '<TMP>/root/sub/frag.crv', 'resolved' => true],
            ['id' => '../../gone.crv', 'resolved' => false],
        ], $dependencies);
    }

    /**
     * @param array<string, string> $tree
     * @param string $root
     *
     * @return array<int, array{id: string, resolved: bool}>
     */
    protected function expand(array $tree, string $root): array
    {
        $base = $this->materialize($tree);
        $entryPath = $base . '/' . ($root === '.' ? 'main.crv' : $root . '/main.crv');
        $resolver = new FilesystemIncludeResolver($base . ($root === '.' ? '' : '/' . $root));
        $converter = CarveConverter::create();
        $expander = new IncludeExpander($resolver, $entryPath, 16, null, self::ENTRY);
        $converter->transform($converter->parse(self::ENTRY), $expander);

        $out = [];
        foreach ($expander->getDependencies() as $dependency) {
            $target = $dependency->getTarget();
            $out[] = [
                'id' => str_starts_with($target, $base . '/')
                    ? '<TMP>' . substr($target, strlen($base))
                    : $target,
                'resolved' => $dependency->isResolved(),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, string> $tree
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    protected function materialize(array $tree): string
    {
        $base = tempnam(sys_get_temp_dir(), 'carve-i11-');
        if ($base === false) {
            throw new RuntimeException('Could not create a temporary directory.');
        }
        unlink($base);
        mkdir($base, 0o777, true);
        $real = realpath($base);
        if ($real === false) {
            throw new RuntimeException("Could not canonicalize {$base}.");
        }
        $this->base = $real;
        foreach ($tree as $relative => $content) {
            $path = $real . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $content);
        }

        return $real;
    }

    protected function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
