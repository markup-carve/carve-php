<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\Frontmatter;
use MarkupCarve\Carve\Node\Block\AbbreviationDef;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Subscript;
use MarkupCarve\Carve\Node\Inline\Substitution;
use MarkupCarve\Carve\Node\Inline\Superscript;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Node;
use ReflectionObject;
use Throwable;

/**
 * Renders AST back to canonical Carve source.
 */
class CarveRenderer implements RendererInterface
{
    /**
     * @var int
     */
    private const MAX_RENDER_DEPTH = 200;

    /**
     * @var list<string>
     */
    private const ADMONITION_TYPES = ['note', 'tip', 'warning', 'danger', 'info', 'success', 'example', 'quote'];

    /**
     * @var string
     */
    private const ESCAPE_MODE_MINIMAL = 'minimal';

    /**
     * @var string
     */
    private const ESCAPE_MODE_CONSERVATIVE = 'conservative';

    protected int $blockDepth = 0;

    protected int $inlineDepth = 0;

    protected int $listDepth = 0;

    protected string $escapeMode = self::ESCAPE_MODE_CONSERVATIVE;

    public function render(Document $document): string
    {
        $minimal = $this->renderWithEscapeMode($document, self::ESCAPE_MODE_MINIMAL);
        $conservative = $this->renderWithEscapeMode($document, self::ESCAPE_MODE_CONSERVATIVE);
        if ($minimal === $conservative) {
            return $minimal;
        }

        return $this->escapingIsRedundant($minimal, $conservative) ? $minimal : $conservative;
    }

    protected function renderWithEscapeMode(Document $document, string $escapeMode): string
    {
        $previousEscapeMode = $this->escapeMode;
        $this->escapeMode = $escapeMode;
        try {
            return $this->renderDocumentParts($document);
        } finally {
            $this->escapeMode = $previousEscapeMode;
        }
    }

    protected function renderDocumentParts(Document $document): string
    {
        $parts = [];
        $abbrs = [];
        foreach ($document->getAbbreviations() as $abbr => $expansion) {
            $abbrs[] = '*[' . $this->escapeBracketText((string)$abbr) . ']: ' . str_replace("\n", ' ', (string)$expansion);
        }
        if ($abbrs !== [] && $document->hasAbbreviationsBeforeBody()) {
            $parts[] = implode("\n", $abbrs);
        }
        $body = $this->renderBlocks($document->getChildren());
        if ($body !== '') {
            $parts[] = $body;
        }
        if ($abbrs !== [] && !$document->hasAbbreviationsBeforeBody()) {
            $parts[] = implode("\n", $abbrs);
        }

        return $this->normalize(implode("\n\n", $parts));
    }

    /**
     * PART 11 section 4: compare the parsed minimal and conservative renders,
     * not either render against the source AST. If parsing fails, keep the old
     * conservative behavior.
     */
    protected function escapingIsRedundant(string $minimal, string $conservative): bool
    {
        try {
            $parser = new CarveConverter();

            return $this->canonicalizeAst($parser->parse($minimal)) == $this->canonicalizeAst($parser->parse($conservative));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return mixed
     */
    protected function canonicalizeAst(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $out[$key] = $this->canonicalizeAst($child);
            }
            if (array_is_list($out)) {
                $out = $this->coalesceTextNodes($out);
            } else {
                ksort($out);
            }

            return $out;
        }

        if (is_object($value)) {
            $ref = new ReflectionObject($value);
            $class = $value instanceof EscapedText ? Text::class : $ref->getName();
            $out = ['__class' => $class];
            foreach ($ref->getProperties() as $property) {
                $name = $property->getName();
                if ($name === 'parent' || $name === 'sourceLength') {
                    continue;
                }
                $property->setAccessible(true);
                $out[$name] = $this->canonicalizeAst($property->getValue($value));
            }
            ksort($out);

            return $out;
        }

        return $value;
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return array<mixed>
     */
    protected function coalesceTextNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $lastIndex = count($out) - 1;
            $content = $this->canonicalTextContent($node);
            if ($lastIndex >= 0 && $content !== null) {
                $previousContent = $this->canonicalTextContent($out[$lastIndex]);
                if ($previousContent !== null && is_array($out[$lastIndex])) {
                    $out[$lastIndex]['content'] = $previousContent . $content;

                    continue;
                }
            }
            $out[] = $node;
        }

        return $out;
    }

    protected function canonicalTextContent(mixed $node): ?string
    {
        if (
            is_array($node)
            && ($node['__class'] ?? null) === Text::class
            && ($node['attributes'] ?? []) === []
            && ($node['attributeOrder'] ?? []) === []
            && ($node['children'] ?? []) === []
            && is_string($node['content'] ?? null)
        ) {
            return $node['content'];
        }

        return null;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $blocks
     */
    protected function renderBlocks(array $blocks): string
    {
        if ($this->blockDepth >= self::MAX_RENDER_DEPTH) {
            return '';
        }

        $this->blockDepth++;
        try {
            $parts = [];
            foreach ($blocks as $block) {
                $rendered = $this->renderBlock($block);
                if ($rendered !== '') {
                    $parts[] = $rendered;
                }
            }

            return implode("\n\n", $parts);
        } finally {
            $this->blockDepth--;
        }
    }

    /**
     * Depth of line-block nesting, so the inline writer can drop the explicit
     * hard-break backslash where the container already implies one.
     */
    protected int $inLineBlock = 0;

    protected function renderBlock(Node $node): string
    {
        $attrs = $this->renderAttrs($node);
        $withAttrs = static fn (string $body): string => $attrs === '' ? $body : $attrs . "\n" . $body;

        return match (true) {
            $node instanceof Frontmatter => $withAttrs($this->renderFrontmatter($node)),
            $node instanceof Heading => $withAttrs(str_repeat('#', $node->getLevel()) . ' ' . $this->trimNonNbsp($this->renderInlines($node->getChildren()))),
            $node instanceof Paragraph => $withAttrs($this->guardThematicBreakLines($this->renderInlines($node->getChildren()))),
            // The opener's quoted title is resolved onto the `title` attribute at
            // parse time so it reaches every consumer, but the fence carries it
            // too - emitting both says it twice and re-parses with an attribute
            // order the source never had (carve#369). The fence is the authored
            // spelling, so it wins.
            $node instanceof CodeBlock => $this->withCodeBlockAttrs($node),
            $node instanceof BlockQuote => $withAttrs($this->renderBlockQuote($node)),
            $node instanceof ListBlock => $withAttrs($this->renderList($node)),
            $node instanceof ListItem => $this->renderListItem($node),
            $node instanceof ThematicBreak => $withAttrs('---'),
            $node instanceof Table => $withAttrs($this->renderTable($node)),
            $node instanceof Div && $node->isTyped() && $this->canRenderTypedDiv($node) => $this->withFencedDivAttrs($node, [$node->getClassList()[0] ?? ''], $this->renderTypedDiv($node)),
            $node instanceof Div && $node->isTyped() && $this->admonitionKind($node) !== null => $this->withFencedDivAttrs($node, [$this->admonitionKind($node)], $this->renderAdmonition($node)),
            $node instanceof Div => $withAttrs($this->renderDiv($node)),
            $node instanceof LineBlock => $withAttrs($this->renderLineBlock($node)),
            $node instanceof DefinitionList => $withAttrs($this->renderDefinitionList($node)),
            $node instanceof Figure => $withAttrs($this->renderFigure($node)),
            $node instanceof RawBlock => $withAttrs($this->renderRawBlock($node)),
            $node instanceof Comment => $this->renderComment($node),
            $node instanceof AbbreviationDef => '',
            $node instanceof Footnote => $this->renderFootnote($node),
            $node instanceof Caption => '^ ' . $this->renderInlines($node->getChildren()),
            default => $this->renderBlocks($node->getChildren()),
        };
    }

    /**
     * A code block's attribute line, minus a `title` the fence already carries.
     */
    protected function withCodeBlockAttrs(CodeBlock $node): string
    {
        $body = $this->renderCodeBlock($node);
        $header = $node->getHeader();
        $attributes = $node->getAttributes();
        if ($header !== null && ($attributes['title'] ?? null) === $header) {
            $clone = clone $node;
            $clone->removeAttribute('title');
            $attrs = $this->renderAttrs($clone);
        } else {
            $attrs = $this->renderAttrs($node);
        }

        return $attrs === '' ? $body : $attrs . "\n" . $body;
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $content = $node->getContent();
        $fence = $this->safeFence($content, 3);
        $info = $this->codeFenceInfo($node);

        return $fence . $info . "\n" . $this->protectVerbatim($content) . "\n" . $fence;
    }

    protected function codeFenceInfo(CodeBlock $node): string
    {
        $parts = [];
        $language = $node->getLanguage();
        if ($language !== null && $language !== '') {
            $parts[] = $this->escapeFenceToken($language);
        }
        $header = $node->getHeader() ?? $node->getAttribute('title');
        if (is_string($header)) {
            $parts[] = '"' . $this->escapeQuoted($header) . '"';
        }
        $label = $node->getLabel();
        if ($label !== null) {
            $parts[] = '[' . $this->escapeBracketText($label) . ']';
        }

        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $inner = $this->renderBlocks($node->getChildren());
        $lines = explode("\n", $inner);

        return implode("\n", array_map(static fn (string $line): string => $line === '' ? '>' : '> ' . $line, $lines));
    }

    protected function renderList(ListBlock $node): string
    {
        $this->listDepth++;
        try {
            $out = '';
            $counter = $node->getStart();
            // The marker is semantic (section 11: a different bullet char or
            // ordered delimiter starts a new list), so emit it as authored -
            // normalizing would merge adjacent sibling lists on re-parse
            // (carve issue 286). Absent markers fall back to `-` / `.`.
            $marker = $node->getMarker();
            $delim = $marker === ')' ? ')' : '.';
            $bullet = $marker === '*' ? '*' : '-';
            $children = array_values(array_filter($node->getChildren(), static fn (Node $child): bool => $child instanceof ListItem));
            foreach ($children as $index => $item) {
                $indent = str_repeat('  ', $this->listDepth - 1);
                if ($node->getListType() === ListBlock::TYPE_ORDERED) {
                    $prefix = $this->orderedMarker($counter, $node->getStyle()) . $delim . ' ';
                    $counter++;
                } elseif ($item->isTask()) {
                    $prefix = $bullet . ' [' . ($item->isCompleted() ? 'x' : ' ') . '] ';
                } else {
                    $prefix = $bullet . ' ';
                }

                $itemAttrs = $this->renderAttrs($item);
                if ($itemAttrs !== '') {
                    $prefix = $node->getListType() === ListBlock::TYPE_ORDERED
                        ? rtrim($prefix) . $itemAttrs . ' '
                        : $bullet . $itemAttrs . ($item->isTask() ? ' [' . ($item->isCompleted() ? 'x' : ' ') . '] ' : ' ');
                }

                $content = $this->trimNonNbsp($this->renderListItem($item, $node->isTight()));
                $itemChildren = $item->getChildren();
                if (count($itemChildren) === 1 && $itemChildren[0] instanceof ListBlock) {
                    $content = (string)preg_replace('/^  /m', '', $content);
                }
                $lines = $content === '' ? [''] : explode("\n", $content);
                $first = array_shift($lines);
                $out .= $indent . $prefix . ($first === '' ? '+' : $first) . "\n";
                $continuation = str_repeat(' ', strlen($prefix));
                foreach ($lines as $line) {
                    $out .= $indent . $continuation . $line . "\n";
                }
                if (!$node->isTight() && $index < count($children) - 1) {
                    $out .= "\n";
                }
            }

            return $this->trimEndNonNbsp($out);
        } finally {
            $this->listDepth--;
        }
    }

    protected function renderListItem(ListItem $node, bool $tight = false): string
    {
        $children = $node->getChildren();
        if (!$tight || count($children) < 2) {
            return $this->renderBlocks($children);
        }

        // A tight item with more than one child block must not gain a blank line
        // between its blocks - a blank there loosens the item on re-parse, so
        // toHtml(fmt(x)) would diverge from toHtml(x) (carve corpus 162).
        // Adjacent blocks are joined with a single newline instead, matching
        // the canonical carve-js writer.
        if ($this->blockDepth >= self::MAX_RENDER_DEPTH) {
            return '';
        }

        $this->blockDepth++;
        try {
            $out = '';
            foreach ($children as $child) {
                $rendered = $this->renderBlock($child);
                if ($rendered === '') {
                    continue;
                }
                if ($out !== '') {
                    $out .= "\n";
                }
                $out .= $rendered;
            }

            return $out;
        } finally {
            $this->blockDepth--;
        }
    }

    protected function orderedMarker(int $n, ?string $type): string
    {
        return match ($type) {
            'a' => chr((($n - 1) % 26) + 97),
            'A' => chr((($n - 1) % 26) + 65),
            'i' => strtolower($this->romanMarker($n)),
            'I' => $this->romanMarker($n),
            default => (string)$n,
        };
    }

    protected function romanMarker(int $n): string
    {
        $values = [[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']];
        $out = '';
        foreach ($values as [$value, $token]) {
            while ($n >= $value) {
                $out .= $token;
                $n -= $value;
            }
        }

        return $out === '' ? 'I' : $out;
    }

    protected function renderDiv(Div $node): string
    {
        $label = $node->getLabel() === null ? '' : ' [' . $this->escapeBracketText($node->getLabel()) . ']';
        $body = $this->renderBlocks($node->getChildren());
        $fence = $this->colonFenceFor($node->getChildren());

        return $fence . $label . "\n" . $body . "\n" . $fence;
    }

    protected function canRenderTypedDiv(Div $node): bool
    {
        $classes = $node->getClassList();

        return count($classes) === 1
            && !in_array($classes[0], ['hardbreaks', 'line-block'], true)
            && preg_match('/^[A-Za-z_][\w-]*$/', $classes[0]) === 1;
    }

    protected function renderTypedDiv(Div $node): string
    {
        $classes = $node->getClassList();
        $kind = $classes[0] ?? '';
        $title = $node->getHeader();
        $titlePart = is_string($title) ? ' "' . $this->escapeQuoted($title) . '"' : '';
        $label = $node->getLabel() === null ? '' : ' [' . $this->escapeBracketText($node->getLabel()) . ']';
        $body = $this->renderBlocks($node->getChildren());
        $fence = $this->colonFenceFor($node->getChildren());

        return $fence . ' ' . $kind . $titlePart . $label . "\n" . $body . "\n" . $fence;
    }

    protected function renderAdmonition(Div $node): string
    {
        $kind = $this->admonitionKind($node) ?? 'note';
        $title = $node->getHeader();
        $titlePart = is_string($title) ? ' "' . $this->escapeQuoted($title) . '"' : '';
        $label = $node->getLabel() === null ? '' : ' [' . $this->escapeBracketText($node->getLabel()) . ']';
        $body = $this->renderBlocks($node->getChildren());
        $fence = $this->colonFenceFor($node->getChildren());

        return $fence . ' ' . $kind . $titlePart . $label . "\n" . $body . "\n" . $fence;
    }

    protected function admonitionKind(Div $node): ?string
    {
        foreach ($node->getClassList() as $class) {
            if (in_array($class, self::ADMONITION_TYPES, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Div $node
     * @param array<string> $structuralClasses
     * @param string $body
     */
    protected function withFencedDivAttrs(Div $node, array $structuralClasses, string $body): string
    {
        $attrs = $this->renderFencedDivAttrs($node, $structuralClasses);

        return $attrs === '' ? $body : $attrs . "\n" . $body;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Div $node
     * @param array<string> $structuralClasses
     */
    protected function renderFencedDivAttrs(Div $node, array $structuralClasses): string
    {
        if ($node->getAttributes() === []) {
            return '';
        }

        $attrs = $node->getAttributes();
        $structural = array_flip($structuralClasses);
        $parts = [];
        $seen = [];
        $emit = function (string $slot) use (&$parts, &$seen, $attrs, $structural): void {
            if ($slot === '#id') {
                if (!array_key_exists('id', $attrs)) {
                    return;
                }
                $id = $attrs['id'];
                $parts[] = $this->isAttrIdentifier($id) ? '#' . $this->escapeAttrNameValue($id) : 'id=' . $this->quoteAttrValue($id);

                return;
            }
            if ($slot === '.class') {
                foreach (preg_split('/\s+/', trim($attrs['class'] ?? '')) ?: [] as $class) {
                    if ($class !== '' && !isset($structural[$class])) {
                        $parts[] = '.' . $this->escapeAttrNameValue($class);
                    }
                }

                return;
            }
            if (isset($seen[$slot]) || !array_key_exists($slot, $attrs) || $slot === 'id' || $slot === 'class') {
                return;
            }
            $seen[$slot] = true;
            $parts[] = $this->escapeAttrKey($slot) . '=' . $this->quoteAttrValue($attrs[$slot]);
        };

        $order = $node->getAttributeOrder();
        if ($order !== []) {
            foreach ($order as $slot) {
                $emit($slot);
            }
            foreach ($attrs as $key => $_value) {
                $emit((string)$key);
            }
        } else {
            $emit('#id');
            $emit('.class');
            foreach ($attrs as $key => $_value) {
                $emit((string)$key);
            }
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        // Inside a line block every newline IS a hard break (grammar PART 3,
        // line_block_body), so the explicit backslash the inline writer emits
        // for a HardBreak would double it on re-parse.
        $this->inLineBlock++;

        try {
            $body = $this->renderBlocks($node->getChildren());
        } finally {
            $this->inLineBlock--;
        }

        // `::: |` is the line-block opener (grammar PART 3, line_block_open).
        // Emitting a bare `:::` and tagging the node with a `line-block` class
        // instead re-parsed as an ordinary div, so the node type changed across
        // a format round trip and `parse(fmt(x)) == parse(x)` did not hold.
        return "::: |\n" . $body . "\n:::";
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     */
    protected function colonFenceFor(array $children): string
    {
        foreach ($children as $child) {
            if ($child instanceof Div || $child instanceof LineBlock) {
                return '::::';
            }
        }

        return ':::';
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        $out = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof DefinitionTerm) {
                $out[] = ':: ' . $this->renderInlines($child->getChildren());
            } elseif ($child instanceof DefinitionDescription) {
                $lines = explode("\n", $this->trimNonNbsp($this->renderBlocks($child->getChildren())));
                $out[] = ':  ' . array_shift($lines);
                foreach ($lines as $line) {
                    $out[] = '   ' . $line;
                }
            }
        }

        return implode("\n", $out);
    }

    protected function renderTable(Table $node): string
    {
        $rows = [];
        $tableRows = array_values(array_filter($node->getChildren(), static fn (Node $child): bool => $child instanceof TableRow));
        $columns = 0;
        foreach ($tableRows as $row) {
            $width = 0;
            foreach ($row->getChildren() as $cell) {
                $width += $cell instanceof TableCell ? max(1, $cell->getColspan()) : 1;
            }
            $columns = max($columns, $width);
        }
        // Tables prefer the NATIVE header form: an `=` on each header cell plus
        // the per-cell alignment markers. The GFM delimiter row is an accepted
        // alias on input, but it says something the AST does not - its alignment
        // applies to the WHOLE column, header and body alike (PART 9 T7), while
        // alignment on the AST belongs to each cell. Writing one for the
        // ordinary shape (aligned header over unaligned body cells) brought
        // every body cell back aligned, so `parse(fmt(x)) == parse(x)` did not
        // hold (carve#359).
        //
        // Two header shapes have no native spelling, because `header_cell` in
        // the grammar is `'=' [alignment_marker] content` and admits neither an
        // attribute block nor a span marker:
        //
        //     | < | b |     a span marker promoted to a header cell
        //     |{.x} a | b | a header cell carrying attributes
        //
        // Those keep a delimiter row to promote the first row, emitted BARE so
        // the cells keep their own alignment markers and the delimiter cannot
        // spill alignment down the column.
        $headerRow = isset($tableRows[0]) && $tableRows[0]->isHeader();
        // This parser resolves a cell's alignment at parse time, so a body cell
        // carries the column's alignment even when the author only wrote it on
        // the header. carve-js and carve-rs keep the author's own marker and
        // resolve at render, and their AST is the one the writer can reproduce.
        // Until the three agree (carve#361), suppress the marker on a body cell
        // that merely inherited it: the emitted source then matches the other
        // two engines byte for byte, and re-parsing it here restores the same
        // resolved alignment.
        $headerAligns = [];
        if ($headerRow) {
            $headerColumn = 0;
            foreach ($tableRows[0]->getChildren() as $cell) {
                if (!$cell instanceof TableCell) {
                    continue;
                }
                $width = max(1, $cell->getColspan());
                for ($i = 0; $i < $width; $i++) {
                    $headerAligns[$headerColumn++] = $cell->getAlignment();
                }
            }
        }
        $needsDelimiter = false;
        if ($headerRow) {
            foreach ($tableRows[0]->getChildren() as $cell) {
                if (!$cell instanceof TableCell) {
                    continue;
                }
                if ($cell->getSpanMarker() !== null || $this->renderAttrs($cell) !== '') {
                    $needsDelimiter = true;

                    break;
                }
            }
        }
        $rowspans = array_fill(0, $columns, 0);
        foreach ($tableRows as $rowIndex => $row) {
            $cells = [];
            $rowCells = $row->getChildren();
            $sourceIndex = 0;
            for ($column = 0; $column < $columns; $column++) {
                if (($rowspans[$column] ?? 0) > 0) {
                    $cells[] = ['text' => '^', 'tight' => true];
                    $rowspans[$column]--;

                    continue;
                }
                $cell = $rowCells[$sourceIndex] ?? null;
                $sourceIndex++;
                if (!$cell instanceof TableCell) {
                    $cells[] = ['text' => '', 'tight' => false];

                    continue;
                }
                // In the delimiter form the promoted row is written as ordinary
                // data cells - the row after it is what makes them headers.
                $markHeader = !($needsDelimiter && $rowIndex === 0);
                $inherited = $headerRow
                    && $rowIndex > 0
                    && ($headerAligns[$column] ?? null) === $cell->getAlignment();
                $cells[] = $this->renderTableCell($cell, $markHeader, $inherited);
                if ($cell->getRowspan() > 1) {
                    $rowspans[$column] = $cell->getRowspan() - 1;
                }
                for ($span = 1; $span < $cell->getColspan() && $column + 1 < $columns; $span++) {
                    $column++;
                    if (($rowspans[$column] ?? 0) > 0) {
                        $cells[] = ['text' => '^', 'tight' => true];
                        $rowspans[$column]--;
                        $span--;

                        continue;
                    }
                    $cells[] = ['text' => '<', 'tight' => true];
                }
            }
            $rows[] = $this->renderTableRow($cells, $this->renderAttrs($row));
        }
        if ($needsDelimiter) {
            array_splice($rows, 1, 0, '|' . implode('|', array_fill(0, max(1, $columns), '---')) . '|');
        }
        if ($node->hasCaption()) {
            $caption = $node->getCaption();
            if ($caption !== null) {
                $rows[] = '^ ' . $this->renderInlines($caption->getChildren());
            }
        }

        return implode("\n", $rows);
    }

    /**
     * @param list<array{text: string, tight: bool}> $cells Rendered cells.
     * @param string $attrs Row attributes.
     */
    protected function renderTableRow(array $cells, string $attrs): string
    {
        return '|' . implode('|', array_map(static fn (array $cell): string => $cell['tight'] ? $cell['text'] : ' ' . $cell['text'] . ' ', $cells)) . '|' . $attrs;
    }

    /**
     * @return array{text: string, tight: bool}
     */
    protected function renderTableCell(TableCell $cell, bool $markHeader = true, bool $inheritedAlign = false): array
    {
        $attrs = $this->renderAttrs($cell);
        if ($cell->getSpanMarker() !== null) {
            return ['text' => $attrs . $cell->getSpanMarker(), 'tight' => true];
        }
        $align = $inheritedAlign ? '' : $this->alignMarker($cell->getAlignment());
        $prefix = $attrs . ($cell->isHeader() && $markHeader ? '=' : '') . $align;

        return ['text' => $prefix . $this->renderInlines($cell->getChildren()), 'tight' => $prefix !== ''];
    }

    protected function renderFigure(Figure $node): string
    {
        $target = '';
        $caption = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                $caption = '^ ' . $this->renderInlines($child->getChildren());
            } elseif ($child instanceof Image) {
                $target .= ($target === '' ? '' : "\n") . $this->renderImage($child);
            } else {
                $target .= ($target === '' ? '' : "\n") . $this->renderBlock($child);
            }
        }

        return $caption === '' ? $target : $target . "\n" . $caption;
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        $content = $node->getContent();
        $fence = $this->safeFence($content, 3);

        return $fence . '=' . $this->escapeFormat($node->getFormat()) . "\n" . $this->protectVerbatim($content) . "\n" . $fence;
    }

    protected function renderComment(Comment $node): string
    {
        $content = $node->getContent();
        if ($node->getFenceLength() !== null) {
            $fence = str_repeat('%', max(3, $node->getFenceLength()));

            return $fence . "\n" . $this->protectVerbatim($content) . "\n" . $fence;
        }
        if (!str_contains($content, "\n")) {
            return '%% ' . $content;
        }
        preg_match_all('/%+/', $content, $matches);
        $longest = 0;
        foreach ($matches[0] as $match) {
            $longest = max($longest, strlen($match));
        }
        $fence = str_repeat('%', max(3, $longest + 1));

        return $fence . "\n" . $this->protectVerbatim($content) . "\n" . $fence;
    }

    protected function renderFootnote(Footnote $node): string
    {
        $body = $this->trimNonNbsp($this->renderBlocks($node->getChildren()));
        $lines = explode("\n", $body);
        $out = '[^' . $this->escapeFootnoteLabel($node->getLabel()) . ']: ' . array_shift($lines);
        foreach ($lines as $line) {
            $out .= "\n   " . $line;
        }

        return $out;
    }

    protected function renderFrontmatter(Frontmatter $node): string
    {
        $open = $node->getFormat() === 'yaml' ? '---' : '---' . $this->escapeFormat($node->getFormat());

        return $open . "\n" . $this->protectVerbatim($node->getContent()) . "\n---";
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function renderInlines(array $nodes): string
    {
        if ($this->inlineDepth >= self::MAX_RENDER_DEPTH) {
            return '';
        }
        $this->inlineDepth++;
        try {
            $out = '';
            $count = count($nodes);
            for ($i = 0; $i < $count; $i++) {
                $node = $nodes[$i];
                if ($node instanceof InlineNode) {
                    // A trailing `!` on a Text node immediately before an
                    // unresolved reference (a RawText starting with `[`) must NOT
                    // be escaped: together they round-trip as a literal reference
                    // image (`![a][nope]`), matching carve-js / carve-rs. A
                    // RawText never holds an inline-link `(...)`, so `!`+`[…]`
                    // can only re-parse to a literal, never a real image. Escaping
                    // just the `!` (`\![a][nope]`) would diverge.
                    $next = $nodes[$i + 1] ?? null;
                    if (
                        $node instanceof Text
                        && str_ends_with($node->getContent(), '!')
                        && $next instanceof RawText
                        && str_starts_with($next->getContent(), '[')
                    ) {
                        $content = $node->getContent();
                        $out .= $this->escapeText(substr($content, 0, -1)) . '!';

                        continue;
                    }
                    $out .= $this->renderInline($node, $this->lastBoundary($nodes[$i - 1] ?? null), $this->firstBoundary($nodes[$i + 1] ?? null));
                } elseif ($node instanceof Comment) {
                    $out .= ' %% ' . $node->getContent();
                }
            }

            return $out;
        } finally {
            $this->inlineDepth--;
        }
    }

    protected function renderInline(InlineNode $node, string $prevChar = '', string $nextChar = ''): string
    {
        $withAttrs = fn (string $body): string => $body . $this->renderAttrs($node);

        return match (true) {
            $node instanceof Text => $this->escapeText($this->resolveIndentPlaceholder($node->getContent())) . (string)$node->getAttribute('data-carve-raw-suffix'),
            // The whole point: reproduce the author's source run verbatim.
            $node instanceof SmartPunctuation => $node->getContent(),
            // The author escaped this character; the writer says so again. No
            // minimal/conservative decision applies -- the node IS the decision.
            // Routing it through escapeText() made the minimal render DROP the
            // author's escape, so `\*x\*` came back as `*x*`, re-parsed with a
            // Strong, and W4 escalated the whole document to conservative
            // (carve#374).
            $node instanceof EscapedText => '\\' . $node->getContent(),
            $node instanceof Emphasis => $withAttrs($this->renderEmphasis('/', $this->renderInlines($node->getChildren()), $prevChar, $nextChar)),
            $node instanceof Strong => $withAttrs($this->renderStrongNode($node, $prevChar, $nextChar)),
            $node instanceof Underline => $withAttrs($this->renderEmphasis('_', $this->renderInlines($node->getChildren()), $prevChar, $nextChar)),
            $node instanceof Strike => $withAttrs($this->renderEmphasis('~', $this->renderInlines($node->getChildren()), $prevChar, $nextChar)),
            $node instanceof Superscript => $withAttrs($this->renderForcedEmphasis('^', $this->renderInlines($node->getChildren()))),
            $node instanceof Subscript => $withAttrs($this->renderForcedEmphasis(',', $this->renderInlines($node->getChildren()))),
            $node instanceof Highlight => $withAttrs($this->renderEmphasis('=', $this->renderInlines($node->getChildren()), $prevChar, $nextChar)),
            $node instanceof Code => $withAttrs($this->renderCode($node->getContent())),
            $node instanceof Mention => $this->renderMention($node),
            $node instanceof Link && $node->isAutolink() => $withAttrs('<' . $this->escapeAutolinkHref($this->plainInlineText($node)) . '>'),
            $node instanceof Link => $this->renderLink($node),
            $node instanceof Image => $this->renderImage($node),
            $node instanceof CriticComment => '{#' . $this->escapeCriticText($node->getContent()) . '#}',
            $node instanceof Span => '[' . $this->renderInlines($node->getChildren()) . ']' . ($this->renderAttrs($node) ?: '{}'),
            $node instanceof Math => $withAttrs($this->renderMath($node)),
            $node instanceof RawInline => $this->renderCode($node->getContent()) . '{=' . $this->escapeFormat($node->getFormat()) . '}',
            $node instanceof LiteralInline => $this->renderLiteralInline($node),
            $node instanceof RawText => $node->getContent(),
            $node instanceof Symbol => $withAttrs(':' . $this->escapeSymbolName($node->getName()) . ':'),
            $node instanceof InlineExtension => $withAttrs(':' . $this->escapeIdentifier($node->getExtensionType()) . '[' . $this->renderInlines($node->getChildren()) . ']'),
            $node instanceof Abbreviation => $this->escapeText($this->renderInlines($node->getChildren())),
            $node instanceof InlineFootnote => $withAttrs('^[' . $this->renderInlines($node->getChildren()) . ']'),
            $node instanceof FootnoteRef => $withAttrs('[^' . $this->escapeFootnoteLabel($node->getLabel()) . ']'),
            $node instanceof SoftBreak => "\n",
            $node instanceof HardBreak => $this->inLineBlock > 0
                ? "\n"
                : "\\\n",
            $node instanceof Insert => $withAttrs('{+' . $this->renderInlines($node->getChildren()) . '+}'),
            $node instanceof Delete => $withAttrs('{-' . $this->renderInlines($node->getChildren()) . '-}'),
            $node instanceof Substitution => '{~' . $this->escapeCriticText($node->getOldText()) . '~>' . $this->escapeCriticText($node->getNewText()) . '~}',
            $node instanceof HeadingRef => '</#' . $this->escapeCrossrefTarget($node->getTargetId()) . '>',
            $node instanceof CaptionNumber => '#',
            $node instanceof CitationGroup => $node->getRaw(),
            default => $this->renderInlines($node->getChildren()),
        };
    }

    protected function renderStrongNode(Strong $node, string $prevChar, string $nextChar): string
    {
        // The COMBINED bold-italic form is a single production, and the nested
        // spelling parses to the same Strong>Emphasis tree -- so serializing the
        // nesting alone normalized one into the other, rewriting the spelling
        // Carve documents (cheatsheet, migrate-from-markdown) into one documented
        // nowhere. `isBoldItalic()` carries which one the author wrote
        // (PART 11 section 6; carve#375).
        $children = $node->getChildren();
        $inner = $children[0] ?? null;
        if ($node->isBoldItalic() && count($children) === 1 && $inner instanceof Emphasis) {
            return '/*' . $this->renderInlines($inner->getChildren()) . '*/';
        }

        return $this->renderEmphasis('*', $this->renderInlines($node->getChildren()), $prevChar, $nextChar);
    }

    protected function renderLink(Link $node): string
    {
        $text = $this->renderInlines($node->getChildren());
        $title = $node->getTitle() === null ? '' : ' "' . $this->escapeQuoted($node->getTitle()) . '"';

        return '[' . $text . '](' . $this->escapeDestination((string)$node->getDestination()) . $title . ')' . $this->renderAttrs($node);
    }

    protected function renderImage(Image $node): string
    {
        $title = $node->getTitle() === null ? '' : ' "' . $this->escapeQuoted($node->getTitle()) . '"';

        return '![' . $this->escapeImageAlt($node->getAlt()) . '](' . $this->escapeDestination($node->getSource()) . $title . ')' . $this->renderAttrs($node);
    }

    protected function renderMention(Mention $node): string
    {
        $label = $this->renderInlines($node->getChildren());
        if (($node->getDestination() ?? '') === '') {
            return $this->plainInlineText($node);
        }
        if (str_starts_with($label, '#')) {
            return '#' . $this->escapeName(substr($label, 1));
        }

        return '@' . $this->escapeName(ltrim($label, '@'));
    }

    protected function renderMath(Math $node): string
    {
        return ($node->isDisplay() ? '$$' : '$') . $this->renderCode($node->getContent());
    }

    /**
     * Superscript and subscript have no bare delimiter form - always emit the
     * braced form.
     */
    protected function renderForcedEmphasis(string $delimiter, string $content): string
    {
        return '{' . $delimiter . $content . $delimiter . '}';
    }

    protected function renderEmphasis(string $delimiter, string $content, string $prevChar, string $nextChar): string
    {
        $needsForced = preg_match('/[A-Za-z0-9_]/', $prevChar) === 1
            || preg_match('/[A-Za-z0-9_]/', $nextChar) === 1
            || str_starts_with($content, $delimiter)
            || str_ends_with($content, $delimiter)
            || str_starts_with($content, ' ')
            || str_ends_with($content, ' ')
            || $content === '';

        return $needsForced ? '{' . $delimiter . $content . $delimiter . '}' : $delimiter . $content . $delimiter;
    }

    /**
     * Serialize an inline literal back to `` !`content` `` / `` !`content`{.cls
     * #id} `` (grammar PART 9 §27): a `!` prefix on a verbatim span, mirroring
     * the `$`-math prefix. A trailing attribute block is the ordinary inline
     * attribute block (as a code span carries). renderCode widens the backtick
     * fence when the content holds backticks, so the round-trip is byte-stable
     * and idempotent.
     */
    protected function renderLiteralInline(LiteralInline $node): string
    {
        return '!' . $this->renderCode($node->getContent()) . $this->renderAttrs($node);
    }

    protected function renderCode(string $content): string
    {
        $fence = $this->safeFence($content, 1);

        // Pad exactly where the parser strips, so the strip is reversible and fmt
        // stays idempotent; the padding sits inside the fence, so a trailing
        // attribute block still attaches to the closing run. The parser strips
        // one leading and one trailing space when the content BOTH begins and
        // ends with a space but is NOT entirely spaces (see
        // InlineParser::stripVerbatimPadding), and needs a space around
        // backtick-adjacent content. All-space content must therefore NOT be
        // padded: it is emitted verbatim and read back unchanged. Padding it
        // instead grew the span by two spaces on every fmt pass. One-sided space
        // is left as-is (the parser only strips when both sides are spaces).
        $needsPad = str_starts_with($content, '`')
            || str_ends_with($content, '`')
            || (str_starts_with($content, ' ')
                && str_ends_with($content, ' ')
                && strspn($content, ' ') !== strlen($content));

        return $needsPad
            ? $fence . ' ' . $content . ' ' . $fence
            : $fence . $content . $fence;
    }

    protected function renderAttrs(?Node $node): string
    {
        if ($node === null || $node->getAttributes() === []) {
            return '';
        }
        $attrs = $node->getAttributes();
        $parts = [];
        $seen = [];
        $emit = function (string $slot) use (&$parts, &$seen, $attrs): void {
            if ($slot === '#id') {
                if (!array_key_exists('id', $attrs)) {
                    return;
                }
                $id = $attrs['id'];
                $parts[] = $this->isAttrIdentifier($id) ? '#' . $this->escapeAttrNameValue($id) : 'id=' . $this->quoteAttrValue($id);

                return;
            }
            if ($slot === '.class') {
                foreach (preg_split('/\s+/', trim($attrs['class'] ?? '')) ?: [] as $class) {
                    if ($class !== '') {
                        $parts[] = '.' . $this->escapeAttrNameValue($class);
                    }
                }

                return;
            }
            if (
                isset($seen[$slot])
                || !array_key_exists($slot, $attrs)
                || $slot === 'id'
                || $slot === 'class'
                || $slot === 'data-carve-raw-suffix'
            ) {
                return;
            }
            $seen[$slot] = true;
            $parts[] = $this->escapeAttrKey($slot) . '=' . $this->quoteAttrValue($attrs[$slot]);
        };

        $order = $node->getAttributeOrder();
        if ($order !== []) {
            foreach ($order as $slot) {
                $emit($slot);
            }
            foreach ($attrs as $key => $_value) {
                $emit((string)$key);
            }
        } else {
            $emit('#id');
            $emit('.class');
            foreach ($attrs as $key => $_value) {
                $emit((string)$key);
            }
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    /**
     * Protect a paragraph line that would re-parse as a thematic break.
     *
     * Source indentation is not in the AST, so an indented `---` - a paragraph
     * holding an em dash - is emitted at column 0, where it stops being a
     * paragraph and becomes a thematic break.
     *
     * Text nodes are already covered: the conservative form escapes the
     * hyphens, so the round-trip check sees the difference and picks that form.
     * A smart-punctuation run is not, because its source run is emitted
     * verbatim in BOTH forms - that is the point of the node - so the check
     * never has a difference to act on. Escaping the run in the conservative
     * form does not work either: it would make that form change the document,
     * and the check could then never prefer the minimal one.
     *
     * It INDENTS rather than escapes: escaping would split the run (a leading
     * escaped hyphen plus an en dash) and change the document just as surely,
     * while a single leading space keeps the line a paragraph and keeps the em
     * dash - which is what the source said.
     *
     * The marker is a sentinel rather than a literal space because
     * normalize() trims the document's leading whitespace, which would
     * silently undo the guard whenever the paragraph is the first block.
     */
    protected function guardThematicBreakLines(string $body): string
    {
        if (!str_contains($body, '-')) {
            return $body;
        }

        $lines = explode("\n", $body);
        foreach ($lines as $i => $line) {
            if (preg_match('/^-{3,}[ \t]*$/', $line) === 1) {
                $lines[$i] = "\u{E004}" . $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Write a line block's leading indentation back as ordinary spaces.
     *
     * The parser records that indentation with the U+E000 placeholder - the
     * same sentinel an escaped space uses, so it never collides with a literal
     * nbsp - and normalize() resolves every remaining one to a real nbsp. That
     * is right for an escaped space and wrong here: the source form of a line
     * block's indent is a plain space, and a real nbsp re-parses as literal
     * text rather than as indentation, so the text node came back different
     * (carve#359).
     *
     * Only a run at the START of a line is indentation, so a mid-line escaped
     * space inside a line block still resolves to a real nbsp. The leading run
     * goes to the verbatim scheme, which restores plain spaces after
     * normalize() has run.
     */
    protected function resolveIndentPlaceholder(string $text): string
    {
        if ($this->inLineBlock === 0) {
            return $text;
        }

        return (string)preg_replace_callback(
            '/^\x{E000}+/mu',
            static fn (array $m): string => str_repeat("\u{E001}", (int)mb_strlen($m[0], 'UTF-8')),
            $text,
        );
    }

    protected function normalize(string $text): string
    {
        // The placeholder means the author wrote an ESCAPED SPACE, so the writer
        // says that again. Resolving it to a literal no-break space instead lost
        // the distinction the parser draws: `10\ kg` came back carrying U+00A0,
        // which re-parses as a literal nbsp rather than as an escape, so the text
        // node differed even though the HTML did not (carve#352, corpus
        // 29-non-breaking-space; carve-js fixed this in carve#369 and carve-rs in
        // carve-rs#310).
        //
        // This runs AFTER escaping, so the backslash it introduces is not seen by
        // escapeText and cannot be doubled. A line block's leading indent is
        // already routed through the verbatim scheme by resolveIndentPlaceholder
        // before this point, so what is left here is an escaped space.
        $text = str_replace("\u{E000}", '\ ', $text);
        $lines = explode("\n", $this->trimNonNbsp($text));
        foreach ($lines as $i => $line) {
            // Strip a line's trailing whitespace only where it cannot be
            // content. At the end of a paragraph the parser drops it too, so
            // the writer must; before a SOFT BREAK the parser keeps it, and
            // stripping it there changed the rendered output (carve#359).
            // A line whose successor is blank ends its block; one followed by
            // more text is mid-paragraph.
            // A line is blank when it holds only whitespace, counting the
            // non-breaking space: PHP trim() leaves NBSP in place where JS
            // trim() removes it, and the two writers must agree on which lines
            // end a block.
            // A line whose only content is ASCII space or tab is emitted EMPTY,
            // wherever it sits (PART 11 section 7). Editors and CI that strip
            // trailing whitespace rewrite such a line, so `fmt` would report a
            // diff on a file nobody edited (carve#375). NBSP is excluded because
            // it is content the author wrote, and verbatim content is still
            // sentinel-protected here, so three spaces inside a code block are
            // not reachable by this and stay intact.
            if ($line !== '' && trim($line, " \t") === '') {
                $lines[$i] = '';

                continue;
            }
            $next = $lines[$i + 1] ?? null;
            if ($next !== null && preg_replace('/[\s\x{00A0}]+/u', '', $next) !== '') {
                continue;
            }
            $lines[$i] = (string)preg_replace('/[^\S' . "\u{00A0}" . ']+$/u', '', $line);
        }
        $text = implode("\n", $lines);
        $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);

        return $this->restoreVerbatim($this->trimNonNbsp($text)) . "\n";
    }

    /**
     * Whole-document normalization (trailing-whitespace strip, blank-line
     * collapsing) must not reach inside verbatim content - code blocks, raw
     * blocks, frontmatter, and block comments reproduce their content
     * byte-exact (carve-js issue 340). Sentinel-encode the vulnerable bytes
     * before the content joins the document string; normalize() restores
     * them at the end. U+E000 is already the NBSP sentinel; U+E001..U+E003
     * extend the scheme.
     */
    protected function protectVerbatim(string $content): string
    {
        $content = (string)preg_replace_callback(
            '/[ \t]+(?=\n|$)/',
            static fn (array $m): string => strtr($m[0], [' ' => "\u{E001}", "\t" => "\u{E002}"]),
            $content,
        );
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if ($line === '') {
                $lines[$i] = "\u{E003}";
            }
        }

        return implode("\n", $lines);
    }

    protected function restoreVerbatim(string $text): string
    {
        $result = strtr($text, ["\u{E001}" => ' ', "\u{E002}" => "\t", "\u{E003}" => '']);

        // U+E004 marks a paragraph line that must not begin at column 0. It
        // resolves AFTER normalize()'s trims, which would otherwise strip a
        // plain leading space when the paragraph is the document's first block.
        return str_replace("\u{E004}", ' ', $result);
    }

    protected function trimNonNbsp(string $text): string
    {
        return (string)preg_replace('/^[^\S' . "\u{00A0}" . ']+|[^\S' . "\u{00A0}" . ']+$/u', '', $text);
    }

    protected function trimEndNonNbsp(string $text): string
    {
        return (string)preg_replace('/[^\S' . "\u{00A0}" . ']+$/u', '', $text);
    }

    protected function safeFence(string $content, int $min): string
    {
        preg_match_all('/`+/', $content, $matches);
        $longest = 0;
        foreach ($matches[0] as $match) {
            $longest = max($longest, strlen($match));
        }

        return str_repeat('`', max($min, $longest + 1));
    }

    protected function lastBoundary(?Node $node): string
    {
        $text = $this->inlineBoundaryText($node);

        return $text === '' ? '' : substr($text, -1);
    }

    protected function firstBoundary(?Node $node): string
    {
        $text = $this->inlineBoundaryText($node);

        return $text === '' ? '' : $text[0];
    }

    protected function inlineBoundaryText(?Node $node): string
    {
        if ($node instanceof Text) {
            return $node->getContent();
        }
        if ($node instanceof EscapedText) {
            return $node->getContent();
        }
        if ($node instanceof Code) {
            return $node->getContent();
        }

        return '';
    }

    protected function plainInlineText(Node $node): string
    {
        $out = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $out .= $child->getContent();
            } elseif ($child instanceof EscapedText) {
                $out .= $child->getContent();
            } else {
                $out .= $this->plainInlineText($child);
            }
        }

        return $out;
    }

    protected function alignMarker(string $align): string
    {
        return match ($align) {
            TableCell::ALIGN_LEFT => '<',
            TableCell::ALIGN_RIGHT => '>',
            TableCell::ALIGN_CENTER => '~',
            default => '',
        };
    }

    protected function escapeText(string $text): string
    {
        $text = (string)preg_replace('/[\x00-\x08\x0B-\x1F\x7F-\x9F]/u', '', $text);
        if (preg_match('/^\[\^[^\]\n]+\]$/u', $text) === 1) {
            return $text;
        }

        $pattern = $this->escapeMode === self::ESCAPE_MODE_MINIMAL
            ? '/([\\\\`"\'^])/'
            : '/([\\\\`*_{}\[\]()#+\-.!~^\/<>@%|=:;"\'])/';

        $minimal = $this->escapeMode === self::ESCAPE_MODE_MINIMAL;

        return (string)preg_replace_callback(
            $pattern,
            static function (array $match) use ($text, $minimal): string {
                $char = $match[1][0];
                $offset = $match[1][1];
                if ($minimal && $char === '^' && $offset > 0 && $text[$offset - 1] === '[') {
                    return '^';
                }

                return '\\' . $char;
            },
            $text,
            flags: PREG_OFFSET_CAPTURE,
        );
    }

    protected function escapeImageAlt(string $text): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $text);
    }

    protected function escapeDestination(string $text): string
    {
        $text = (string)preg_replace('/^[\x00-\x20\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+/u', '', $text);
        $scheme = null;
        if (preg_match('/^[\x00-\x20\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]*([a-zA-Z][a-zA-Z0-9+.-]*):/u', $text, $m) === 1) {
            $scheme = strtolower($m[1]);
        }
        $sanitizeBlank = $scheme !== null && in_array($scheme, ['javascript', 'vbscript', 'data', 'file'], true);
        // Whitespace is percent-encoded (it would otherwise end the
        // destination). A parenthesis is escaped only when it is UNBALANCED: a
        // balanced pair re-parses as itself, so leaving it bare is both the
        // minimal escaping PART 11 section 4 asks for and what keeps the common
        // URL readable. A backslash is escaped only in front of the three
        // characters the destination scan treats as escapes, so backslashes
        // elsewhere in a URL stay verbatim.
        if (!$sanitizeBlank) {
            $text = $this->escapeDestinationEscapes($text);
        }
        $text = (string)preg_replace_callback('/\s/u', static fn (array $m): string => $m[0] === ' ' ? '%20' : sprintf('%%%02X', ord($m[0])), $text);

        return (string)preg_replace_callback('/[()]/', static fn (array $m): string => $sanitizeBlank ? ($m[0] === '(' ? '%28' : '%29') : $m[0], $text);
    }

    /**
     * Backslash-escape exactly what the destination scan would otherwise read
     * differently: a parenthesis with no partner, and a backslash sitting in
     * front of one of the three escapable characters.
     */
    protected function escapeDestinationEscapes(string $text): string
    {
        $length = strlen($text);
        $openers = [];
        $marked = [];
        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === '(') {
                $openers[] = $i;
            } elseif ($text[$i] === ')') {
                if ($openers === []) {
                    $marked[$i] = true;
                } else {
                    array_pop($openers);
                }
            }
        }
        foreach ($openers as $i) {
            $marked[$i] = true;
        }

        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            $escapable = $char === '\\'
                && $i + 1 < $length
                && in_array($text[$i + 1], ['(', ')', '\\'], true);
            $out .= isset($marked[$i]) || $escapable ? '\\' . $char : $char;
        }

        return $out;
    }

    protected function escapeQuoted(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }

    protected function escapeBracketText(string $text): string
    {
        return str_replace(['\\', ']'], ['\\\\', '\\]'], $text);
    }

    protected function escapeFootnoteLabel(string $text): string
    {
        return $this->escapeBracketText($text);
    }

    protected function escapeIdentifier(string $text): string
    {
        return (string)preg_replace('/[^\w-]/u', '', $text);
    }

    /**
     * A symbol name may contain `+` and `-` (so `:+1:` / `:-1:` round-trip),
     * unlike an extension identifier.
     */
    protected function escapeSymbolName(string $text): string
    {
        return (string)preg_replace('/[^\w+-]/u', '', $text);
    }

    protected function escapeName(string $text): string
    {
        return trim((string)preg_replace('/[^\w.-]/u', '', $text), '.');
    }

    protected function escapeFormat(string $text): string
    {
        $safe = (string)preg_replace('/[^\w-]/u', '', $text);

        return $safe === '' ? 'text' : $safe;
    }

    protected function escapeFenceToken(string $text): string
    {
        $token = preg_split('/\s/u', $text, 2)[0] ?? '';

        return str_replace('`', '', $token);
    }

    protected function escapeAttrKey(string $text): string
    {
        $safe = (string)preg_replace('/^[^a-zA-Z_]+|[^\w-]/u', '', $text);

        return $safe === '' ? 'x' : $safe;
    }

    protected function escapeAttrNameValue(string $text): string
    {
        return (string)preg_replace('/[^\w-]/u', '-', $text);
    }

    protected function isAttrIdentifier(string $text): bool
    {
        return preg_match('/^[A-Za-z_][\w-]*$/', $text) === 1;
    }

    protected function quoteAttrValue(string $value): string
    {
        if (preg_match('/^[^\s"\'{}]+$/u', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    protected function escapeCriticText(string $text): string
    {
        return str_replace(['\\', '{', '}'], ['\\\\', '\\{', '\\}'], $text);
    }

    protected function escapeAutolinkHref(string $text): string
    {
        return str_replace(['\\', '<', '>'], ['\\\\', '\\<', '\\>'], $text);
    }

    protected function escapeCrossrefTarget(string $text): string
    {
        return str_replace(['\\', '>'], ['\\\\', '\\>'], $text);
    }
}
