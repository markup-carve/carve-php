<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\RawBlock;

final class FenceAndContinuationLinter
{
    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $parser = $converter->getParser();
        $rows = preg_split('/\r\n|\r|\n/', $source, flags: PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $warnings = [];
        $seen = [];
        foreach ($parser->getUnclosedCodeFences() as $fence) {
            $key = $fence['line'] . ':' . $fence['column'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $marker = $fence['marker'];
            $ending = match ($fence['reason']) {
                'continuation' => sprintf('a continuation marker on line %d ends it', $fence['endLine']),
                'container' => sprintf('it ends at the end of its container on line %d', $fence['endLine']),
                default => 'it runs to the end of the document',
            };
            $warnings[] = $this->warning(
                $rows,
                $fence['line'],
                $fence['column'],
                strlen($marker),
                'unclosed-code-fence',
                sprintf('The %s fence opened here has no closing fence; %s. Add a closing %s where the code should stop.', $marker, $ending, $marker),
            );
        }
        // A container can finish a code node before a physical closer that
        // belongs to the following block. The node span preserves that end.
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            array_push($pending, ...$node->getChildren());
            if (!$node instanceof CodeBlock && !$node instanceof RawBlock) {
                continue;
            }
            $pos = $node->getPos();
            if ($pos === null) {
                continue;
            }
            $lineText = $rows[$pos->startLine - 1][0] ?? '';
            $prefix = mb_substr($lineText, 0, $pos->startColumn - 1, 'UTF-8');
            if (trim($prefix) !== '') {
                continue;
            }
            $opener = mb_substr($lineText, $pos->startColumn - 1, null, 'UTF-8');
            if (preg_match('/^(`{3,}|~{3,})/', $opener, $match) !== 1) {
                continue;
            }
            $marker = $match[1];
            $endText = $rows[$pos->endLine - 1][0] ?? '';
            $closed = $pos->endLine > $pos->startLine
                && preg_match('/^[ \t]*' . preg_quote($marker[0], '/') . '{' . strlen($marker) . ',}[ \t]*$/', $endText) === 1;
            $key = $pos->startLine . ':' . $pos->startColumn;
            if ($closed || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $nextLine = $pos->endLine + 1;
            $next = $rows[$nextLine - 1][0] ?? null;
            if ($next === '' && $nextLine === count($rows)) {
                $next = null;
            }
            if ($next === null || trim($next) === '' || strlen($next) - strlen(ltrim($next, " \t")) >= strlen($prefix)) {
                continue;
            }
            $ending = trim($next) === '+' ? sprintf('a continuation marker on line %d ends it', $nextLine)
                : sprintf('it ends at the end of its container on line %d', $nextLine);
            $warnings[] = $this->warning(
                $rows,
                $pos->startLine,
                $pos->startColumn,
                strlen($marker),
                'unclosed-code-fence',
                sprintf('The %s fence opened here has no closing fence; %s. Add a closing %s where the code should stop.', $marker, $ending, $marker),
            );
        }
        $seen = [];
        foreach ($parser->getRefusedContinuationMarkers() as $marker) {
            $key = $marker['line'] . ':' . $marker['column'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $following = match ($marker['reason']) {
                'blank' => 'a blank line',
                'document' => 'the end of the document',
                'sibling' => 'a sibling list item',
                'list' => 'a list',
                'left' => 'a block left of its column',
                'right' => 'a block right of its column',
                default => 'no open container at its column',
            };
            $remedy = match ($marker['reason']) {
                'blank' => 'Delete the blank line to attach the next block, or delete the +.',
                'document' => 'Add a block after the +, or delete the +.',
                'sibling', 'list' => 'Use a non-list block after the +, or delete the +.',
                'left', 'right' => 'Align the next block with the +, or delete the +.',
                default => 'Move the + to a container marker column, or write it as text.',
            };
            $warnings[] = $this->warning(
                $rows,
                $marker['line'],
                $marker['column'],
                1,
                'continuation-marker-refused',
                sprintf(
                    'This + attaches nothing: %s follows it, so the marker is refused in the %s. %s %s',
                    $following,
                    $marker['host'],
                    $this->refusedMarkerEffect($marker, $rows[$marker['line'] - 1][0] ?? ''),
                    $remedy,
                ),
            );
        }

        return $warnings;
    }

    /**
     * PART 9 §17 L4: a refused first-block `+` adds no block to an item or a description, and stays text in a quote or a note.
     *
     * @param array{line: int, column: int, host: string, reason: string} $marker
     * @param string $lineText
     */
    private function refusedMarkerEffect(array $marker, string $lineText): string
    {
        $lead = trim(str_replace('>', '', mb_substr($lineText, 0, $marker['column'] - 1, 'UTF-8')));
        if ($lead === '' || !in_array($marker['host'], ['list item', 'definition description'], true)) {
            return sprintf('It remains text in the %s.', $marker['host']);
        }
        $host = $marker['host'] === 'list item' ? 'item' : 'description';
        if ($marker['reason'] === 'right') {
            return sprintf('It adds no block to the %s, and the next line follows the ordinary column rules.', $host);
        }

        return sprintf('The %s stays empty.', $host);
    }

    /**
     * @param array<int, array{string, int}> $rows
     * @param string $message
     * @param string $rule
     * @param int $width
     * @param int $column
     * @param int $line
     */
    private function warning(array $rows, int $line, int $column, int $width, string $rule, string $message): LintWarning
    {
        [$content, $start] = $rows[$line - 1];
        $prefix = mb_substr($content, 0, $column - 1, 'UTF-8');
        $bom = $line === 1 && str_starts_with($content, "\u{FEFF}") ? 3 : 0;
        $offset = $start + strlen($prefix) + $bom;

        return new LintWarning($line, $column, $rule, $message, $offset, $offset + $width);
    }
}
