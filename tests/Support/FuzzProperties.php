<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use Closure;
use ErrorException;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\CarveException;
use MarkupCarve\Carve\Node;
use Throwable;

/**
 * The properties scripts/fuzz.php checks on every input. Each returns its violations as `[property, signature, detail]`,
 * where the signature groups finds of one cause: the exception class and the first frame in src/, or the property name.
 */
final class FuzzProperties
{
    /**
     * An operation slower than this on one input is a find (deep-dive surface "Performance").
     */
    public const SLOW_MS = 500.0;

    /**
     * The budget grows with the input past this size: 500 ms per 32 KB.
     *
     * @var int
     */
    public const SLOW_PER_BYTES = 32768;

    /**
     * The largest rendered HTML that is imported again under the worker's memory_limit of 128M.
     *
     * @var int
     */
    public const IMPORT_HTML_BYTES = 262144;

    /**
     * The largest source imported through the Markdown, GFM or Djot hub under that memory_limit: league/commonmark
     * holds about 1.7 KB per byte of a line of brackets (a 100 KB line peaks at 173 MB, linear in the size).
     *
     * @var int
     */
    public const IMPORT_HUB_BYTES = 65536;

    /**
     * JSON bytes per source byte up to which a decoded tree gets the time budget of its source.
     *
     * @var int
     */
    public const TREE_BYTES_PER_BYTE = 32;

    /**
     * @var array<string>
     */
    private const HUB = ['markdown', 'gfm', 'djot'];

    /**
     * @var array<array{string, string, string}>
     */
    private array $finds = [];

    private int $size = 0;

    /**
     * @param float $slowFactor Multiplies the time budget, for slower machines such as CI runners.
     */
    public function __construct(private float $slowFactor = 1.0)
    {
    }

    public static function install(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /**
     * @return array<array{string, string, string}>
     */
    public function check(string $source): array
    {
        $this->finds = [];
        $this->size = strlen($source);
        // Results are dropped as soon as they are compared: memory_limit applies to one operation, not to the sum.
        $parsed = $this->run('parse', static fn (): bool => Carve::parse($source) instanceof Node);
        $html = $this->run('html', static fn (): string => Carve::toHtml($source));
        if ($parsed === null || !is_string($html)) {
            return $this->finds;
        }
        $json = $this->run('json', static fn (): string => Carve::toJson($source));
        if (is_string($json)) {
            // The tree repeats data such as an abbreviation's expansion on every use, so its size sets the budget here.
            $treeSize = max($this->size, intdiv(strlen($json), self::TREE_BYTES_PER_BYTE));
            $again = $this->run('json-render', static fn (): string => Carve::render(Carve::decodeTree($json)), $treeSize);
            unset($json);
            if (is_string($again) && $again !== $html) {
                $this->finds[] = ['json-render', 'json-render: decoded tree renders other HTML', $this->diff($html, $again)];
            }
            unset($again);
        }
        $this->format($source, $html);
        $this->run('lint', static fn (): array => Carve::lint($source));
        foreach (['markdown', 'plain', 'ansi'] as $target) {
            $this->run('write-' . $target, static fn (): string => Carve::writeTo($target, Carve::parse($source)));
        }
        // A DOM costs about 100 bytes per byte of HTML, so a large page would test memory_limit rather than the importer.
        if (strlen($html) <= self::IMPORT_HTML_BYTES) {
            $this->run('import-html', static fn (): string => Carve::importHtml($html)->source);
        }
        if ($this->size <= self::IMPORT_HUB_BYTES) {
            $from = self::HUB[crc32($source) % count(self::HUB)];
            $this->run('import-' . $from, static fn (): string => Carve::importDocument($source, ['from' => $from])->source);
        }

        return $this->finds;
    }

    private function format(string $source, string $html): void
    {
        $once = $this->run('fmt', static fn (): string => Carve::format($source));
        if (!is_string($once)) {
            return;
        }
        $twice = $this->run('fmt', static fn (): string => Carve::format($once));
        if (is_string($twice) && $twice !== $once) {
            $this->finds[] = ['fmt-idempotent', 'fmt-idempotent: fmt(fmt(x)) differs from fmt(x)', $this->diff($once, $twice)];
        }
        $after = $this->run('fmt-html', static fn (): string => Carve::toHtml($once));
        if (is_string($after) && $after !== $html) {
            $this->finds[] = ['fmt-html', 'fmt-html: fmt changes the HTML', $this->diff($html, $after)];
        }
    }

    /**
     * @param string $property
     * @param \Closure(): mixed $call
     * @param int|null $size The size the time budget grows with, the source size by default.
     */
    private function run(string $property, Closure $call, ?int $size = null): mixed
    {
        if (getenv('CARVE_FUZZ_TRACE')) {
            fwrite(STDERR, $property . "\n");
        }
        // The chunks an earlier property left count against memory_limit; see the worker loop in scripts/fuzz.php.
        gc_mem_caches();
        $start = self::cpuMs();
        try {
            $result = $call();
        } catch (CarveException $e) {
            // A documented refusal is an answer, not a crash; a refusal of what parse accepted is still worth a look.
            if ($property !== 'parse' && $property !== 'html' && !str_starts_with($property, 'import-')) {
                $this->finds[] = [$property . '-refused', $property . '-refused: ' . self::where($e), $e->getMessage()];
            }

            return null;
        } catch (Throwable $e) {
            $this->finds[] = [$property . '-crash', $property . '-crash: ' . self::where($e), $e->getMessage()];

            return null;
        }
        $ms = self::cpuMs() - $start;
        $budget = self::SLOW_MS * max(1, ($size ?? $this->size) / self::SLOW_PER_BYTES) * $this->slowFactor;
        if ($ms > $budget) {
            // Once more, so a busy machine is no find: a slow input is slow twice.
            $start = self::cpuMs();
            try {
                $call();
            } catch (Throwable) {
            }
            $ms = min($ms, self::cpuMs() - $start);
        }
        if ($ms > $budget) {
            $this->finds[] = [$property . '-slow', $property . '-slow', sprintf('%.0f ms', $ms)];
        }

        return $result;
    }

    /**
     * Process CPU time in ms, so load from other processes does not count.
     */
    private static function cpuMs(): float
    {
        $usage = getrusage();

        $seconds = $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'];

        return $seconds * 1000 + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
    }

    /**
     * The exception class and the first frame in src/: one signature per throw site.
     */
    public static function where(Throwable $e): string
    {
        $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';
            $at = strpos($file, '/src/');
            if ($at !== false) {
                return $e::class . ' at ' . substr($file, $at + 1) . ':' . ($frame['line'] ?? 0);
            }
        }

        return $e::class . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    private function diff(string $a, string $b): string
    {
        $at = 0;
        $max = min(strlen($a), strlen($b));
        while ($at < $max && $a[$at] === $b[$at]) {
            $at++;
        }

        return json_encode([substr($a, max(0, $at - 40), 120), substr($b, max(0, $at - 40), 120)], JSON_INVALID_UTF8_SUBSTITUTE)
            ?: '';
    }
}
