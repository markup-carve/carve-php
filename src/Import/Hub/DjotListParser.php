<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\Node\Block\ListBlock;
use Djot\Parser\Block\ListParser;

/**
 * The library list markers as djot.js 0.3.2 reads them: a marker ends at any white space, a task box is `[ ]`, `[x]` or
 * `[X]` one space after a bullet and before white space, and no attributes are glued to a marker (`-{.c}` is text).
 */
final class DjotListParser extends ListParser
{
    /**
     * @var string A list marker before a tab, which the library reads only before a space.
     */
    private const MARKER_TAB = '/\A([-*+](?: \[[xX ]\])?|\d+[.)]|\(\d+\)|[a-zA-Z][.)]|\([a-zA-Z]\)'
        . '|[ivxlcdmIVXLCDM]+[.)]|\([ivxlcdmIVXLCDM]+\))\t/';

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
}
