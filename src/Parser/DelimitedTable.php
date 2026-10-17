<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Inline\Text;

final class DelimitedTable
{
    /**
     * @return list<list<string>>|null
     */
    public static function rows(string $source, string $separator, ?callable $onField = null, ?callable $onRow = null): ?array
    {
        if ($source === '') {
            return [];
        }
        $rows = [];
        $row = [];
        $field = '';
        $quoted = false;
        $closed = false;
        $atStart = true;
        $fieldStart = 0;
        $rowStart = 0;
        $wasQuoted = false;
        $escaped = false;
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ($quoted) {
                if ($char === '"') {
                    if (($source[$i + 1] ?? null) === '"') {
                        $field .= '"';
                        $escaped = true;
                        $i++;
                    } else {
                        $quoted = false;
                        $closed = true;
                    }
                } else {
                    $field .= $char;
                }

                continue;
            }
            if ($char === $separator || $char === "\n") {
                $row[] = $field;
                if ($onField !== null) {
                    $onField($fieldStart, $i, $wasQuoted, $escaped);
                }
                $field = '';
                $closed = false;
                $atStart = true;
                $fieldStart = $i + 1;
                $wasQuoted = false;
                $escaped = false;
                if ($char === "\n") {
                    $rows[] = $row;
                    if ($onRow !== null) {
                        $onRow($rowStart, $i);
                    }
                    $row = [];
                    $rowStart = $i + 1;
                }
            } elseif ($char === '"') {
                if (!$atStart || $closed) {
                    return null;
                }
                $quoted = true;
                $atStart = false;
                $wasQuoted = true;
            } else {
                if ($closed) {
                    return null;
                }
                $field .= $char;
                $atStart = false;
            }
        }
        if ($quoted) {
            return null;
        }
        if ($source[$length - 1] !== "\n") {
            $row[] = $field;
            if ($onField !== null) {
                $onField($fieldStart, $length, $wasQuoted, $escaped);
            }
            $rows[] = $row;
            if ($onRow !== null) {
                $onRow($rowStart, $length);
            }
        }

        return $rows;
    }

    public static function fromCode(CodeBlock $code, ?SourceMap $sourceMap = null): ?Table
    {
        $format = $code->getLanguage();
        if (($format !== 'csv' && $format !== 'tsv') || $code->getAttribute('table') !== '') {
            return null;
        }
        $fieldRanges = [];
        $rowRanges = [];
        $fields = [];
        $data = self::rows(
            $code->getContent(),
            $format === 'csv' ? ',' : "\t",
            static function (int $start, int $end, bool $quoted, bool $escaped) use (&$fields): void {
                $fields[] = [$start, $end, $quoted, $escaped];
            },
            static function (int $start, int $end) use (&$fields, &$fieldRanges, &$rowRanges): void {
                $fieldRanges[] = $fields;
                $fields = [];
                $rowRanges[] = [$start, $end];
            },
        );
        if ($data === null) {
            return null;
        }
        $table = new Table();
        $table->setAttributesWithOrder($code->getAttributeEntries(), $code->getAttributeOrder());
        $table->removeAttribute('table');
        $table->setAttributeOrder(array_values(array_filter($table->getAttributeOrder(), static fn (string $key): bool => $key !== 'table')));
        $table->setSourceFormat($format);
        $table->setSourceRecordWidths(array_map('count', $data));
        $table->setPos($code->getPos());
        $width = 0;
        foreach ($data as $fields) {
            $width = max($width, count($fields));
        }
        $footer = self::rowCount($table->getAttribute('footer-rows')) ?? 0;
        $headers = self::rowCount($table->getAttribute('header-rows'));
        $headers ??= min(1, max(0, count($data) - $footer));
        foreach ($data as $index => $fields) {
            $header = $index < $headers;
            $row = new TableRow($header);
            if ($sourceMap !== null && isset($rowRanges[$index])) {
                $row->setPos($sourceMap->spanRange(...$rowRanges[$index]));
            }
            for ($column = 0; $column < $width; $column++) {
                $cell = new TableCell($header);
                $text = new Text($fields[$column] ?? '');
                if ($sourceMap !== null && isset($fieldRanges[$index][$column])) {
                    [$start, $end, $quoted, $escaped] = $fieldRanges[$index][$column];
                    $cell->setPos($sourceMap->spanRange($start, $end));
                    if (!$escaped) {
                        $textStart = $start + (int)$quoted;
                        $textEnd = $end - (int)$quoted;
                        $text->setPos($sourceMap->spanFor($textStart, substr($code->getContent(), $textStart, $textEnd - $textStart)));
                    }
                }
                $cell->appendChild($text);
                $row->appendChild($cell);
            }
            $table->appendChild($row);
        }
        $hasGroups = false;
        foreach (['header-rows', 'footer-rows', 'body-rows', 'body-header-rows', 'body-header-cols'] as $key) {
            if ($table->getAttribute($key) !== null) {
                $hasGroups = true;

                break;
            }
        }
        if ($hasGroups) {
            $metadata = clone $table;
            if ($metadata->getAttribute('header-rows') === null) {
                $metadata->setAttribute('header-rows', (string)$headers);
            }
            $table->setRowGroups($metadata->statedRowGroups());
        }

        return $table;
    }

    public static function rowCount(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if ($raw === '') {
            return 1;
        }

        return ctype_digit($raw) ? min((int)$raw, PHP_INT_MAX) : null;
    }
}
