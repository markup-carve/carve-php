<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final class MarkdownReferenceContext
{
    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    public array $boundaryDiagnostics = [];

    /**
     * @var array<int, true>
     */
    public array $hardReferenceSeparators = [];

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
}
