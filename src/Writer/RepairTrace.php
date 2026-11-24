<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * Source positions of the text characters and inline nodes in a bare writer candidate.
 * The temporary markers are removed before the candidate is read back.
 */
final class RepairTrace
{
    /**
     * @var array<int, int> Each punctuation character as `owner()` reads it: its text node and offset in one integer.
     */
    private array $text = [];

    /**
     * @var array<int, string>
     */
    private array $textNodes = [];

    /**
     * @var array<int, string> The path of each inline node, as `packPath()` writes it.
     */
    private array $nodePaths = [];

    /**
     * @var array<int, string>
     */
    private array $nodeTypes = [];

    /**
     * @var array<string, string> `packPath()` of the paths an inline node still to be traced may extend, by path.
     */
    private array $packed = [];

    /**
     * @var array<string, array<string>> The paths in `$packed` made from each path's form, by that path.
     */
    private array $packedFrom = [];

    /**
     * @param array<string, array<int, true>> $escapes Text paths and byte offsets escaped by prior rounds.
     * @param bool $mark
     * @param string $marker A byte sequence absent from the unmarked candidate.
     */
    public function __construct(private array $escapes = [], private bool $mark = true, private string $marker = '')
    {
    }

    public function bareText(string $value, string $path): string
    {
        $out = '';
        // Keep line endings after the final marker. The block writer decides whether a
        // terminal empty line is retained by looking at the last emitted byte.
        $bodyLength = strlen(rtrim($value, "\r\n"));
        if ($bodyLength === 0) {
            return $value;
        }
        $nodeId = count($this->textNodes);
        if ($this->mark) {
            $this->textNodes[] = $path;
            $out .= $this->marker . 's' . $nodeId . "\x02";
        }
        for ($at = 0; $at < $bodyLength; $at++) {
            $char = $value[$at];
            if (isset($this->escapes[$path][$at])) {
                $out .= '\\';
            }
            // A literal backslash before punctuation must remain a backslash in the read-back text.
            if ($char === '\\' && $at + 1 < $bodyLength && Text::isPunct($value[$at + 1])) {
                $out .= '\\';
            }
            if ($this->mark && Text::isPunct($char)) {
                $id = count($this->text);
                $this->text[] = $nodeId << 32 | $at;
                $out .= $this->marker . 't' . $id . "\x02";
            }
            $out .= $char;
        }

        if ($this->mark) {
            $out .= $this->marker . 'f' . $nodeId . "\x02";
        }

        return $out . substr($value, $bodyLength);
    }

    public function inlineNode(string $source, string $path, string $type): string
    {
        // A marker after a terminal break hides its newline from the block writer.
        // Breaks have no paired boundary to probe, so they need no node span.
        if (!$this->mark || $type === 'soft_break' || $type === 'hard_break') {
            return $source;
        }
        $id = count($this->nodePaths);
        $this->nodePaths[] = $this->packedPath($path);
        // Nodes are traced inside out, so no later node extends the paths below this one.
        foreach ($this->packedFrom[$path] ?? [] as $below) {
            unset($this->packed[$below], $this->packedFrom[$below]);
        }
        unset($this->packedFrom[$path]);
        $this->nodeTypes[] = $type;

        return $this->marker . 'n' . $id . "\x02" . $source . $this->marker . 'e' . $id . "\x02";
    }

    /**
     * The marked text with the backslashes `$respelled` adds to or drops from its decoded `$source`, its markers kept;
     * null where the two differ in more than backslashes. An added backslash stands before the marker of the character
     * it escapes, as `bareText()` writes one.
     */
    public function respelled(string $marked, string $source, string $respelled): ?string
    {
        if ($this->marker === '') {
            return $respelled;
        }
        $out = '';
        $pending = '';
        $length = strlen($marked);
        $j = 0;
        $k = 0;
        for ($at = 0; $at < $length;) {
            if (substr_compare($marked, $this->marker, $at, strlen($this->marker)) === 0) {
                $token = $this->markerAt($marked, $at);
                if ($token === null) {
                    return null;
                }
                if ($token[strlen($this->marker)] === 't') {
                    $pending .= $token;
                } else {
                    $out .= $token;
                }
                $at += strlen($token);

                continue;
            }
            $step = ($marked[$at] ?? '') === ($source[$j] ?? '')
                ? self::step($marked[$at], $respelled[$k] ?? '', $pending)
                : null;
            if ($step === null) {
                return null;
            }
            [$append, $taken, $written, $pending] = $step;
            $out .= $append;
            $at += $taken;
            $j += $taken;
            $k += $written;
        }
        $rest = substr($respelled, $k);

        return self::settled($source, $j, $pending, $rest) ? $out . $rest : null;
    }

    /**
     * One step of `respelled()` on the source character `$char` against the respelled byte `$next`: keep it behind the
     * pending text markers, add a backslash before it, or drop it (a backslash, only where no text marker waits).
     *
     * @return array{string, int, int, string}|null What to append, source and respelled bytes used, the pending markers.
     */
    private static function step(string $char, string $next, string $pending): ?array
    {
        if ($char === $next) {
            return [$pending . $char, 1, 1, ''];
        }
        if ($next === '\\') {
            return ['\\', 0, 1, $pending];
        }

        return $char === '\\' && $pending === '' ? ['', 1, 0, ''] : null;
    }

    /**
     * Whether the walk of `respelled()` used up its source and left only added backslashes behind.
     */
    private static function settled(string $source, int $j, string $pending, string $rest): bool
    {
        return $j === strlen($source) && $pending === '' && trim($rest, '\\') === '';
    }

    /**
     * The whole marker that starts at `$at`, its closing byte included; null where it does not close.
     */
    private function markerAt(string $marked, int $at): ?string
    {
        $close = strpos($marked, "\x02", $at + strlen($this->marker));

        return $close === false || $close === $at + strlen($this->marker) ? null : substr($marked, $at, $close + 1 - $at);
    }

    /**
     * @return array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges: array<string, array{int, int}>
     * }
     */
    public function decode(string $marked): array
    {
        $source = '';
        $text = [];
        $nodes = [];
        $ranges = [];
        if ($this->marker === '') {
            return ['source' => $marked, 'text' => $text, 'paths' => [], 'nodes' => $nodes, 'ranges' => $ranges];
        }
        $length = strlen($marked);
        for ($at = 0; $at < $length;) {
            $next = strpos($marked, $this->marker, $at);
            if ($next === false) {
                $source .= substr($marked, $at);

                break;
            }
            if ($next > $at) {
                $source .= substr($marked, $at, $next - $at);
                $at = $next;
            }
            $kindAt = $at + strlen($this->marker);
            $kind = $marked[$kindAt] ?? '';
            $close = strpos($marked, "\x02", $kindAt + 1);
            if ($close === false) {
                $source .= $marked[$at];
                $at++;

                continue;
            }
            $digits = substr($marked, $kindAt + 1, $close - $kindAt - 1);
            if ($digits === '' || strspn($digits, '0123456789') !== strlen($digits)) {
                $source .= $marked[$at];
                $at++;

                continue;
            }
            $id = (int)$digits;
            $place = strlen($source);
            if ($kind === 't' && isset($this->text[$id])) {
                $text[$place] = $this->text[$id];
            } elseif ($kind === 'n' && isset($this->nodePaths[$id])) {
                $nodes[$id] = [$this->nodePaths[$id], $this->nodeTypes[$id], $place, -1];
            } elseif ($kind === 'e' && isset($nodes[$id])) {
                $nodes[$id][3] = $place;
            } elseif ($kind === 's' && isset($this->textNodes[$id])) {
                $ranges[$this->textNodes[$id]] = [$place, -1];
            } elseif ($kind === 'f' && isset($this->textNodes[$id], $ranges[$this->textNodes[$id]])) {
                $ranges[$this->textNodes[$id]][1] = $place;
            } else {
                $source .= $marked[$at];
                $at++;

                continue;
            }
            $at = $close + 1;
        }

        return ['source' => $source, 'text' => $text, 'paths' => $this->textNodes, 'nodes' => $nodes, 'ranges' => $ranges];
    }

    /**
     * A node path in the form the candidate's `nodes` hold it: each `/children/` as one byte, as deep inline nesting
     * makes these paths long. One path maps to one form and back, so forms compare and key as the paths do.
     */
    public static function packPath(string $path): string
    {
        if (str_contains($path, "\x01") || str_starts_with($path, "\x02")) {
            return "\x02" . $path;
        }

        return str_replace('/children/', "\x01", $path);
    }

    /**
     * `packPath()` from the form of the parent, so a deep nest does not rewrite the whole path at every node.
     */
    private function packedPath(string $path): string
    {
        if (isset($this->packed[$path])) {
            return $this->packed[$path];
        }
        $cut = strrpos($path, '/children/');
        // A parent that ends in `/children` shares the slash with the last `/children/`, which `str_replace()` would
        // not match there.
        if (
            $cut === false || str_contains($path, "\x01") || str_starts_with($path, "\x02")
            || str_ends_with(substr($path, 0, $cut), '/children')
        ) {
            return $this->packed[$path] = self::packPath($path);
        }

        $parent = substr($path, 0, $cut);
        $this->packedFrom[$parent][] = $path;

        return $this->packed[$path] = $this->packedPath($parent) . "\x01" . substr($path, $cut + 10);
    }

    public static function unpackPath(string $packed): string
    {
        return str_starts_with($packed, "\x02") ? substr($packed, 1) : str_replace("\x01", '/children/', $packed);
    }

    /**
     * The text node path and the offset in its value of the punctuation character at a place of a candidate.
     *
     * @param array{text: array<int, int>, paths: array<int, string>} $candidate
     * @param int $place
     *
     * @return array{string, int}|null
     */
    public static function owner(array $candidate, int $place): ?array
    {
        $code = $candidate['text'][$place] ?? null;

        return $code === null ? null : [$candidate['paths'][$code >> 32], $code & 0xFFFFFFFF];
    }
}
