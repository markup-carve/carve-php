<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

/**
 * Tracks whole raw tags in one heading. Complex fragments retain the section separator.
 */
final class HeadingRawCodeTracker
{
    private int $depth = 0;

    private ?string $rawTextTag = null;

    private bool $comment = false;

    private bool $wholeTags = true;

    public function observe(string $content): void
    {
        if ($this->comment) {
            $end = strpos($content, '-->');
            if ($end !== false) {
                $this->comment = false;
                if (str_contains(substr($content, $end + 3), '<')) {
                    $this->wholeTags = false;
                }
            }

            return;
        }
        if ($this->rawTextTag !== null) {
            if ($this->rawTextTag !== 'plaintext' && preg_match('/^<\/' . $this->rawTextTag . '[ \t\r\n\f]*>$/iD', $content) === 1) {
                $this->rawTextTag = null;
            }

            return;
        }
        if (str_starts_with($content, '<!--')) {
            $this->comment = !str_contains($content, '-->');
            if (!$this->comment && str_contains(preg_replace('/<!--.*?-->/s', '', $content) ?? $content, '<')) {
                $this->wholeTags = false;
            }

            return;
        }
        if (preg_match('/^<(\/?)([a-z][a-z0-9:-]*)(?=[ \t\r\n\f\/>])(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>$/iD', $content, $tag) !== 1) {
            if (str_contains($content, '<')) {
                $this->wholeTags = false;
            }

            return;
        }
        $name = strtolower($tag[2]);
        if ($name === 'code') {
            $this->depth = $tag[1] === '' ? $this->depth + 1 : max(0, $this->depth - 1);
        } elseif ($tag[1] === '' && in_array($name, ['script', 'style', 'title', 'textarea', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'], true)) {
            $this->rawTextTag = $name;
        }
    }

    public function hasOpenCode(): bool
    {
        return $this->wholeTags && $this->depth > 0;
    }
}
