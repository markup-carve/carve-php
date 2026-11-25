<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Closure;
use Djot\Node\Block\ListBlock;
use Djot\Parser\Block\ListParser;
use Djot\Parser\Utility\IndentationHelper;

/**
 * The library list markers as djot.js 0.3.2 reads them: a marker ends at any white space, a task box is `[ ]`, `[x]` or
 * `[X]` one space after a bullet and before white space, and no attributes are glued to a marker (`-{.c}` is text).
 * A single roman letter keeps both styles open until a later item of the list narrows them, and is roman at the end.
 */
final class DjotListParser extends ListParser
{
    /**
     * @var string A list marker before a tab, which the library reads only before a space.
     */
    private const MARKER_TAB = '/\A([-*+](?: \[[xX ]\])?|\d+[.)]|\(\d+\)|[a-zA-Z][.)]|\([a-zA-Z]\)'
        . '|[ivxlcdmIVXLCDM]+[.)]|\([ivxlcdmIVXLCDM]+\))\t/';

    /**
     * @param \Closure(string): bool $opensBlock Whether a line ends a list as a block of its own, by the block parser.
     */
    /**
     * @var bool Whether the open paragraph of the lookahead is a heading, which a block opener ends.
     */
    private bool $heading = false;

    public function __construct(private Closure $opensBlock)
    {
    }

    /**
     * @param string $line
     *
     * @return array<string, mixed>|null
     */
    public function parseListItemMarker(string $line): ?array
    {
        $info = parent::parseListItemMarker(preg_replace(self::MARKER_TAB, '$1 ', $line, 1) ?? $line);
        if ($info === null) {
            return null;
        }
        if ($info['type'] === ListBlock::TYPE_TASK && preg_match('/\A[-*+] \[[xX ]\](?:[ \t]|\z)/', $line) !== 1) {
            return [
                'type' => ListBlock::TYPE_BULLET,
                'marker' => $info['marker'],
                'content' => ltrim(substr($line, 1), " \t"),
            ];
        }

        return ($info['attrs'] ?? '') === '' ? $info : null;
    }

    /**
     * @param array<string, mixed> $listInfo
     * @param array<string> $lines
     * @param int $start
     *
     * @return array<string, mixed>
     */
    public function disambiguateListStyle(array $listInfo, array $lines, int $start): array
    {
        $base = IndentationHelper::getLeadingSpaces($lines[$start]);
        $fence = null;
        $this->heading = false;
        $open = $this->opensParagraph(self::content($listInfo), false, $fence);
        $blank = false;
        $count = count($lines);
        for ($i = $start + 1; $i < $count; $i++) {
            $line = $lines[$i];
            if (IndentationHelper::isBlankLine($line)) {
                $blank = true;
                $open = false;

                continue;
            }
            if (IndentationHelper::getLeadingSpaces($line) > $base) {
                $open = $this->opensParagraph(ltrim($line, " \t"), $open, $fence);
                $blank = false;

                continue;
            }
            $item = $this->parseListItemMarker(ltrim($line, " \t"));
            if ($item === null) {
                // Only an open paragraph takes a lazy line.
                if ($blank || !$open || $fence !== null || ($this->opensBlock)($line)) {
                    break;
                }

                continue;
            }
            $blank = false;
            $fence = null;
            $alpha = self::narrows($listInfo, $item);
            if ($alpha === null) {
                $open = $this->opensParagraph(self::content($item), false, $fence);

                continue;
            }
            if ($alpha) {
                $listInfo['start'] = $listInfo['alpha_start'];
                $listInfo['style'] = $listInfo['alpha_style'];
            }

            break;
        }
        unset($listInfo['ambiguous'], $listInfo['alpha_start'], $listInfo['alpha_style']);

        return $listInfo;
    }

    /**
     * Whether a later item narrows an ambiguous list to alphabetic (true) or roman (false); null when both stay open.
     * An item of another type or delimiter ends the list, which is then roman.
     *
     * @param array<string, mixed> $listInfo
     * @param array<string, mixed> $item
     */
    private static function narrows(array $listInfo, array $item): ?bool
    {
        if ($item['type'] !== $listInfo['type'] || $item['marker'] !== $listInfo['marker']) {
            return false;
        }
        $roman = ($item['style'] ?? null) === $listInfo['style'];
        $alpha = ($item['alpha_style'] ?? $item['style'] ?? null) === $listInfo['alpha_style'];

        return $roman && $alpha ? null : $alpha;
    }

    /**
     * @param array<string, mixed> $info
     */
    private static function content(array $info): string
    {
        return is_string($info['content'] ?? null) ? $info['content'] : '';
    }

    /**
     * Whether an item's line leaves a paragraph open for a lazy line: a heading and a quote do, a code fence and the
     * other blocks the library ends a list at do not.
     *
     * @param string $text The line without its indentation.
     * @param bool $open Whether a paragraph is open before it.
     * @param string|null $fence The open code fence.
     */
    private function opensParagraph(string $text, bool $open, ?string &$fence): bool
    {
        if ($fence !== null) {
            if (preg_match('/\A' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*\z/', $text) === 1) {
                $fence = null;
            }

            return false;
        }
        if ($text === '' || ($open && !$this->heading)) {
            return $open;
        }
        if ($open && preg_match('/\A(?:`{3,}|~{3,})/', $text) !== 1 && !($this->opensBlock)($text)) {
            return true;
        }
        $this->heading = false;
        if (preg_match('/\A(`{3,}|~{3,})/', $text, $m) === 1) {
            $fence = $m[1];

            return false;
        }
        if (preg_match('/\A#{1,6}(?:[ \t]|\z)/', $text) === 1) {
            return $this->heading = true;
        }
        if (preg_match('/\A>(?:[ \t]|\z)/', $text) === 1 || $this->parseListItemMarker($text) !== null) {
            return true;
        }

        return !($this->opensBlock)($text);
    }
}
