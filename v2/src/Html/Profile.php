<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

use MarkupCarve\Carve2\Generated\Tables;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Resolve\PlainText;
use MarkupCarve\Carve2\Resolve\Walk;

/**
 * Render profiles (C2-H-129, C2-H-163 to C2-H-167): which nodes a profile accepts and the degraded
 * form of the others.
 */
final class Profile
{
    /**
     * @var array<string, true>
     */
    private const HOLDS_BLOCKS = [
        'list_item' => true,
        'definition_description' => true,
        'footnote' => true,
        'figure' => true,
        'figure_group' => true,
        'section' => true,
        'caption' => true,
        'table_cell' => true,
        'block_extension' => true,
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

    public function __construct(string $name, private RenderLog $log)
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
     * @param \MarkupCarve\Carve2\Node $doc
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
            [$node, $level] = array_pop($stack);
            $this->level[spl_object_id($node)] = $level;
            foreach (Walk::kids($node) as $kid) {
                $stack[] = [$kid, $level + 1];
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
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return bool
     */
    private function tooDeep(Node $node): bool
    {
        // G-render-3: a list item is part of its list and is never degraded on its own, as the oracle does.
        return $this->maxNesting !== null && $node->type !== 'list_item' && ($this->level[spl_object_id($node)] ?? 0) > $this->maxNesting;
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     * @param int $depth
     *
     * @return bool
     */
    public function allowsBlock(Node $node, int $depth): bool
    {
        if (isset($this->deniedBlock[$node->type]) || $this->tooDeep($node)) {
            return false;
        }

        return $this->allowedBlock === null || isset($this->allowedBlock[$node->type]) || !HtmlRenderer::visible($node);
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
     * @param \MarkupCarve\Carve2\Node $node
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
     * @param \MarkupCarve\Carve2\Node $node
     * @param int $depth
     * @param \MarkupCarve\Carve2\Html\InlineRenderer $inline
     *
     * @return string
     */
    public function inline(Node $node, int $depth, InlineRenderer $inline): string
    {
        if ($this->allowsInline($node) || $node->type === 'comment') {
            return $inline->node($node, $depth);
        }
        $this->report($node);

        return Escape::text($this->inlineText($node));
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function report(Node $node): void
    {
        $this->log->loss('profile-construct-degraded', 'warning', $node->type . ' is outside the profile');
    }

    /**
     * C2-H-165: the plain text of a degraded inline node.
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return string
     */
    private function inlineText(Node $node): string
    {
        $f = $node->f;

        return match ($node->type) {
            'image' => '[img: ' . PlainText::str($f['alt'] ?? '') . ']',
            'non_breaking_space' => "\u{00A0}",
            'soft_break', 'hard_break' => ' ',
            'smart_punctuation' => PlainText::str($f['glyph'] ?? $f['source'] ?? ''),
            'footnote_ref' => '[^' . PlainText::str($f['key'] ?? '') . ']',
            'comment' => '',
            'text', 'code', 'literal_inline', 'math', 'raw_inline', 'critic_comment' => PlainText::str($f['value'] ?? ''),
            'symbol' => ':' . PlainText::str($f['name'] ?? '') . ':',
            'mention' => '@' . PlainText::str($f['target'] ?? ''),
            'tag' => '#' . PlainText::str($f['name'] ?? ''),
            'autolink' => PlainText::str($f['text'] ?? $f['href'] ?? ''),
            'xref' => '</#' . PlainText::str($f['key'] ?? '') . '>',
            'abbreviation' => PlainText::str($f['key'] ?? ''),
            'caption_number' => '',
            'citation_group' => PlainText::str($f['raw'] ?? ''),
            default => $this->childrenText($node->children ?? []),
        };
    }

    /**
     * @param array<\MarkupCarve\Carve2\Node> $nodes
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
     * @param \MarkupCarve\Carve2\Node $node
     * @param int $depth
     *
     * @return string|null
     */
    public function degradeBlock(Node $node, int $depth): ?string
    {
        if (!HtmlRenderer::visible($node)) {
            return null;
        }
        $this->report($node);
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
     * Escaped paragraph contents of a degraded block.
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return array<string>
     */
    private function degradedTexts(Node $node): array
    {
        if ($node->type === 'table') {
            return [$this->tableText($node)];
        }
        if ($node->type === 'definition_list') {
            $out = [];
            foreach ($node->children ?? [] as $child) {
                if ($child->type === 'definition_term') {
                    $out[] = Escape::text($this->childrenText($child->children ?? []));
                } elseif ($child->type === 'definition_description') {
                    $out = array_merge($out, $this->blockList($child->children ?? []));
                }
            }

            return $out;
        }
        if (isset(self::HOLDS_BLOCKS[$node->type])) {
            $out = $this->blockList($node->children ?? []);
            $caption = $node->f['caption'] ?? null;

            return $caption instanceof Node ? array_merge($out, $this->blockList($caption->children ?? [])) : $out;
        }

        return [Escape::text($this->plainOf($node))];
    }

    /**
     * @param array<\MarkupCarve\Carve2\Node> $blocks
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
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return string
     */
    private function plainOf(Node $node): string
    {
        $f = $node->f;

        return match ($node->type) {
            'heading' => str_repeat('#', is_int($f['level'] ?? null) ? $f['level'] : 1) . ' ' . $this->childrenText($node->children ?? []),
            'block_quote' => '> ' . $this->joined($node->children ?? []),
            'list' => $this->listText($node),
            'thematic_break' => '---',
            'code_block', 'raw_block' => rtrim(PlainText::str($f['value'] ?? ''), "\n"),
            'div', 'admonition', 'directive' => trim((is_array($f['title'] ?? null) ? $this->childrenText(PlainText::nodes($f['title'])) : '') . ' ' . $this->joined($node->children ?? [])),
            'include' => HtmlRenderer::includeText($node),
            'paragraph', 'definition_term', 'cell_paragraph', 'line' => $this->childrenText($node->children ?? []),
            default => $this->joined($node->children ?? []),
        };
    }

    /**
     * @param \MarkupCarve\Carve2\Node $list
     *
     * @return string
     */
    private function listText(Node $list): string
    {
        $items = [];
        foreach ($list->children ?? [] as $item) {
            $items[] = '- ' . $this->joined($item->children ?? []);
        }

        return implode(' ', $items);
    }

    /**
     * The degraded texts of blocks joined by single spaces.
     *
     * @param array<\MarkupCarve\Carve2\Node> $blocks
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
        }

        return implode(' ', $parts);
    }

    /**
     * @param \MarkupCarve\Carve2\Node $table
     *
     * @return string
     */
    private function tableText(Node $table): string
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
                        $cells[] = Escape::text($this->joined($cell->children ?? []));
                    }
                }
                $rows[] = implode(' | ', $cells);
            }
        }

        return implode("<br>\n", $rows);
    }
}
