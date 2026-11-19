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
        $lines[] = $pad . '  <hr>';
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
            $visible = HtmlRenderer::visibleOf($note->children ?? []);
            $last = $visible === [] ? null : $visible[count($visible) - 1];
            $endsWithParagraph = $last !== null && $this->r->writesParagraph($last);
            foreach ($visible as $block) {
                $html = $block === $last && $endsWithParagraph
                    ? $this->r->paragraph($block, $depth + 1, false, $back)
                    : ($block->type === 'paragraph' && !(($block->f['caption'] ?? null) instanceof Node)
                        ? $this->r->paragraph($block, $depth + 1, false)
                        : $this->r->block($block, $depth + 1));
                if ($html !== null) {
                    $lines[] = $html;
                }
            }
            if (!$endsWithParagraph) {
                $lines[] = $pad . '  <p>' . $back . '</p>';
            }
        }

        return $pad . '<li' . AttrWriter::join($structural) . ">\n" . implode("\n", $lines) . "\n" . $pad . '</li>';
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
