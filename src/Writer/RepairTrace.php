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
     * @var array<int, array{string, string}>
     */
    private array $nodes = [];

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
        $id = count($this->nodes);
        $this->nodes[] = [$path, $type];

        return $this->marker . 'n' . $id . "\x02" . $source . $this->marker . 'e' . $id . "\x02";
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
            } elseif ($kind === 'n' && isset($this->nodes[$id])) {
                [$path, $type] = $this->nodes[$id];
                $nodes[$id] = [$path, $type, $place, -1];
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
