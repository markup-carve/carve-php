<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

final class TableContext
{
    public bool $droppedTableRows = false;

    public string $omittedTableComment = '';

    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    public array $tableDiagnostics = [];
}
