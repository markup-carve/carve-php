<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Walk;

/**
 * Render profiles (C2-H-129, C2-H-163 to C2-H-167): which nodes a profile accepts and the degraded
 * form of the others.
 */
final class Profile
{
    /**
     * Blocks whose plain text is that of their inline children.
     *
     * @var array<string, true>
     */
    private const INLINE_BLOCKS = ['paragraph' => true, 'definition_term' => true, 'cell_paragraph' => true, 'line' => true];

    /**
     * Blocks whose plain text is their title, then their blocks.
     *
     * @var array<string, true>
     */
    private const TITLED_BLOCKS = ['div' => true, 'admonition' => true, 'directive' => true];

    /**
     * C2-H-165: degraded inline nodes with a fixed text.
     *
     * @var array<string, string>
     */
    private const FIXED_TEXT = [
        'non_breaking_space' => "\u{00A0}",
        'soft_break' => ' ',
        'hard_break' => ' ',
        'comment' => '',
        'critic_comment' => '',
        'caption_number' => '',
    ];

    /**
     * C2-H-165: degraded inline nodes written as one field between a prefix and a suffix.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const FIELD_TEXT = [
        'text' => ['value', '', ''],
        'footnote_ref' => ['key', '[^', ']'],
        'symbol' => ['name', ':', ':'],
        'mention' => ['target', '@', ''],
        'tag' => ['name', '#', ''],
        'abbreviation' => ['key', '', ''],
        'citation_group' => ['raw', '', ''],
    ];

    /**
     * @var array<string, true>
     */
    private const HOLDS_BLOCKS = [
        'list_item' => true,
        'definition_description' => true,
        'footnote' => true,
        'figure_group' => true,
        'section' => true,
        'caption' => true,
        'table_cell' => true,
        'block_extension' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const NESTING_CONTAINERS = ['list' => true, 'block_quote' => true, 'div' => true, 'admonition' => true];

    /**
     * Nodes that write nothing at their place but are still reported outside the profile (C2-H-129, C2-H-165).
     * C2-H-178 exempts `abbreviation_def`.
     *
     * @var array<string, true>
     */
    private const REPORTED_SILENT = [
        'comment' => true,
        'link_reference_definition' => true,
        'footnote' => true,
        'citation_definition' => true,
    ];

    /**
     * @var array<string, true>|null
     */
    private ?array $allowedBlock = null;

    /**
     * @var array<string, true>|null
     */
    private ?array $allowedInline = null;

    /**
     * @var array<string, true>
     */
    private array $deniedBlock = [];

    /**
     * @var array<string, true>
     */
    private array $deniedInline = [];

    private ?int $maxNesting = null;

    /**
     * @var array<string>
     */
    private array $rel = [];

    /**
     * Nesting level per node (C2-H-166).
     *
     * @var array<int, int>
     */
    private array $level = [];

    public ?RenderState $renderState = null;

    public function __construct(private string $name, private RenderLog $log, private ?Options $o = null)
    {
        $spec = Tables::PROFILES[$name] ?? [];
        if (isset($spec['allowed_block'])) {
            $this->allowedBlock = array_fill_keys($spec['allowed_block'], true);
        }
        if (isset($spec['allowed_inline'])) {
            $this->allowedInline = array_fill_keys($spec['allowed_inline'], true);
        }
        $this->deniedBlock = array_fill_keys($spec['denied_block'] ?? [], true);
        $this->deniedInline = array_fill_keys($spec['denied_inline'] ?? [], true);
        $this->maxNesting = isset($spec['max_nesting']) ? (int)$spec['max_nesting'] : null;
        $this->rel = $spec['link_rel'] ?? [];
    }

    /**
     * @return int|null C2-H-163: the input length bound in UTF-8 bytes.
     */
    public static function maxLength(string $name): ?int
    {
        $spec = Tables::PROFILES[$name] ?? [];

        return isset($spec['max_length']) ? (int)$spec['max_length'] : null;
    }

    /**
     * @param \MarkupCarve\Carve\Node $doc
     *
     * @return void
     */
    public function measure(Node $doc): void
    {
        if ($this->maxNesting === null) {
            return;
        }
        $stack = [];
        foreach (Walk::kids($doc) as $kid) {
            $stack[] = [$kid, 0];
        }
        while ($stack !== []) {
            [$node, $parentLevel] = array_pop($stack);
            $level = $parentLevel + (isset(self::NESTING_CONTAINERS[$node->type]) ? 1 : 0);
            $this->level[spl_object_id($node)] = $level;
            foreach (Walk::kids($node) as $kid) {
                $stack[] = [$kid, $level];
            }
        }
    }

    /**
     * @return array<string>
     */
    public function linkRel(): array
    {
        return $this->rel;
    }

    /**
     * C2-H-166: profile nesting limit.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    private function tooDeep(Node $node): bool
    {
        // A list item shares its list's container level and is never degraded on its own.
        return $this->maxNesting !== null && $node->type !== 'list_item' && ($this->level[spl_object_id($node)] ?? 0) > $this->maxNesting;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return bool
     */
    public function allowsBlock(Node $node, int $depth): bool
    {
        $type = in_array($node->type, ['paragraph', 'code_block', 'block_quote'], true)
            && ($node->f['caption'] ?? null) instanceof Node ? 'figure' : $node->type;
        if (isset($this->deniedBlock[$type]) || $this->tooDeep($node)) {
            return false;
        }

        return $this->allowedBlock === null || isset($this->allowedBlock[$type])
            || (!HtmlRenderer::visible($node) && !isset(self::REPORTED_SILENT[$type]));
    }

    /**
     * Whether an inline type is inside the profile, nesting aside.
     *
     * @param string $type
     *
     * @return bool
     */
    public function allowsType(string $type): bool
    {
        return ($this->allowedInline === null || isset($this->allowedInline[$type])) && !isset($this->deniedInline[$type]);
    }

    /**
     * Whether a block type is inside the profile, nesting aside.
     *
     * @param string $type
     *
     * @return bool
     */
    public function allowsBlockType(string $type): bool
    {
        return ($this->allowedBlock === null || isset($this->allowedBlock[$type])) && !isset($this->deniedBlock[$type]);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    public function allowsInline(Node $node): bool
    {
        if ($node->type === 'autolink' && $this->allowedInline !== null) {
            // C2-H-167: an autolink is allowed where `link` is.
            $allowed = isset($this->allowedInline['link']) || isset($this->allowedInline['autolink']);
        } else {
            $allowed = $this->allowedInline === null || isset($this->allowedInline[$node->type]);
        }

        return $allowed && !isset($this->deniedInline[$node->type]) && !$this->tooDeep($node);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     * @param \MarkupCarve\Carve\Html\InlineRenderer $inline
     *
     * @return string
     */
    public function inline(Node $node, int $depth, InlineRenderer $inline): string
    {
        if ($this->allowsInline($node)) {
            return $inline->node($node, $depth);
        }
        $this->report($node);

        return Escape::text($this->inlineText($node));
    }

    public function plainInline(Node $node): string
    {
        return Escape::text($this->inlineText($node));
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param bool $block
     *
     * @return void
     */
    private function report(Node $node, bool $block = false): void
    {
        $type = $node->type;
        if (
            $block && in_array($type, ['paragraph', 'code_block', 'block_quote'], true)
            && ($node->f['caption'] ?? null) instanceof Node
        ) {
            $type = 'figure';
        }
        $typeAllowed = $block ? $this->allowsBlockType($type) : $this->allowsType($type);
        $this->log->loss(
            'profile-construct-degraded',
            'warning',
            ['profile' => $this->name, 'construct' => $node->type],
            variant: $typeAllowed && $this->tooDeep($node) ? 1 : 0,
            node: $node,
        );
    }

    /**
     * C2-H-165: a node that writes nothing at its place is still reported when it is outside the profile,
     * and so is each comment of a paragraph that holds only comments.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return void
     */
    public function reportSilent(Node $node): void
    {
        if (isset(self::REPORTED_SILENT[$node->type])) {
            if (!$this->allowsBlock($node, 0)) {
                $this->report($node, true);
            }

            return;
        }
        if ($node->type !== 'paragraph' || isset($node->f['caption'])) {
            return;
        }
        foreach ($node->children ?? [] as $kid) {
            if ($kid->type === 'comment' && !$this->allowsInline($kid)) {
                $this->report($kid);
            }
        }
    }

    /**
     * C2-H-165: the plain text of a degraded inline node.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    private function inlineText(Node $node): string
    {
        $type = $node->type;
        if (isset(self::FIXED_TEXT[$type])) {
            return self::FIXED_TEXT[$type];
        }
        $field = self::FIELD_TEXT[$type] ?? null;
        if ($field !== null) {
            return $field[1] . PlainText::str($node->f[$field[0]] ?? '') . $field[2];
        }

        return $this->inlineTextByType($node);
    }

    /**
     * C2-H-165: inline fallback for nodes without a fixed or single-field spelling.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    private function inlineTextByType(Node $node): string
    {
        $f = $node->f;

        return match ($node->type) {
            'image' => '[img: ' . PlainText::str($f['alt'] ?? '') . ']',
            'smart_punctuation' => PlainText::str($f['glyph'] ?? $f['source'] ?? ''),
            'autolink' => PlainText::str($f['text'] ?? $f['href'] ?? ''),
            'code', 'literal_inline', 'math', 'raw_inline' => $this->leafValue($f),
            'xref' => $this->xrefText($node),
            'inline_extension' => $this->childrenText(PlainText::nodes($f['fallback'] ?? [])),
            default => $this->childrenText($node->children ?? []),
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function leafValue(array $fields): string
    {
        return PlainText::str($fields['value'] ?? '');
    }

    private function xrefText(Node $node): string
    {
        $key = PlainText::str($node->f['key'] ?? '');

        return isset($node->f['href']) ? ($this->renderState?->xrefText($key) ?? $key) : '</#' . $key . '>';
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return string
     */
    private function childrenText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            $out .= $this->inlineText($n);
        }

        return $out;
    }

    /**
     * C2-H-165: the degraded form of a block.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string|null
     */
    public function degradeBlock(Node $node, int $depth): ?string
    {
        if (!HtmlRenderer::visible($node)) {
            return null;
        }
        $this->report($node, true);
        $paras = $this->degradedTexts($node);
        $out = [];
        foreach ($paras as $text) {
            if ($text !== '') {
                $out[] = HtmlRenderer::pad($depth) . '<p>' . $text . '</p>';
            }
        }

        return $out === [] ? null : implode("\n", $out);
    }

    /**
     * C2-H-034, C2-H-165: the first degraded paragraph in a tight item is bare.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array<string>
     */
    public function degradedParagraphs(Node $node): array
    {
        $this->report($node, true);

        return array_values(array_filter($this->degradedTexts($node), static fn (string $text): bool => $text !== ''));
    }

    /**
     * Escaped paragraph contents of a degraded block.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array<string>
     */
    private function degradedTexts(Node $node): array
    {
        $out = match (true) {
            $node->type === 'table' => [$this->tableText($node)],
            $node->type === 'definition_list' => $this->definitionTexts($node),
            // C2-H-165: a block extension gives the blocks of its fallback.
            $node->type === 'block_extension' => $this->blockList(PlainText::nodes($node->f['fallback'] ?? [])),
            isset(self::HOLDS_BLOCKS[$node->type]) => $this->blockList($node->children ?? []),
            default => [Escape::text($this->plainOf($node))],
        };
        $caption = $node->type === 'definition_list' ? null : $node->f['caption'] ?? null;

        return $caption instanceof Node ? array_merge($out, $this->captionList($caption)) : $out;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array<string>
     */
    private function definitionTexts(Node $node): array
    {
        $out = [];
        foreach ($node->children ?? [] as $child) {
            if ($child->type === 'definition_term') {
                $out[] = Escape::text($this->blockInlineText($child->children ?? []));
            } elseif ($child->type === 'definition_description') {
                $out = array_merge($out, $this->blockList($child->children ?? []));
            }
        }

        return $out;
    }

    /**
     * C2-H-165: a caption in a degraded block uses C2-H-143, including its number.
     *
     * @param \MarkupCarve\Carve\Node $caption
     *
     * @return array<string>
     */
    private function captionList(Node $caption): array
    {
        $out = [];
        foreach ($caption->children ?? [] as $block) {
            $out[] = Escape::text(PlainText::of($block->children ?? []));
        }

        return $out;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $blocks
     *
     * @return array<string>
     */
    private function blockList(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (HtmlRenderer::visible($block)) {
                $out = array_merge($out, $this->degradedTexts($block));
            }
        }

        return $out;
    }

    /**
     * C2-H-165: the plain text of a block behind the mark of its kind.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    private function plainOf(Node $node): string
    {
        $type = $node->type;
        $kids = $node->children ?? [];
        if (isset(self::INLINE_BLOCKS[$type])) {
            return $this->blockInlineText($kids);
        }
        if (isset(self::TITLED_BLOCKS[$type])) {
            $title = $node->f['title'] ?? null;

            return trim((is_array($title) ? PlainText::of(PlainText::nodes($title)) : '') . ' ' . $this->joined($kids));
        }

        return match ($type) {
            'heading' => str_repeat('#', self::level($node)) . ' ' . $this->blockInlineText($kids),
            'block_quote' => $this->quoteText($kids),
            'list' => $this->listText($node),
            'table' => implode(' ', $this->tableRows($node)),
            'thematic_break' => '---',
            'code_block', 'raw_block' => rtrim(PlainText::str($node->f['value'] ?? ''), "\n"),
            'include' => HtmlRenderer::includeText($node),
            default => $this->joined($kids),
        };
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $blocks
     */
    private function quoteText(array $blocks): string
    {
        $text = $this->joined($blocks);

        return $text === '' ? '' : '> ' . $text;
    }

    /**
     * C2-H-165: whole-block text uses C2-H-143 for every inline node.
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return string
     */
    private function blockInlineText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if ($node->type === 'image') {
                $out .= '[img: ' . PlainText::str($node->f['alt'] ?? '') . ']';
            } elseif (in_array($node->type, ['raw_inline', 'xref', 'symbol'], true)) {
                $out .= $this->inlineText($node);
            } elseif ($node->children !== null && PlainText::usesChildren($node->type)) {
                $out .= $this->blockInlineText($node->children);
            } else {
                $out .= PlainText::node($node);
            }
        }

        return $out;
    }

    /**
     * @param \MarkupCarve\Carve\Node $heading
     *
     * @return int
     */
    private static function level(Node $heading): int
    {
        $level = $heading->f['level'] ?? null;

        return is_int($level) ? $level : 1;
    }

    /**
     * @param \MarkupCarve\Carve\Node $list
     *
     * @return string
     */
    private function listText(Node $list): string
    {
        $items = [];
        foreach ($list->children ?? [] as $item) {
            $text = $this->joined($item->children ?? []);
            if ($text !== '') {
                $items[] = '- ' . $text;
            }
        }

        return implode(' ', $items);
    }

    /**
     * The degraded texts of blocks joined by single spaces.
     *
     * @param array<\MarkupCarve\Carve\Node> $blocks
     *
     * @return string
     */
    private function joined(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (!HtmlRenderer::visible($block)) {
                continue;
            }
            $text = $this->plainOf($block);
            if ($text !== '') {
                $parts[] = $text;
            }
            // C2-H-165: a caption follows the text of its block.
            $caption = $block->type === 'definition_list' ? null : $block->f['caption'] ?? null;
            foreach ($caption instanceof Node ? $caption->children ?? [] : [] as $para) {
                $text = PlainText::of($para->children ?? []);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param \MarkupCarve\Carve\Node $table
     *
     * @return string
     */
    private function tableText(Node $table): string
    {
        return implode($this->o?->xhtml ? "<br />\n" : "<br>\n", array_map(Escape::text(...), $this->tableRows($table)));
    }

    /**
     * The plain text of each row, ` | ` between the cells.
     *
     * @param \MarkupCarve\Carve\Node $table
     *
     * @return array<string>
     */
    private function tableRows(Node $table): array
    {
        $rows = [];
        foreach ($table->children ?? [] as $group) {
            foreach ($group->children ?? [] as $row) {
                if ($row->type !== 'table_row') {
                    continue;
                }
                $cells = [];
                foreach ($row->children ?? [] as $cell) {
                    if (!isset($cell->f['span'])) {
                        $cells[] = $this->joined($cell->children ?? []);
                    }
                }
                $rows[] = implode(' | ', $cells);
            }
        }

        return $rows;
    }
}
