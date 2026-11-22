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
     */
    public function __construct(private array $escapes = [], private bool $mark = true)
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
                $out .= "\x01" . $id . "\x02";
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

        return "\x03" . $id . "\x04" . $source . "\x05" . $id . "\x06";
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
            $first = $marked[$at];
            $end = match ($first) {
                "\x01" => "\x02",
                "\x03" => "\x04",
                "\x05" => "\x06",
                default => null,
            };
            if ($end === null) {
                $source .= $first;
                $at++;

                continue;
            }
            $close = strpos($marked, $end, $at + 1);
            if ($close === false) {
                $source .= $first;
                $at++;

                continue;
            }
            $digits = substr($marked, $at + 1, $close - $at - 1);
            if ($digits === '' || strspn($digits, '0123456789') !== strlen($digits)) {
                $source .= $first;
                $at++;

                continue;
            }
            $id = (int)$digits;
            $place = strlen($source);
            if ($first === "\x01" && isset($this->text[$id])) {
                $text[$place] = $this->text[$id];
            } elseif ($first === "\x03" && isset($this->nodes[$id])) {
                [$path, $type] = $this->nodes[$id];
                $nodes[$id] = [$path, $type, $place, -1];
            } elseif ($first === "\x05" && isset($nodes[$id])) {
                $nodes[$id][3] = $place;
            }
            $at = $close + 1;
        }

        return ['source' => $source, 'text' => $text, 'nodes' => $nodes];
    }
}
