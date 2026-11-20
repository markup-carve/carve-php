<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Include;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Include\FileResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IncludeVectorsTest extends TestCase
{
    /**
     * C2-W-058 changed the writer order; the shared 0.1 vector files stay read-only.
     *
     * @var array<string, string>
     */
    private const CODE_FIRST_HTML = [
        'i01-lines-bom-only-file-has-no-lines' => '<p>{{ bom.txt @code @lines:1-1 }}</p>',
        'i01-lines-start-past-the-end-code' => '<p>{{ two.txt @code @lines:3-3 }}</p>',
        'i01-repeated-option-lines' => '<p>{{ a.js @code @lines:1-1 @lines:3-3 }}</p>',
        'i01-repeated-option-shift' => '<p>{{ a.js @code @shift:1 @shift:2 }}</p>',
        'i01b-code-region-trailing-text' => '<p>{{ child @code:txt @region:r }}</p>',
        'i01c-code-marker-block-closer-on-the-same-line' => '<p>{{ a.css @code:css @region:demo }}</p>',
        'i01c-code-marker-keyword-is-case-sensitive' => '<p>{{ a.js @code:js @region:demo }}</p>',
        'i01c-code-span-cr-region' => '<p>{{ q.js @code @region:a }}</p>',
    ];

    /**
     * @var array
     */
    private const HELD = [];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function vectors(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../fixtures/include-vectors/vectors/*.json') ?: [] as $path) {
            $vector = json_decode((string)file_get_contents($path), true, 4096, JSON_THROW_ON_ERROR);
            $out[$vector['name']] = [$vector];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('vectors')]
    public function testC2N083Vector(array $vector): void
    {
        [$entry, $files, $resolver, $file, $cleanup] = self::fixture($vector);
        try {
            $options = $vector['options'] ?? [];
            $include = [];
            foreach (['file', 'depth', 'bytes'] as $key) {
                if (array_key_exists($key, $options)) {
                    $include[$key] = $options[$key];
                    unset($options[$key]);
                }
            }
            unset($options['throws']);
            if ($file !== null) {
                $include['file'] = $file;
            }
            if ($resolver !== null) {
                $include['resolver'] = $resolver;
            }
            if ($include !== []) {
                $options['includes'] = $include;
            }
            $result = Carve::renderWithDiagnostics($entry, $options);
            $expected = $vector['expected'];
            if (isset($expected['html'])) {
                $html = self::CODE_FIRST_HTML[$vector['name']] ?? $expected['html'];
                $this->assertSame(self::replaceTmp($html, $cleanup), $result->html);
            }
            $warnings = self::warnings($result->diagnostics);
            $wanted = $expected['warnings'] ?? [];
            if (isset(self::HELD[$vector['name']])) {
                $this->assertNotEqualsCanonicalizing($wanted, $warnings, self::HELD[$vector['name']]);
            } else {
                $this->assertEqualsCanonicalizing($wanted, $warnings);
            }
            if (isset($expected['dependencies'])) {
                $this->assertSame(self::replaceTmp($expected['dependencies'], $cleanup), self::dependencies($entry, $options));
            }
            if (isset($expected['positions'])) {
                $tree = json_decode(Carve::toJson($entry, $options), true, 4096, JSON_THROW_ON_ERROR);
                $this->assertEquals($expected['positions'], self::positions($tree, $expected['positions']));
            }
            if (isset($expected['locations'])) {
                $this->assertEqualsCanonicalizing($expected['locations'], self::locations($result->diagnostics, $entry, $files));
            }
        } finally {
            if ($cleanup !== null) {
                self::removeTree($cleanup);
            }
        }
    }

    /**
     * @param array<string, mixed> $vector
     *
     * @return array{0: string, 1: array<string, string>, 2: \Closure|null, 3: string|null, 4: string|null}
     */
    private static function fixture(array $vector): array
    {
        $kind = $vector['resolver'];
        if ($kind === 'filesystem') {
            return self::filesystem($vector);
        }
        $files = $vector['files'] ?? [];
        $resolver = match ($kind) {
            'none' => null,
            'throws' => static function (array $request): never {
                throw new RuntimeException('private path');
            },
            default => static function (array $request) use ($files, $kind): array {
                $path = $kind === 'virtual-ids' ? preg_replace('~^\./~', '', $request['path']) : $request['path'];

                return isset($files[$path]) ? ['text' => $files[$path], 'id' => $path] : ['error' => 'not_found'];
            },
        };

        return [$vector['entry'], $files, $resolver, null, null];
    }

    /**
     * @param array<string, mixed> $vector
     *
     * @return array{0: string, 1: array<string, string>, 2: \Closure, 3: string, 4: string}
     */
    private static function filesystem(array $vector): array
    {
        $tmp = sys_get_temp_dir() . '/carve-vector-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        foreach ($vector['tree'] as $path => $value) {
            $target = $tmp . '/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            if (is_array($value)) {
                symlink($tmp . '/' . $value['symlink'], $target);
            } else {
                file_put_contents($target, self::replaceTmp($value, $tmp));
            }
        }
        $entryPath = $tmp . '/' . $vector['entryPath'];
        $root = realpath($tmp . '/' . $vector['root']);
        $entry = (string)file_get_contents($entryPath);
        $file = ltrim(substr((string)realpath($entryPath), strlen($root)), '/');
        $files = [];
        foreach ($vector['tree'] as $path => $value) {
            if (is_string($value)) {
                $files[$path] = self::replaceTmp($value, $tmp);
            }
        }

        return [$entry, $files, FileResolver::closure($root), $file, $tmp];
    }

    private static function replaceTmp(mixed $value, ?string $tmp): mixed
    {
        if ($tmp === null) {
            return $value;
        }
        if (is_string($value)) {
            return str_replace(['<TMP>', '<ABS:secret.crv>'], [$tmp, $tmp . '/secret.crv'], $value);
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::replaceTmp($v, $tmp), $value);
        }

        return $value;
    }

    /**
     * @param array<\MarkupCarve\Carve\Test\Include\Diagnostic> $diagnostics
     *
     * @return array<array{code: string, file?: string}>
     */
    private static function warnings(array $diagnostics): array
    {
        $out = [];
        foreach ($diagnostics as $diagnostic) {
            $row = ['code' => $diagnostic->code];
            if ($diagnostic->file !== null) {
                $row['file'] = $diagnostic->file;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     *      * @param string $entry
     *     * @param string $entry
     *
     * @param string $entry
@param array<string, mixed> $options
     *
     * @return array<array{id: string, read: bool}>
     */
    private static function dependencies(string $entry, array $options): array
    {
        $out = [];
        $seen = [];
        foreach (Carve::includeDependencies($entry, $options) as $dep) {
            $place = self::normalize(($dep['from'] === null ? '' : dirname($dep['from']) . '/') . $dep['path']);
            $id = $dep['file'] ?? (str_starts_with($dep['path'], '/') || str_starts_with($place, '../')
                ? $dep['path'] : $place);
            if (!isset($seen[$id])) {
                $out[] = ['id' => $id, 'read' => $dep['read']];
                $seen[$id] = true;
            }
        }

        return $out;
    }

    private static function normalize(string $path): string
    {
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' && $parts !== [] && end($parts) !== '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<array<string, mixed>> $wanted
     *
     * @return array<array<string, mixed>>
     */
    private static function positions(array $tree, array $wanted): array
    {
        $types = array_fill_keys(array_column($wanted, 'type'), true);
        $out = [];
        $stack = [$tree];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (isset($types[$node['type'] ?? '']) && isset($node['pos']['file'])) {
                $out[] = ['type' => $node['type'], 'pos' => $node['pos']];
            }
            foreach (array_reverse($node['children'] ?? []) as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }

    /**
     * @param array<\MarkupCarve\Carve\Test\Include\Diagnostic> $diagnostics
     * @param string $entry
     * @param array<string, string> $files
     *
     * @return array<array{code: string, file?: string, line: int, column: int}>
     */
    private static function locations(array $diagnostics, string $entry, array $files): array
    {
        $out = [];
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->startOffset < 0) {
                continue;
            }
            $source = $diagnostic->file === null ? $entry : ($files[$diagnostic->file] ?? '');
            $before = substr($source, 0, $diagnostic->startOffset);
            $line = substr_count($before, "\n") + 1;
            $at = strrpos($before, "\n");
            $column = mb_strlen($at === false ? $before : substr($before, $at + 1), 'UTF-8') + 1;
            $row = ['code' => $diagnostic->code];
            if ($diagnostic->file !== null) {
                $row['file'] = $diagnostic->file;
            }
            $row['line'] = $line;
            $row['column'] = $column;
            $out[] = $row;
        }

        return $out;
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
