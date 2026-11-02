<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

final class ReferenceContext
{
    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    public array $boundaryDiagnostics = [];

    /**
     * @var array<int, true>
     */
    public array $hardReferenceSeparators = [];

    /**
     * Hard blank lines after such a comment, with the column of the list they end.
     *
     * @var array<int, int>
     */
    public array $commentListBoundaries = [];

    public bool $importingFootnoteBody = false;

    public int $markdownFrontmatterLines = 0;

    /**
     * @var array<int, true>
     */
    public array $markdownHtmlSourceLines = [];

    /**
     * @var array<int, int>
     */
    public array $markdownSourceLines = [];

    /**
     * @var list<string>
     */
    public array $movedDefinitions = [];

    /**
     * @var array<int, true>
     */
    public array $movedFootnoteBlockBodies = [];

    /**
     * @var list<int>
     */
    public array $movedFootnoteSourceLines = [];

    /**
     * @var list<list<string>>
     */
    public array $movedFootnotes = [];

    /**
     * The marker column of the list a hard blank line ends: all of them,
     * except after a comment, which ends only its own list.
     */
    public function boundaryColumn(int $line): int
    {
        return $this->commentListBoundaries[$line] ?? 0;
    }
}
