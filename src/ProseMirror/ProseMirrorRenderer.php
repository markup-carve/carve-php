<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;

/**
 * Renders a Carve AST as a ProseMirror (Tiptap) document.
 *
 * Names come from the published schema map, not from a table restated here, so
 * the output loads into an editor running CarveKit. See SchemaMap.
 *
 * Two shape differences do the real work:
 *
 * - **Marks.** Carve nests emphasis as elements (`Strong > Text`); ProseMirror
 *   hangs marks off the text node itself. Inline walking therefore carries the
 *   active mark set down and attaches it when it reaches text.
 * - **Type narrowing.** One Carve type can be several ProseMirror nodes - a
 *   `list` is a bulletList, orderedList or taskList - so the node's own state
 *   selects among the names the map lists.
 *
 * Types the editor model cannot represent are dropped rather than guessed at,
 * and every drop is recorded: `droppedTypes()` reports what a caller lost, which
 * is the difference between a bridge and a data-loss bug.
 */
class ProseMirrorRenderer
{
    /**
     * @var array<string, string> carve type => reason
     */
    protected array $dropped = [];

    /**
     * @var array<string, string> carve type => reason
     */
    protected array $degraded = [];

    /**
     * @return array<string, mixed>
     */
    public function render(Document $document): array
    {
        $this->dropped = [];
        $this->degraded = [];

        return [
            'type' => 'doc',
            'content' => $this->renderBlocks($document->getChildren()),
        ];
    }

    public function renderJson(Document $document, int $flags = 0): string
    {
        return (string)json_encode($this->render($document), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * What the last render could not represent, as type => reason.
     *
     * Empty means the document survived intact. A caller storing documents
     * should assert on this rather than trust it.
     *
     * @return array<string, string>
     */
    public function droppedTypes(): array
    {
        return $this->dropped;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     *
     * @return array<int, array<string, mixed>>
     */
    protected function renderBlocks(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $rendered = $this->renderBlock($node);
            if ($rendered !== null) {
                $out[] = $rendered;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function renderBlock(Node $node): ?array
    {
        $type = $node->getType();
        $this->noteUnrepresentableState($node);

        // A section is a rendering wrapper, not editor content: hoist its
        // children so the heading and body land at document level.
        if ($type === 'section') {
            $children = $this->renderBlocks($node->getChildren());

            return $children === [] ? null : ['type' => 'carveSection', 'content' => $children];
        }

        $name = $this->proseMirrorName($node);
        if ($name === null) {
            $this->dropped[$type] = SchemaMap::unmappedReason($type) ?? 'no ProseMirror name for this type';

            return null;
        }

        $out = ['type' => $name];
        $attrs = $this->attributesFor($node);
        if ($attrs !== []) {
            $out['attrs'] = $attrs;
        }

        if ($node instanceof Table && $node->hasCaption()) {
            // A caption hangs off the table as state rather than as a child, so
            // walking children alone loses it silently - the worst kind of loss,
            // since nothing would report it. Emit it as the leading child.
            $caption = $node->getCaption();
            if ($caption !== null) {
                $out['content'] = [
                    ['type' => 'carveCaption', 'content' => $this->renderInlines($caption->getChildren(), [])],
                    ...$this->renderBlocks($node->getChildren()),
                ];

                return $out;
            }
        }

        if ($node instanceof CodeBlock) {
            // The text lives in a property, not in children, so it has to be
            // emitted as the single text child ProseMirror expects.
            $code = $node->getContent();
            $content = $code === '' ? [] : [['type' => 'text', 'text' => $code]];
        } else {
            $content = $this->isInlineContainer($node)
                ? $this->renderInlines($node->getChildren(), [])
                : $this->renderBlocks($node->getChildren());
        }

        if ($content !== []) {
            $out['content'] = $content;
        }

        return $out;
    }

    /**
     * Inline content, flattening Carve's nested mark elements onto text nodes.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param array<int, array<string, mixed>> $marks marks active from enclosing nodes
     *
     * @return array<int, array<string, mixed>>
     */
    protected function renderInlines(array $nodes, array $marks): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $type = $node->getType();
            $this->noteUnrepresentableState($node);

            if ($node instanceof Text) {
                $text = $node->getContent();
                if ($text === '') {
                    continue;
                }
                $textNode = ['type' => 'text', 'text' => $text];
                if ($marks !== []) {
                    $textNode['marks'] = $marks;
                }
                $out[] = $textNode;

                continue;
            }

            // Types the editor model has no node for, but whose CONTENT is plain
            // text: degrading them to text keeps the document readable, where
            // dropping them would run words together or lose a character. This is
            // recorded separately from a drop - the text survives, the node type
            // does not, so an AST round-trip will not be identical.
            $asText = $this->degradeToText($node);
            if ($asText !== null) {
                $this->degraded[$type] = SchemaMap::unmappedReason($type) ?? 'degraded to text';
                if ($asText !== '') {
                    $textNode = ['type' => 'text', 'text' => $asText];
                    if ($marks !== []) {
                        $textNode['marks'] = $marks;
                    }
                    $out[] = $textNode;
                }

                continue;
            }

            if ($node instanceof Code || $node instanceof CriticComment) {
                // Same asymmetry inline: Carve holds the text on the node, while
                // ProseMirror wants a text node carrying a mark. Both of these
                // have literal content and no children, so descending as a mark
                // would emit an empty element and lose the text.
                $out[] = [
                    'type' => 'text',
                    'text' => $node->getContent(),
                    'marks' => [...$marks, ['type' => (string)SchemaMap::nameFor($type)]],
                ];

                continue;
            }

            if (SchemaMap::isMark($type)) {
                $mark = ['type' => (string)SchemaMap::nameFor($type)];
                $attrs = $this->attributesFor($node);
                if ($attrs !== []) {
                    $mark['attrs'] = $attrs;
                }
                // Descend with this mark added rather than emitting a node.
                foreach ($this->renderInlines($node->getChildren(), [...$marks, $mark]) as $child) {
                    $out[] = $child;
                }

                continue;
            }

            $name = $this->proseMirrorName($node);
            if ($name === null) {
                $this->dropped[$type] = SchemaMap::unmappedReason($type) ?? 'no ProseMirror name for this type';

                continue;
            }

            $inline = ['type' => $name];
            $attrs = $this->attributesFor($node);
            if ($attrs !== []) {
                $inline['attrs'] = $attrs;
            }
            if ($marks !== []) {
                $inline['marks'] = $marks;
            }
            $children = $this->renderInlines($node->getChildren(), []);
            if ($children !== []) {
                $inline['content'] = $children;
            }
            $out[] = $inline;
        }

        return $out;
    }

    /**
     * The plain text an unmodeled inline stands for, or null when it has none.
     */
    protected function degradeToText(Node $node): ?string
    {
        return match (true) {
            $node instanceof SoftBreak => ' ',
            $node instanceof EscapedText => $node->getContent(),
            $node instanceof LiteralInline => $node->getContent(),
            $node instanceof RawText => $node->getContent(),
            $node instanceof SmartPunctuation => $node->getGlyph(),
            $node instanceof Symbol => $node->getName(),
            default => null,
        };
    }

    /**
     * Types the last render kept as text rather than as their own node.
     *
     * @return array<string, string>
     */
    public function degradedTypes(): array
    {
        return $this->degraded;
    }

    /**
     * Narrow a Carve type to one ProseMirror name using the node's own state.
     */
    protected function proseMirrorName(Node $node): ?string
    {
        $type = $node->getType();

        if ($node instanceof ListBlock) {
            if ($this->isTaskList($node)) {
                return 'taskList';
            }

            return $node->getListType() === 'ordered' ? 'orderedList' : 'bulletList';
        }

        if ($node instanceof ListItem) {
            return $node->isTask() ? 'taskItem' : 'listItem';
        }

        if ($node instanceof TableCell) {
            return $node->isHeader() ? 'tableHeader' : 'tableCell';
        }

        if ($node instanceof Div) {
            $class = (string)($node->getAttribute('class') ?? '');

            return match (true) {
                str_contains($class, 'tabs') => 'carveTabSet',
                str_contains($class, 'tab') => 'carveTab',
                default => 'carveDiv',
            };
        }

        return SchemaMap::nameFor($type);
    }

    protected function isTaskList(ListBlock $list): bool
    {
        foreach ($list->getChildren() as $item) {
            if ($item instanceof ListItem && $item->isTask()) {
                return true;
            }
        }

        return false;
    }

    /**
     * ProseMirror attrs for a node: the state the editor needs, plus the Carve
     * attribute map (id, class and every data-*), which is how an application's
     * own block types survive the trip.
     *
     * @return array<string, mixed>
     */
    protected function attributesFor(Node $node): array
    {
        $attrs = [];

        if ($node instanceof Heading) {
            $attrs['level'] = $node->getLevel();
        } elseif ($node instanceof CodeBlock) {
            // An empty info string is not a language: emitting it would render
            // class="language-".
            $language = (string)$node->getLanguage();
            if ($language !== '') {
                $attrs['language'] = $language;
            }
        } elseif ($node instanceof ListBlock) {
            if ($node->getListType() === 'ordered') {
                $attrs['start'] = $node->getStart();
            }
            // Looseness decides whether items render their paragraphs, so it is
            // content, not styling: without it a loose list comes back tight.
            $attrs['tight'] = $node->isTight();
        } elseif ($node instanceof ListItem) {
            if ($node->isTask()) {
                $attrs['checked'] = $node->isCompleted();
            }
        } elseif ($node instanceof TableCell) {
            $attrs['colspan'] = $node->getColspan();
            $attrs['rowspan'] = $node->getRowspan();
            if ($node->getAlignment() !== TableCell::ALIGN_DEFAULT) {
                $attrs['alignment'] = $node->getAlignment();
            }
        } elseif ($node instanceof Image) {
            $attrs['src'] = $node->getSource();
            $attrs['alt'] = $node->getAlt();
            // An empty title is not the same as no title - corpus 108 pins that
            // `[x](u "")` keeps `title=""`.
            if ($node->getTitle() !== null) {
                $attrs['title'] = $node->getTitle();
            }
        } elseif ($node instanceof Mention) {
            // Mention extends Link, so this must come first or the Link branch
            // swallows it and the css class never reaches the editor.
            $attrs['href'] = $node->getDestination();
            $attrs['cssClass'] = $node->getCssClass();
        } elseif ($node instanceof Link) {
            $attrs['href'] = $node->getDestination();
            if ($node->getTitle() !== null) {
                $attrs['title'] = $node->getTitle();
            }
        } elseif ($node instanceof Math) {
            $attrs['src'] = $node->getContent();
            $attrs['display'] = $node->isDisplay();
        } elseif ($node instanceof FootnoteRef || $node instanceof Footnote) {
            // The label is the note's identity: it binds a reference to its
            // definition and distinguishes two references to the same note.
            // Without it every footnote in the document is the same anonymous
            // one. `carveFootnote` declares the attribute; only this side was
            // leaving it unset.
            $attrs['label'] = $node->getLabel();
        } elseif ($node instanceof Div) {
            $label = $node->getLabel();
            if ($label !== null && $label !== '') {
                $attrs['label'] = $label;
            }
        }

        foreach ($node->getAttributes() as $key => $value) {
            $attrs[$key] = $value;
        }

        return $attrs;
    }

    /**
     * Records state the editor model has no place for.
     *
     * A type can map cleanly and still lose something: the NODE survives, one
     * of its fields does not. Those losses were invisible - the type never
     * appeared in either report, because nothing was dropped or degraded to
     * text - so a caller storing documents had no way to find out. Each entry
     * names the field rather than the type alone.
     */
    protected function noteUnrepresentableState(Node $node): void
    {
        if ($node instanceof Link && !$node instanceof Mention) {
            if ($node->isAutolink()) {
                $this->degraded['autolink'] = 'an autolink is a plain link mark in the editor model, '
                    . 'so it comes back written as [text](url)';
            }
            if ($node->getChildren() === []) {
                // A mark needs text to attach to. An empty label has none, so
                // the link does not merely change shape - it disappears.
                $this->degraded['link'] = 'a link with an empty label has no text to carry the mark, '
                    . 'so it is not represented at all';
            }
        }

        if ($node instanceof Code && $node->getAttributes() !== []) {
            $this->degraded['code'] = 'inline code is a mark; its attributes have nowhere to live';
        }

        if ($node instanceof ListBlock) {
            if ($node->getStyle() !== null) {
                $this->degraded['list'] = 'an alphabetic or roman list style is not in the editor model, '
                    . 'so the list comes back numbered';
            } elseif ($node->getMarker() !== null && !in_array($node->getMarker(), ['-', '.'], true)) {
                $this->degraded['list'] = 'a list marker character is not in the editor model, '
                    . 'so the canonical one comes back';
            }
        }
    }

    protected function isInlineContainer(Node $node): bool
    {
        return in_array($node->getType(), [
            'paragraph',
            'heading',
            'table_cell',
            'definition_term',
            'caption',
        ], true);
    }
}
