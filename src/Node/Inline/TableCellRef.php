<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

final class TableCellRef extends InlineNode
{
    protected ?string $value = null;

    public function __construct(
        protected string $target,
        protected string|int $row,
        protected string|int $column,
        protected string $source,
    ) {
    }

    public static function fromSource(string $source): ?self
    {
        $selector = '(?:[1-9][0-9]*|"(?:\\\\["\\\\]|[^"\\\\\n])*")';
        if (preg_match('~^@\{#([A-Za-z0-9_][A-Za-z0-9_-]*)\[(' . $selector . ')\]\[(' . $selector . ')\]\}$~u', $source, $match) !== 1) {
            return null;
        }

        $decode = static function (string $raw): int|string|null {
            if ($raw[0] === '"') {
                return preg_replace('~\\\\(["\\\\])~', '$1', substr($raw, 1, -1));
            }
            $value = filter_var($raw, FILTER_VALIDATE_INT);

            return is_int($value) && $value > 0 && $value <= 9007199254740991 ? $value : null;
        };
        $row = $decode($match[2]);
        $column = $decode($match[3]);

        return $row !== null && $column !== null ? new self($match[1], $row, $column, $source) : null;
    }

    public static function sourceEnd(string $text, int $start): ?int
    {
        static $cachedText = null;
        static $lastBrace = null;
        if ($cachedText !== $text) {
            $cachedText = $text;
            $lastBrace = strrpos($text, '}');
        }
        if ($lastBrace === false || $lastBrace < $start) {
            return null;
        }
        $quoted = false;
        for ($i = $start + 3, $length = strlen($text); $i < $length; $i++) {
            if ($text[$i] === '\\') {
                $i++;

                continue;
            }
            if ($text[$i] === '"') {
                $quoted = !$quoted;

                continue;
            }
            if (!$quoted && $text[$i] === '}') {
                return $i + 1;
            }
            if (!$quoted && ($text[$i] === "\n" || $text[$i] === "\r")) {
                return null;
            }
        }

        return null;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function setTarget(string $target): void
    {
        $this->target = $target;
    }

    public function getRow(): string|int
    {
        return $this->row;
    }

    public function getColumn(): string|int
    {
        return $this->column;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): void
    {
        $this->value = $value;
    }

    public function getType(): string
    {
        return 'table_cell_ref';
    }

    public function fallback(): string
    {
        $parts = [];
        foreach ($this->getAttributeEntries() as $key => $value) {
            if ($key === 'id' && is_string($value)) {
                $parts[] = '#' . $value;
            } elseif ($key === 'class') {
                foreach ((array)$value as $class) {
                    $parts[] = '.' . $class;
                }
            } elseif (is_string($value)) {
                $parts[] = $value === '' ? $key : $key . '=' . (preg_match('/^[^\s{}"\']+$/u', $value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return $this->source . ($parts === [] ? '' : '{' . implode(' ', $parts) . '}');
    }
}
