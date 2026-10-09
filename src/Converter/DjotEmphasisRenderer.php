<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;

final class DjotEmphasisRenderer
{
    private string $literalPrefix;

    /**
     * @var array<string, string>
     */
    private array $literals = [];

    /**
     * @var array<int, string>
     */
    private array $rendered = [];

    /**
     * @var array<int, true>
     */
    private array $emptyBlockAttributes = [];

    /**
     * @var array<int, string>
     */
    private array $attributeComments = [];

    /**
     * @param string $source
     * @param string $mask
     * @param array<int, true> $structural
     * @param array<int, true> $literalBrackets
     * @param array<int, true> $literalDashes
     * @param \Closure(string): string $convert
     * @param array<int, array{end: int, source: string, single?: bool}> $attributes
     * @param array<int, true> $validBraces
     * @param array<int, true> $validBraceClosers
     * @param array<int, true> $bracketCloses
     * @param \Closure(int): void|null $onFlattened
     */
    public function __construct(
        private readonly string $source,
        private readonly string $mask,
        private readonly array $structural,
        private readonly array $literalBrackets,
        private readonly array $literalDashes,
        private readonly Closure $convert,
        private readonly array $attributes = [],
        private readonly array $bracketCloses = [],
        private readonly array $validBraceClosers = [],
        private readonly array $validBraces = [],
        private readonly ?Closure $onFlattened = null,
    ) {
        $this->literalPrefix = "\0DJOTLITERAL\0";
        while (str_contains($source, $this->literalPrefix)) {
            $this->literalPrefix .= "\0";
        }
        $lineStart = 0;
        $lineEnd = -1;
        $previous = '';
        $prefixEnd = 0;
        $listAttribute = false;
        $previousContent = '';
        $previousItemWidth = 0;
        $attributeIndent = 0;
        $matchedPrefix = '';
        $activeListColumn = null;
        foreach ($attributes as $at => $attrs) {
            while ($lineEnd < $at) {
                if ($lineEnd >= 0) {
                    $previous = substr($source, $lineStart, $lineEnd - $lineStart);
                    $lineStart = $lineEnd + 1;
                }
                $newline = strpos($source, "\n", $lineStart);
                $lineEnd = $newline === false ? strlen($source) : $newline;
                preg_match('/^[ \t>]*(?:(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)?/', substr($source, $lineStart, $lineEnd - $lineStart), $prefix);
                $matchedPrefix = $prefix[0] ?? '';
                $prefixEnd = $lineStart + strlen($matchedPrefix);
                $listAttribute = preg_match('/[-*+.)]/', $matchedPrefix) === 1;
                $previousContent = preg_replace('/^(?:[ \t]*>[ \t]?)*/', '', $previous) ?? $previous;
                preg_match('/^[ \t]*(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+/', $previousContent, $previousItem);
                $previousItemWidth = strlen($previousItem[0] ?? '');
                $attributeIndent = strlen(preg_replace('/^(?:[ \t]*>[ \t]?)*/', '', $matchedPrefix) ?? $matchedPrefix);
                $listAttribute = $listAttribute && ($lineStart === 0 || trim($previousContent) === '' || $activeListColumn !== null);
                if ($activeListColumn !== null && trim(substr($source, $lineStart, $lineEnd - $lineStart)) !== '' && $attributeIndent < $activeListColumn && !$listAttribute) {
                    $previousItemWidth = $activeListColumn;
                    $activeListColumn = null;
                }
                if ($listAttribute) {
                    $activeListColumn = $attributeIndent;
                }
            }
            if (
                $at === $prefixEnd && !($attrs['single'] ?? true) && $attrs['end'] <= $lineEnd
            ) {
                $this->attributeComments[$at] = '{%%}';
            }
            if (
                $attrs['source'] === '{}' && ($attrs['single'] ?? true) && $attrs['end'] <= $lineEnd
                && $at === $prefixEnd
                && trim(substr($source, $attrs['end'], $lineEnd - $attrs['end'])) === ''
                && ($listAttribute || $lineStart === 0 || trim($previousContent) === '' || preg_match('/^\{.*\}$/', trim($previousContent)) === 1 || $attributeIndent < $previousItemWidth)
            ) {
                $this->emptyBlockAttributes[$at] = true;
                if ($attributeIndent < $previousItemWidth) {
                    $this->attributeComments[$at] = "%%%\n" . $matchedPrefix . '%%%';
                }
            }
        }
    }

    /**
     * @param list<\MarkupCarve\Carve\Converter\DjotEmphasisSpan> $roots
     */
    public function convert(array $roots): string
    {
        $work = [];
        foreach (array_reverse($roots) as $pair) {
            $work[] = ['pair' => $pair, 'outer' => [], 'ready' => false];
        }
        while ($work !== []) {
            $frame = array_pop($work);
            $pair = $frame['pair'];
            $outer = $frame['outer'];
            if ($frame['ready']) {
                $this->rendered[spl_object_id($pair)] = $this->render($pair, $outer);

                continue;
            }
            $work[] = ['pair' => $pair, 'outer' => $outer, 'ready' => true];
            $scope = false;
            foreach ($pair->children as $child) {
                $scope = $scope || array_intersect_key($child->kinds, $outer) !== [];
            }
            $inner = isset($outer[$pair->kind]) ? $outer : ($scope ? [$pair->kind => true] : $outer + [$pair->kind => true]);
            foreach (array_reverse($pair->children) as $child) {
                $work[] = ['pair' => $child, 'outer' => $inner, 'ready' => false];
            }
        }

        return strtr(($this->convert)($this->body(0, strlen($this->source), $roots, [])), $this->literals);
    }

    private function plain(int $start, int $end): string
    {
        $text = '';
        for ($i = $start; $i < $end; $i++) {
            $attributes = $this->attributes[$i] ?? null;
            if ($attributes !== null && $attributes['end'] <= $end) {
                $written = $this->attributeComments[$i] ?? $attributes['source'];
                if ($written === '{}') {
                    $span = isset($this->bracketCloses[$i - 1]) && !isset($this->literalBrackets[$i - 1]);
                    $written = $span ? '{}' : (isset($this->emptyBlockAttributes[$i]) ? '%%' : '{%%}');
                }
                $text .= $this->protect($written);
                $i = $attributes['end'] - 1;

                continue;
            }
            $ch = $this->source[$i];
            if ($ch === '\\' && ($this->source[$i + 1] ?? '') !== "\n") {
                $text .= substr($this->source, $i, min(2, $end - $i));
                $i++;

                continue;
            }
            if ($ch === '=' && (isset($this->validBraceClosers[$i]) || isset($this->validBraces[$i - 1]))) {
                $text .= $this->protect($ch);

                continue;
            }
            if ($this->mask[$i] === $ch && ((str_contains('~^', $ch) && ($this->source[$i + 1] ?? '') === '}' && !isset($this->validBraceClosers[$i])) || (str_contains('_*', $ch) && !isset($this->structural[$i])) || isset($this->literalBrackets[$i]))) {
                $token = $this->literalPrefix . count($this->literals) . "\0";
                $this->literals[$token] = '\\' . $ch;
                $text .= $token;
            } else {
                $text .= isset($this->literalDashes[$i]) ? '\\-' : $ch;
            }
        }

        return $text;
    }

    /**
     * @param int $start
     * @param int $end
     * @param list<\MarkupCarve\Carve\Converter\DjotEmphasisSpan> $children
     * @param array<string, true> $outer
     */
    private function body(int $start, int $end, array $children, array $outer): string
    {
        $text = '';
        $cursor = $start;
        foreach ($children as $child) {
            $text .= $this->plain($cursor, $child->start) . $this->rendered[spl_object_id($child)];
            $cursor = $child->end;
        }

        return $text . $this->plain($cursor, $end);
    }

    /**
     * @param \MarkupCarve\Carve\Converter\DjotEmphasisSpan $pair
     * @param array<string, true> $outer
     */
    private function render(DjotEmphasisSpan $pair, array $outer): string
    {
        if (isset($outer[$pair->kind])) {
            if ($this->onFlattened !== null) {
                ($this->onFlattened)($pair->start);
            }

            return $this->body($pair->openEnd, $pair->close, $pair->children, $outer);
        }
        $scope = false;
        foreach ($pair->children as $child) {
            if (array_intersect_key($child->kinds, $outer) !== []) {
                $scope = true;

                break;
            }
        }
        $content = $this->body($pair->openEnd, $pair->close, $pair->children, $scope ? [$pair->kind => true] : $outer + [$pair->kind => true]);
        $delimiter = $pair->kind === '_' ? '/' : '*';
        $forced = $pair->forced || $scope || str_starts_with($content, "\0") || str_ends_with($content, "\0")
                || ($pair->start > 0 && preg_match('/[A-Za-z0-9_]/', $this->source[$pair->start - 1]) === 1)
                || preg_match('/[A-Za-z0-9_]/', $this->source[$pair->end] ?? '') === 1
                || preg_match('/^[ \t\r\n]|[ \t\r\n]$/', $content) === 1
                || str_starts_with($content, $delimiter) || str_ends_with($content, $delimiter)
                || ($delimiter === '/' && str_starts_with($content, '*') && str_ends_with($content, '*'));

        return $this->protect($forced ? '{' . $delimiter : $delimiter) . $content . $this->protect($forced ? $delimiter . '}' : $delimiter);
    }

    private function protect(string $value): string
    {
        $token = $this->literalPrefix . count($this->literals) . "\0";
        $this->literals[$token] = $value;

        return $token;
    }
}
