<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * `scripts/check-schema-map.php` against a throwaway carve-grammars history: a
 * tag that still ships the map, then a `main` that deletes it, as carve-grammars
 * 0.2 does (carve-php#5256). A reference without the map must stop the run as a
 * setup fault (exit 2), never pass and never read as drift (exit 1), which the
 * daily workflow would file as a false ticket.
 */
final class SchemaMapDriftSourceTest extends TestCase
{
    private string $dir;

    private string $grammars;

    private string $pin;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/carve-schema-map-' . bin2hex(random_bytes(6));
        $this->grammars = $this->dir . '/grammars';
        mkdir($this->grammars . '/tiptap', 0777, true);

        $vendored = $this->vendored();
        unset($vendored['_provenance']);
        file_put_contents($this->grammars . '/tiptap/schema-map.json', $this->encode($vendored));

        $this->git('init', '-q', '-b', 'main');
        $this->git('add', 'tiptap/schema-map.json');
        $this->git('commit', '-q', '-m', 'map');
        $this->pin = $this->git('rev-parse', 'HEAD');
        $this->git('tag', 'v0.1.24');
        $this->git('rm', '-q', 'tiptap/schema-map.json');
        $this->git('commit', '-q', '-m', 'drop the 0.1 map');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
    }

    public function testATagThatShipsTheMapIsCompared(): void
    {
        [$status, $output] = $this->check($this->vendored(), 'v0.1.24');

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('every assertion holds', $output);
    }

    public function testAReferenceWithoutTheMapFailsAsASetupFault(): void
    {
        [$status, $output] = $this->check($this->vendored(), 'main');

        $this->assertSame(2, $status, $output);
        $this->assertStringContainsString('::error::check-schema-map: nothing to compare with', $output);
        $this->assertStringContainsString('has no tiptap/schema-map.json', $output);
    }

    public function testAMutatedEntryIsDrift(): void
    {
        $map = $this->vendored();
        $this->assertIsArray($map['types']);
        $type = (string)array_key_first($map['types']);
        $map['types'][$type] = ['pm' => 'not_' . $type];

        [$status, $output] = $this->check($map, 'v0.1.24');

        $this->assertSame(1, $status, $output);
        $this->assertMatchesRegularExpression('/^::error::map_matches_pin: /m', $output);
    }

    /**
     * @param array<string, mixed> $map
     * @param string $ref
     *
     * @return array{int, string}
     */
    private function check(array $map, string $ref): array
    {
        $this->assertIsArray($map['_provenance']);
        $map['_provenance']['commit'] = $this->pin;
        $path = $this->dir . '/map.json';
        file_put_contents($path, $this->encode($map));

        $command = sprintf(
            '%s %s --grammars %s --ref %s --map %s --github 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 3) . '/scripts/check-schema-map.php'),
            escapeshellarg($this->grammars),
            escapeshellarg($ref),
            escapeshellarg($path),
        );
        $output = [];
        $status = 0;
        exec($command, $output, $status);

        return [$status, implode("\n", $output)];
    }

    /**
     * @return array<string, mixed>
     */
    private function vendored(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/resources/prosemirror-schema-map.json');
        $this->assertIsString($contents);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $map
     */
    private function encode(array $map): string
    {
        return json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function git(string ...$arguments): string
    {
        $command = 'git -C ' . escapeshellarg($this->grammars)
            . ' -c user.name=test -c user.email=test@example.invalid -c commit.gpgsign=false -c tag.gpgsign=false';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException($command . ': ' . implode("\n", $output));
        }

        return trim(implode("\n", $output));
    }
}
