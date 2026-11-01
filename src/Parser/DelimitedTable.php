<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
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
    public static function rows(string $source, string $separator, ?callable $onField = null, ?callable $onRow = null, ?callable $onMalformed = null): ?array
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
                    if ($onMalformed !== null) {
                        $onMalformed($rowStart);
                    }

                    return null;
                }
                $quoted = true;
                $atStart = false;
                $wasQuoted = true;
            } else {
                if ($closed) {
                    if ($onMalformed !== null) {
                        $onMalformed($rowStart);
                    }

                    return null;
                }
                $field .= $char;
                $atStart = false;
            }
        }
        if ($quoted) {
            if ($onMalformed !== null) {
                $onMalformed($rowStart);
            }

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

    /**
     * @param \MarkupCarve\Carve\Node\Block\CodeBlock $code
     * @param \MarkupCarve\Carve\Parser\SourceMap|null $sourceMap
     * @param \Closure(string): bool|null $skipRecord Drops a record by its raw source text.
     */
    public static function fromCode(CodeBlock $code, ?SourceMap $sourceMap = null, ?Closure $skipRecord = null): ?Table
    {
        $format = $code->getLanguage();
        if (($format !== 'csv' && $format !== 'tsv') || $code->getAttribute('table') !== '') {
            return null;
        }
        /** @var list<list<array{int, int, bool, bool}>> $fieldRanges */
        $fieldRanges = [];
        /** @var list<array{int, int}> $rowRanges */
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
        if ($skipRecord !== null) {
            foreach ($rowRanges as $index => [$start, $end]) {
                if ($skipRecord(substr($code->getContent(), $start, $end - $start))) {
                    unset($data[$index], $fieldRanges[$index], $rowRanges[$index]);
                }
            }
            $data = array_values($data);
            $fieldRanges = array_values($fieldRanges);
            $rowRanges = array_values($rowRanges);
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
            $rowRange = $rowRanges[$index] ?? null;
            $emptyRecord = $rowRange !== null && $rowRange[0] === $rowRange[1];
            if ($sourceMap !== null && $rowRange !== null && !$emptyRecord) {
                $row->setPos($sourceMap->spanRange(...$rowRange));
            }
            for ($column = 0; $column < $width; $column++) {
                $cell = new TableCell($header);
                $text = new Text($fields[$column] ?? '');
                if ($sourceMap !== null && !$emptyRecord && isset($fieldRanges[$index][$column])) {
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
            // A value that states no count is absent for delimited layout. Keep
            // the authored attribute on the table, but give row grouping the
            // effective counts used to classify its rows (CARVE-P12-034).
            if (self::rowCount($metadata->getAttribute('header-rows')) === null) {
                $metadata->setAttribute('header-rows', (string)$headers);
            }
            if (self::rowCount($metadata->getAttribute('footer-rows')) === null) {
                $metadata->setAttribute('footer-rows', (string)$footer);
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
        $value = trim($raw);
        if ($value === '') {
            return 1;
        }

        if (preg_match('/^\d+$/D', $value) !== 1) {
            return null;
        }
        $digits = ltrim($value, '0');
        $maximum = '9007199254740991';
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return null;
        }

        return (int)$value;
    }
}
