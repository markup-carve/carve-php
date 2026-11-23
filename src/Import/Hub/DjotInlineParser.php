<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;
use Djot\Parser\InlineParser;

/**
 * The library inline parser with djot.js 0.3.2 autolinks (the reference library decides Djot syntax in the 0.2 hub):
 * `<`, content without `<`, `>` or white space, `>`; an email when the content matches `[^:]@`, else a URL when it
 * matches `[a-zA-Z]:`, else no autolink.
 */
final class DjotInlineParser extends InlineParser
{
    /**
     * @var string The white space of JavaScript `\s` beyond ASCII, which the djot.js autolink pattern also excludes.
     */
    private const UNICODE_SPACE = '/[\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]/u';

    /**
     * @return array{node: \Djot\Node\Inline\Link, pos: int}|null
     */
    protected function parseAutolink(string $text, int $pos): ?array
    {
        $autolink = $this->autolink($text, $pos);
        if ($autolink === null) {
            return null;
        }
        [$content, $email, $endPos] = $autolink;
        $link = new Link(($email ? 'mailto:' : '') . $content);
        $link->setAutolink(true);
        $link->appendChild(new Text($content));
        if ($endPos < strlen($text) && $text[$endPos] === '{') {
            $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
        }

        return ['node' => $link, 'pos' => $endPos];
    }

    protected function findAutolinkEnd(string $text, int $pos): ?int
    {
        return $this->autolink($text, $pos)[2] ?? null;
    }

    /**
     * @return array{0: string, 1: bool, 2: int}|null The content, whether it is an email, and the offset after `>`.
     */
    private function autolink(string $text, int $pos): ?array
    {
        if (($text[$pos] ?? '') !== '<') {
            return null;
        }
        $length = strcspn($text, "<> \t\n\v\f\r", $pos + 1);
        if ($length === 0 || ($text[$pos + 1 + $length] ?? '') !== '>') {
            return null;
        }
        $content = substr($text, $pos + 1, $length);
        if (preg_match(self::UNICODE_SPACE, $content) === 1) {
            return null;
        }
        $email = preg_match('/[^:]@/', $content) === 1;
        if (!$email && preg_match('/[a-zA-Z]:/', $content) !== 1) {
            return null;
        }

        return [$content, $email, $pos + $length + 2];
    }
}
