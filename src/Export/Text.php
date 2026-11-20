<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Writer\Width;

/**
 * The plain-text and ANSI exports (carve-js `src/export/text.ts`). They share one walk; the ANSI target adds SGR
 * styling, box tables and a quote bar.
 *
 * @internal
 */
final class Text
{
    /**
     * The method that writes each node type.
     *
     * @var array<string, string>
     */
    private const BLOCK_METHODS = [
        'section' => 'blockSection',
        'heading' => 'blockHeading',
        'paragraph' => 'blockParagraph',
        'code_block' => 'blockCodeBlock',
        'block_quote' => 'blockBlockQuote',
        'list' => 'list',
        'thematic_break' => 'blockThematicBreak',
        'table' => 'blockTable',
        'admonition' => 'blockAdmonition',
        'directive' => 'blockAdmonition',
        'div' => 'blockAdmonition',
        'line_block' => 'lineBlock',
        'definition_list' => 'definitionList',
        'figure_group' => 'blockFigureGroup',
        'include' => 'blockInclude',
        'block_extension' => 'blockBlockExtension',
        'raw_block' => 'rawBlock',
        'abbreviation_def' => 'abbreviationDefinition',
        'link_reference_definition' => 'linkDefinition',
    ];

    /**
     * The method that writes each node type.
     *
     * @var array<string, string>
     */
    private const INLINE_METHODS = [
        'text' => 'inlineText',
        'emphasis' => 'inlineEmphasis',
        'strong' => 'strong',
        'underline' => 'inlineUnderline',
        'strike' => 'inlineStrike',
        'highlight' => 'inlineHighlight',
        'insert' => 'inlineInsert',
        'delete' => 'inlineDelete',
        'subscript' => 'inlineSubscript',
        'superscript' => 'inlineSuperscript',
        'small_caps' => 'inlineSmallCaps',
        'span' => 'span',
        'code' => 'inlineCode',
        'literal_inline' => 'inlineLiteralInline',
        'math' => 'math',
        'link' => 'link',
        'image' => 'image',
        'autolink' => 'autolink',
        'xref' => 'xref',
        'footnote_ref' => 'noteRef',
        'inline_footnote' => 'noteRef',
        'abbreviation' => 'abbreviation',
        'ruby' => 'ruby',
        'raw_inline' => 'rawInline',
        'symbol' => 'inlineSymbol',
        'mention' => 'inlineMention',
        'tag' => 'inlineTag',
        'inline_extension' => 'inlineInlineExtension',
        'critic_comment' => 'criticComment',
        'caption_number' => 'inlineCaptionNumber',
        'citation_group' => 'inlineCitationGroup',
        'smart_punctuation' => 'inlineSmartPunctuation',
        'non_breaking_space' => 'inlineNonBreakingSpace',
        'soft_break' => 'inlineSoftBreak',
        'hard_break' => 'inlineHardBreak',
    ];

    /**
     * @var string
     */
    private const RESET = "\e[0m";

    /**
     * @var string
     */
    private const BOLD = "\e[1m";

    /**
     * @var string
     */
    private const DIM = "\e[2m";

    /**
     * @var string
     */
    private const ITALIC = "\e[3m";

    /**
     * @var string
     */
    private const UNDERLINE = "\e[4m";

    /**
     * @var string
     */
    private const STRIKE = "\e[9m";

    /**
     * @var string
     */
    private const REVERSE = "\e[7m";

    /**
     * @var string
     */
    private const FG_RED = "\e[31m";

    /**
     * @var string
     */
    private const FG_GREEN = "\e[32m";

    /**
     * @var string
     */
    private const FG_YELLOW = "\e[33m";

    /**
     * @var string
     */
    private const FG_BLUE = "\e[34m";

    /**
     * @var string
     */
    private const FG_MAGENTA = "\e[35m";

    /**
     * @var string
     */
    private const FG_CYAN = "\e[36m";

    /**
     * @var string
     */
    private const FG_BRIGHT_BLACK = "\e[90m";

    /**
     * @var string
     */
    private const FG_BRIGHT_MAGENTA = "\e[95m";

    /**
     * @var string
     */
    private const FG_BRIGHT_YELLOW = "\e[93m";

    /**
     * @var string
     */
    private const FG_BRIGHT_WHITE = "\e[97m";

    /**
     * @var array
     */
    /**
     * @var array<string>
     */
    private const HEADING_COLORS = ["\e[95m", "\e[96m", "\e[94m", "\e[92m", "\e[93m", "\e[97m"];

    /**
     * Stands for a non-breaking space until the end, so the trims leave it alone; then it is a space.
     *
     * @var string
     */
    private const NBSP_HOLD = "\0";

    /**
     * @var array
     */
    /**
     * @var array<int|string, string>
     */
    private const SUPERSCRIPT = [
        '0' => '⁰',
        '1' => '¹',
        '2' => '²',
        '3' => '³',
        '4' => '⁴',
        '5' => '⁵',
        '6' => '⁶',
        '7' => '⁷',
        '8' => '⁸',
        '9' => '⁹',
        '+' => '⁺',
        '-' => '⁻',
        '=' => '⁼',
        '(' => '⁽',
        ')' => '⁾',
        'n' => 'ⁿ',
        'i' => 'ⁱ',
    ];

    /**
     * @var array
     */
    /**
     * @var array<int|string, string>
     */
    private const SUBSCRIPT = [
        '0' => '₀',
        '1' => '₁',
        '2' => '₂',
        '3' => '₃',
        '4' => '₄',
        '5' => '₅',
        '6' => '₆',
        '7' => '₇',
        '8' => '₈',
        '9' => '₉',
        '+' => '₊',
        '-' => '₋',
        '=' => '₌',
        '(' => '₍',
        ')' => '₎',
        'a' => 'ₐ',
        'e' => 'ₑ',
        'o' => 'ₒ',
        'x' => 'ₓ',
    ];

    private int $listDepth = 0;

    /**
     * The list depth whose indentation an enclosing continuation prefix already wrote.
     */
    private int $indentBase = 0;

    /**
     * Above 0 while the children of an inline node are written.
     */
    private int $inlineLevel = 0;

    /**
     * Inside a span with an authored `abbr`, whose value is written instead of nested expansions.
     */
    private int $abbrDepth = 0;

    /**
     * The (term, expansion) pairs this run wrote.
     *
     * @var array<string, true>
     */
    private array $expanded = [];

    /**
     * @param \MarkupCarve\Carve\Export\Exporter $x
     * @param bool $ansi
     * @param array<string, true> $footnotes Footnote definitions by key.
     * @param array<string, true> $referenced Labels of the link reference definitions some reference used.
     * @param array<string, true> $expandedBefore The pairs the first run wrote.
     */
    private function __construct(
        private Exporter $x,
        private bool $ansi,
        private array $footnotes,
        private array $referenced,
        private array $expandedBefore,
    ) {
    }

    public static function export(Exporter $x, bool $ansi): string
    {
        $footnotes = [];
        $referenced = [];
        $abbreviations = false;
        Exporter::eachNode($x->document, static function (Node $node) use (&$footnotes, &$referenced, &$abbreviations): void {
            $key = $node->f['key'] ?? null;
            if ($node->type === 'abbreviation_def') {
                $abbreviations = true;
            }
            if (!is_string($key)) {
                return;
            }
            if ($node->type === 'footnote') {
                $footnotes[$key] = true;
            } elseif (($node->type === 'link' || $node->type === 'image') && !Exporter::isUnresolved($node)) {
                $referenced[$key] = true;
            }
        });
        if (!$abbreviations) {
            return (new self($x, $ansi, $footnotes, $referenced, []))->run()[0];
        }
        // A definition's line depends on whether the occurrences after it wrote its expansion, so a document with one
        // runs twice, the first time only to learn that, with its losses and budget thrown away.
        $losses = $x->losses;
        $budget = $x->derivedLeft;
        $reported = $x->derivedReported;
        [, $expanded] = (new self($x, $ansi, $footnotes, $referenced, []))->run();
        $x->losses = $losses;
        $x->derivedLeft = $budget;
        $x->derivedReported = $reported;

        return (new self($x, $ansi, $footnotes, $referenced, $expanded))->run()[0];
    }

    /**
     * @return array{0: string, 1: array<string, true>}
     */
    private function run(): array
    {
        $body = (string)preg_replace('/\n{3,}/', "\n\n", $this->blocks($this->x->document->children ?? []) . $this->footnoteSection());
        // The plain target keeps the indentation of its first line, which may be code; ANSI trims both ends.
        $text = $this->ansi ? self::trim($body) : self::trimEnd((string)preg_replace('/^\n+/', '', $body));

        return [Exporter::stripBidi(str_replace(self::NBSP_HOLD, ' ', $text . "\n")), $this->expanded];
    }

    private function style(string $text, string $codes): string
    {
        return $this->ansi ? $codes . $text . self::RESET : $text;
    }

    /**
     * Author text on its way out. The plain target keeps the C0 controls as content and drops CR, DEL and the C1 range;
     * the terminal acts on every control, so ANSI keeps only tab and line feed.
     *
     * @param string $text
     *
     * @return string
     */
    private function clean(string $text): string
    {
        // Every forbidden plain-text byte is CR, DEL, or the UTF-8 lead byte of a C1 control.
        // Most text nodes need no replacement; keep the Unicode pattern for the few that do.
        if (!$this->ansi && strpbrk($text, "\r\x7f\xc2") === false) {
            return $text;
        }

        return $this->ansi
            ? (string)preg_replace('/[\x00-\x08\x0b-\x1f\x{7f}-\x{9f}]/u', '', $text)
            : (string)preg_replace('/[\r\x{7f}-\x{9f}]/u', '', $text);
    }

    private static function stripAnsi(string $text): string
    {
        return (string)preg_replace('/\e\[[0-9;]*m/', '', $text);
    }

    /**
     * Trims spaces, tabs and line breaks, keeping a held non-breaking space.
     *
     * @param string $text
     *
     * @return string
     */
    private static function trim(string $text): string
    {
        return trim($text, " \t\n\r");
    }

    private static function trimEnd(string $text): string
    {
        return rtrim($text, " \t\n\r");
    }

    private static function prefixLines(string $content, string $prefix): string
    {
        $lines = explode("\n", $content);

        return implode("\n", array_map(static fn (string $line): string => $line === '' ? $line : $prefix . $line, $lines));
    }

    /**
     * Columns `$text` takes in a terminal; bidirectional controls are dropped from the output, so they take none.
     *
     * @param string $text
     *
     * @return int
     */
    private static function displayWidth(string $text): int
    {
        return Width::of(Exporter::stripBidi($text));
    }

    /**
     * Maps each character that has a form in `$map`, leaving SGR sequences of styled children intact.
     *
     * @param string $text
     * @param array<int|string, string> $map
     *
     * @return string
     */
    private static function mapOutsideSgr(string $text, array $map): string
    {
        $parts = preg_split('/(\e\[[0-9;]*m)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($parts as $at => $part) {
            if ($at % 2 === 0) {
                $parts[$at] = strtr($part, $map);
            }
        }

        return implode('', $parts);
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $list
     *
     * @return string
     */
    private function blocks(array $list): string
    {
        return $this->x->nested(function () use ($list): string {
            $out = '';
            foreach ($list as $node) {
                $out .= $this->block($node);
            }

            return $out;
        });
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $list
     *
     * @return string
     */
    private function inlines(array $list): string
    {
        return $this->x->nested(function () use ($list): string {
            $inside = $this->inlineLevel > 0;
            $out = '';
            $this->inlineLevel++;
            try {
                foreach ($list as $at => $node) {
                    if (($node->type === 'soft_break' || $node->type === 'hard_break') && Exporter::isSkippedBreak($list, $at, $inside)) {
                        continue;
                    }
                    $out .= $this->inline($node);
                }
            } finally {
                $this->inlineLevel--;
            }

            return $out;
        });
    }

    /**
     * A caption on one line: its blocks with their breaks folded into spaces.
     *
     * @param \MarkupCarve\Carve\Node $caption
     *
     * @return string
     */
    private function captionLine(Node $caption): string
    {
        return (string)preg_replace('/[ \t\r]*(?:\n[ \t\r]*)+/', ' ', self::trim($this->blocks($caption->children ?? [])));
    }

    private function captionBlock(Node $caption): string
    {
        $line = $this->captionLine($caption);

        return $this->ansi ? $this->style($line, self::ITALIC . self::DIM) . "\n\n" : $line . "\n\n";
    }

    /**
     * A captioned host: the host, then its caption on the next line, or after a blank line below a quote.
     *
     * @param string $host
     * @param \MarkupCarve\Carve\Node|null $caption
     * @param bool $quote
     *
     * @return string
     */
    private function captioned(string $host, ?Node $caption, bool $quote = false): string
    {
        if ($caption === null) {
            return $host;
        }

        return self::trimEnd($host) . ($quote ? "\n\n" : "\n") . $this->captionBlock($caption);
    }

    private function titleLine(Node $node): string
    {
        $out = '';
        $title = PlainText::nodes($node->f['title'] ?? null);
        if ($title !== []) {
            $text = $this->inlines($this->ansi ? self::unwrapStrong($title) : $title);
            if ($text !== '') {
                $out .= $this->style($text, self::BOLD) . "\n\n";
            }
        }
        $label = Exporter::str($node->f['groupLabel'] ?? '');
        if ($label !== '') {
            $out .= $this->style($this->clean($label), self::BOLD) . "\n\n";
        }

        return $out;
    }

    private function block(Node $node): string
    {
        $method = self::BLOCK_METHODS[$node->type] ?? null;

        return $method === null ? '' : $this->{$method}($node);
    }

    private function blockSection(Node $node): string
    {
        return $this->blocks($node->children ?? []);
    }

    private function blockHeading(Node $node): string
    {
        $level = is_int($node->f['level'] ?? null) ? $node->f['level'] : 1;

        return $this->heading($level, $this->inlines($node->children ?? []));
    }

    private function blockParagraph(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $this->captioned($this->inlines($node->children ?? []) . "\n\n", $caption);
    }

    private function blockCodeBlock(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $this->captioned($this->codeBlock($node), $caption);
    }

    private function blockBlockQuote(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $this->captioned($this->blockQuote($node->children ?? []), $caption, true);
    }

    private function blockThematicBreak(Node $node): string
    {
        return $this->ansi ? $this->style(str_repeat('─', 40), self::DIM) . "\n\n" : "---\n\n";
    }

    private function blockTable(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $this->table($node, $caption);
    }

    private function blockAdmonition(Node $node): string
    {
        return $this->titleLine($node) . $this->blocks($node->children ?? []);
    }

    private function blockFigureGroup(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $this->figureGroup($node, $caption);
    }

    private function blockInclude(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $node->children === null
            ? $this->captioned($this->clean($this->x->unexpandedInclude($node)) . "\n\n", $caption)
            : $this->captioned($this->blocks($node->children), $caption);
    }

    private function blockBlockExtension(Node $node): string
    {
        return $this->x->extensionOutput($node, fn (): string => $this->blocks(Exporter::extensionContent($node, true)));
    }

    private function lineBlock(Node $node): string
    {
        $out = '';
        foreach ($node->children ?? [] as $line) {
            if ($line->type !== 'line') {
                continue;
            }
            $children = $line->children ?? [];
            if ($children !== [] && $children[count($children) - 1]->type === 'hard_break') {
                array_pop($children);
            }
            $out .= $this->inlines($children) . "\n";
        }

        return $out . "\n";
    }

    private function definitionList(Node $node): string
    {
        $out = '';
        foreach ($node->children ?? [] as $child) {
            if ($child->type === 'definition_term') {
                $out .= $this->style($this->inlines($child->children ?? []), self::BOLD . self::FG_YELLOW) . "\n";
            } elseif ($child->type === 'definition_description') {
                $out .= '  ' . self::trim($this->blocks($child->children ?? [])) . "\n";
            }
        }

        return $out . "\n";
    }

    private function figureGroup(Node $node, ?Node $caption): string
    {
        $out = $caption === null ? '' : $this->captionBlock($caption);
        foreach ($node->children ?? [] as $child) {
            $out .= $this->panel($child);
        }

        return $out;
    }

    private function rawBlock(Node $node): string
    {
        // The payload without the line end that closes its last line.
        if ($this->ansi) {
            $value = Exporter::str($node->f['value'] ?? '');
            $value = str_ends_with($value, "\n") ? substr($value, 0, -1) : $value;

            $format = $this->clean(Exporter::str($node->f['format'] ?? ''));

            return $this->style('[raw:' . $format . '] ' . $this->clean($value), self::DIM) . "\n\n";
        }
        $this->x->rawDropped($node, true);

        return '';
    }

    private function abbreviationDefinition(Node $node): string
    {
        $key = Exporter::str($node->f['key'] ?? '');
        $expansion = Exporter::str($node->f['expansion'] ?? '');
        // A definition whose expansion this run writes beside its occurrences loses its line.
        if (isset($this->expandedBefore[$key . "\0" . $expansion])) {
            return '';
        }

        return $this->style('*[' . $this->clean($key) . ']: ' . $this->clean($expansion), self::DIM) . "\n\n";
    }

    private function linkDefinition(Node $node): string
    {
        $key = Exporter::str($node->f['key'] ?? '');
        if (isset($this->referenced[$key])) {
            return '';
        }
        $href = $this->x->allowedDestination(Exporter::stripBidi($this->clean(Exporter::str($node->f['href'] ?? ''))), $node);
        $tooltip = $node->f['tooltip'] ?? null;
        $title = is_string($tooltip) ? ' "' . $this->clean($tooltip) . '"' : '';

        return $this->style('[' . $this->clean($key) . ']:' . ($href === '' ? '' : ' ' . $href) . $title, self::DIM) . "\n\n";
    }

    /**
     * A panel of a figure group: its caption first, then the host, so the caption does not read as the next panel's.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    private function panel(Node $node): string
    {
        $caption = in_array($node->type, ['paragraph', 'code_block', 'block_quote'], true) && ($node->f['caption'] ?? null) instanceof Node
            ? $node->f['caption'] : null;
        if ($caption === null) {
            return $this->block($node);
        }
        $line = $this->captionLine($caption);
        unset($node->f['caption']);
        try {
            $host = self::trim($this->block($node));
        } finally {
            $node->f['caption'] = $caption;
        }

        return ($this->ansi ? $this->style($line, self::ITALIC . self::DIM) : $line) . "\n" . $host . "\n\n";
    }

    private function heading(int $level, string $content): string
    {
        if (!$this->ansi) {
            return $content . "\n\n";
        }
        $color = self::HEADING_COLORS[min($level, 6) - 1] ?? self::FG_BRIGHT_WHITE;
        $out = $this->style($content, self::BOLD . $color);
        if ($level <= 2) {
            $out .= "\n" . $this->style(str_repeat($level === 1 ? '═' : '─', self::displayWidth(self::stripAnsi($content))), $color);
        }

        return $out . "\n\n";
    }

    private function codeBlock(Node $node): string
    {
        $content = $this->clean(Exporter::str($node->f['value'] ?? ''));
        $out = '';
        $title = PlainText::nodes($node->f['title'] ?? null);
        if ($title !== []) {
            $out .= $this->style($this->inlines($title), self::BOLD) . "\n\n";
        }
        $label = Exporter::str($node->f['groupLabel'] ?? '');
        if ($label !== '') {
            $out .= $this->style($this->clean($label), self::BOLD) . "\n\n";
        }
        if (!$this->ansi) {
            return $out . $content . "\n";
        }
        $syntax = Exporter::str($node->f['syntax'] ?? '');
        if ($syntax !== '') {
            $out .= $this->style('┌── ' . $this->clean($syntax) . ' ', self::DIM) . "\n";
        }
        $ends = str_ends_with($content, "\n");
        $lines = $content === '' ? [] : explode("\n", $ends ? substr($content, 0, -1) : $content);
        $last = count($lines) - 1;
        foreach ($lines as $index => $line) {
            $out .= $this->style('  ' . $line, self::FG_BRIGHT_WHITE) . ($index < $last || $ends ? "\n" : '');
        }

        return $out . "\n";
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $children
     *
     * @return string
     */
    private function blockQuote(array $children): string
    {
        $body = $this->blocks($children);
        if (!$this->ansi) {
            return '"' . self::trim($body) . "\"\n\n";
        }

        // The bar marks every line the quote contains, and none of the blank lines between its blocks.
        return self::prefixLines($body, $this->style('│', self::FG_CYAN . self::DIM) . ' ');
    }

    private function itemMarker(bool $ordered, int $counter, ?string $task): string
    {
        if ($ordered) {
            return $this->ansi ? $this->style($counter . '.', self::FG_YELLOW) : $counter . '.';
        }
        if (!$this->ansi) {
            return '-';
        }
        if ($task === null) {
            return $this->style('•', self::FG_CYAN);
        }

        return $task === 'x' || $task === 'X' ? $this->style('☑', self::FG_GREEN) : $this->style('☐', self::FG_BRIGHT_BLACK);
    }

    /**
     * The blocks of an item after its first: a sublist under the line above, any other block at the content column.
     *
     * @param array<\MarkupCarve\Carve\Node> $rest
     * @param bool $tight
     * @param string $first
     *
     * @return string
     */
    private function itemRest(array $rest, bool $tight, string $first): string
    {
        $content = $first;
        foreach ($rest as $child) {
            if ($child->type === 'list') {
                if (!$this->ansi && $tight && str_ends_with($content, "\n\n")) {
                    $content = substr($content, 0, -1);
                }
                $content .= $this->blocks([$child]);

                continue;
            }
            $base = $this->indentBase;
            $this->indentBase = $this->listDepth;
            try {
                $content .= self::prefixLines($this->blocks([$child]), str_repeat('  ', $this->listDepth - $base));
            } finally {
                $this->indentBase = $base;
            }
        }

        return $content;
    }

    private function list(Node $node): string
    {
        $this->listDepth++;
        $out = '';
        $marker = $node->f['marker'] ?? [];
        $marker = is_array($marker) ? $marker : [];
        $counter = is_int($marker['start'] ?? null) ? $marker['start'] : 1;
        $ordered = ($marker['kind'] ?? null) === 'ordered';
        $tight = ($node->f['spacing'] ?? null) === 'tight';
        foreach ($node->children ?? [] as $item) {
            $indent = str_repeat('  ', max(0, $this->listDepth - 1 - $this->indentBase));
            $task = $item->f['task'] ?? null;
            $mark = $this->itemMarker($ordered, $counter++, is_array($task) ? Exporter::str($task['mark'] ?? '') : null);
            // A comment writes nothing, so it does not take the marker line from the first visible block.
            $children = array_values(array_filter($item->children ?? [], static fn (Node $child): bool => $child->type !== 'comment'));
            $first = $children === [] ? '' : $this->blocks([$children[0]]);
            $content = $this->itemRest(array_slice($children, 1), $tight, $first);
            $out .= $indent . $mark . ' ' . self::trim($content) . "\n";
        }
        $this->listDepth--;

        return $this->ansi && $this->listDepth > 0 ? $out : $out . "\n";
    }

    private function cellText(Node $cell): string
    {
        $out = '';
        foreach ($cell->children ?? [] as $child) {
            $out .= $child->type === 'cell_paragraph' ? $this->inlines($child->children ?? []) : $this->blocks([$child]) . "\n";
        }

        return (string)preg_replace('/[ \t\r]*(?:\n[ \t\r]*)+/', ' ', self::trim($out));
    }

    /**
     * @param \MarkupCarve\Carve\Node $row
     *
     * @return array<array{0: string, 1: bool}> Each cell's text and whether it is a header cell.
     */
    private function rowCells(Node $row): array
    {
        $cells = [];
        foreach ($row->children ?? [] as $cell) {
            if (($cell->f['colspan'] ?? 1) > 1 || ($cell->f['rowspan'] ?? 1) > 1) {
                $this->x->loss($cell, 'table-span-flattened', 'Flattened a spanned table cell while rendering ' . $this->x->target);
            }
            $header = ($cell->f['header'] ?? false) === true;
            // A cell a span covers writes nothing in plain text, and an empty cell in a box.
            if (!isset($cell->f['span'])) {
                $cells[] = [$this->cellText($cell), $header];
            } elseif ($this->ansi) {
                $cells[] = ['', $header];
            }
        }

        return $cells;
    }

    private function table(Node $node, ?Node $caption): string
    {
        $rows = [];
        foreach ($node->children ?? [] as $section) {
            if ($section->type === 'comment') {
                continue;
            }
            foreach ($section->children ?? [] as $row) {
                if ($row->type !== 'comment') {
                    $rows[] = $this->rowCells($row);
                }
            }
        }
        if ($this->ansi) {
            $out = $this->boxTable($rows);
        } else {
            $out = '';
            foreach ($rows as $cells) {
                $out .= implode(' | ', array_column($cells, 0)) . "\n";
            }
        }
        if ($caption === null) {
            return $out . "\n";
        }

        return $this->ansi ? $out . $this->captionBlock($caption) : self::trimEnd($out) . "\n" . $this->captionLine($caption) . "\n\n";
    }

    /**
     * @param array<array<array{0: string, 1: bool}>> $rows
     *
     * @return string
     */
    private function boxTable(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $columns = max(array_map('count', $rows));
        $widths = array_fill(0, $columns, 0);
        foreach ($rows as $row) {
            foreach ($row as $at => [$text]) {
                $widths[$at] = max($widths[$at], self::displayWidth(self::stripAnsi($text)));
            }
        }
        $border = fn (string $left, string $cross, string $right): string => $this->style(
            $left . implode($cross, array_map(static fn (int $w): string => str_repeat('─', $w + 2), $widths)) . $right,
            self::DIM,
        ) . "\n";
        $bar = $this->style('│', self::DIM);
        $out = $border('┌', '┬', '┐');
        $headed = false;
        foreach ($rows as $row) {
            $header = $row !== [] && !in_array(false, array_column($row, 1), true);
            $parts = [];
            foreach ($widths as $at => $w) {
                $text = $row[$at][0] ?? '';
                $padded = $text . str_repeat(' ', max(0, $w - self::displayWidth(self::stripAnsi($text))));
                $parts[] = ' ' . ($header ? $this->style($padded, self::BOLD) : $padded) . ' ';
            }
            $out .= $bar . implode($bar, $parts) . $bar . "\n";
            if ($header && !$headed) {
                $out .= $border('├', '┼', '┤');
                $headed = true;
            }
        }

        return $out . $border('└', '┴', '┘');
    }

    private function link(Node $node): string
    {
        $key = $node->f['key'] ?? null;
        if (is_string($key) && Exporter::isUnresolved($node)) {
            return '[' . $this->inlines($node->children ?? []) . ']' . $this->clean(Exporter::referenceLabel($node, $key));
        }
        // A link inside link text writes its content only.
        if ($this->x->linkDepth > 0) {
            return $this->inlines($node->children ?? []);
        }
        $label = $this->x->insideLink(fn (): string => $this->inlines($node->children ?? []));
        if (!$this->ansi) {
            return $label;
        }
        $authored = Exporter::stripBidi($this->clean(Exporter::str($node->f['href'] ?? '')));
        $out = $this->style($label, self::UNDERLINE . self::FG_BLUE);
        if ($authored !== '' && !str_starts_with($authored, '#') && $authored !== self::stripAnsi($label)) {
            $out .= $this->style(' (' . $this->x->allowedDestination($authored, $node) . ')', self::DIM);
        }

        return $out;
    }

    private function image(Node $node): string
    {
        $key = $node->f['key'] ?? null;
        $alt = $this->clean(Exporter::str($node->f['alt'] ?? ''));
        if (is_string($key) && Exporter::isUnresolved($node)) {
            return '![' . $alt . ']' . $this->clean(Exporter::referenceLabel($node, $key));
        }
        if (!$this->ansi) {
            return $alt;
        }

        return $this->style('[img:', self::FG_MAGENTA) . ($alt === '' ? '' : ' ' . $alt) . $this->style(']', self::FG_MAGENTA);
    }

    private function noteRef(Node $node): string
    {
        if ($node->type === 'inline_footnote') {
            return $this->x->outsideLinks(fn (): string => '(' . $this->inlines($node->children ?? []) . ')');
        }
        $raw = Exporter::str($node->f['key'] ?? '');
        $key = $this->clean($raw);
        // An unresolved reference stays as typed and unstyled: the construct did not form.
        if (Exporter::noteNumber($node) === null && !isset($this->footnotes[$raw])) {
            return '[^' . $key . ']';
        }

        return $this->style('[' . $key . ']', self::FG_CYAN . self::BOLD);
    }

    private function inline(Node $node): string
    {
        $method = self::INLINE_METHODS[$node->type] ?? null;

        return $method === null ? '' : $this->{$method}($node);
    }

    private function inlineText(Node $node): string
    {
        $f = $node->f;

        return $this->clean(Exporter::str($f['value'] ?? ''));
    }

    private function inlineEmphasis(Node $node): string
    {
        $children = fn (): string => $this->inlines($node->children ?? []);

        return $this->style($children(), self::ITALIC);
    }

    private function inlineUnderline(Node $node): string
    {
        $children = fn (): string => $this->inlines($node->children ?? []);

        return $this->style($children(), self::UNDERLINE);
    }

    private function inlineStrike(Node $node): string
    {
        $children = fn (): string => $this->inlines($node->children ?? []);

        return $this->style($children(), self::STRIKE);
    }

    private function inlineHighlight(Node $node): string
    {
        $children = fn (): string => $this->inlines($node->children ?? []);

        return $this->style($children(), self::REVERSE . self::FG_YELLOW);
    }

    private function inlineInsert(Node $node): string
    {
        $children = fn (): string => $this->inlines($node->children ?? []);

        return $this->style($children(), self::FG_GREEN . self::UNDERLINE);
    }

    private function inlineDelete(Node $node): string
    {
        $children = fn (): string => $this->inlines($node->children ?? []);

        return $this->ansi ? $this->style($children(), self::STRIKE . self::FG_RED) : '~' . $children() . '~';
    }

    private function inlineSubscript(Node $node): string
    {
        $children = fn (): string => $this->inlines($node->children ?? []);

        return $this->ansi ? self::mapOutsideSgr($children(), self::SUBSCRIPT) : $children();
    }

    private function inlineSuperscript(Node $node): string
    {
        $children = fn (): string => $this->inlines($node->children ?? []);

        return $this->ansi ? self::mapOutsideSgr($children(), self::SUPERSCRIPT) : $children();
    }

    private function inlineSmallCaps(Node $node): string
    {
        $children = fn (): string => $this->inlines($node->children ?? []);

        return $children();
    }

    private function inlineCode(Node $node): string
    {
        $f = $node->f;

        return $this->style($this->clean(Exporter::str($f['value'] ?? '')), self::FG_BRIGHT_YELLOW);
    }

    private function inlineLiteralInline(Node $node): string
    {
        $f = $node->f;

        return $this->clean(Exporter::str($f['value'] ?? ''));
    }

    private function inlineSymbol(Node $node): string
    {
        $f = $node->f;

        return ':' . $this->clean(Exporter::str($f['name'] ?? '')) . ':';
    }

    private function inlineMention(Node $node): string
    {
        $f = $node->f;

        return $this->clean(isset($f['label']) ? Exporter::str($f['label']) : '@' . Exporter::str($f['target'] ?? ''));
    }

    private function inlineTag(Node $node): string
    {
        $f = $node->f;

        return '#' . $this->clean(Exporter::str($f['name'] ?? ''));
    }

    private function inlineInlineExtension(Node $node): string
    {
        return $this->x->extensionOutput($node, fn (): string => $this->inlines(Exporter::extensionContent($node, false)));
    }

    private function inlineCaptionNumber(Node $node): string
    {
        $f = $node->f;

        return is_int($f['n'] ?? null) ? (string)$f['n'] : '#';
    }

    private function inlineCitationGroup(Node $node): string
    {
        $f = $node->f;

        return $this->clean(Exporter::str($f['raw'] ?? ''));
    }

    private function inlineSmartPunctuation(Node $node): string
    {
        return $this->x->smartText($node);
    }

    private function inlineNonBreakingSpace(Node $node): string
    {
        return self::NBSP_HOLD;
    }

    private function inlineSoftBreak(Node $node): string
    {
        return ' ';
    }

    private function inlineHardBreak(Node $node): string
    {
        return "\n";
    }

    private function strong(Node $node): string
    {
        $children = $node->children ?? [];
        $only = count($children) === 1 ? $children[0] : null;
        if ($this->ansi && $only?->type === 'emphasis') {
            return $this->style($this->inlines($only->children ?? []), self::BOLD . self::ITALIC);
        }

        return $this->style($this->inlines($children), self::BOLD);
    }

    private function math(Node $node): string
    {
        $f = $node->f;
        $number = ($f['display'] ?? false) === true && isset($f['number'])
            ? ' ' . $this->clean(Exporter::str($f['numberPrefix'] ?? '')) . ' ' . Exporter::str($f['number']) : '';

        return $this->style($this->clean(Exporter::str($f['value'] ?? '')), self::FG_BRIGHT_MAGENTA) . $number;
    }

    private function autolink(Node $node): string
    {
        $text = $this->clean(isset($node->f['text']) ? Exporter::str($node->f['text']) : Exporter::str($node->f['href'] ?? ''));

        return $this->x->linkDepth > 0 ? $text : $this->style($text, self::UNDERLINE . self::FG_BLUE);
    }

    private function xref(Node $node): string
    {
        [$text, $resolved] = $this->x->xrefText($node);

        $text = $this->clean($text);

        return $resolved && $this->x->linkDepth === 0 ? $this->style($text, self::UNDERLINE . self::FG_BLUE) : $text;
    }

    private function abbreviation(Node $node): string
    {
        $key = Exporter::str($node->f['key'] ?? '');
        $term = $this->clean($key);
        $expansion = $node->f['expansion'] ?? null;
        if ($this->abbrDepth > 0 || !is_string($expansion) || !$this->x->drawDerived($node, $expansion)) {
            return $term;
        }
        $this->expanded[$key . "\0" . $expansion] = true;

        return $term . $this->style(' (' . $this->clean($expansion) . ')', self::DIM);
    }

    private function ruby(Node $node): string
    {
        $this->x->loss($node, 'ruby-flattened', 'Flattened ruby annotation while rendering ' . $this->x->target);
        $out = '';
        foreach (PlainText::nodes($node->f['pairs'] ?? []) as $pair) {
            $out .= $this->inlines(PlainText::nodes($pair->f['base'] ?? [])) . '('
                . $this->inlines(PlainText::nodes($pair->f['annotation'] ?? [])) . ')';
        }

        return $out;
    }

    private function rawInline(Node $node): string
    {
        $this->x->rawDropped($node, false);

        return '';
    }

    private function criticComment(Node $node): string
    {
        $this->x->loss($node, 'editorial-comment-flattened', 'Flattened an editorial comment while rendering ' . $this->x->target);

        return $this->clean(Exporter::str($node->f['value'] ?? ''));
    }

    /**
     * A span with an authored `abbr` writes its value after the content, and no expansion nested in it.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    private function span(Node $node): string
    {
        $abbr = Exporter::attrValue($node->f['attrs'] ?? null, 'abbr');
        if ($abbr === null) {
            return $this->inlines($node->children ?? []);
        }
        $this->abbrDepth++;
        try {
            $inner = $this->inlines($node->children ?? []);
        } finally {
            $this->abbrDepth--;
        }
        if ($abbr === '' || !$this->x->drawDerived(null, $abbr)) {
            return $inner;
        }

        return $inner . $this->style(' (' . $this->clean($abbr) . ')', self::DIM);
    }

    /**
     * Bold in a bold title line is a no-op, and its reset would end the title's styling early.
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function unwrapStrong(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node->type === 'strong') {
                array_push($out, ...self::unwrapStrong($node->children ?? []));
            } elseif ($node->children !== null) {
                $copy = clone $node;
                $copy->children = self::unwrapStrong($node->children);
                $out[] = $copy;
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    private function footnoteSection(): string
    {
        $out = '';
        foreach (Exporter::footnoteDefinitions($this->x->document) as $child) {
            $body = self::trim($this->x->outsideLinks(fn (): string => $this->blocks($child->children ?? [])));
            $key = $this->clean(Exporter::str($child->f['key'] ?? ''));
            $label = $this->ansi ? $this->style('[^' . $key . ']', self::FG_CYAN . self::DIM) : '[^' . $key . ']:';
            $out .= $label . ' ' . $body . "\n";
        }

        return $out;
    }
}
