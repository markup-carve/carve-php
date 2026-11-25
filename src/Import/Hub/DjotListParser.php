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
        $blank = false;
        $count = count($lines);
        for ($i = $start + 1; $i < $count; $i++) {
            $line = $lines[$i];
            if (IndentationHelper::isBlankLine($line)) {
                $blank = true;

                continue;
            }
            // A line past the marker belongs to the item, and so does a lazy line.
            $item = IndentationHelper::getLeadingSpaces($line) > $base ? null : $this->parseListItemMarker(ltrim($line, " \t"));
            if ($item === null) {
                if (IndentationHelper::getLeadingSpaces($line) <= $base && ($blank || ($this->opensBlock)($line))) {
                    break;
                }
                $blank = false;

                continue;
            }
            $blank = false;
            if ($item['type'] !== $listInfo['type'] || $item['marker'] !== $listInfo['marker']) {
                break;
            }
            $roman = ($item['style'] ?? null) === $listInfo['style'];
            $alpha = ($item['alpha_style'] ?? $item['style'] ?? null) === $listInfo['alpha_style'];
            if ($roman && $alpha) {
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
}
