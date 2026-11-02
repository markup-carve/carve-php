<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

use Closure;
use MarkupCarve\Carve\Parser\InlineParser;

final class EmphasisRenderer
{
    private string $literalPrefix;

    /**
     * @var array<string, string>
     */
    private array $literals = [];

    /**
     * @var array<string, true>
     */
    private array $bareSlashClosers = [];

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
     * @param \Closure(string, array{closers: array<int, true>}=): string $convert
     * @param array<int, array{end: int, source: string, single?: bool}> $attributes
     * @param array<int, true> $validBraces
     * @param array<int, true> $validBraceClosers
     * @param array<int, true> $bracketCloses
     * @param array<int, true> $omittedQuoteBraces
     * @param bool $omitQuoteDelimiters
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
        private readonly array $omittedQuoteBraces = [],
        private readonly bool $omitQuoteDelimiters = false,
    ) {
        $this->literalPrefix = PlaceholderPrefix::choose($source, "\0DJOTLITERAL\0");
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
     * @param list<\MarkupCarve\Carve\Converter\Djot\EmphasisSpan> $roots
     */
    public function convert(array $roots): string
    {
        $work = [];
        foreach (array_reverse($roots) as $pair) {
            $work[] = ['pair' => $pair, 'outer' => [], 'depth' => 0, 'ready' => false];
        }
        while ($work !== []) {
            $frame = array_pop($work);
            $pair = $frame['pair'];
            $outer = $frame['outer'];
            if ($frame['ready']) {
                $this->rendered[spl_object_id($pair)] = $this->render($pair, $outer, $frame['depth']);

                continue;
            }
            $work[] = ['pair' => $pair, 'outer' => $outer, 'depth' => $frame['depth'], 'ready' => true];
            $inner = $outer + [$pair->kind => true];
            foreach (array_reverse($pair->children) as $child) {
                $work[] = ['pair' => $child, 'outer' => $inner, 'depth' => $frame['depth'] + 1, 'ready' => false];
            }
        }

        $body = $this->body(0, strlen($this->source), $roots, []);
        $closers = [];
        $cursor = 0;
        while (($at = strpos($body, $this->literalPrefix, $cursor)) !== false) {
            $end = strpos($body, "\0", $at + strlen($this->literalPrefix));
            if ($end === false) {
                break;
            }
            $token = substr($body, $at, $end - $at + 1);
            if (isset($this->bareSlashClosers[$token])) {
                $closers[$at] = true;
            }
            $cursor = $end + 1;
        }

        return strtr(($this->convert)($body, ['closers' => $closers]), $this->literals);
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
            if (isset($this->omittedQuoteBraces[$i])) {
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
            if ($this->mask[$i] === $ch && ((str_contains('~^_*', $ch) && !isset($this->structural[$i])) || isset($this->literalBrackets[$i]))) {
                $token = $this->literalPrefix . count($this->literals) . "\0";
                $this->literals[$token] = '\\' . $ch;
                $text .= $token;
            } else {
                $text .= isset($this->literalDashes[$i]) ? '\\-' : $ch;
                if ($ch === '^' && isset($this->structural[$i]) && ($this->source[$i + 1] ?? '') === "\t") {
                    $text .= ' ';
                    $i++;
                }
            }
        }

        return $text;
    }

    /**
     * @param int $start
     * @param int $end
     * @param list<\MarkupCarve\Carve\Converter\Djot\EmphasisSpan> $children
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
     * @param \MarkupCarve\Carve\Converter\Djot\EmphasisSpan $pair
     * @param array<string, true> $outer
     * @param int $depth
     */
    private function render(EmphasisSpan $pair, array $outer, int $depth): string
    {
        if ($depth + $pair->hostDepth >= InlineParser::MAX_INLINE_DEPTH - 1) {
            if ($this->onFlattened !== null) {
                ($this->onFlattened)($pair->start);
            }

            return $this->body($pair->openEnd, $pair->close, $pair->children, $outer);
        }
        $scope = isset($outer[$pair->kind]);
        foreach ($pair->children as $child) {
            if (isset($child->kinds[$pair->kind])) {
                $scope = true;

                break;
            }
        }
        $content = $this->body($pair->openEnd, $pair->close, $pair->children, $outer + [$pair->kind => true]);
        if ($pair->kind === '"' || $pair->kind === "'") {
            return $content;
        }
        $delimiter = match ($pair->kind) {
            '_' => '/', '~' => ',', '^' => '^', '=' => '=', default => '*'
        };
        $forced = $this->omitQuoteDelimiters || str_contains('~^', $pair->kind) || $pair->forced || $scope || str_starts_with($content, "\0") || str_ends_with($content, "\0")
                || ($pair->start > 0 && preg_match('/[A-Za-z0-9_]/', $this->source[$pair->start - 1]) === 1)
                || preg_match('/[A-Za-z0-9_]/', $this->source[$pair->end] ?? '') === 1
                // An empty attribute block writes a comment, which counts as nothing for a bare guard (carve#4719).
                || substr($this->source, $pair->end, 2) === '{}'
                || ($pair->start >= 2 && substr($this->source, $pair->start - 2, 2) === '{}' && !$this->escapedAt($pair->start - 2))
                || preg_match('/^[ \t\r\n]|[ \t\r\n]$/', $content) === 1
                || str_starts_with($content, $delimiter) || str_ends_with($content, $delimiter)
                || ($delimiter === '/' && (($this->source[$pair->start - 1] ?? '') === '/' || ($this->source[$pair->end] ?? '') === '/'))
                || ($delimiter === '/' && preg_match('/\/[[:punct:]’“”]/u', $content) === 1)
                || ($delimiter === '/' && str_starts_with($content, '*') && str_ends_with($content, '*'));
        $opening = $this->protect($forced ? '{' . $delimiter : $delimiter);
        $closing = $this->protect($forced ? $delimiter . '}' : $delimiter);
        if ($delimiter === '/') {
            if (!$forced) {
                $this->bareSlashClosers[$closing] = true;
            }
        }

        return $opening . $content . $closing;
    }

    private function escapedAt(int $at): bool
    {
        $start = $at;
        while ($start > 0 && $this->source[$start - 1] === '\\') {
            $start--;
        }

        return ($at - $start) % 2 !== 0;
    }

    private function protect(string $value): string
    {
        $token = $this->literalPrefix . count($this->literals) . "\0";
        $this->literals[$token] = $value;

        return $token;
    }
}
