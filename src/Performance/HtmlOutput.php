<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Performance;

use Closure;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * @internal
 */
final class HtmlOutput
{
    private string $pending = '';

    private bool $wrote = false;

    private ?Closure $sink;

    public function __construct(?callable $sink = null, private bool $discard = false)
    {
        $this->sink = $sink === null ? null : Closure::fromCallable($sink);
    }

    public function push(string $first = '', string ...$parts): void
    {
        if ($this->discard) {
            return;
        }
        if ($this->sink === null) {
            $this->pending .= $first;
            foreach ($parts as $part) {
                $this->pending .= $part;
            }

            return;
        }
        $this->pushPart($first);
        foreach ($parts as $part) {
            $this->pushPart($part);
        }
    }

    private function pushPart(string $part): void
    {
        $offset = 0;
        $length = strlen($part);
        while ($offset < $length) {
            $end = min($length, $offset + 4096 - strlen($this->pending));
            while ($end < $length && $end > $offset && (ord($part[$end]) & 0xC0) === 0x80) {
                $end--;
            }
            if ($end === $offset) {
                $this->flush();

                continue;
            }
            $newline = strpos($part, "\n", $offset);
            if ($newline !== false && $newline < $end) {
                $end = $newline + 1;
            }
            $this->pending .= substr($part, $offset, $end - $offset);
            $offset = $end;
            if (strlen($this->pending) === 4096 || str_ends_with($this->pending, "\n")) {
                $this->flush();
            }
        }
    }

    public function text(string $text): void
    {
        $this->escape($text, false);
    }

    public function attr(string $text): void
    {
        $this->escape($text, true);
    }

    private function escape(string $text, bool $attribute): void
    {
        if ($this->discard) {
            return;
        }
        if ($this->sink === null) {
            $this->pending .= $attribute ? StringUtil::escapeHtml($text)
                : str_replace("\u{00A0}", '&nbsp;', htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8'));

            return;
        }
        $length = strlen($text);
        for ($offset = 0; $offset < $length;) {
            $end = min($length, $offset + 4096);
            while ($end < $length && (ord($text[$end]) & 0xC0) === 0x80) {
                $end--;
            }
            $chunk = substr($text, $offset, $end - $offset);
            $this->push($attribute ? StringUtil::escapeHtml($chunk)
                : str_replace("\u{00A0}", '&nbsp;', htmlspecialchars($chunk, ENT_NOQUOTES | ENT_HTML5, 'UTF-8')));
            $offset = $end;
        }
    }

    private function flush(): void
    {
        $sink = $this->sink;
        if ($this->pending !== '' && $sink !== null) {
            $sink($this->pending);
            $this->pending = '';
            $this->wrote = true;
        }
    }

    public function finish(): string
    {
        $sink = $this->sink;
        if ($sink !== null) {
            $this->flush();
            if (!$this->wrote) {
                $sink('');
            }
        }

        return $sink === null ? $this->pending : '';
    }
}
