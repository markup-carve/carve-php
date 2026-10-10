<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMDocument;
use DOMNode;
use MarkupCarve\Carve\CarveConverter;
use Throwable;

final class MarkdownAssessment
{
    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    private array $diagnostics = [];

    private bool $complete = true;

    /**
     * @var list<string>
     */
    private array $sourceLines = [];

    /**
     * @var array<string, array{destination: string, title: ?string}>
     */
    private array $definitions = [];

    /**
     * @return array{complete: bool, diagnostics: list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>}
     */
    public function assess(string $source, string $value): array
    {
        $this->diagnostics = [];
        $this->definitions = [];
        $this->complete = true;
        if (strlen($source) > 1000000 || preg_match('//u', $source) !== 1 || !class_exists(DOMDocument::class)) {
            return ['complete' => false, 'diagnostics' => []];
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $source));
        $this->sourceLines = $lines;
        foreach ($lines as $line) {
            if (preg_match('/^ {0,3}\[([^\]\n]+)\]:[ \t]+(\S+)(?:[ \t]+"([^"\n]*)")?[ \t]*$/', $line, $m)) {
                $key = self::label($m[1]);
                $this->definitions[$key] ??= ['destination' => $m[2], 'title' => $m[3] ?? null];
            }
        }
        $expected = $this->blocks($lines, 1);
        if (str_contains($source, "\0")) {
            $this->complete = false;
        }
        if ($this->complete) {
            try {
                $actual = (new CarveConverter(smartTypography: false))->convert($value);
                $this->complete = $this->shape($expected) === $this->shape($actual);
            } catch (Throwable) {
                $this->complete = false;
            }
        }

        return ['complete' => $this->complete, 'diagnostics' => $this->complete ? $this->diagnostics : []];
    }

    private static function label(string $value): string
    {
        return strtolower(preg_replace('/[ \t\n]+/', ' ', trim($value)) ?? $value);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function emit(string $construct, int $line, string $fidelity = 'preserved', ?string $code = null): void
    {
        $message = $construct === 'ordered-task' ? MarkdownToCarve::ORDERED_TASK_ITEM_UNSPELLABLE : 'Assessed Markdown ' . $construct . '.';
        $this->diagnostics[] = new MigrationDiagnostic($code ?? 'markdown-' . $construct, $message, $fidelity === 'dropped' ? 'warning' : 'info', $fidelity, 'exact', 'line:' . $line);
    }

    /**
     * @param string $pattern
     * @param string $text
     * @param int $offset
     * @param array<array-key, string> $matches
     */
    private static function match(string $pattern, string $text, int $offset, array &$matches): bool
    {
        $anchored = $pattern[0] . '\\G' . substr($pattern, 2);

        return preg_match($anchored, $text, $matches, 0, $offset) === 1;
    }

    private function inline(string $text, int $line): string
    {
        $result = '';
        $m = [];
        $run = [];
        for ($i = 0, $length = strlen($text); $i < $length;) {
            if ($text[$i] === '`' && self::match('/^`+/', $text, $i, $run) && strlen($run[0]) > 64) {
                $this->complete = false;

                return $result . self::escape(substr($text, $i));
            }
            if (self::match('/^(`{1,64})([\s\S]*?)\1(?!`)/', $text, $i, $m) && !str_contains($m[2], '`')) {
                $body = str_replace("\n", ' ', $m[2]);
                if (str_starts_with($body, ' ') && str_ends_with($body, ' ') && trim($body, ' ') !== '') {
                    $body = substr($body, 1, -1);
                }
                $this->emit('code-span', $line);
                $result .= '<code>' . self::escape($body) . '</code>';
            } elseif (self::match('/^\\\\([!"#$%&\'()*+,\-.\/:;<=>?@\[\]\\\\^_`{|}~])/', $text, $i, $m)) {
                $this->emit('escape', $line, 'normalized');
                $result .= self::escape($m[1]);
            } elseif (self::match('/^&(?:#[xX][\da-fA-F]+|#\d+|[A-Za-z][A-Za-z\d]+);/', $text, $i, $m)) {
                if (html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $m[0]) {
                    $this->emit('entity', $line, 'normalized');
                }
                $result .= $m[0];
            } elseif (self::match('/^(!?)\[([^\]\n]*)\]\(([^ ()\n]+)(?:[ \t]+"([^"\n]*)")?\)/', $text, $i, $m)) {
                if (!preg_match('~^(https?://|mailto:|[./#])~', $m[3]) && preg_match('/^[A-Za-z][\w+.-]*:/', $m[3])) {
                    $this->complete = false;
                }
                $this->emit($m[1] === '!' ? 'image' : 'link', $line);
                $title = isset($m[4]) ? ' title="' . self::escape($m[4]) . '"' : '';
                $result .= $m[1] === '!' ? '<img src="' . self::escape($m[3]) . '" alt="' . self::escape($m[2]) . '"' . $title . '>' : '<a href="' . self::escape($m[3]) . '"' . $title . '>' . $this->inline($m[2], $line) . '</a>';
            } elseif (self::match('/^(!?)\[([^\]\n]+)\](?:\[([^\]\n]*)\])?/', $text, $i, $m) && isset($this->definitions[self::label(($m[3] ?? '') ?: $m[2])])) {
                $definition = $this->definitions[self::label(($m[3] ?? '') ?: $m[2])];
                $this->emit('reference-link', $line, 'normalized');
                $title = $definition['title'] === null ? '' : ' title="' . self::escape($definition['title']) . '"';
                $result .= $m[1] === '!' ? '<img src="' . self::escape($definition['destination']) . '" alt="' . self::escape($m[2]) . '"' . $title . '>' : '<a href="' . self::escape($definition['destination']) . '"' . $title . '>' . $this->inline($m[2], $line) . '</a>';
            } elseif (self::match('~^<(https?://[^<>\s]+|[^<>\s@]+@[^<>\s@]+\.[^<>\s@]+)>~', $text, $i, $m)) {
                $this->emit('autolink', $line, 'normalized');
                $href = str_contains($m[1], '://') ? $m[1] : 'mailto:' . $m[1];
                $result .= '<a href="' . self::escape($href) . '">' . self::escape($m[1]) . '</a>';
            } elseif (self::match('/^(\*\*|__|~~|\*|_)([^\n]+?)\1/', $text, $i, $m) && !str_contains($m[2], $m[1][0])) {
                if ((str_contains($m[1], '_') && $i > 0 && preg_match('/[A-Za-z0-9]/', $text[$i - 1])) || preg_match('/^\s|\s$/', $m[2])) {
                    $this->complete = false;
                }
                $kind = $m[1] === '~~' ? 'strikethrough' : (strlen($m[1]) === 2 ? 'strong' : 'emphasis');
                $this->emit($kind, $line);
                $tag = $kind === 'strong' ? 'strong' : ($kind === 'emphasis' ? 'em' : 's');
                $result .= '<' . $tag . '>' . $this->inline($m[2], $line) . '</' . $tag . '>';
            } elseif (self::match('/^(?: {2,}|\\\\)\n/', $text, $i, $m)) {
                $this->emit('hard-break', $line, 'normalized');
                $result .= "<br>\n";
            } elseif ($text[$i] === "\n") {
                $m = ["\n"];
                $this->emit('soft-break', $line);
                $result .= "\n";
            } elseif (self::match('/^<(?:!--[\s\S]*?--|\/?[A-Za-z][^<>]*|\?[\s\S]*?\?)>/', $text, $i, $m)) {
                if (preg_match('/^<\/?(?:section|h[1-6]|input)\b/i', $m[0])) {
                    $this->complete = false;
                }
                $this->emit('raw-html', $line, 'degraded', 'raw-preserved');
                $result .= $m[0];
            } else {
                if (str_contains('*_`[\\<', $text[$i]) || self::match('~^(?:\~\~|https?://|www\.)~', $text, $i, $m) || (($i === 0 || !preg_match('/[\w.+-]/', $text[$i - 1])) && self::match('/^[\w.+-]+@[\w.-]+\.[A-Za-z]/', $text, $i, $m))) {
                    $this->complete = false;

                    return $result . self::escape(substr($text, $i));
                }
                if (self::match('~^[^*_\~`\\\\[<&\n]+~', $text, $i, $m)) {
                    $next = $text[$i + strlen($m[0])] ?? '';
                    if ($next === '[' && str_ends_with($m[0], '!')) {
                        $m[0] = substr($m[0], 0, -1);
                    }
                    if ($next === "\n") {
                        $m[0] = preg_replace('/ {2,}$/', '', $m[0]) ?? $m[0];
                    }
                    if ($m[0] === '' || preg_match('~https?://|www\.|[\w.+-]+@[\w.-]+\.[A-Za-z]~', $m[0])) {
                        $this->complete = false;

                        return $result . self::escape(substr($text, $i));
                    }
                } else {
                    $m = [$text[$i]];
                }
                $result .= self::escape($m[0]);
            }
            $line += substr_count($m[0], "\n");
            $i += strlen($m[0]);
        }

        return $result;
    }

    /**
     * @param list<string> $lines
     * @param int $depth
     * @param int $first
     */
    private function blocks(array $lines, int $first, int $depth = 0): string
    {
        if ($depth > 64) {
            $this->complete = false;

            return '';
        }
        $html = '';
        for ($i = 0, $count = count($lines); $i < $count;) {
            $line = $lines[$i];
            $n = $first + $i;
            if (trim($line) === '') {
                $i++;

                continue;
            }
            if (preg_match('/^ {0,3}\[[^\]\n]+\]:[ \t]+\S+(?:[ \t]+"[^"\n]*")?[ \t]*$/', $line)) {
                $this->emit('reference-definition', $n, 'normalized');
                $i++;

                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})([^`]*)$/', $line, $m)) {
                $fence = $m[1];
                $info = trim($m[2]);
                $body = [];
                $i++;
                $closer = '/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*$/';
                while ($i < $count && !preg_match($closer, $lines[$i])) {
                    $body[] = $lines[$i++];
                }
                if ($i < $count) {
                    $i++;
                }
                $this->emit('fenced-code', $n);
                if ($info !== '' && !preg_match('~^[A-Za-z0-9_+./-]+$~', $info)) {
                    $this->complete = false;
                }
                $html .= '<pre><code' . ($info === '' ? '' : ' class="language-' . self::escape($info) . '"') . '>' . self::escape(implode("\n", $body) . ($body === [] ? '' : "\n")) . "</code></pre>\n";

                continue;
            }
            if (str_starts_with($line, '    ')) {
                $body = [];
                while ($i < $count && (str_starts_with($lines[$i], '    ') || trim($lines[$i]) === '')) {
                    $body[] = preg_replace('/^ {4}/', '', $lines[$i++]) ?? '';
                }
                while ($body !== [] && end($body) === '') {
                    array_pop($body);
                }
                $this->emit('indented-code', $n, 'normalized');
                $html .= '<pre><code>' . self::escape(implode("\n", $body) . "\n") . "</code></pre>\n";

                continue;
            }
            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*)|$)/', $line, $m)) {
                $body = trim(preg_replace('/[ \t]+#+[ \t]*$/', '', $m[2] ?? '') ?? '');
                $this->emit('atx-heading', $n);
                $level = strlen($m[1]);
                $html .= '<h' . $level . '>' . $this->inline($body, $n) . '</h' . $level . ">\n";
                $i++;

                continue;
            }
            if ($i + 1 < $count && str_contains($line, '|')) {
                $cells = static fn (string $row): array => array_map('trim', explode('|', trim(trim($row), '|')));
                $separators = $cells($lines[$i + 1]);
                $headers = $cells($line);
                if (count($separators) === count($headers) && count(array_filter($separators, static fn (string $cell): bool => (bool)preg_match('/^:?-{3,}:?$/', $cell))) === count($separators)) {
                    $this->emit('table', $n);
                    $this->emit('table-row', $n);
                    $cellHtml = function (string $body, int $column, string $tag, int $row) use ($separators): string {
                        $this->emit('table-cell', $row);
                        $separator = $separators[$column];
                        $align = str_starts_with($separator, ':') && str_ends_with($separator, ':') ? 'center' : (str_ends_with($separator, ':') ? 'right' : (str_starts_with($separator, ':') ? 'left' : ''));

                        return '<' . $tag . ($align === '' ? '' : ' style="text-align: ' . $align . ';"') . '>' . $this->inline($body, $row) . '</' . $tag . '>';
                    };
                    $html .= "<table>\n<thead>\n<tr>";
                    foreach ($headers as $column => $cell) {
                        $html .= $cellHtml($cell, $column, 'th', $n);
                    }
                    $html .= "</tr>\n</thead>\n";
                    $i += 2;
                    $rows = '';
                    while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                        $row = $cells($lines[$i]);
                        $location = $first + $i;
                        if (count($row) > count($headers) || str_contains($lines[$i], '\\|')) {
                            $this->complete = false;
                        }
                        $this->emit('table-row', $location);
                        $rows .= '<tr>';
                        foreach ($headers as $column => $_header) {
                            $rows .= $cellHtml($row[$column] ?? '', $column, 'td', $location);
                        }
                        $rows .= "</tr>\n";
                        $i++;
                    }
                    if ($rows !== '') {
                        $html .= "<tbody>\n" . $rows . "</tbody>\n";
                    }
                    $html .= "</table>\n";

                    continue;
                }
            }
            if ($i + 1 < $count && preg_match('/^ {0,3}(?:=+|-+)[ \t]*$/', $lines[$i + 1])) {
                $level = str_starts_with(trim($lines[$i + 1]), '=') ? 1 : 2;
                $this->emit('setext-heading', $n, 'normalized');
                $html .= '<h' . $level . '>' . $this->inline(trim($line), $n) . '</h' . $level . ">\n";
                $i += 2;

                continue;
            }
            if (preg_match('/^ {0,3}(?:(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,})$/', $line)) {
                $this->emit('thematic-break', $n);
                $html .= "<hr>\n";
                $i++;

                continue;
            }
            if (preg_match('/^ {0,3}>/', $line)) {
                $body = [];
                $start = $i;
                while (
                    $i < $count && preg_match('/^ {0,3}>/', $lines[$i])
                ) {
                    $body[] = preg_replace('/^ {0,3}> ?/', '', $lines[$i++]) ?? '';
                }
                $this->emit('block-quote', $n);
                $html .= "<blockquote>\n" . $this->blocks($body, $first + $start, $depth + 1) . "</blockquote>\n";

                continue;
            }
            if (preg_match('/^ {0,3}([-+*]|\d{1,9}[.)])[ \t]+(.*)$/', $line, $m)) {
                $ordered = ctype_digit($m[1][0]);
                $tag = $ordered ? 'ol' : 'ul';
                $this->emit($ordered ? 'ordered-list' : 'bullet-list', $n);
                $taskList = !$ordered && preg_match('/^\[([ xX])\][ \t]+/', $m[2]) === 1;
                $html .= '<' . $tag . ($taskList ? ' class="task-list"' : '') . ($ordered && (int)$m[1] !== 1 ? ' start="' . (int)$m[1] . '"' : '') . ">\n";
                while (
                    $i < $count && preg_match('/^ {0,3}([-+*]|\d{1,9}[.)])[ \t]+(.*)$/', $lines[$i], $m) && ctype_digit($m[1][0]) === $ordered
                    && ($ordered || (preg_match('/^\[([ xX])\][ \t]+/', $m[2]) === 1) === $taskList)
                ) {
                    $this->emit('list-item', $first + $i);
                    $body = $m[2];
                    if (preg_match('/^\[([ xX])\][ \t]+(.*)$/', $body, $task)) {
                        if ($ordered) {
                            if (preg_match('/^[ \t]*\d{1,9}[.)][ \t]+\[[ xX]\](?=[ \t])/', $this->sourceLines[$first + $i - 1] ?? '')) {
                                $this->emit('ordered-task', $first + $i, 'dropped', 'structure-unspellable');
                            }
                            $html .= '<li>' . self::escape(substr($body, 0, 3)) . ' ' . $this->inline($task[2], $first + $i) . "</li>\n";
                        } else {
                            $this->emit('bullet-task', $first + $i);
                            $html .= '<li' . ($task[1] === ' ' ? '' : ' data-task-state="x"') . '><input type="checkbox"' . ($task[1] === ' ' ? '' : ' checked') . ' disabled aria-label="' . self::escape($task[2]) . '"> ' . $this->inline($task[2], $first + $i) . "</li>\n";
                        }
                    } else {
                        $html .= '<li>' . $this->inline($body, $first + $i) . "</li>\n";
                    }
                    $i++;
                }
                if (!$ordered && $i < $count && preg_match('/^ {0,3}([-+*])[ \t]+(.*)$/', $lines[$i], $next) && (preg_match('/^\[([ xX])\][ \t]+/', $next[2]) === 1) !== $taskList) {
                    $this->complete = false;
                }
                $html .= '</' . $tag . ">\n";

                continue;
            }
            if (preg_match('/^ {0,3}<(?:div|table|script|style|pre|!--)(?:[\s>]|$)/i', $line)) {
                $body = [];
                while ($i < $count && trim($lines[$i]) !== '') {
                    $body[] = $lines[$i++];
                }
                if (preg_match('/<\/?(?:section|h[1-6]|input)\b/i', implode("\n", $body))) {
                    $this->complete = false;
                }
                $this->emit('raw-html', $n, 'degraded', 'raw-preserved');
                $html .= implode("\n", $body) . "\n";

                continue;
            }
            $body = [$line];
            $i++;
            while ($i < $count && trim($lines[$i]) !== '' && !preg_match('/^(?: {0,3}(?:[#>`~]|[-+*][ \t]|\d+[.)][ \t]|\[[^\]]+\]:)| {4})/', $lines[$i])) {
                $body[] = $lines[$i++];
            }
            $this->emit('paragraph', $n);
            foreach ($body as $text) {
                if (preg_match('/\||\t/', $text)) {
                    $this->complete = false;
                }
            }
            $html .= '<p>' . $this->inline(trim(implode("\n", $body)), $n) . "</p>\n";
        }

        return $html;
    }

    /**
     * @return array<mixed>
     */
    private function shape(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return [];
        }
        $visit = function (DOMNode $node) use (&$visit): array {
            if ($node->nodeType === XML_TEXT_NODE) {
                return [$node->nodeValue];
            }
            if ($node->nodeType === XML_COMMENT_NODE) {
                return [['comment', $node->nodeValue]];
            }
            $tag = $node->nodeName === 'body' ? 'root' : $node->nodeName;
            $children = [];
            $heading = false;
            foreach ($node->childNodes as $child) {
                $children = array_merge($children, $visit($child));
                $heading = $heading || (bool)preg_match('/^h[1-6]$/', $child->nodeName);
            }
            $layout = static fn (mixed $child): bool => is_string($child) && trim($child, " \t\r\n") === '';
            if ($tag === 'section' && $heading) {
                return array_values(array_filter($children, static fn (mixed $child): bool => !$layout($child)));
            }
            $attributes = [];
            foreach ($node->attributes ?? [] as $attribute) {
                if (($attribute->name === 'id' && preg_match('/^h[1-6]$/', $tag)) || ($attribute->name === 'scope' && $tag === 'th') || ($attribute->name === 'aria-label' && $tag === 'input')) {
                    continue;
                }
                $attributes[] = [$attribute->name, $attribute->value];
            }
            sort($attributes);
            if (preg_match('/^(root|ul|ol|li|blockquote|table|thead|tbody|tr)$/', $tag)) {
                $children = array_values(array_filter($children, static fn (mixed $child): bool => !$layout($child)));
            }

            return [[$tag, $attributes, $children]];
        };

        return $visit($body);
    }
}
