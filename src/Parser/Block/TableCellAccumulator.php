<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Block;

use ReflectionMethod;

/**
 * Collects continuation fragments without rescanning earlier cell text.
 *
 * @internal
 */
final class TableCellAccumulator
{
    /**
     * @var array<int, list<string>>
     */
    private array $fragments = [];

    /**
     * @var array<int, int>
     */
    private array $open = [];

    /**
     * @var array<string>|null
     */
    private ?array $legacyCells = null;

    /**
     * @param \MarkupCarve\Carve\Parser\Block\TableParser $parser
     * @param array<string> $cells
     */
    public function __construct(private TableParser $parser, array $cells)
    {
        if (
            $parser::class !== TableParser::class && (
            (new ReflectionMethod($parser, 'mergeCellContents'))->getDeclaringClass()->getName() !== TableParser::class
            || (new ReflectionMethod($parser, 'openCodeSpanDelimiter'))->getDeclaringClass()->getName() !== TableParser::class
            )
        ) {
            $this->legacyCells = $cells;

            return;
        }
        $this->append($cells);
    }

    /**
     * @param array<string> $cells
     *
     * @return void
     */
    public function append(array $cells): void
    {
        if ($this->legacyCells !== null) {
            $this->legacyCells = $this->parser->mergeCellContents($this->legacyCells, $cells);

            return;
        }
        foreach ($cells as $index => $cell) {
            $this->fragments[$index] ??= [];
            $fragment = trim($cell, ' ');
            if ($fragment === '') {
                continue;
            }
            $this->fragments[$index][] = $fragment;
            $width = $this->parser->advanceCodeSpanDelimiter($fragment, $this->open[$index] ?? 0);
            if ($width > 0) {
                $this->open[$index] = $width;
            } else {
                unset($this->open[$index]);
            }
        }
    }

    /**
     * @return array<int, int>
     */
    public function openDelimiters(): array
    {
        if ($this->legacyCells !== null) {
            $this->updateLegacyDelimiters();
        }

        return $this->open;
    }

    /**
     * @return array<string>
     */
    public function contents(): array
    {
        return $this->legacyCells ?? array_map(static fn (array $fragments): string => implode(' ', $fragments), $this->fragments);
    }

    private function updateLegacyDelimiters(): void
    {
        $this->open = [];
        foreach ($this->legacyCells ?? [] as $index => $cell) {
            $width = $this->parser->openCodeSpanDelimiter($cell);
            if ($width > 0) {
                $this->open[$index] = $width;
            }
        }
    }
}
