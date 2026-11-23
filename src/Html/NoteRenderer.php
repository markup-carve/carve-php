<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * The footnote section (C2-H-089 to C2-H-094, C2-H-158, C2-H-169).
 */
final class NoteRenderer
{
    public function __construct(private HtmlRenderer $r)
    {
    }

    /**
     * @param int $depth
     * @param \MarkupCarve\Carve\Node|null $directive The `::: footnotes` directive that places the section.
     *
     * @return string|null
     */
    public function section(int $depth, ?Node $directive): ?string
    {
        $state = $this->r->state;
        if ($state->notes === []) {
            return null;
        }
        $o = $this->r->o;
        $pad = HtmlRenderer::pad($depth);
        $titleId = $directive !== null ? $state->admonitionIds[spl_object_id($directive)] ?? null : null;
        $label = $titleId !== null ? ' aria-labelledby="' . Escape::attr($titleId) . '"' : ' aria-label="'
            . Escape::attr($o->label('endnotes')) . '"';
        $lines = [$pad . '<section role="doc-endnotes"' . $label . '>'];
        if ($directive !== null) {
            foreach ($this->r->titleAndLabel($directive, $depth + 1, $titleId) as $line) {
                $lines[] = $line;
            }
        }
        $lines[] = $pad . '  <hr' . ($this->r->o->xhtml ? ' />' : '>');
        $lines[] = $pad . '  <ol>';
        foreach ($state->notes as $number => $note) {
            $lines[] = $this->note($number, $note, $depth + 2);
        }
        $lines[] = $pad . '  </ol>';
        $lines[] = $pad . '</section>';

        return implode("\n", $lines);
    }

    /**
     * @param int $number
     * @param \MarkupCarve\Carve\Node $note
     * @param int $depth
     *
     * @return string
     */
    private function note(int $number, Node $note, int $depth): string
    {
        $structural = [['id', $this->r->state->noteIds[$number]]];
        if ($this->r->o->roundtrip) {
            $structural[] = $note->type === 'footnote' ? [
                'data-carve-footnote-label',
                PlainText::str($note->f['key'] ?? ''),
            ] : ['data-carve-inline-footnote', null];
        }
        $back = $this->backlinks($number);
        $pad = HtmlRenderer::pad($depth);
        $lines = [];
        if ($note->type === 'inline_footnote') {
            $lines[] = $pad . '  <p>' . $this->r->inline->leaf($note->children ?? [], $depth + 2) . $back . '</p>';
        } else {
            $lines = $this->blocks($note->children ?? [], $depth, $back);
        }

        return $pad . '<li' . AttrWriter::join($structural, $this->r->o->xhtml) . ">\n" . implode("\n", $lines) . "\n" . $pad . '</li>';
    }

    /**
     * C2-H-091: the blocks of a note, the backlinks on the last block that renders something when it is written
     * as `<p>`, and otherwise in a paragraph of their own.
     *
     * @param array<\MarkupCarve\Carve\Node> $children
     * @param int $depth
     * @param string $back
     *
     * @return array<string>
     */
    private function blocks(array $children, int $depth, string $back): array
    {
        $visible = $this->r->visibleOf($children);
        $last = $this->host($visible, $depth + 1);
        $endsWithParagraph = $last !== null && $this->r->writesParagraph($last);
        $profile = $this->r->profile;
        // A block the profile degrades is written as paragraphs, so the last of them takes the backlinks.
        $degraded = $last !== null && !$endsWithParagraph && $profile !== null && !$profile->allowsBlock($last, $depth + 1);
        $hosted = $endsWithParagraph;
        $lines = [];
        foreach ($visible as $block) {
            $html = $block === $last && $endsWithParagraph
                ? $this->r->paragraph($block, $depth + 1, false, $back)
                : ($block->type === 'paragraph' && !(($block->f['caption'] ?? null) instanceof Node)
                    ? $this->r->paragraph($block, $depth + 1, false)
                    : $this->r->block($block, $depth + 1));
            if ($block === $last && $degraded && $html !== null && str_ends_with($html, '</p>')) {
                $html = substr($html, 0, -4) . $back . '</p>';
                $hosted = true;
            }
            if ($html !== null) {
                $lines[] = $html;
            }
        }
        if (!$hosted) {
            $lines[] = HtmlRenderer::pad($depth) . '  <p>' . $back . '</p>';
        }

        return $lines;
    }

    /**
     * The last block that renders something: a raw block of another format writes nothing (C2-H-054), unless
     * the profile degrades it into a paragraph, which an empty block does not write.
     *
     * @param array<\MarkupCarve\Carve\Node> $visible
     * @param int $depth
     *
     * @return \MarkupCarve\Carve\Node|null
     */
    private function host(array $visible, int $depth): ?Node
    {
        $profile = $this->r->profile;
        for ($i = count($visible) - 1; $i >= 0; $i--) {
            $block = $visible[$i];
            if ($block->type !== 'raw_block') {
                return $block;
            }
            $renders = $profile !== null && !$profile->allowsBlock($block, $depth)
                ? rtrim(PlainText::str($block->f['value'] ?? ''), "\n") !== ''
                : PlainText::str($block->f['format'] ?? '') === 'html';
            if ($renders) {
                return $block;
            }
        }

        return null;
    }

    /**
     * C2-H-091.
     *
     * @param int $number
     *
     * @return string
     */
    private function backlinks(int $number): string
    {
        $ids = $this->r->state->backlinks[$number] ?? [];
        $label = $this->r->o->label('footnoteBacklink');
        if (count($ids) === 1) {
            return '<a href="#' . Escape::attr($ids[0]) . '" role="doc-backlink" aria-label="' . Escape::attr($label) . '">↩</a>';
        }
        $out = [];
        foreach ($ids as $k => $id) {
            $n = $k + 1;
            $out[] = '<a href="#' . Escape::attr($id) . '" role="doc-backlink" aria-label="' . Escape::attr($label . ' '
                . $n) . '">↩<sup>' . $n . '</sup></a>';
        }

        return implode(' ', $out);
    }
}
