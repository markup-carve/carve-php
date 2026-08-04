<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Extension\Frontmatter;
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
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use ReflectionObject;
use Throwable;

/**
 * Renders AST back to canonical Carve source.
 */
class CarveRenderer implements RendererInterface
{
    /**
     * The writer's recursion bound, and it must sit ABOVE the parser's.
     *
     * The guard is for hand-built ASTs, which nest without limit. It is not a
     * language rule, and the parser's own number made it one: a document nested
     * at exactly MAX_NESTING_DEPTH parses fine, and the writer then emitted
     * nothing for the innermost block, deleting content with no error and
     * breaking PART 11's semantic invariant at the boundary (issue 517).
     *
     * A parsed tree is at least one block level deeper than the containers that
     * produced it - the paragraph inside the innermost one - so the bound needs
     * slack over the cap rather than equality with it. The HTML, Markdown,
     * plain-text and ANSI renderers already sit above the cap.
     *
     * @var int
     */
    public const MAX_RENDER_DEPTH = BlockParser::MAX_NESTING_DEPTH + 32;

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

    /**
     * Inside a table cell, where a leading `^` cannot open a caption: a caption
     * marker is a BLOCK line, and a cell's content is not one.
     */
    protected int $tableCellDepth = 0;

    protected int $listDepth = 0;

    protected int $colonFenceDepth = 0;

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
        $previousColonFenceDepth = $this->colonFenceDepth;
        $this->escapeMode = $escapeMode;
        $this->colonFenceDepth = 0;
        try {
            return $this->renderDocumentParts($document);
        } finally {
            $this->escapeMode = $previousEscapeMode;
            $this->colonFenceDepth = $previousColonFenceDepth;
        }
    }

    protected function renderDocumentParts(Document $document): string
    {
        $parts = [];
        $abbrs = [];
        // Every authored definition, in source order. A term defined twice is
        // two lines the author wrote; which one wins is resolution (PART 9R)
        // and the formatter does not resolve.
        foreach ($document->getAbbreviationDefinitions() as $definition) {
            $abbrs[] = '*[' . $this->escapeBracketText($definition['abbr']) . ']: '
                . str_replace("\n", ' ', $definition['expansion']);
        }
        // One BLANK line between definitions, matching carve-js and carve-rs.
        // Joining them with a single newline round-trips (the next line is a
        // definition either way), so nothing but a byte comparison across
        // engines could see it - and no corpus document had two definitions.
        if ($abbrs !== [] && $document->hasAbbreviationsBeforeBody()) {
            $parts[] = implode("\n\n", $abbrs);
        }
        $body = $this->renderBlocks($document->getChildren());
        if ($body !== '') {
            $parts[] = $body;
        }
        if ($abbrs !== [] && !$document->hasAbbreviationsBeforeBody()) {
            $parts[] = implode("\n\n", $abbrs);
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
     *
     * @throws \MarkupCarve\Carve\Exception\RenderDepthExceededException
     */
    protected function renderBlocks(array $blocks): string
    {
        if ($this->blockDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'Carve');
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
            $node instanceof Heading => $withAttrs(str_repeat('#', $node->getLevel()) . ' ' . $this->collapseBreaks($this->trimNonNbsp($this->renderInlines($node->getChildren())))),
            // A LONE image is a block node, not a paragraph wrapping one (the
            // `image` node's own description in the AST vocabulary). The block
            // match had no arm for it, so it fell through and lost the blank
            // line that separates it from the next block (#633).
            $node instanceof Image => $this->renderImage($node),
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
        $inner = $this->withResetColonFenceDepth(fn (): string => $this->renderBlocks($node->getChildren()));
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
            $bareDot = $node->getListType() === ListBlock::TYPE_ORDERED
                && $node->hasBareMarker()
                && $node->getStart() === 1
                && $node->getStyle() === null
                && $delim === '.';
            $children = array_values(array_filter($node->getChildren(), static fn (Node $child): bool => $child instanceof ListItem));
            foreach ($children as $index => $item) {
                $indent = str_repeat('  ', $this->listDepth - 1);
                if ($node->getListType() === ListBlock::TYPE_ORDERED) {
                    $prefix = $bareDot
                        ? '. '
                        : $this->orderedMarker($counter, $node->getStyle()) . $delim . ' ';
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
                    // A BLANK continuation line stays blank: indenting it emits a
                    // whitespace-only line, which the writer never may
                    // (NoWhitespaceOnlyLineTest).
                    //
                    // The U+E003 form is the one that actually reaches here. A
                    // blank line inside a fenced code block in a list item is
                    // verbatim content, so protectVerbatim() encodes it to keep
                    // the document-wide trim off it; indenting that placeholder
                    // left `  ` behind once restoreVerbatim() mapped it back to
                    // nothing. The blank is content, but it is BLANK - the indent
                    // was trailing whitespace the source never had.
                    //
                    // A code line that genuinely holds spaces arrives as those
                    // spaces (U+E001), not as this placeholder, and still indents.
                    $blank = $line === '' || $line === "\u{E003}";
                    $out .= $blank ? $line . "\n" : $indent . $continuation . $line . "\n";
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
            return $this->withResetColonFenceDepth(fn (): string => $this->renderBlocks($children));
        }

        // A tight item with more than one child block must not gain a blank line
        // between its blocks - a blank there loosens the item on re-parse, so
        // toHtml(fmt(x)) would diverge from toHtml(x) (carve corpus 162).
        // Adjacent blocks are joined with a single newline instead, matching
        // the canonical carve-js writer.
        if ($this->blockDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'Carve');
        }

        $previousColonFenceDepth = $this->colonFenceDepth;
        $this->colonFenceDepth = 0;
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
            $this->colonFenceDepth = $previousColonFenceDepth;
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
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);

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
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);

        return $fence . ' ' . $kind . $titlePart . $label . "\n" . $body . "\n" . $fence;
    }

    protected function renderAdmonition(Div $node): string
    {
        $kind = $this->admonitionKind($node) ?? 'note';
        $title = $node->getHeader();
        $titlePart = is_string($title) ? ' "' . $this->escapeQuoted($title) . '"' : '';
        $label = $node->getLabel() === null ? '' : ' [' . $this->escapeBracketText($node->getLabel()) . ']';
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);

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
            $body = $this->renderColonFenceBody($node);
        } finally {
            $this->inLineBlock--;
        }

        // `::: |` is the line-block opener (grammar PART 3, line_block_open).
        // Emitting a bare `:::` and tagging the node with a `line-block` class
        // instead re-parsed as an ordinary div, so the node type changed across
        // a format round trip and `parse(fmt(x)) == parse(x)` did not hold.
        $fence = $this->colonFenceFor($node);

        return $fence . " |\n" . $body . "\n" . $fence;
    }

    protected function colonFenceFor(Node $node): string
    {
        return str_repeat(':', 3 + $this->colonFenceDepth);
    }

    protected function renderColonFenceBody(Node $node): string
    {
        $this->colonFenceDepth++;
        try {
            return $this->renderBlocks($node->getChildren());
        } finally {
            $this->colonFenceDepth--;
        }
    }

    protected function withResetColonFenceDepth(callable $render): string
    {
        $previous = $this->colonFenceDepth;
        $this->colonFenceDepth = 0;
        try {
            return $render();
        } finally {
            $this->colonFenceDepth = $previous;
        }
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        $out = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof DefinitionTerm) {
                $out[] = ':: ' . $this->renderInlines($child->getChildren());
            } elseif ($child instanceof DefinitionDescription) {
                $body = $this->withResetColonFenceDepth(fn (): string => $this->renderBlocks($child->getChildren()));
                $lines = explode("\n", $this->trimNonNbsp($body));
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
        // Every row already carries one cell per grid column, including a
        // placeholder for each `^`/`<` span marker (carve-php#527), so the
        // column count and each row's own cells are read directly - no more
        // reconstructing covered columns from a colspan/rowspan count.
        $columns = 0;
        foreach ($tableRows as $row) {
            $columns = max($columns, count(array_filter(
                $row->getChildren(),
                static fn (Node $child): bool => $child instanceof TableCell,
            )));
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
                if ($cell instanceof TableCell) {
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
        foreach ($tableRows as $rowIndex => $row) {
            $cells = [];
            $column = 0;
            foreach ($row->getChildren() as $cell) {
                if (!$cell instanceof TableCell) {
                    continue;
                }
                // In the delimiter form the promoted row is written as ordinary
                // data cells - the row after it is what makes them headers.
                $markHeader = !($needsDelimiter && $rowIndex === 0);
                $inherited = $headerRow
                    && $rowIndex > 0
                    && ($headerAligns[$column] ?? null) === $cell->getAlignment();
                $cells[] = $this->renderTableCell($cell, $markHeader, $inherited);
                $column++;
            }
            // Pad a row that genuinely has fewer cells than the widest row (an
            // AST built by hand rather than parsed) with blank cells, matching
            // the previous fallback for a missing source cell.
            $cellCount = count($cells);
            while ($cellCount < $columns) {
                $cells[] = ['text' => '', 'tight' => false];
                $cellCount++;
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

        $this->tableCellDepth++;
        try {
            $content = $this->renderInlines($cell->getChildren());
        } finally {
            $this->tableCellDepth--;
        }

        return ['text' => $prefix . $content, 'tight' => $prefix !== ''];
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
        $recorded = $node->getFenceLength();
        if ($recorded === null && !str_contains($content, "\n")) {
            return '%% ' . $content;
        }

        // A fence must be WIDER than any run of `%` inside it, whatever width
        // the author used - a nested `%%%` inside a `%%%` block closes it early.
        // The recorded width is a floor, not the answer, so it is widened here
        // rather than trusted: a document decoded from a serialized AST carries
        // no width at all (PART 12 §3 - the wire says `block`, which is what a
        // consumer asks; the width is a writer's concern).
        preg_match_all('/%+/', $content, $matches);
        $longest = 0;
        foreach ($matches[0] as $match) {
            $longest = max($longest, strlen($match));
        }
        $fence = str_repeat('%', max(3, $recorded ?? 0, $longest + 1));

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
     *
     * @throws \MarkupCarve\Carve\Exception\RenderDepthExceededException
     */
    protected function renderInlines(array $nodes): string
    {
        if ($this->inlineDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'Carve');
        }
        $this->inlineDepth++;
        try {
            $out = '';
            $count = count($nodes);
            for ($i = 0; $i < $count; $i++) {
                $node = $nodes[$i];
                if ($node instanceof InlineNode) {
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
        // An unresolved reference renders as the source the author
        // wrote, never as a link (PART 12 section 3a).
        $rawReference = UnresolvedReference::sourceOf($node);

        return match (true) {
            $node instanceof Text => $this->escapeText(
                $this->resolveIndentPlaceholder($node->getContent()),
                // Does this node's first character sit at the start of a block
                // line? Only there can `^ ` be read back as a caption marker.
                ($prevChar === '' || $prevChar === "\n") && $this->tableCellDepth === 0,
            ) . (string)$node->getAttribute('data-carve-raw-suffix'),
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
            $rawReference !== null => $rawReference,
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

        // A reference RESOLVED FROM A HEADING is written back as the reference
        // the author wrote (PART 11 R1, carve#478). There is no `[label]: url`
        // line for it, so `[getting started][]` is the only record of the
        // authored form - resolving it to `[getting started](#Getting-Started)`
        // bakes a generated id into the source on every `fmt` pass, and both
        // other engines keep the reference (carve-rs#435, carve-js#526).
        //
        // An EXPLICIT definition still writes the resolved link: there the
        // definition line is dropped either way, so the authored pair is not
        // reproducible from the tree and all three engines agree on the inline
        // form.
        if ($node->isFromHeadingReference()) {
            // The AUTHORED source. `ref` now holds the real label rather than
            // `''` for the collapsed form (PART 12 §3a, carve#597), so building
            // the reference from it would write `[text][text]` where the author
            // wrote `[text][]`. `rawRef` is that source verbatim.
            $raw = $node->getRawReferenceLabel();
            if ($raw !== null) {
                return $raw . $this->renderAttrs($node);
            }

            return '[' . $text . '][' . $node->getReferenceLabel() . ']' . $this->renderAttrs($node);
        }

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
        if (($node->getDestination() ?? '') === '') {
            // A bare `@name` has nowhere to hang an attribute: the parser leaves
            // a trailing `{.x}` outside the node, so this spelling cannot carry
            // one back. Writing it anyway dropped the attribute silently, which
            // is the one outcome worth refusing (carve-php#567) - the link form
            // is unavailable too, since there is no destination to put in it.
            //
            // The bracketed form keeps it. `[@alice]{#x}` re-parses as a span
            // AROUND the mention rather than a mention carrying the attribute,
            // so the HTML gains a wrapper `<span>`. That is the fallback here;
            // `writeStaticMentionExactly()` reproduces the rendered form instead
            // wherever it can, which is every case the bridge produces. Only a
            // programmatically built tree or the ProseMirror bridge can reach
            // this state - the parser never produces it - so no parsed document
            // changes.
            $bare = $this->plainInlineText($node);
            if ($node->getAttributes() === []) {
                return $bare;
            }

            $exact = $this->writeStaticMentionExactly($node);
            if ($exact !== null) {
                return $exact;
            }

            // The PLAIN label inside the brackets, not the escaped inlines:
            // `renderInlines()` writes `\@alice`, and an escaped sigil re-parses
            // as ordinary text, so the wrapper would keep the attribute and lose
            // the mention. Anything that is not a flat name has no unescaped
            // spelling, and keeping the text is then worth more than the class.
            $sigilled = str_starts_with($bare, '#') ? '#' : '@';
            $plain = str_starts_with($bare, $sigilled) ? substr($bare, 1) : $bare;
            $inner = $this->isFlatText($node) && $this->isMentionName($plain)
                ? $bare
                : $this->renderInlines($node->getChildren());

            return '[' . $inner . ']' . $this->renderAttrs($node);
        }

        // The plain text, not the rendered inlines: a name is tested against
        // what the author wrote, and `renderInlines()` has already escaped the
        // dot in `john.doe` into `john\.doe`, which is not a name.
        $label = $this->plainInlineText($node);
        $sigil = str_starts_with($label, '#') ? '#' : '@';
        // ONE sigil, not a run of them: `ltrim($label, '@')` read `@@user` as
        // the name `user` and wrote back one `@` fewer than it was handed.
        $name = str_starts_with($label, $sigil) ? substr($label, 1) : $label;

        // A mention name carries no escape, so a label holding anything else
        // has no spelling in this syntax. It degrades to the link form rather
        // than to a name the author did not write: `@o'brien` would have to
        // become `@obrien`, which is a DIFFERENT mention, silently.
        //
        // An attribute and nested markup have no spelling either, and were
        // dropped rather than deleted: a trailing `{.x}` after a mention stays
        // literal text (the parser leaves it outside the node), and `@*user*` is
        // not a mention at all, so a mention carrying either one lost it with a
        // perfectly valid name to point at.
        if (!$this->isMentionName($name) || $node->getAttributes() !== [] || !$this->isFlatText($node)) {
            return $this->renderMentionAsLink($node);
        }

        return $sigil . $name;
    }

    /**
     * Whether a label can be spelled as a mention or tag name.
     *
     * `mention_name = name_word, {'.', name_word}`, dots interior-only. The
     * character set is the one {@see \MarkupCarve\Carve\Extension\MentionsExtension}
     * actually accepts, which is ASCII: writing a name this engine's own parser
     * would then read differently is the bug being fixed, not a fix. (The
     * grammar's `letter` reads wider than that, but a writer has to target the
     * reader that exists.)
     */
    protected function isMentionName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/', $name) === 1;
    }

    /**
     * Is the node's content a plain run of text, with no markup inside it?
     */
    protected function isFlatText(Mention $node): bool
    {
        foreach ($node->getChildren() as $child) {
            if (!$child instanceof Text && !$child instanceof EscapedText) {
                return false;
            }
        }

        return true;
    }

    /**
     * A destination-less mention written so the source RENDERS as the node did.
     *
     * With no URL template a mention is `<span class="…"><strong>…</strong>
     * </span>` plus its own attributes - pinned by the corpus, so it is the
     * target, not a choice. Three pieces reproduce it exactly:
     *
     * - `*…*` supplies the `<strong>`. Without it the span holds bare text.
     * - the label is ESCAPED, so `\@alice` stays text rather than re-parsing as
     *   a mention inside the span, which is what put a second `<span>` in the
     *   output.
     * - the class is written FIRST. A span renders its attributes in source
     *   order, so `{#x .mention}` yields `<span id="x" class="mention">` and
     *   fails on order alone.
     *
     * Returns null where no spelling reaches the rendered form, and the caller
     * keeps the bracketed fallback: markup inside the label needs a doubled
     * `*` delimiter that reads as literal asterisks, a label padded with
     * whitespace puts a space beside a delimiter that then does not open, and a
     * mention with no css class renders `class=""`, which is not worth
     * spelling out.
     *
     * A `class` ATTRIBUTE is written after the structural class: the HTML
     * renderer merges it into the same leading class slot, so the authored class
     * has to be present here for `toHtml(fmt(x)) == toHtml(x)`.
     */
    protected function writeStaticMentionExactly(Mention $node): ?string
    {
        if ($node->getCssClass() === '' || !$this->isFlatText($node)) {
            return null;
        }

        $label = $this->renderInlines($node->getChildren());
        if ($label === '' || $label !== trim($label)) {
            // An emphasis delimiter needs a non-space beside it, so a label
            // padded with whitespace writes a pair of literal asterisks into the
            // span instead of a strong - and `[**]` is literal for the same
            // reason. Both decline rather than emit source that renders
            // differently, which is the one outcome this method exists to avoid.
            return null;
        }

        // Everything in the node's own order, via the normal attribute writer -
        // so an author class stays `.class`, an id stays `#id`, and a key/value
        // stays one.
        $rest = clone $node;
        $written = $this->renderAttrs($rest);

        return '[*' . $label . '*]{.' . $this->escapeAttrNameValue($node->getCssClass())
            . ($written === '' ? '' : ' ' . substr($written, 1, -1)) . '}';
    }

    /**
     * The nearest construct that holds everything a mention does: the label,
     * the destination and the class, rendering the same anchor.
     *
     * A CLONE, not a fresh node the children are appended to: `appendChild()`
     * reparents, so building the link that way left every child of the mention
     * pointing at a throwaway parent once the document had been written. The
     * renderer is handed a tree it does not own.
     */
    protected function renderMentionAsLink(Mention $node): string
    {
        $link = clone $node;
        if ($node->getCssClass() !== '') {
            $link->addClass($node->getCssClass());
        }

        return $this->renderLink($link);
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
     * Write a line block's preserved whitespace back as ordinary spaces.
     *
     * The parser records it with the U+E000 placeholder - the same sentinel an
     * escaped space uses, so it never collides with a literal nbsp - and
     * normalize() resolves every remaining one to a real nbsp. That is right
     * for an escaped space and wrong here: the source form of a line block's
     * layout is plain spaces, and a real nbsp re-parses as literal text rather
     * than as layout, so the text node came back different (carve#359).
     *
     * The runs handed to the verbatim scheme - which restores plain spaces
     * after normalize() has run - are exactly the ones the parser reproduces
     * from plain spaces (PART 9 §23, carve#487): a LEADING run of any width,
     * and a medial or trailing run of TWO OR MORE. A lone medial sentinel can
     * then only have come from an escaped space, so `a\ b` still round-trips
     * as written. Two adjacent escaped spaces are the one form that changes -
     * `a\ \ b` is written back as two plain spaces - because inside a line
     * block the two are the same document: both parse to the same pair of
     * sentinels.
     */
    protected function resolveIndentPlaceholder(string $text): string
    {
        if ($this->inLineBlock === 0) {
            return $text;
        }

        return (string)preg_replace_callback(
            '/(?:^\x{E000}+)|\x{E000}{2,}/mu',
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

    /**
     * Fold every line break in $text (a hard break's marker included) to a
     * single space, then trim.
     *
     * A heading is SINGLE-LINE (PART 2), so its text must not contain a
     * newline: writing one would end the heading and re-parse the remainder as
     * a following block, moving text out of the title. No parse builds such a
     * heading, but PART 12 lets an ingested AST put any inline in one, break
     * nodes included. Only an ODD run of backslashes before the newline is a
     * hard break's marker; an even run is literal backslashes that happen to
     * end the line, and dropping one there would eat the escape. Matches
     * carve-js and carve-rs.
     */
    protected function collapseBreaks(string $text): string
    {
        $collapsed = preg_replace_callback(
            '/(\\\\*)\\n[ \\t]*/',
            static fn (array $m): string => (strlen($m[1]) % 2 === 1 ? substr($m[1], 1) : $m[1]) . ' ',
            $text,
        );

        return $this->trimNonNbsp((string)$collapsed);
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

    protected function escapeText(string $text, bool $opensBlockLine = false): string
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
            static function (array $match) use ($text, $minimal, $opensBlockLine): string {
                $char = $match[1][0];
                $offset = $match[1][1];
                if ($char === '^' && self::caretOpensACaption($text, $offset, $opensBlockLine)) {
                    // Forced in BOTH modes - see the note on the method.
                    return '\\^';
                }
                if ($minimal && $char === '^' && !self::caretOpensAConstruct($text, $offset)) {
                    return '^';
                }
                // A COLON only opens something at the start of a line - `::`
                // opens a definition term, `:::` a div, and a caption's
                // `^ Figure #:` is read from the marker. Mid-line it is
                // ordinary punctuation, and PART 11 §2 escapes a character
                // only where omitting it would change the re-parse. Escaping
                // every colon put `\:` in `\^ Figure 1\: moon`, where the
                // caret is already escaped so nothing downstream reads the
                // colon at all (carve-php#743).
                if ($char === ':' && !self::opensLine($text, $offset)) {
                    return ':';
                }

                return '\\' . $char;
            },
            $text,
            flags: PREG_OFFSET_CAPTURE,
        );
    }

    /**
     * Is this offset at the start of a line within the node's text?
     *
     * A construct opens at a line start; mid-line the same character is
     * punctuation. The node's own first character counts, because a text node
     * that begins a paragraph begins a line.
     *
     * @param string $text
     * @param int $offset
     */
    private static function opensLine(string $text, int $offset): bool
    {
        for ($i = $offset - 1; $i >= 0; $i--) {
            $char = $text[$i];
            if ($char === "\n") {
                return true;
            }
            if ($char !== ' ' && $char !== "\t") {
                return false;
            }
        }

        return true;
    }

    /**
     * Would a BARE `^` at this offset let a construct form?
     *
     * PART 11 §2 escapes a character IF AND ONLY IF omitting the escape would
     * change the re-parsed AST, and a lone `^` no longer opens anything: bare
     * `^sup^` was removed in favour of the braced `{^x^}`. So the caret needs
     * an escape only where it abuts one of the two shapes that still read it -
     * the inline footnote `^[…]` and the braced superscript's own delimiters -
     * and `}^p` is written bare, which is what carve#581 asks for.
     *
     * @param string $text
     * @param int $offset
     */

    /**
     * Is this caret a CAPTION MARKER - `^` plus a space at the start of a block
     * line?
     *
     * Forced in both escape modes, unlike every other candidate. The
     * minimal/conservative decision is per DOCUMENT: rendered bare in the
     * minimal pass the marker becomes a caption, the two passes differ, and the
     * whole document escalates to conservative - which then escapes every
     * candidate in it, including characters that needed nothing. That produced
     * `\^ Figure 1\: moon` for corpus 158-indented-image-and-caption-stay-
     * literal, where the colon escape changes no parse in any engine
     * (carve-php#743).
     *
     * `^sup^` is not this shape: superscript is braced-only and a caption needs
     * the space, so it stays with caretOpensAConstruct() below and is written
     * bare.
     *
     * @param string $text
     * @param int $offset
     * @param bool $opensBlockLine Whether offset 0 of $text is a block-line start.
     */
    private static function caretOpensACaption(string $text, int $offset, bool $opensBlockLine): bool
    {
        $next = $text[$offset + 1] ?? '';
        if ($next !== ' ' && $next !== "\t") {
            return false;
        }

        return $offset === 0 ? $opensBlockLine : ($text[$offset - 1] ?? '') === "\n";
    }

    private static function caretOpensAConstruct(string $text, int $offset): bool
    {
        $next = $text[$offset + 1] ?? '';
        // `^[` opens an inline footnote.
        if ($next === '[') {
            return true;
        }

        // `{^` opens a braced superscript and `^}` closes one. Either half
        // bare would let the pair form around content it does not own.
        return ($text[$offset - 1] ?? '') === '{' || $next === '}';
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
