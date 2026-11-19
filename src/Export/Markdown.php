<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Slug;

/**
 * The Markdown export (carve-js `src/export/markdown.ts`): CommonMark with the GFM tables, task lists, strikethrough
 * and footnotes. What Markdown cannot spell falls back to inline HTML (escaped where it is author content) or
 * flattens, and the flattening is reported.
 *
 * @internal
 */
final class Markdown
{
    /**
     * The method that writes each node type.
     *
     * @var array<string, string>
     */
    private const BLOCK_METHODS = [
        'section' => 'blockSection',
        'heading' => 'heading',
        'paragraph' => 'blockParagraph',
        'code_block' => 'blockCodeBlock',
        'block_quote' => 'blockBlockQuote',
        'list' => 'blockList',
        'thematic_break' => 'blockThematicBreak',
        'table' => 'blockTable',
        'admonition' => 'blockAdmonition',
        'directive' => 'blockAdmonition',
        'div' => 'div',
        'line_block' => 'lineBlock',
        'definition_list' => 'definitionList',
        'figure_group' => 'blockFigureGroup',
        'include' => 'blockInclude',
        'block_extension' => 'blockBlockExtension',
        'raw_block' => 'rawBlock',
        'abbreviation_def' => 'blockAbbreviationDef',
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
        'strong' => 'inlineStrong',
        'strike' => 'inlineStrike',
        'span' => 'span',
        'code' => 'inlineCode',
        'literal_inline' => 'inlineLiteralInline',
        'math' => 'math',
        'link' => 'link',
        'image' => 'image',
        'autolink' => 'autolink',
        'xref' => 'xref',
        'footnote_ref' => 'footnoteRef',
        'inline_footnote' => 'inlineInlineFootnote',
        'abbreviation' => 'abbreviation',
        'ruby' => 'ruby',
        'raw_inline' => 'rawInline',
        'symbol' => 'inlineSymbol',
        'mention' => 'inlineMention',
        'tag' => 'inlineTag',
        'inline_extension' => 'inlineInlineExtension',
        'critic_comment' => 'inlineCriticComment',
        'caption_number' => 'inlineCaptionNumber',
        'citation_group' => 'inlineCitationGroup',
        'smart_punctuation' => 'inlineSmartPunctuation',
        'non_breaking_space' => 'inlineNonBreakingSpace',
        'soft_break' => 'inlineSoftBreak',
        'hard_break' => 'inlineHardBreak',
    ];

    /**
     * @var array
     */
    /**
     * @var array<string, true>
     */
    private const ATTRIBUTE_BLOCKS = [
        'heading' => true,
        'paragraph' => true,
        'code_block' => true,
        'block_quote' => true,
        'list' => true,
        'table' => true,
        'thematic_break' => true,
        'admonition' => true,
        'directive' => true,
        'line_block' => true,
        'definition_list' => true,
        'figure_group' => true,
    ];

    /**
     * @var array
     */
    /**
     * @var array<string, true>
     */
    private const SLUG_CONTAINERS = [
        'section' => true,
        'include' => true,
        'block_quote' => true,
        'div' => true,
        'admonition' => true,
        'directive' => true,
        'list' => true,
        'list_item' => true,
        'footnote' => true,
    ];

    /**
     * @var array
     */
    /**
     * @var array<string, true>
     */
    private const TABLE_MAY_FOLLOW = ['paragraph' => true, 'heading' => true, 'code_block' => true, 'thematic_break' => true];

    /**
     * @var array
     */
    /**
     * @var array<string>
     */
    private const INTERRUPTERS = [
        '/^>/u', '/^#{1,6}(?:[ \t]|$)/u', '/^(?:`{3,}|~{3,})/u', '/^[-*+][ \t]+\S/u', '/^1[.)][ \t]+\S/u', '/^(?:\*[ \t]*){3,}$/u',
    ];

    /**
     * @var array
     */
    /**
     * @var array<string, array{0: string, 1: array<string>}>
     */
    private const ELEMENTS = [
        'underline' => ['u', []],
        'subscript' => ['sub', []],
        'superscript' => ['sup', []],
        'highlight' => ['mark', []],
        'insert' => ['ins', []],
        'delete' => ['del', ['critic-delete']],
        'small_caps' => ['span', ['smallcaps']],
    ];

    /**
     * @var array
     */
    /**
     * @var array<string, string>
     */
    private const ALIGN_ROW = ['left' => ':---', 'center' => ':---:', 'right' => '---:'];

    /**
     * Heading slugs by the id the document gave each heading: GFM has no id syntax, so a link uses the GFM slug.
     *
     * @var array<string, string>
     */
    private array $slugs = [];

    /**
     * @var array<string, true>
     */
    private array $footnotes = [];

    /**
     * @var array<string, true>
     */
    private array $referenced = [];

    private int $abbrDepth = 0;

    /**
     * Above 0 while the children of an inline node are written.
     */
    private int $inlineLevel = 0;

    /**
     * The delimiter runs open around the inline being written.
     *
     * @var array<string>
     */
    private array $open = [];

    /**
     * Inside a table cell or a heading, which hold one line: a hard break is `<br>` and a soft break a space.
     */
    private int $singleLine = 0;

    private function __construct(private Exporter $x)
    {
    }

    public static function export(Exporter $x): string
    {
        $m = new self($x);
        $m->collectKeys();
        $m->headingSlugs();
        $written = $m->blocks($x->document->children ?? []) . $m->footnoteSection();
        $body = rtrim((string)preg_replace(['/\n{3,}/', '/^\n+/'], ["\n\n", ''], $written));
        $head = $m->frontmatter();
        if ($body === '') {
            return $head === '' ? '' : rtrim($head) . "\n";
        }

        return Exporter::stripBidi($head . $body . "\n");
    }

    /**
     * Drops what the target cannot carry: CR, DEL and the C1 range. The other C0 controls are content.
     *
     * @param string $text
     *
     * @return string
     */
    private static function clean(string $text): string
    {
        return (string)preg_replace('/[\r\x{7f}-\x{9f}]/u', '', $text);
    }

    private static function escapeHtml(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], self::clean($text));
    }

    private static function escapeAttribute(string $text): string
    {
        return str_replace('"', '&quot;', self::escapeHtml($text));
    }

    /**
     * Backslash-escapes the characters that would read as Markdown inside a line. An underscore between two letters
     * or digits cannot open or close emphasis, so it stays as written; a `<` is escaped only where it could open HTML.
     * An escaped `]` already keeps a bracket from forming a link, so `[` is escaped only inside a link label.
     *
     * @param string $text
     * @param bool $label
     *
     * @return string
     */
    private static function escapeText(string $text, bool $label = false): string
    {
        $value = self::clean($text);

        return (string)preg_replace_callback('/[_\\\\`*~\]\[<&]/', static function (array $m) use ($value, $label): string {
            [$ch, $at] = $m[0];
            if ($ch === '_') {
                return self::isWord(self::charBefore($value, $at)) && self::isWord(self::charAt($value, $at + 1)) ? '_' : '\_';
            }
            if ($ch === '[') {
                return $label ? '\[' : '[';
            }
            if ($ch === '<') {
                return preg_match('~^[A-Za-z/!?]~', substr($value, $at + 1, 1)) === 1 ? '\<' : '<';
            }
            if ($ch === '&') {
                return preg_match('/^&(?:#[0-9]{1,7};|#[xX][0-9a-fA-F]{1,6};|[A-Za-z][A-Za-z0-9]*;)/', substr($value, $at, 40)) === 1
                    ? '\&' : '&';
            }

            return '\\' . $ch;
        }, $value, flags: PREG_OFFSET_CAPTURE);
    }

    /**
     * A letter or digit of the Basic Multilingual Plane, as the reference tests one UTF-16 unit.
     *
     * @param string $ch
     *
     * @return bool
     */
    private static function isWord(string $ch): bool
    {
        return $ch !== '' && strlen($ch) < 4 && preg_match('/^[\p{L}\p{N}]$/u', $ch) === 1;
    }

    /**
     * The UTF-8 character that ends before byte `$at`.
     *
     * @param string $s
     * @param int $at
     *
     * @return string
     */
    public static function charBefore(string $s, int $at): string
    {
        if ($at <= 0) {
            return '';
        }
        $start = $at - 1;
        while ($start > 0 && (ord($s[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        return substr($s, $start, $at - $start);
    }

    /**
     * The UTF-8 character that starts at byte `$at`.
     *
     * @param string $s
     * @param int $at
     *
     * @return string
     */
    public static function charAt(string $s, int $at): string
    {
        if ($at >= strlen($s)) {
            return '';
        }
        $lead = ord($s[$at]);
        $len = $lead < 0x80 ? 1 : ($lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : 2));

        return substr($s, $at, $len);
    }

    private static function escapeTitle(string $title): string
    {
        return (string)preg_replace('/[\\\\"]/', '\\\\$0', self::clean($title));
    }

    /**
     * A link or image destination: spaces, angle brackets and pipes percent-encoded, parentheses escaped, and every
     * ampersand that opens a character reference escaped, since a reader decodes those before it reads the scheme.
     *
     * @param string $url
     *
     * @return string
     */
    private static function destinationText(string $url): string
    {
        $value = self::clean($url);
        $pipes = str_contains($value, '|');
        $out = (string)preg_replace_callback('/[ ()<>\\\\|\n]/', static fn (array $m): string => match ($m[0]) {
            '\\' => $pipes ? '%5C' : '\\',
            '|' => '%7C',
            ' ' => '%20',
            "\n" => '%0A',
            '<' => '%3C',
            '>' => '%3E',
            default => '\\' . $m[0],
        }, $value);

        return (string)preg_replace('/&(?=#[0-9]{1,8};|#[xX][0-9a-fA-F]{1,8};|[a-zA-Z][a-zA-Z0-9]{0,31};)/', '&amp;', $out);
    }

    /**
     * The HTML attributes of `$attrs`, for an element this target writes as inline HTML.
     *
     * @param mixed $attrs
     * @param array<string> $base
     *
     * @return string
     */
    private static function htmlAttributes(mixed $attrs, array $base = []): string
    {
        $attrs = $attrs instanceof Attrs ? $attrs : null;
        $out = '';
        if ($attrs?->id !== null) {
            $out .= ' id="' . self::escapeAttribute($attrs->id) . '"';
        }
        $classes = $base;
        foreach ($attrs->classes ?? [] as $name) {
            if (!in_array($name, $base, true)) {
                $classes[] = $name;
            }
        }
        if ($classes !== []) {
            $out .= ' class="' . self::escapeAttribute(implode(' ', $classes)) . '"';
        }
        foreach ($attrs->pairs ?? [] as [$key, $value]) {
            if (preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]*$/', $key) === 1 && preg_match('/^on/i', $key) !== 1) {
                $out .= ' ' . $key . '="' . self::escapeAttribute($value) . '"';
            }
        }

        return $out;
    }

    private static function hasAttributes(mixed $attrs): bool
    {
        return $attrs instanceof Attrs && ($attrs->id !== null || $attrs->classes !== [] || $attrs->pairs !== []);
    }

    /**
     * A delimiter run followed by whitespace does not open emphasis (CommonMark 6.2), so the spaces at the edges of the
     * content move outside the delimiters. Content of spaces only has no delimiter form and takes the HTML element.
     *
     * @param string $inner
     * @param string $delimiter
     * @param string $tag
     *
     * @return string
     */
    private static function delimited(string $inner, string $delimiter, string $tag): string
    {
        preg_match('/^\p{White_Space}*/u', $inner, $lm);
        $lead = $lm[0] ?? '';
        $rest = substr($inner, strlen($lead));
        preg_match('/\p{White_Space}*$/uD', $rest, $tm);
        $trail = $tm[0] ?? '';
        $core = substr($rest, 0, strlen($rest) - strlen($trail));
        if ($core === '') {
            return $inner === '' ? '' : '<' . $tag . '>' . $inner . '</' . $tag . '>';
        }
        // A final hard break after a closing delimiter would end the paragraph and read as a backslash.
        if (str_ends_with($core, '\\') && str_starts_with($trail, "\n")) {
            return $lead . '<' . $tag . '>' . $core . '</' . $tag . '>' . $trail;
        }

        return $lead . $delimiter . $core . $delimiter . $trail;
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
                    $written = $this->inline($node);
                    $out .= $this->flankingSafe($node, $written, self::charBefore($out, strlen($out)), $list, $at + 1);
                }
            } finally {
                $this->inlineLevel--;
            }

            return $out;
        });
    }

    /**
     * The first character the inlines from `$from` on would write, without reporting anything twice.
     *
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param int $from
     *
     * @return string
     */
    private function firstCharacter(array $list, int $from): string
    {
        $count = count($list);
        for ($at = $from; $at < $count; $at++) {
            $node = $list[$at];
            if ($node->type === 'comment' || ($node->type === 'text' && Exporter::str($node->f['value'] ?? '') === '')) {
                continue;
            }

            return match ($node->type) {
                'text' => self::charAt(Exporter::str($node->f['value'] ?? ''), 0),
                'soft_break', 'hard_break' => "\n",
                'code' => '`',
                'smart_punctuation' => self::charAt($this->x->smartText($node), 0),
                'link', 'image', 'autolink' => '[',
                // Every other inline starts with markup or a letter of its own; a letter stands for both.
                default => 'a',
            };
        }

        return '';
    }

    /**
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     */
    private function oneLine(Closure $run): mixed
    {
        $this->singleLine++;
        try {
            return $run();
        } finally {
            $this->singleLine--;
        }
    }

    /**
     * @param string $tag
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string> $base
     *
     * @return string
     */
    private function element(string $tag, Node $node, array $base = []): string
    {
        return '<' . $tag . self::htmlAttributes($node->f['attrs'] ?? null, $base) . '>' . $this->inlines($node->children ?? [])
            . '</' . $tag . '>';
    }

    /**
     * A delimiter run inside a run of the same delimiter would close the outer one, so it takes the HTML element.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param string $delimiter
     * @param string $tag
     *
     * @return string
     */
    private function delimitedNode(Node $node, string $delimiter, string $tag): string
    {
        if (self::hasAttributes($node->f['attrs'] ?? null) || in_array($delimiter, $this->open, true)) {
            return $this->element($tag, $node);
        }
        $this->open[] = $delimiter;
        try {
            return self::delimited($this->inlines($node->children ?? []), $delimiter, $tag);
        } finally {
            array_pop($this->open);
        }
    }

    /**
     * CommonMark 6.2: whether a run between `$before` and `$after` (empty at a line edge) can open or close.
     *
     * @param string $before
     * @param string $after
     * @param bool $opening
     *
     * @return bool
     */
    private static function flanks(string $before, string $after, bool $opening): bool
    {
        $inside = $opening ? $after : $before;
        $outside = $opening ? $before : $after;
        if ($inside === '' || self::isSpace($inside)) {
            return false;
        }

        return !self::isPunctuation($inside) || $outside === '' || self::isSpace($outside) || self::isPunctuation($outside);
    }

    private static function isSpace(string $ch): bool
    {
        return preg_match('/^\p{White_Space}$/u', $ch) === 1;
    }

    private static function isPunctuation(string $ch): bool
    {
        return preg_match('/^[\p{P}\p{S}]$/u', $ch) === 1;
    }

    /**
     * A delimiter run written next to a letter on one side and punctuation inside does not flank (CommonMark 6.2), so
     * such an emphasis is written with its HTML element instead.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param string $written
     * @param string $before
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param int $next
     *
     * @return string
     */
    private function flankingSafe(Node $node, string $written, string $before, array $list, int $next): string
    {
        $delimiter = match ($node->type) {
            'emphasis' => '*',
            'strong' => '**',
            'strike' => '~~',
            default => null,
        };
        if ($delimiter === null) {
            return $written;
        }
        $n = strlen($delimiter);
        if (!str_starts_with($written, $delimiter) || !str_ends_with($written, $delimiter) || strlen($written) <= $n * 2) {
            return $written;
        }
        $core = substr($written, $n, -$n);
        $after = $this->firstCharacter($list, $next);
        if (self::flanks($before, self::charAt($core, 0), true) && self::flanks(self::charBefore($core, strlen($core)), $after, false)) {
            return $written;
        }
        $tag = $node->type === 'emphasis' ? 'em' : ($node->type === 'strong' ? 'strong' : 'del');

        return '<' . $tag . '>' . $core . '</' . $tag . '>';
    }

    /**
     * A code span whose fence is longer than any backtick run inside; a tab or a line break needs the HTML element.
     *
     * @param string $value
     *
     * @return string
     */
    private static function codeSpan(string $value): string
    {
        $content = self::clean($value);
        if ($content === '') {
            return '<code></code>';
        }
        // Markdown reads the text between two HTML tags, so every ASCII punctuation character is a reference there; an
        // empty comment around a line break keeps the line from opening a block, and after `@` from forming an autolink.
        if (strpbrk($content, "\n\t") !== false) {
            return '<code>' . preg_replace_callback(
                '/[\n\t\x20-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]/',
                static fn (array $m): string => $m[0] === "\n" ? '<!---->&#10;<!---->'
                    : '&#' . ord($m[0]) . ';' . ($m[0] === '@' ? '<!---->' : ''),
                $content,
            ) . '</code>';
        }
        $longest = 0;
        if (preg_match_all('/`+/', $content, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }
        $fence = str_repeat('`', $longest + 1);
        $spaced = str_starts_with($content, ' ') && str_ends_with($content, ' ') && trim($content, ' ') !== '';
        $pad = str_starts_with($content, '`') || str_ends_with($content, '`') || $spaced;

        return $pad ? $fence . ' ' . $content . ' ' . $fence : $fence . $content . $fence;
    }

    private function linkDestination(string $href, Node $node): string
    {
        $allowed = $this->x->allowedDestination(Exporter::stripBidi(self::clean($href)), $node);
        if (str_starts_with($allowed, '#')) {
            $slug = $this->slugs[self::safeDecode(substr($allowed, 1))] ?? null;
            if ($slug !== null) {
                return '#' . $slug;
            }
        }

        return self::destinationText($allowed);
    }

    /**
     * `decodeURIComponent`, or the fragment as written where it does not decode to UTF-8.
     *
     * @param string $fragment
     *
     * @return string
     */
    private static function safeDecode(string $fragment): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $fragment) === 1) {
            return $fragment;
        }
        $decoded = rawurldecode($fragment);

        return preg_match('//u', $decoded) === 1 ? $decoded : $fragment;
    }

    private function link(Node $node): string
    {
        $key = $node->f['key'] ?? null;
        if (is_string($key) && Exporter::isUnresolved($node)) {
            return '[' . $this->inlines($node->children ?? []) . '\]' . self::escapeText(Exporter::referenceLabel($node, $key));
        }
        if ($this->x->linkDepth > 0) {
            return $this->inlines($node->children ?? []);
        }
        $destination = $this->linkDestination(Exporter::str($node->f['href'] ?? ''), $node);
        $text = $this->x->insideLink(fn (): string => $this->inlines($node->children ?? []));
        $title = $this->x->tooltip($node);

        $titled = $title === null ? '' : ' "' . self::escapeTitle($title) . '"';

        return '[' . $text . '](' . $destination . $titled . ')';
    }

    private function image(Node $node): string
    {
        $key = $node->f['key'] ?? null;
        $alt = self::escapeText(Exporter::str($node->f['alt'] ?? ''), true);
        if (is_string($key) && Exporter::isUnresolved($node)) {
            return '![' . $alt . '\]' . self::escapeText(Exporter::referenceLabel($node, $key));
        }
        $src = Exporter::stripBidi(self::clean(Exporter::str($node->f['src'] ?? '')));
        $src = self::destinationText($this->x->allowedDestination($src, $node));
        $title = $this->x->tooltip($node);

        return $title === null ? '![' . $alt . '](' . $src . ')' : '![' . $alt . '](' . $src . ' "' . self::escapeTitle($title) . '")';
    }

    private function autolink(Node $node): string
    {
        $href = Exporter::str($node->f['href'] ?? '');
        $label = self::clean(isset($node->f['text']) ? Exporter::str($node->f['text']) : $href);
        if ($this->x->linkDepth > 0) {
            return self::escapeText($label);
        }
        $allowed = $this->x->allowedDestination(Exporter::stripBidi(self::clean($href)), $node);
        if ($allowed === $label && preg_match('/^[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s\\\\]+$/u', $label) === 1) {
            return '<' . $label . '>';
        }
        if (
            $allowed === 'mailto:' . $label
            && preg_match('/^[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+$/', $label) === 1
        ) {
            return '<' . $label . '>';
        }

        return '[' . self::escapeText($label) . '](' . self::destinationText($allowed) . ')';
    }

    private function inline(Node $node): string
    {
        $element = self::ELEMENTS[$node->type] ?? null;
        if ($element !== null) {
            return $this->element($element[0], $node, $element[1]);
        }

        $method = self::INLINE_METHODS[$node->type] ?? null;

        return $method === null ? '' : $this->{$method}($node);
    }

    private function inlineText(Node $node): string
    {
        $f = $node->f;

        return self::escapeText(Exporter::str($f['value'] ?? ''), $this->x->linkDepth > 0);
    }

    private function inlineEmphasis(Node $node): string
    {
        return $this->delimitedNode($node, '*', 'em');
    }

    private function inlineStrong(Node $node): string
    {
        return $this->delimitedNode($node, '**', 'strong');
    }

    private function inlineStrike(Node $node): string
    {
        return $this->delimitedNode($node, '~~', 'del');
    }

    private function inlineCode(Node $node): string
    {
        $f = $node->f;

        return self::codeSpan(Exporter::str($f['value'] ?? ''));
    }

    private function inlineLiteralInline(Node $node): string
    {
        $f = $node->f;

        return self::escapeText(Exporter::str($f['value'] ?? ''));
    }

    private function inlineInlineFootnote(Node $node): string
    {
        return $this->x->outsideLinks(fn (): string => '^[' . $this->inlines($node->children ?? []) . ']');
    }

    private function inlineSymbol(Node $node): string
    {
        $f = $node->f;

        return ':' . self::clean(Exporter::str($f['name'] ?? '')) . ':';
    }

    private function inlineMention(Node $node): string
    {
        $f = $node->f;

        return self::escapeText(isset($f['label']) ? Exporter::str($f['label']) : '@' . Exporter::str($f['target'] ?? ''));
    }

    private function inlineTag(Node $node): string
    {
        $f = $node->f;

        return self::escapeText('#' . Exporter::str($f['name'] ?? ''));
    }

    private function inlineInlineExtension(Node $node): string
    {
        return $this->inlines(Exporter::extensionContent($node, false));
    }

    private function inlineCriticComment(Node $node): string
    {
        $f = $node->f;

        return '<span class="critic-comment">' . self::escapeText(Exporter::str($f['value'] ?? '')) . '</span>';
    }

    private function inlineCaptionNumber(Node $node): string
    {
        $f = $node->f;

        return is_int($f['n'] ?? null) ? (string)$f['n'] : '\#';
    }

    private function inlineCitationGroup(Node $node): string
    {
        $f = $node->f;

        return self::escapeText(Exporter::str($f['raw'] ?? ''));
    }

    private function inlineSmartPunctuation(Node $node): string
    {
        return self::escapeText($this->x->smartText($node));
    }

    private function inlineNonBreakingSpace(Node $node): string
    {
        return "\u{00A0}";
    }

    private function inlineSoftBreak(Node $node): string
    {
        return $this->singleLine > 0 ? ' ' : "\n";
    }

    private function inlineHardBreak(Node $node): string
    {
        return $this->singleLine > 0 ? '<br>' : "\\\n";
    }

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
        if ($abbr === '' || !$this->x->drawDerived($node, $abbr)) {
            return $inner;
        }

        return '<abbr title="' . self::escapeAttribute($abbr) . '">' . $inner . '</abbr>';
    }

    private function math(Node $node): string
    {
        $f = $node->f;
        $value = self::escapeHtml(Exporter::str($f['value'] ?? ''));
        $display = ($f['display'] ?? false) === true;
        $number = $display && isset($f['number'])
            ? ' ' . self::escapeText(Exporter::str($f['numberPrefix'] ?? '')) . ' ' . Exporter::str($f['number']) : '';

        return ($display ? '$$' . $value . '$$' : '$' . $value . '$') . $number;
    }

    private function xref(Node $node): string
    {
        [$text, $resolved] = $this->x->xrefText($node);
        if (!$resolved) {
            return '</#' . self::escapeHtml(Exporter::str($node->f['key'] ?? '')) . '>';
        }
        $slug = $this->slugs[self::safeDecode(substr(Exporter::str($node->f['href'] ?? ''), 1))] ?? null;
        if ($this->x->linkDepth > 0 || $slug === null) {
            return self::escapeText($text);
        }

        return '[' . self::escapeText($text) . '](#' . $slug . ')';
    }

    private function footnoteRef(Node $node): string
    {
        $raw = Exporter::str($node->f['key'] ?? '');
        $key = self::escapeHtml($raw);
        if (Exporter::noteNumber($node) === null && !isset($this->footnotes[$raw])) {
            return '\[^' . str_replace('\\', '\\\\', $key) . '\]';
        }

        return '[^' . $key . ']';
    }

    private function abbreviation(Node $node): string
    {
        $term = self::escapeHtml(Exporter::str($node->f['key'] ?? ''));
        $expansion = $node->f['expansion'] ?? null;
        if ($this->abbrDepth > 0 || !is_string($expansion) || !$this->x->drawDerived($node, $expansion)) {
            return $term;
        }

        return '<abbr title="' . self::escapeAttribute($expansion) . '">' . $term . '</abbr>';
    }

    private function ruby(Node $node): string
    {
        $out = '<ruby' . self::htmlAttributes($node->f['attrs'] ?? null) . '>';
        foreach (PlainText::nodes($node->f['pairs'] ?? []) as $pair) {
            $out .= $this->inlines(PlainText::nodes($pair->f['base'] ?? [])) . '<rp>(</rp><rt>'
                . $this->inlines(PlainText::nodes($pair->f['annotation'] ?? [])) . '</rt><rp>)</rp>';
        }

        return $out . '</ruby>';
    }

    private function rawInline(Node $node): string
    {
        if (($node->f['format'] ?? null) !== 'html') {
            $this->x->rawDropped($node, false);

            return '';
        }
        $this->x->loss($node, 'raw-html-escaped', 'Escaped raw HTML in Markdown');

        return self::escapeHtml(Exporter::str($node->f['value'] ?? ''));
    }

    /**
     * Keeps a paragraph line from reading as a block of its own: a list marker, a quote, a heading, a thematic break, a
     * setext underline, or code by its indentation. Spaces and tabs at the edges of a line are layout and go.
     *
     * @param string $line
     * @param bool $first
     *
     * @return string
     */
    private static function protectLine(string $line, bool $first): string
    {
        $text = rtrim(ltrim($line, " \t"), " \t");
        // A hard break keeps its backslash as the last character of the line, with no space before it.
        $backslashes = strlen($text) - strlen(rtrim($text, '\\'));
        if ($backslashes % 2 === 1) {
            $text = rtrim(substr($text, 0, -1), " \t") . '\\';
        }
        if ($text === '') {
            return $text;
        }
        $text = (string)preg_replace(
            [
                $first ? '/^([-+])(?=[ \t]|$)/u' : '/^([-+])(?=[ \t]+\S)/u',
                '/^(\d{1,9})([.)])(?=[ \t]|$)/u',
                '/^>/u',
                '/^(#{1,6})(?=[ \t]|$)/u',
            ],
            ['\\\\$1', '$1\\\\$2', '\\\\>', '\\\\$1'],
            $text,
            1,
        );
        if (preg_match('/^([-_*])(?:[ \t]*\1){2,}[ \t]*$/uD', $text) === 1) {
            $text = '\\' . $text;
        } elseif (!$first && preg_match('/^(?:=+|-+)[ \t]*$/uD', $text) === 1) {
            $text = '\\' . $text;
        }

        return $text;
    }

    private static function paragraphText(string $text): string
    {
        $lines = explode("\n", $text);
        $last = count($lines) - 1;
        $out = [];
        foreach ($lines as $at => $line) {
            $line = self::protectLine($line, $at === 0);
            if ($line !== '' || $at === 0 || $at === $last) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    /**
     * The blocks of a container. Two lists in a row keep apart only where their markers differ, so the second of two
     * lists with the same bullet or delimiter takes the other one.
     *
     * @param array<\MarkupCarve\Carve\Node> $list
     *
     * @return string
     */
    private function blocks(array $list): string
    {
        return $this->x->nested(function () use ($list): string {
            $out = '';
            $previous = null;
            foreach ($list as $node) {
                if ($node->type !== 'list') {
                    $written = $this->block($node);
                    if ($written !== '') {
                        $previous = null;
                    }
                    $out .= $written;

                    continue;
                }
                $mark = self::listMark($node);
                $used = $previous === $mark ? self::alternateMark($mark) : $mark;
                $out .= $this->block($node, $used);
                $previous = $used;
            }

            return $out;
        });
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array<string, mixed>
     */
    private static function marker(Node $node): array
    {
        $marker = $node->f['marker'] ?? [];

        return is_array($marker) ? $marker : [];
    }

    private static function listMark(Node $node): string
    {
        $marker = self::marker($node);
        if (($marker['kind'] ?? null) === 'ordered') {
            return ($marker['delimiter'] ?? null) === ')' ? ')' : '.';
        }
        $char = $marker['char'] ?? null;

        return $char === '*' || $char === '+' ? $char : '-';
    }

    private static function alternateMark(string $mark): string
    {
        return match ($mark) {
            '.' => ')',
            ')' => '.',
            '-' => '*',
            default => '-',
        };
    }

    private function block(Node $node, ?string $mark = null): string
    {
        if (isset(self::ATTRIBUTE_BLOCKS[$node->type]) && self::hasAttributes($node->f['attrs'] ?? null)) {
            $this->x->loss($node, 'markdown-attributes-dropped', 'Dropped block attributes in Markdown');
        }

        if ($node->type === 'list' && $mark !== null) {
            return $this->list($node, $mark);
        }
        $method = self::BLOCK_METHODS[$node->type] ?? null;

        return $method === null ? '' : $this->{$method}($node);
    }

    private function blockSection(Node $node): string
    {
        return $this->blocks($node->children ?? []);
    }

    private function blockParagraph(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $this->captioned($node, self::paragraphText($this->inlines($node->children ?? [])) . "\n\n", $caption, "\n");
    }

    private function blockCodeBlock(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $this->captioned($node, $this->codeBlock($node), $caption, "\n");
    }

    private function blockBlockQuote(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $this->captioned($node, self::quote($this->blocks($node->children ?? [])), $caption, "\n\n");
    }

    private function blockList(Node $node): string
    {
        return $this->list($node, self::listMark($node));
    }

    private function blockThematicBreak(Node $node): string
    {
        return "---\n\n";
    }

    private function blockTable(Node $node): string
    {
        $caption = ($node->f['caption'] ?? null) instanceof Node ? $node->f['caption'] : null;

        return $this->table($node, $caption);
    }

    private function blockAdmonition(Node $node): string
    {
        return $this->titleLines($node) . $this->blocks($node->children ?? []);
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
            ? $this->captioned($node, self::paragraphText(self::escapeText($this->x->unexpandedInclude($node))) . "\n\n", $caption, "\n")
            : $this->captioned($node, $this->blocks($node->children), $caption, "\n\n");
    }

    private function blockBlockExtension(Node $node): string
    {
        return $this->blocks(Exporter::extensionContent($node, true));
    }

    private function blockAbbreviationDef(Node $node): string
    {
        $f = $node->f;

        return '*[' . self::escapeHtml(Exporter::str($f['key'] ?? '')) . ']: '
            . self::escapeHtml(Exporter::str($f['expansion'] ?? '')) . "\n\n";
    }

    private function div(Node $node): string
    {
        $this->x->loss($node, 'markdown-container-flattened', 'Flattened a container in Markdown');

        return $this->titleLines($node) . $this->blocks($node->children ?? []);
    }

    private function lineBlock(Node $node): string
    {
        $lines = [];
        foreach ($node->children ?? [] as $line) {
            if ($line->type !== 'line') {
                continue;
            }
            $children = $line->children ?? [];
            if ($children !== [] && $children[count($children) - 1]->type === 'hard_break') {
                array_pop($children);
            }
            $lines[] = self::paragraphText($this->inlines($children));
        }

        return $lines === [] ? '' : implode("\\\n", $lines) . "\n\n";
    }

    private function definitionList(Node $node): string
    {
        $out = '';
        foreach ($node->children ?? [] as $child) {
            if ($child->type === 'definition_term') {
                $out .= self::strongLine($this->inlines($child->children ?? []));
            } elseif ($child->type === 'definition_description') {
                $out .= $this->blocks($child->children ?? []);
            }
        }

        return $out;
    }

    private function figureGroup(Node $node, ?Node $caption): string
    {
        $out = '';
        foreach ($node->children ?? [] as $child) {
            $out .= $this->panel($child);
        }
        if ($caption !== null) {
            $this->x->loss($node, 'markdown-caption-flattened', 'Flattened a caption in Markdown');
            $out .= self::strongLine($this->captionText($caption));
        }

        return $out;
    }

    private function rawBlock(Node $node): string
    {
        if (($node->f['format'] ?? null) !== 'html') {
            $this->x->rawDropped($node, true);

            return '';
        }
        $this->x->loss($node, 'raw-html-escaped', 'Escaped raw HTML in Markdown');

        return self::escapeHtml(Exporter::str($node->f['value'] ?? '')) . "\n\n";
    }

    private function linkDefinition(Node $node): string
    {
        $key = Exporter::str($node->f['key'] ?? '');
        if (isset($this->referenced[$key])) {
            return '';
        }
        $allowed = $this->x->allowedDestination(Exporter::stripBidi(self::clean(Exporter::str($node->f['href'] ?? ''))), $node);
        $href = $allowed === '' ? '<>' : self::destinationText($allowed);
        $tooltip = $node->f['tooltip'] ?? null;
        $title = is_string($tooltip) ? ' "' . self::escapeTitle($tooltip) . '"' : '';

        return '[' . preg_replace('/[\[\]\\\\]/', '\\\\$0', $key) . ']: ' . $href . $title . "\n\n";
    }

    /**
     * `$text` on a line of its own in bold, or nothing where it is empty.
     *
     * @param string $text
     *
     * @return string
     */
    private static function strongLine(string $text): string
    {
        $run = self::trimSpace(self::delimited(str_replace("\n", ' ', $text), '**', 'strong'));

        return $run === '' ? '' : $run . "\n\n";
    }

    /**
     * JavaScript `trim()`: white space and line terminators at both ends.
     *
     * @param string $text
     *
     * @return string
     */
    private static function trimSpace(string $text): string
    {
        return (string)preg_replace('/^[\p{White_Space}\x{FEFF}]+|[\p{White_Space}\x{FEFF}]+$/uD', '', $text);
    }

    private function titleLines(Node $node): string
    {
        $title = $node->f['title'] ?? null;
        $out = is_array($title)
            ? self::strongLine($this->oneLine(fn (): string => $this->inlines(self::unwrapStrong(PlainText::nodes($title)))))
            : '';
        $label = $node->f['groupLabel'] ?? null;
        if (is_string($label) && $label !== '') {
            $out .= self::strongLine(self::escapeText($label));
        }

        return $out;
    }

    private function captionText(Node $caption): string
    {
        return self::trimSpace($this->oneLine(function () use ($caption): string {
            $parts = [];
            foreach ($caption->children ?? [] as $child) {
                $parts[] = $child->type === 'paragraph' ? $this->inlines($child->children ?? []) : self::trimSpace($this->blocks([$child]));
            }

            return implode(' ', $parts);
        }));
    }

    /**
     * A host with its caption after it, as a paragraph of its own; Markdown has no caption, so this is reported.
     *
     * @param \MarkupCarve\Carve\Node $host
     * @param string $written
     * @param \MarkupCarve\Carve\Node|null $caption
     * @param string $separator
     *
     * @return string
     */
    private function captioned(Node $host, string $written, ?Node $caption, string $separator): string
    {
        if ($caption === null) {
            return $written;
        }
        $this->x->loss($host, 'markdown-caption-flattened', 'Flattened a caption in Markdown');

        return self::trimEndSpace($written) . $separator . $this->captionText($caption) . "\n\n";
    }

    private static function trimEndSpace(string $text): string
    {
        return (string)preg_replace('/[\p{White_Space}\x{FEFF}]+$/uD', '', $text);
    }

    /**
     * A panel of a figure group: the host, then its caption emphasized, subordinate to the group's bold one.
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
        $this->x->loss($node, 'markdown-caption-flattened', 'Flattened a caption in Markdown');
        $text = self::trimSpace(self::delimited($this->captionText($caption), '*', 'em'));
        unset($node->f['caption']);
        try {
            $host = $this->block($node);
        } finally {
            $node->f['caption'] = $caption;
        }

        return $host . ($text === '' ? '' : $text . "\n\n");
    }

    private function heading(Node $node): string
    {
        $inline = $this->oneLine(fn (): string => $this->inlines($node->children ?? []));
        $text = self::trimSpace((string)preg_replace('/[ \t\n]+/', ' ', $inline));
        // A closing run of `#` would be read as the optional closing sequence.
        $text = (string)preg_replace('/(^|[ \t])(#+)$/D', '$1\\\\$2', $text);
        $level = $node->f['level'] ?? 1;

        return str_repeat('#', min(is_int($level) ? $level : 1, 6)) . ($text === '' ? '' : ' ' . $text) . "\n\n";
    }

    /**
     * The info string of a fence: the language, the title in quotes where there is a language, and the group label.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    private function fenceInfo(Node $node): string
    {
        $token = preg_split('/[ \t\n]/', self::clean(Exporter::str($node->f['syntax'] ?? '')))[0] ?? '';
        // An attribute line's title wins over the one in the fence header, as in HTML.
        $title = Exporter::attrValue($node->f['attrs'] ?? null, 'title');
        if ($title === null && is_array($node->f['title'] ?? null)) {
            $title = $this->x->plainInlineText(PlainText::nodes($node->f['title']));
        }
        $info = $token;
        if ($title !== null && $token !== '') {
            $info .= ' "' . self::escapeTitle($title) . '"';
        } elseif ($title !== null) {
            $this->x->loss($node, 'fence-title-dropped', 'Dropped a code fence title without a language in Markdown');
        }
        $label = Exporter::str($node->f['groupLabel'] ?? '');

        return $label === '' ? $info : $info . ' [' . str_replace(['[', ']'], '', self::clean($label)) . ']';
    }

    private function codeBlock(Node $node): string
    {
        $content = self::clean(Exporter::str($node->f['value'] ?? ''));
        $info = $this->fenceInfo($node);
        $delimiter = CodeFence::safe($content, str_contains($info, '`') ? '~' : '`');
        $body = $content === '' || str_ends_with($content, "\n") ? $content : $content . "\n";

        return $delimiter . $info . "\n" . $body . $delimiter . "\n\n";
    }

    private static function quote(string $inner): string
    {
        $lines = explode("\n", rtrim($inner, "\n"));

        return implode("\n", array_map(static fn (string $line): string => $line === '' ? '>' : '> ' . $line, $lines)) . "\n\n";
    }

    /**
     * Puts `$marker` before the first line and indents the others by `$pad`; an empty line takes no indentation.
     *
     * @param string $marker
     * @param string $content
     * @param int $pad
     *
     * @return string
     */
    private static function withMarker(string $marker, string $content, int $pad): string
    {
        $lines = explode("\n", rtrim($content, "\n"));
        $indent = str_repeat(' ', $pad);
        $out = [];
        foreach ($lines as $at => $line) {
            $out[] = $at === 0 ? self::trimEndSpace($marker . $line) : ($line === '' ? '' : $indent . $line);
        }

        return implode("\n", $out) . "\n";
    }

    private function list(Node $node, string $mark): string
    {
        $marker = self::marker($node);
        $ordered = ($marker['kind'] ?? null) === 'ordered';
        $style = $marker['style'] ?? null;
        if ($ordered && is_string($style) && $style !== '1') {
            $this->x->loss($node, 'list-style-dropped', 'Dropped the ordered list style "' . $style . '" in Markdown');
        }
        $tight = ($node->f['spacing'] ?? null) === 'tight';
        $counter = is_int($marker['start'] ?? null) ? $marker['start'] : 1;
        $out = '';
        foreach ($node->children ?? [] as $at => $item) {
            if (!$tight && $at > 0) {
                $out .= "\n";
            }
            $prefix = $ordered ? ($counter++) . $mark . ' ' : $mark . ' ';
            $pad = strlen($prefix);
            if (!$ordered) {
                $prefix .= self::taskBox($item);
            }
            $out .= self::withMarker($prefix, $this->itemContent($item->children ?? [], $tight), $pad);
        }

        return $out . "\n";
    }

    private static function taskBox(Node $item): string
    {
        $task = $item->f['task'] ?? null;
        if (!is_array($task)) {
            return '';
        }

        return ($task['mark'] ?? null) === 'x' || ($task['mark'] ?? null) === 'X' ? '[x] ' : '[ ] ';
    }

    private static function lastLine(string $text): string
    {
        $last = '';
        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                $last = $line;
            }
        }

        return $last;
    }

    /**
     * Whether a tight item may write `$written` right under the block above, with no blank line that makes it loose.
     *
     * @param string $written
     * @param string|null $above
     * @param string $aboveWritten
     *
     * @return bool
     */
    private static function separatorCanGo(string $written, ?string $above, string $aboveWritten): bool
    {
        $lines = explode("\n", $written, 3);
        $first = $lines[0];
        $second = $lines[1] ?? '';
        if (str_starts_with($first, '|')) {
            return preg_match('/^\|(?:[ \t]*:?-+:?[ \t]*\|)+$/D', $second) === 1
                && $above !== null && isset(self::TABLE_MAY_FOLLOW[$above]);
        }
        if ($above === 'code_block' || $above === 'thematic_break' || $above === 'heading') {
            return true;
        }
        if (str_starts_with($first, '>') && str_starts_with(self::lastLine($aboveWritten), '>')) {
            return false;
        }
        foreach (self::INTERRUPTERS as $opener) {
            if (preg_match($opener, $first) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The blocks of an item; a tight item drops the blank line between two blocks wherever the second interrupts.
     *
     * @param array<\MarkupCarve\Carve\Node> $children
     * @param bool $tight
     *
     * @return string
     */
    private function itemContent(array $children, bool $tight): string
    {
        $out = '';
        $above = null;
        $aboveWritten = '';
        foreach ($children as $child) {
            $written = $this->block($child);
            if ($written === '') {
                continue;
            }
            if ($tight && $child->type === 'thematic_break' && ($above === 'paragraph' || $above === 'block_quote')) {
                $written = "***\n\n";
            }
            if ($tight && str_ends_with($out, "\n\n") && self::separatorCanGo($written, $above, $aboveWritten)) {
                $out = substr($out, 0, -1);
            }
            $out .= $written;
            $above = $child->type;
            $aboveWritten = $written;
        }

        return $out;
    }

    private function cellText(Node $cell): string
    {
        $text = $this->oneLine(function () use ($cell): string {
            $parts = [];
            foreach ($cell->children ?? [] as $child) {
                $parts[] = $child->type === 'cell_paragraph' ? $this->inlines($child->children ?? [])
                    : (string)preg_replace('/\n+/', ' ', self::trimSpace($this->blocks([$child])));
            }

            return implode(' ', $parts);
        });

        return self::escapePipes(self::trimSpace(str_replace(["\r", "\n"], ' ', $text)));
    }

    /**
     * GFM splits a row on every `|` that no backslash escapes, code spans included.
     *
     * @param string $cell
     *
     * @return string
     */
    private static function escapePipes(string $cell): string
    {
        return (string)preg_replace_callback('/\\\\.|\|/s', static fn (array $m): string => $m[0] === '|' ? '\|' : $m[0], $cell);
    }

    /**
     * @param \MarkupCarve\Carve\Node $row
     * @param bool $isHeader
     *
     * @return array<string>
     */
    private function tableCells(Node $row, bool $isHeader): array
    {
        $cells = [];
        foreach ($row->children ?? [] as $cell) {
            if (($cell->f['colspan'] ?? 1) > 1 || ($cell->f['rowspan'] ?? 1) > 1) {
                $this->x->loss($cell, 'table-span-flattened', 'Flattened a spanned table cell while rendering markdown');
            }
            if (!$isHeader && ($cell->f['header'] ?? false) === true) {
                $this->x->loss($cell, 'table-header-cell-demoted', 'Wrote a header cell as an ordinary cell in Markdown');
            }
            $cells[] = isset($cell->f['span']) ? '' : $this->cellText($cell);
        }

        return $cells;
    }

    private function table(Node $node, ?Node $caption): string
    {
        [$header, $rows] = $this->tableRows($node);
        $columns = PlainText::nodes($node->f['columns'] ?? []);
        $width = max(count($columns), count($header ?? []), ...array_map('count', $rows ?: [[]]));
        $line = static fn (array $cells): string => '| ' . implode(' | ', array_pad($cells, $width, '')) . ' |';
        $align = [];
        for ($at = 0; $at < $width; $at++) {
            $column = $columns[$at] ?? null;
            $value = $column?->f['align'] ?? $column?->f['resolvedAlign'] ?? '';
            $align[] = self::ALIGN_ROW[is_string($value) ? $value : ''] ?? '---';
        }
        $out = $line($header ?? []) . "\n| " . implode(' | ', $align) . " |\n";
        foreach ($rows as $row) {
            $out .= $line($row) . "\n";
        }

        return $this->captioned($node, $out . "\n", $caption, "\n\n");
    }

    /**
     * The header row (the first row when it is all header cells) and the other rows.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array{0: array<string>|null, 1: array<array<string>>}
     */
    private function tableRows(Node $node): array
    {
        $header = null;
        $rows = [];
        foreach ($node->children ?? [] as $section) {
            foreach ($section->type === 'comment' ? [] : $section->children ?? [] as $row) {
                if ($row->type === 'comment') {
                    continue;
                }
                $isHeader = self::isHeaderRow($row);
                $cells = $this->tableCells($row, $isHeader);
                if ($isHeader && $header === null && $rows === []) {
                    $header = $cells;

                    continue;
                }
                if ($isHeader) {
                    $this->x->loss($row, 'table-header-row-demoted', 'Wrote a header row as an ordinary row in Markdown');
                }
                $rows[] = $cells;
            }
        }

        return [$header, $rows];
    }

    private static function isHeaderRow(Node $row): bool
    {
        if ($row->children === null || $row->children === []) {
            return false;
        }
        foreach ($row->children as $cell) {
            if (($cell->f['header'] ?? false) !== true && !isset($cell->f['span'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bold in a bold title line would close the run early.
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function unwrapStrong(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node->type === 'strong' && !self::hasAttributes($node->f['attrs'] ?? null)) {
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
            $body = $this->x->outsideLinks(fn (): string => $this->blocks($child->children ?? []));
            $out .= self::withMarker('[^' . self::escapeHtml(Exporter::str($child->f['key'] ?? '')) . ']: ', $body, 4);
        }

        return $out;
    }

    private function frontmatter(): string
    {
        foreach ($this->x->document->children ?? [] as $child) {
            if ($child->type !== 'frontmatter') {
                continue;
            }
            $value = Exporter::str($child->f['value'] ?? '');
            $value = str_ends_with($value, "\n") ? substr($value, 0, -1) : $value;
            $format = Exporter::str($child->f['format'] ?? '');

            return '---' . ($format === 'yaml' ? '' : $format) . "\n" . $value . "\n---\n\n";
        }

        return '';
    }

    /**
     * GFM's slug of the text of a heading: lower case, punctuation dropped, spaces as hyphens.
     *
     * @param \MarkupCarve\Carve\Node $heading
     *
     * @return string
     */
    private function gfmSlug(Node $heading): string
    {
        $slug = mb_strtolower(Slug::of($this->x->plainInlineText($heading->children ?? []), 'keep-case'), 'UTF-8');

        return str_replace(' ', '-', (string)preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} -]/u', '', $slug));
    }

    /**
     * The GFM slug of each heading with an id, in document order, with GFM's numbering of repeats.
     *
     * @return void
     */
    private function headingSlugs(): void
    {
        $counts = [];
        $visit = function (array $children) use (&$visit, &$counts): void {
            foreach ($children as $child) {
                if ($child->type === 'heading') {
                    $attrs = $child->f['attrs'] ?? null;
                    $id = $attrs instanceof Attrs && $attrs->id !== null ? $attrs->id : ($child->f['generatedId'] ?? null);
                    $base = $this->gfmSlug($child);
                    $seen = $counts[$base] ?? 0;
                    $counts[$base] = $seen + 1;
                    if (is_string($id) && !isset($this->slugs[$id])) {
                        $this->slugs[$id] = $seen === 0 ? $base : $base . '-' . $seen;
                    }
                } elseif (isset(self::SLUG_CONTAINERS[$child->type]) && $child->children !== null) {
                    $visit($child->children);
                }
            }
        };
        $visit($this->x->document->children ?? []);
    }

    private function collectKeys(): void
    {
        Exporter::eachNode($this->x->document, function (Node $node): void {
            $key = $node->f['key'] ?? null;
            if (!is_string($key)) {
                return;
            }
            if ($node->type === 'footnote') {
                $this->footnotes[$key] = true;
            } elseif (($node->type === 'link' || $node->type === 'image') && !Exporter::isUnresolved($node)) {
                $this->referenced[$key] = true;
            }
        });
    }
}
