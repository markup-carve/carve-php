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
     * @var array<int, array{string, int}>
     */
    private array $text = [];

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
        for ($at = 0, $length = strlen($value); $at < $length; $at++) {
            $char = $value[$at];
            if ($this->mark && Text::isPunct($char)) {
                $id = count($this->text);
                $this->text[] = [$path, $at];
                $out .= $this->marker . 't' . $id . "\x02";
            }
            if (isset($this->escapes[$path][$at])) {
                $out .= '\\';
            }
            // A literal backslash before punctuation must remain a backslash in the read-back text.
            if ($char === '\\' && $at + 1 < $length && Text::isPunct($value[$at + 1])) {
                $out .= '\\';
            }
            $out .= $char;
        }

        return $out;
    }

    public function inlineNode(string $source, string $path, string $type): string
    {
        if (!$this->mark) {
            return $source;
        }
        $id = count($this->nodes);
        $this->nodes[] = [$path, $type];

        return $this->marker . 'n' . $id . "\x02" . $source . $this->marker . 'e' . $id . "\x02";
    }

    /**
     * @return array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>}
     */
    public function decode(string $marked): array
    {
        $source = '';
        $text = [];
        $nodes = [];
        $length = strlen($marked);
        for ($at = 0; $at < $length;) {
            if ($this->marker === '' || substr_compare($marked, $this->marker, $at, strlen($this->marker)) !== 0) {
                $source .= $marked[$at];
                $at++;

                continue;
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
            } else {
                $source .= $marked[$at];
                $at++;

                continue;
            }
            $at = $close + 1;
        }

        return ['source' => $source, 'text' => $text, 'nodes' => $nodes];
    }
}
