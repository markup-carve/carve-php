<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Extension\Frontmatter;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CitationDefinition;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
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
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;
use MarkupCarve\Carve\Util\StringUtil;
use ReflectionObject;
use Throwable;

/**
 * Renders AST back to canonical Carve source.
 */
class CarveRenderer implements RendererInterface
{
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

    /**
     * Inside an inline note's content, where `^[` opens nothing.
     *
     * PART 9 §16: a note's content is parsed with footnote recognition
     * DISABLED, at every depth, in both directions. So the inner spelling is
     * ordinary text there and the writer has nothing to escape
     * (markup-carve/carve#1191).
     */
    protected int $inlineNoteDepth = 0;

    protected int $listDepth = 0;

    protected int $colonFenceDepth = 0;

    protected string $escapeMode = self::ESCAPE_MODE_CONSERVATIVE;

    /**
     * The four writer-only sentinels, chosen per render from code points the
     * DOCUMENT does not contain.
     *
     * They used to be the fixed U+E001..U+E004, restored unconditionally, so an
     * AUTHORED occurrence was indistinguishable from one this renderer inserted:
     * U+E001 and U+E004 came back as a space, U+E002 as a tab, U+E003 as nothing
     * at all. Three of those are worse than a deletion, because a space or a tab
     * is plausible content and the diff reads as whitespace (carve#678). It was
     * never limited to code blocks - a paragraph holding one was corrupted too.
     *
     * Escaping the authored occurrences cannot fix it: any escape needs a
     * reserved character, and that character has the same collision. Choosing
     * characters the document does not use cannot collide by construction, and
     * cannot run out - the BMP private-use area alone has 6400 code points.
     *
     * U+E000 is not one of the chosen characters - it is the parser's in-band
     * marker for a non-breaking space, shared with the HTML, plain, ANSI and
     * Markdown renderers, so an authored U+E000 is already conflated with a
     * parsed nbsp before this renderer runs. That is the other half of carve#678.
     *
     * Slot 4 CARRIES an authored U+E000 through normalization, which would
     * otherwise rewrite it to `\ ` - correct outside verbatim content and wrong
     * inside it, where a backslash is literal (carve-php#829). It is a carrier,
     * not a replacement for the marker: restoreVerbatim() puts the character
     * back.
     *
     * SLOT 5 IS THE MARKER-COLUMN TAG, and it is in this run rather than beside
     * it. It used to be the fixed U+E010, on the reasoning that a tag inside the
     * re-picked run would be rewritten underneath itself - which is true, and is
     * why it belongs IN the run instead of next to it. Outside it, the tag was
     * a fixed sentinel with exactly the collision this array exists to remove:
     * an authored U+E010 opening a list item's continuation line was eaten AND
     * the paragraph was written back at column 0, out of the item it was in.
     * That is worse than the four carve#678 found, because it changes the
     * document's BLOCK STRUCTURE rather than a character
     * (markup-carve/carve-php#1087).
     *
     * A run of six also cannot collide with itself: the picker returns six
     * DISTINCT consecutive code points, so the tag differs from every carrier by
     * construction rather than by being parked at a hopefully-unused address.
     *
     * @var array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    protected array $verbatimSentinels = ["\u{E001}", "\u{E002}", "\u{E003}", "\u{E004}", "\u{E005}", "\u{E006}"];

    /**
     * Every string in the tree, joined.
     *
     * The walk itself lives in DocumentSentinels, because the HTML target needs
     * the same one to keep an authored U+0001 out of its soft-break guard
     * (carve-php#1077), and two copies of a collision rule is how one rule
     * acquires two answers.
     */
    protected function collectStrings(object $root): string
    {
        return DocumentSentinels::collectStrings($root);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    protected function pickVerbatimSentinels(string $text): array
    {
        /** @var array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string} $picked */
        $picked = DocumentSentinels::pick($text, 6, 0xE001);

        return $picked;
    }

    public function render(Document $document): string
    {
        // Choose the sentinels before anything is rendered, so both escape passes
        // below agree on them.
        $this->verbatimSentinels = $this->pickVerbatimSentinels($this->collectStrings($document));
        $minimal = $this->renderWithEscapeMode($document, self::ESCAPE_MODE_MINIMAL);
        $conservative = $this->renderWithEscapeMode($document, self::ESCAPE_MODE_CONSERVATIVE);
        if ($minimal === $conservative) {
            return $minimal;
        }

        return $this->escapingIsRedundant($minimal, $conservative) ? $minimal : $conservative;
    }

    /**
     * The spelling a thematic break is written with.
     *
     * The document-wide fallback spelling for a break that would otherwise
     * open manufactured frontmatter. PART 11 section 1 requires
     * `to_html(fmt(x)) == to_html(x)`.
     */
    protected string $thematicBreakMarker = '---';

    /**
     * Render, and fall back to a break spelling that cannot be read as
     * frontmatter when the finished bytes would be.
     *
     * THE WRITER MANUFACTURED FRONTMATTER. A frontmatter block is an opening
     * fence at byte 0 plus a bare `---` CLOSER anywhere below it, so the
     * collision is a property of the whole emitted document rather than of its
     * first line, and a first-line test answers a different question. Two
     * unrelated writer decisions reach it:
     *
     * - an authored `---` break can open the document and gain a closer from
     *   any later break.
     * - renderDocumentParts() writes a hoisted link or footnote definition after
     *   the body, promoting whatever stood second to byte 0. Nothing is
     *   respelled there - the `---` was already in the source - so fixing the
     *   first cause does not fix this one.
     *
     * And a THIRD shape the ticket does not name falls out of the same check: a
     * hoisted definition can promote a PARAGRAPH whose first line is
     * `---yaml`-shaped, which no head-of-document respelling can repair, because
     * the paragraph's text is not the writer's to change. That document is saved
     * by respelling the CLOSER instead - which is why the fallback moves every
     * break in the document rather than the one at the head.
     *
     * So the FINISHED bytes are handed to the PARSER'S own opener test, twice:
     * once to ask whether the canonical spelling is misread, and once to confirm
     * the fallback is not. A document still misread with `***` - a `---` closer
     * that came from somewhere other than a break, such as the inside of a
     * fenced block - keeps the canonical spelling rather than paying a
     * respelling that buys nothing.
     *
     * A leading break with nothing below it to close a block keeps `---`, which
     * is what carve-js and carve-rs write and what the corpus asks for. It is a
     * CONTROL: no mutation of this fallback moves it.
     */
    protected function renderWithEscapeMode(Document $document, string $escapeMode): string
    {
        $canonical = $this->renderOnePass($document, $escapeMode);
        // The frontmatter arm is a COST GATE, not a correctness one, and saying
        // so is the honest reading: a document that really carries frontmatter
        // has it written by renderFrontmatter(), whose closer is not a break, so
        // the fallback pass would open frontmatter too and the canonical form
        // would be returned anyway. Removing the arm changes no output, only the
        // number of renders paid by every document with frontmatter.
        if ($this->documentOpensFrontmatter($document) || !$this->opensFrontmatter($canonical)) {
            return $canonical;
        }

        $previousMarker = $this->thematicBreakMarker;
        $this->thematicBreakMarker = '***';
        try {
            $fallback = $this->renderOnePass($document, $escapeMode);

            return $this->opensFrontmatter($fallback) ? $canonical : $fallback;
        } finally {
            $this->thematicBreakMarker = $previousMarker;
        }
    }

    /**
     * Whether the AST itself carries frontmatter, which the writer emits as
     * frontmatter rather than manufacturing.
     */
    protected function documentOpensFrontmatter(Document $document): bool
    {
        return ($document->getChildren()[0] ?? null) instanceof Frontmatter;
    }

    /**
     * Whether `$text` would be READ AS OPENING A FRONTMATTER BLOCK.
     *
     * "Would this be read as frontmatter" is a question only the parser can
     * answer here, and it is spread across six sites in two files: the opener
     * pattern, the first-production guard and the closer search live in
     * FrontmatterExtension, behind BlockParser's first-refusal hand-off for a
     * bare `---` on line 1. A pattern match in the writer would be a seventh
     * spelling of that rule, and this org keeps finding one rule spelled N times
     * with N larger than anyone claimed - so the bytes are parsed instead, by
     * the same default converter escapingIsRedundant() already trusts to
     * decide the escape mode.
     */
    protected function opensFrontmatter(string $text): bool
    {
        // Frontmatter is document-leading, so nothing that does not start with
        // the fence can open one. The gate keeps a whole parse off the path
        // every ordinary document takes.
        if (!str_starts_with($text, '---')) {
            return false;
        }

        return $this->documentOpensFrontmatter((new CarveConverter())->parse($text));
    }

    protected function renderOnePass(Document $document, string $escapeMode): string
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

    /**
     * Attributes carried by each authored definition, keyed by label.
     *
     * @var array<string, array<string, string>>
     */
    protected array $definitionAttributes = [];

    /**
     * Collected definitions, by the source line the author wrote them on.
     *
     * @var array<int, \MarkupCarve\Carve\Node\Block\LinkReferenceDefinition|\MarkupCarve\Carve\Node\Block\Footnote>
     */
    protected array $definitionsByLine = [];

    /**
     * Definitions already written on a description line, so the document-level
     * pass does not write them a second time.
     *
     * @var array<int, true>
     */
    protected array $definitionsWrittenInPlace = [];

    /**
     * A term defined twice is two lines the author wrote; which one wins is
     * resolution (PART 9R) and the formatter does not resolve, so every
     * authored node is written, each at its own position.
     */
    protected function renderAbbreviationDefinition(AbbreviationDefinition $node): string
    {
        return '*[' . $this->escapeBracketText($node->getAbbr()) . ']: '
            . str_replace("\n", ' ', $node->getExpansion());
    }

    protected function renderDocumentParts(Document $document): string
    {
        $parts = [];
        // PART 12 §10: definition attributes serialize ONCE, on the definition.
        // Resolution materializes them onto every link that resolves the label
        // so the HTML target can render them, which leaves the writer unable to
        // tell them from attributes the author wrote AT the reference. Emitting
        // both wrote `{.x}` twice and broke PART 11 §1 (carve#642), so the
        // definition's own keys are subtracted at the reference site.
        $this->definitionAttributes = [];
        // A definition COLLECTED from a description is written back on that
        // description's line, so the emptied `dd` is not a bare `:` that
        // re-parses into the term above it (carve#805, carve-php#903). Indexed
        // by the line the author wrote it on; the description carries the same
        // line since the parser stopped losing it.
        $this->definitionsByLine = [];
        $this->definitionsWrittenInPlace = [];
        foreach ($document->getChildren() as $child) {
            if ($child instanceof LinkReferenceDefinition && $child->getAttributes() !== []) {
                $this->definitionAttributes[$child->getLabel()] = $child->getAttributes();
            }
            // BOTH collected kinds, because the author can write either on a
            // description line: a link reference definition or a footnote.
            if ($child instanceof LinkReferenceDefinition || $child instanceof Footnote) {
                $line = $child->getPos()?->startLine;
                // First writer wins for a line, which cannot normally collide:
                // two definitions on one line is not a shape the parser builds.
                if ($line !== null && !isset($this->definitionsByLine[$line])) {
                    $this->definitionsByLine[$line] = $child;
                }
            }
        }
        // The definition is written WHERE IT WAS AUTHORED, from its node, because
        // `renderBlocks` has an arm for it. This used to place the whole set at
        // one end of the body, chosen by `hasAbbreviationsBeforeBody()` - two
        // positions, which is one fewer than a document can express, so a
        // definition authored BETWEEN two blocks moved to an end and
        // `parse(fmt(x)) != parse(x)` (PART 11 section 1). The parser in this
        // engine already keeps the node at its source position because PART 12
        // section 7 refuses to collect it (BlockParser::orderCollectedDefinitions),
        // so the two halves disagreed about the same clause.
        $residual = [];
        foreach ($document->getAbbreviationDefinitionsNotInTree() as $definition) {
            $residual[] = '*[' . $this->escapeBracketText($definition['abbr']) . ']: '
                . str_replace("\n", ' ', $definition['expansion']);
        }
        if ($residual !== [] && $document->hasAbbreviationsBeforeBody()) {
            $parts[] = implode("\n\n", $residual);
        }
        $body = $this->renderBlocks($document->getChildren());
        if ($body !== '') {
            $parts[] = $body;
        }
        if ($residual !== [] && !$document->hasAbbreviationsBeforeBody()) {
            $parts[] = implode("\n\n", $residual);
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
                if ($name === 'parent' || $name === 'sourceLength' || $name === 'ingestPayloadLength') {
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
     * Whether two adjacent sibling lists would read back as ONE list.
     *
     * PART 9 section 11 N1's axes. `listType` already separates a task list
     * from a plain one, so what remains is the authored marker character (the
     * bullet, or the ordered delimiter) and the ordered dialect. Where any of
     * them differs the lists separate on their own and the writer owes them
     * nothing, which is what carve#286 established.
     */
    protected static function listsWouldMerge(ListBlock $a, ListBlock $b): bool
    {
        return $a->getListType() === $b->getListType()
            && $a->getMarker() === $b->getMarker()
            && $a->getStyle() === $b->getStyle();
    }

    protected static function indentLines(string $text, int $columns): string
    {
        $pad = str_repeat(' ', $columns);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? $line : $pad . $line,
            explode("\n", $text),
        ));
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
        $previousCaptionHost = $this->afterCaptionHost;
        try {
            $parts = [];
            // TWO ADJACENT SIBLING LISTS NEED SOMETHING BETWEEN THEM. Written at
            // the same column with matching markers they merge on re-parse, so
            // `parse(fmt(x)) == parse(x)` is false for a document the parser
            // reads as two lists (carve#1088). carve#286 spent the marker axis -
            // emit the marker as authored - which separates them only while the
            // markers DIFFER; when both are `1.` at column 0 there is nothing
            // left to preserve and indentation is the axis remaining.
            //
            // ONE SPACE, CUMULATIVE, RELATIVE TO THE LIST BEFORE IT. One space is
            // the only offset safe for both kinds: a bullet's content column is
            // 2, so two spaces already NEST the second list inside the first. And
            // the step is per list rather than per run - writing every later list
            // at a flat +1 leaves the second and third at the same column, where
            // they merge with each other.
            $previousList = null;
            $listOffset = 0;
            foreach ($blocks as $block) {
                $rendered = $this->renderBlock($block);
                // Remember, for the NEXT block, whether a `^ ` line after it
                // would be read back as a caption. Only then does the caption
                // marker need escaping (PART 11 §2, carve-php#758).
                $this->afterCaptionHost = self::hostsACaption($block);
                if ($block instanceof ListBlock) {
                    $listOffset = $previousList !== null && self::listsWouldMerge($previousList, $block)
                        ? $listOffset + 1
                        : 0;
                    $previousList = $block;
                } elseif ($rendered !== '') {
                    $previousList = null;
                    $listOffset = 0;
                }
                if ($rendered !== '') {
                    $parts[] = $listOffset > 0 ? self::indentLines($rendered, $listOffset) : $rendered;
                }
            }

            return implode("\n\n", $parts);
        } finally {
            $this->blockDepth--;
            $this->afterCaptionHost = $previousCaptionHost;
        }
    }

    /**
     * Could a `^ ` line following this block be read back as its caption?
     *
     * The parser attaches a caption to a table, a code block, a block quote,
     * and a paragraph holding nothing but an image or display math. After
     * anything else the marker cannot form, so escaping it says nothing -
     * which is what §2 calls a defect rather than a safe default.
     *
     * An UNRESOLVED reference image is not an image here either, for the same
     * reason it is not a figure (#751): the label resolves to nothing, so
     * there is no image for a caption to attach to.
     *
     * @param \MarkupCarve\Carve\Node\Node $block
     */
    private static function hostsACaption(Node $block): bool
    {
        if ($block instanceof Table || $block instanceof CodeBlock || $block instanceof BlockQuote) {
            return true;
        }

        // The closing fence of a bare `::: figure` container is §4's sixth
        // caption host (PART 9 §4c), so a paragraph starting with `^` right
        // after a composite figure needs its escape - and the group's own
        // caption, written by renderFigureGroup() itself, does not pass
        // through here at all.
        if ($block instanceof FigureGroup) {
            return true;
        }

        if ($block instanceof Image) {
            return UnresolvedReference::sourceOf($block) === null;
        }

        if (!$block instanceof Paragraph) {
            return false;
        }

        $children = $block->getChildren();
        if (count($children) !== 1) {
            return false;
        }

        if ($children[0] instanceof Image) {
            return UnresolvedReference::sourceOf($children[0]) === null;
        }

        return $children[0] instanceof Math && $children[0]->isDisplay();
    }

    /**
     * Depth of line-block nesting, so the inline writer can drop the explicit
     * hard-break backslash where the container already implies one.
     */
    protected int $inLineBlock = 0;

    /**
     * Whether the block just written can host a caption, so a following `^ `
     * line would be read back as one (see hostsACaption()).
     */
    protected bool $afterCaptionHost = false;

    /**
     * Whether the first inline line of the paragraph being rendered follows a
     * block that can host a caption. Kept separate from $afterCaptionHost so a
     * soft break inside that paragraph cannot inherit the previous block's
     * caption slot (carve-php#1113).
     */
    protected bool $paragraphStartsAfterCaptionHost = false;

    protected function renderBlock(Node $node): string
    {
        $attrs = $this->renderAttrs($node);
        $withAttrs = static fn (string $body): string => $attrs === '' ? $body : $attrs . "\n" . $body;

        return match (true) {
            $node instanceof Frontmatter => $withAttrs($this->renderFrontmatter($node)),
            $node instanceof Heading => $withAttrs(str_repeat('#', $node->getLevel()) . ' ' . $this->collapseBreaks($this->trimNonNbsp($this->renderInlines($node->getChildren())))),
            // A REFERENCE image cannot carry its attributes inline: the writer
            // returns the authored `rawRef` verbatim, and an attribute block
            // that came from the block-attribute LINE above is not part of that
            // source - so it was dropped, and an `#id` on a captionless
            // `![a][r]` was lost outright (carve-php#831). Written back as the
            // line it came from, which is where carve-js and carve-rs keep it.
            $node instanceof Image && $this->referenceImageAttributeLine($node) !== '' => $this->referenceImageAttributeLine($node) . "\n" . $this->renderImage($node),
            // A LONE image is a block node, not a paragraph wrapping one (the
            // `image` node's own description in the AST vocabulary).
            $node instanceof Image => $this->renderImage($node),
            $node instanceof AbbreviationDefinition
            => $withAttrs($this->renderAbbreviationDefinition($node)),
            $node instanceof Paragraph => $withAttrs($this->renderParagraph($node, $attrs === '')),
            // The opener's quoted title is resolved onto the `title` attribute at
            // parse time so it reaches every consumer, but the fence carries it
            // too - emitting both says it twice and re-parses with an attribute
            // order the source never had (carve#369). The fence is the authored
            // spelling, so it wins.
            $node instanceof CodeBlock => $this->withCodeBlockAttrs($node),
            $node instanceof BlockQuote => $withAttrs($this->renderBlockQuote($node)),
            $node instanceof ListBlock => $withAttrs($this->renderList($node)),
            $node instanceof ListItem => $this->renderListItem($node),
            $node instanceof ThematicBreak => $withAttrs(
                $this->thematicBreakMarker === '---' ? str_repeat($node->char, 3) : $this->thematicBreakMarker,
            ),
            $node instanceof Table => $withAttrs($this->renderTable($node)),
            $node instanceof Div && $node->isTyped() && $this->canRenderTypedDiv($node) => $this->withFencedDivAttrs($node, [$node->getClassList()[0] ?? ''], $this->renderTypedDiv($node)),
            $node instanceof Div && $node->isTyped() && $this->admonitionKind($node) !== null => $this->withFencedDivAttrs($node, [$this->admonitionKind($node)], $this->renderAdmonition($node)),
            $node instanceof Div => $withAttrs($this->renderDiv($node)),
            $node instanceof LineBlock => $withAttrs($this->renderLineBlock($node)),
            $node instanceof DefinitionList => $withAttrs($this->renderDefinitionList($node)),
            $node instanceof FigureGroup => $withAttrs($this->renderFigureGroup($node)),
            $node instanceof Figure => $withAttrs($this->renderFigure($node)),
            $node instanceof RawBlock => $withAttrs($this->renderRawBlock($node)),
            $node instanceof Comment => $this->renderComment($node),
            $node instanceof Footnote => isset($this->definitionsWrittenInPlace[spl_object_id($node)])
                ? ''
                : $this->renderFootnote($node),
            // PART 12 §10 gives the definition a node, so the writer emits the
            // AUTHORED line instead of folding the destination into every
            // reference. Inlining satisfied `toHtml(fmt(x)) == toHtml(x)` and
            // broke PART 11 §1: `ref`/`rawRef` - which §3a keeps precisely so
            // `[a][r]` and `[a](/u)` stay distinguishable - were absent from the
            // reparse (carve#642).
            $node instanceof LinkReferenceDefinition => isset($this->definitionsWrittenInPlace[spl_object_id($node)])
                ? ''
                : $this->renderLinkReferenceDefinition($node),
            $node instanceof Caption => '^ ' . $this->renderInlines($node->getChildren()),
            // PART 12 §18 gives the bibliography line a node; it does NOT move
            // rendered output on any target, and this writer is a target. The
            // line was dropped when the collect pass consumed it and it is
            // dropped here, so `fmt` is byte-identical either way
            // (markup-carve/carve#1276). Writing it back is a separate change
            // to what this renderer emits, not a consequence of the node
            // existing - without this arm the default branch would emit the
            // entry's inlines as a bare paragraph, which is neither.
            $node instanceof CitationDefinition => '',
            default => $this->renderBlocks($node->getChildren()),
        };
    }

    protected function renderParagraph(Paragraph $node, bool $canUsePreviousCaptionSlot): string
    {
        $previous = $this->paragraphStartsAfterCaptionHost;
        $this->paragraphStartsAfterCaptionHost = $canUsePreviousCaptionSlot && $this->afterCaptionHost;
        try {
            $body = $this->guardThematicBreakLines($this->renderInlines($node->getChildren()));

            return $this->inLineBlock > 0 ? self::spellEmptyVerseLines($body) : $body;
        } finally {
            $this->paragraphStartsAfterCaptionHost = $previous;
        }
    }

    /**
     * AN EMPTY LINE INSIDE A VERBATIM RUN IS SPELLED `%%` (PART 11 §7c).
     *
     * A run left unclosed on an earlier line swallows an emptied verse line as
     * a NEWLINE in its value (PART 9 §23), so the tree holds no `hard_break`
     * for §7c to spell and no `comment` node to put back: what the writer has
     * is a value containing an empty line, and a blank body line would END THE
     * STANZA. An empty verse line has exactly ONE spelling that does not - a
     * comment line, which the block layer removes before the run exists, so
     * `%%` re-reads to the empty line it was written for and to nothing else.
     *
     * There is no other source of an empty line here. A `hard_break` over an
     * empty line is written `\` by §7c, and the blank line BETWEEN stanzas is
     * the container's, added after this runs.
     */
    private static function spellEmptyVerseLines(string $body): string
    {
        if (!str_contains($body, "\n")) {
            return $body;
        }

        $lines = explode("\n", $body);
        foreach ($lines as $index => $line) {
            if ($line === '') {
                $lines[$index] = '%%';
            }
        }

        return implode("\n", $lines);
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
            $parts[] = '[' . $this->writeFlatBracketRun($label) . ']';
        }

        // NO SPACE between the fence run and the info string. `fenced_code_block`
        // names the slot OPTIONAL and the no-space form CANONICAL: "The no-space
        // form (```php) is canonical and is what the X->Carve converters emit."
        // The reader stays lenient and accepts both, which is why a single-pass
        // output check never caught this - ``` php re-parses to the same tree.
        //
        // The separators BETWEEN the parts are a different slot and stay: inside
        // `code_fence_info` they are `space+`, mandatory, so ```php"t" is not a
        // fence opener at all and joining without one would lose the header.
        return implode(' ', $parts);
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $inner = $this->withResetColonFenceDepth(fn (): string => $this->renderBlocks($node->getChildren()));
        $lines = explode("\n", $inner);

        $quoted = implode("\n", array_map(static fn (string $line): string => $line === '' ? '>' : '> ' . $line, $lines));

        return $quoted;
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
                // NO absolute depth term here. The parent item's continuation
                // prefix already IS the child list's indentation, so adding
                // `'  ' * (depth - 1)` on top indented every level twice, and
                // the two-space strip below was compensating for it. Output grew
                // as O(depth^3) where the source is O(depth^2) - 1720 bytes in,
                // 23040 out at depth 40 - and `05-lists-5` came back with four
                // spaces where it was written with two (carve-php#792). Same
                // defect and same fix as carve-js#653 and carve-rs#594.
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
                $lines = $content === '' ? [''] : explode("\n", $content);
                $first = array_shift($lines);
                $out .= $prefix . ($first === '' ? '+' : $first) . "\n";
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
                    $blank = $this->isBlankContinuationLine($line);
                    if (!$blank && str_starts_with($line, $this->markerColumn())) {
                        // The continuation marker and the block it attaches sit
                        // at the ITEM's marker column, not its content column
                        // (§17 L3). Indenting either is what made the attached
                        // paragraph fold (carve#861).
                        $out .= substr($line, strlen($this->markerColumn())) . "\n";

                        continue;
                    }
                    $out .= $blank ? $line . "\n" : $continuation . $line . "\n";
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

    /**
     * A continuation line is BLANK when it has no content of its own.
     *
     * Two spellings reach a writer and both mean the same thing. `''` is an
     * ordinary blank line. The SENTINEL form is the one that actually bites: a
     * blank line inside a fenced code block, a raw block or a block comment is
     * VERBATIM content, so protectVerbatim() encodes it to keep the
     * document-wide trim off it, and restoreVerbatim() maps it back to nothing
     * at the very end - after every container has already prefixed it with its
     * own indent. What is left is a line holding the indent and nothing else.
     *
     * A verbatim line that genuinely holds spaces arrives as those spaces under
     * a DIFFERENT sentinel and still indents; only the empty one is blank.
     */
    protected function isBlankContinuationLine(string $line): bool
    {
        return $line === '' || $line === $this->verbatimSentinels[2];
    }

    /**
     * `$line` at `$indent`, except a blank one, which stays blank.
     *
     * PART 11 §7: the writer never emits a line whose only content is space or
     * tab. Such a line is not stable - editors that strip trailing whitespace on
     * save, `git apply --whitespace=fix` and CI whitespace checks all rewrite
     * it, so the formatter produces output ordinary tooling changes behind it
     * (markup-carve/carve#375).
     *
     * SHARED because three writers indent a block body and each one had to know
     * this: the list writer knew it and the footnote and definition writers did
     * not, which is the whole of carve-php#1068. Measured as four verbatim
     * constructs crossed with ten container contexts: 15 of the 40 rows emitted
     * a whitespace-only line, every one of them through those two writers.
     *
     * The blank line is returned AS IT IS rather than as an empty string, and
     * the difference is not observable today: restoreVerbatim() maps the
     * sentinel to nothing at the end, so both spellings reach the same byte and
     * a mutation returning the empty string survives the whole suite. Keeping
     * the sentinel is the conservative half all the same - holding the
     * document-wide trim off the line is the reason it exists.
     */
    protected function indentContinuationLine(string $line, string $indent): string
    {
        return $this->isBlankContinuationLine($line) ? $line : $indent . $line;
    }

    /**
     * Sentinel marking a line to be written at the ITEM's marker column.
     *
     * The list writer prefixes an item's continuation lines with its content
     * column. A `+` continuation marker and the block it attaches are the two
     * things that must NOT get that prefix (§17 L3), and they are produced deep
     * inside the item body where the prefix is not yet known - so they are
     * tagged here and the prefix loop honours the tag.
     *
     * SLOT 5 of the run `$verbatimSentinels` picks per document, so an authored
     * occurrence cannot exist: the run moves off any code point the document
     * writes. It used to be the FIXED U+E010, parked outside the run on the
     * reasoning that a re-picked run would rewrite it - which is a reason to
     * put it IN the run, not beside it (markup-carve/carve-php#1087).
     *
     * WHICH slot it is, is INTENT rather than a load-bearing constraint, and the
     * difference was measured rather than assumed: pointing this at slot 0
     * instead passes the whole suite, because protectVerbatim() only ever
     * encodes TRAILING whitespace and whole blank lines, so no line reaching the
     * continuation loop can BEGIN with the trailing-space carrier. A slot of its
     * own keeps the next reader from redoing that reasoning, and keeps the two
     * independent if protectVerbatim() ever learns to encode a leading run.
     */
    protected function markerColumn(): string
    {
        return $this->verbatimSentinels[5];
    }

    /**
     * Whether `$node`'s canonical source is a bare inline run on its own line,
     * so at a container's content column it CONTINUES an open paragraph instead
     * of opening a block of its own.
     *
     * Derived by sweeping twenty-two block constructs rather than by reasoning
     * about them: a `figure` is an image line plus a caption line and an `image`
     * is the image line alone, which is why both read as paragraph text one
     * column in.
     */
    protected function foldsIntoAnOpenParagraph(Node $node): bool
    {
        return $node instanceof Paragraph || $node instanceof Image || $node instanceof Figure;
    }

    /**
     * Whether the WRITTEN form of a block opens with a block-attributes line.
     *
     * The three kinds above fold into an open paragraph one column in because
     * their canonical source is a bare inline run. That stops being true the
     * moment the writer has to put the block's attributes on a line of their
     * own ahead of it: `block_attributes` is one of PART 9 §10's INVISIBLE
     * CONSTRUCTS, so it INTERRUPTS the open paragraph and the block below it
     * opens its own.
     *
     * So this is not a preference between two spellings. Where the attribute
     * line is written, the fold the continuation marker exists to prevent
     * cannot happen, and the marker costs a construct the document did not
     * have. The marker form and the indented form render the same document in
     * carve-php, carve-js and carve-rs alike - and the
     * indented one is what the corpus source and carve-rs write, so writing the
     * marker was this engine disagreeing with carve-rs (markup-carve/carve#1275).
     *
     * A paragraph whose own text is `{...}` does not reach this: the writer
     * escapes that leading brace (`\{.c\}`), precisely so it cannot come back
     * as attributes.
     */
    protected function opensWithAnAttributeLine(string $rendered): bool
    {
        $first = explode("\n", $rendered, 2)[0];

        return (bool)preg_match('/^\{.*\}$/', $first);
    }

    protected function adjacentBlocksMerge(Node $left, Node $right): bool
    {
        if ($left::class !== $right::class) {
            return false;
        }
        if ($left instanceof ListBlock && $right instanceof ListBlock) {
            return $left->getListType() === $right->getListType()
                && $left->getMarker() === $right->getMarker()
                && $left->getStyle() === $right->getStyle();
        }

        return $left instanceof BlockQuote
            || $left instanceof Table
            || $left instanceof LineBlock
            || $left instanceof DefinitionList;
    }

    protected function atMarkerColumn(string $text): string
    {
        return implode("\n", array_map(
            fn (string $line): string => $this->markerColumn() . $line,
            explode("\n", $text),
        ));
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
            $previous = null;
            // Whether any child so far was written at the item's MARKER column,
            // which is column 0. Everything after it has to sit there too - see
            // below - so this only ever latches on.
            $atMarkerColumn = false;
            foreach ($children as $index => $child) {
                $next = $children[$index + 1] ?? null;
                // A definition the author wrote BETWEEN these two blocks was
                // collected out of the item, and the gap it left is what split
                // one paragraph into two. Dropping the line would rejoin them,
                // so it is written back where it was (carve#805).
                $separated = false;
                if ($previous !== null) {
                    $written = $this->definitionInGap($previous, $child);
                    if ($written !== null && $written !== '') {
                        if ($out !== '') {
                            $out .= "\n";
                        }
                        $out .= $written;
                        $separated = true;
                    }
                }
                $rendered = $this->renderBlock($child);
                if ($rendered === '') {
                    $previous = $child;

                    continue;
                }
                if ($out !== '') {
                    $out .= "\n";
                }
                // §17 L3: a block that FOLDS INTO AN OPEN PARAGRAPH needs its
                // continuation marker written back. Indented under the item it
                // is a lazy continuation of the paragraph above (§10 I2), so
                // the item comes back holding ONE block where the author wrote
                // two (carve#861).
                //
                // WHICH BLOCKS FOLD. The claim this condition used to carry -
                // "only a paragraph reaches this, no other attached kind can
                // fold into an open paragraph" - was a premise in the code, and
                // measuring it across twenty-two constructs refuted it for two:
                // a standalone `image` and a `figure` are both written as a
                // bare inline run on their own line (`![a](i.png)`, plus a
                // `^ cap` line), so at the item's content column they are lazy
                // continuation exactly as a paragraph is. `- x` / `+` /
                // `![a](i.png)` / `^ cap` came back as ONE paragraph holding an
                // inline image and the literal text `^ cap`, with the
                // `<figure>` and its `<figcaption>` gone (carve-php#1069 cause
                // 3). Every other construct measured - fence, quote, heading,
                // table, break, div, list, definition list, admonition, verse,
                // line block, math, raw block, comment, abbreviation
                // definition, link definition, footnote definition - either
                // opens its own block at that column or never reaches the item
                // as its own node, and is unaffected.
                //
                // AND ONCE ONE CHILD IS AT THE MARKER COLUMN, EVERY LATER ONE
                // MUST BE. The marker column is column 0, so a following child
                // written at the item's CONTENT column is indented relative to
                // the block above it and becomes that block's lazy
                // continuation. `- x` / `+` / `---yaml` / `k: v` / `---` wrote
                // the paragraph flush and the thematic break at two columns,
                // and the break was absorbed into the paragraph and folded to
                // an em dash where the input rendered a rule (cause 4). Mixed
                // indentation inside one attached run is not a form any reader
                // round-trips, whichever indentation was intended, so it is not
                // written.
                //
                // A definition written back in the gap already ended the
                // paragraph above, so the marker would be redundant there and
                // would change corpus 228's canonical form. It does not release
                // a run that is already at the marker column, because the
                // column, not the paragraph, is what the later child continues.
                if (
                    $atMarkerColumn
                    || ($next !== null && $this->adjacentBlocksMerge($child, $next))
                    || (
                        !$separated
                        && $previous instanceof Paragraph
                        && $this->foldsIntoAnOpenParagraph($child)
                        && !$this->opensWithAnAttributeLine($rendered)
                    )
                ) {
                    $out .= $this->atMarkerColumn('+') . "\n" . $this->atMarkerColumn($rendered);
                    $previous = $child;
                    $atMarkerColumn = true;

                    continue;
                }
                $previous = $child;
                $out .= $rendered;
            }

            return $out;
        } finally {
            $this->blockDepth--;
            $this->colonFenceDepth = $previousColonFenceDepth;
        }
    }

    /**
     * The definition the author wrote on a line strictly between two blocks.
     *
     * Collecting it emptied the line, and an emptied line is a blank one: the
     * blocks it separated re-parse as a single paragraph, which is a different
     * document (carve#805, corpus 228). The description write-back finds its
     * definition by the description's own line; here there is no node left to
     * carry it, so the gap between the neighbours names it instead.
     */
    protected function definitionInGap(Node $before, Node $after): ?string
    {
        $from = $before->getPos()?->endLine;
        $to = $after->getPos()?->startLine;
        if ($from === null || $to === null) {
            return null;
        }

        foreach ($this->definitionsByLine as $line => $node) {
            if ($line <= $from || $line >= $to) {
                continue;
            }
            if (isset($this->definitionsWrittenInPlace[spl_object_id($node)])) {
                continue;
            }
            $this->definitionsWrittenInPlace[spl_object_id($node)] = true;

            return $node instanceof Footnote
                ? $this->renderFootnote($node)
                : $this->renderLinkReferenceDefinition($node);
        }

        return null;
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

    /**
     * Join a colon fence's body to its closer.
     *
     * An EMPTY body is written as a BLANK LINE, for every container shape
     * including the bare `:::` div (markup-carve/carve#961 ruling 1). PART 10
     * §4 already settled the same question for the HTML target and chose the
     * blank line; this follows that sibling clause rather than inventing a
     * second rule, and deliberately does NOT import §4's bare-div exception,
     * which §4 itself says "has no principle behind it".
     */
    private static function fencedDivBody(string $body): string
    {
        return $body === '' ? "\n\n" : "\n" . $body . "\n";
    }

    protected function renderDiv(Div $node): string
    {
        $label = $node->getLabel() === null ? '' : ' [' . $this->writeFlatBracketRun($node->getLabel()) . ']';
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);

        return $fence . $label . self::fencedDivBody($body) . $fence;
    }

    protected function canRenderTypedDiv(Div $node): bool
    {
        // Only the OPENER class decides. Requiring exactly one class meant a
        // class-carrying attribute line above a typed custom div (`{.sidebar}`
        // + `::: widget "Title"`) fell through to the untyped writer, which
        // has no title slot - so the quoted title was dropped and one fmt pass
        // changed the rendered HTML (carve-php#1284). Extra classes are the
        // attribute line's business; withFencedDivAttrs() already writes them
        // back there with the opener excluded.
        $classes = $node->getClassList();

        return $classes !== []
            && !in_array($classes[0], ['hardbreaks', 'line-block'], true)
            && preg_match('/^[A-Za-z_][\w-]*$/', $classes[0]) === 1;
    }

    protected function renderTypedDiv(Div $node): string
    {
        $classes = $node->getClassList();
        $kind = $classes[0] ?? '';
        $title = $node->getHeader();
        $titlePart = is_string($title) ? ' "' . $this->escapeQuoted($title) . '"' : '';
        $label = $node->getLabel() === null ? '' : ' [' . $this->writeFlatBracketRun($node->getLabel()) . ']';
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);

        return $fence . ' ' . $kind . $titlePart . $label . self::fencedDivBody($body) . $fence;
    }

    protected function renderAdmonition(Div $node): string
    {
        $kind = $this->admonitionKind($node) ?? 'note';
        $title = $node->getHeader();
        $titlePart = is_string($title) ? ' "' . $this->escapeQuoted($title) . '"' : '';
        $label = $node->getLabel() === null ? '' : ' [' . $this->writeFlatBracketRun($node->getLabel()) . ']';
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);

        return $fence . ' ' . $kind . $titlePart . $label . self::fencedDivBody($body) . $fence;
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
            $value = $attrs[$slot];
            // EXACT key match, not case-insensitive: `LANG` and `lang` are
            // different attribute names, so folding here rewrote
            // `[x]{LANG=fr}` into `[x]{:fr}` and changed the name, which
            // breaks PART 11 SS1 (carve#1137).
            if ($slot === 'lang' && ($value === '' || preg_match('/^[A-Za-z0-9]{1,8}(?:-[A-Za-z0-9]{1,8})*$/D', $value) === 1)) {
                $parts[] = ':' . $value;
            } elseif ($value === '' && $this->isAttrIdentifier($slot)) {
                // PART 11 SS6c: a value-less attribute comes back as the bare
                // name, which is the production the language has for it. A key
                // needing escaping has no bare spelling to fall back to.
                $parts[] = $this->escapeAttrKey($slot);
            } else {
                $parts[] = $this->escapeAttrKey($slot) . '=' . $this->quoteAttrValue($value);
            }
        };

        $order = $node->getAttributeOrder();
        if ($order !== []) {
            foreach ($order as $slot) {
                $emit($slot);
            }
            foreach ($attrs as $key => $_value) {
                // An id with no `#id` slot is a GENERATED one - since carve#750
                // a heading's slugged id is on the wire, so a decoded node
                // carries it - and a writer reproduces what the author wrote.
                // Emitting it here put `{#Notes}` into 39 corpus documents
                // whose source has no attribute block at all.
                if ((string)$key === 'id' && !in_array('#id', $order, true)) {
                    continue;
                }
                $emit((string)$key);
            }
        } else {
            // NO SLOTS AT ALL. An `id` here is a GENERATED one - since carve#750
            // a heading's slugged id is published, and an AUTHORED id always
            // carries its `#id` slot - so emitting it writes `{#Welcome}` above
            // a heading whose source has no attribute block. A programmatic
            // tree that wants the id in the source records the slot.
            if (!array_key_exists('id', $attrs)) {
                $emit('#id');
            }
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
                // An EMPTY description whose line carries a collected definition
                // is one the author wrote that definition on: write it back
                // there. Without this the line came out as a bare `:`, which
                // re-parses into the term above it (carve#805).
                $line = $child->getChildren() === [] ? $child->getPos()?->startLine : null;
                $collected = $line === null ? null : ($this->definitionsByLine[$line] ?? null);
                if ($collected !== null) {
                    $this->definitionsWrittenInPlace[spl_object_id($collected)] = true;
                    $written = $collected instanceof Footnote
                        ? $this->renderFootnote($collected)
                        : $this->renderLinkReferenceDefinition($collected);
                    $out[] = ':  ' . $written;

                    continue;
                }
                $body = $this->withResetColonFenceDepth(fn (): string => $this->renderBlocks($child->getChildren()));
                $lines = explode("\n", $this->trimNonNbsp($body));
                $out[] = ':  ' . array_shift($lines);
                foreach ($lines as $line) {
                    $out[] = $this->indentContinuationLine($line, '   ');
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
        // Tables prefer the NATIVE header form: an `=` on each header cell plus
        // the per-cell alignment markers. The GFM delimiter row is an accepted
        // alias on input, but it says something the AST does not - its alignment
        // applies to the WHOLE column, header and body alike (PART 9 T7), while
        // alignment on the AST belongs to each cell. Writing one for the
        // ordinary shape (aligned header over unaligned body cells) brought
        // every body cell back aligned, so `parse(fmt(x)) == parse(x)` did not
        // hold (carve#359).
        //
        // ONE header shape still has no native spelling: `header_cell` is
        // `'=' [alignment_marker] [cell_attributes] content` and admits no span
        // marker, so
        //
        //     | < | b |     a span marker promoted to a header cell
        //
        // keeps a delimiter row to promote the first row, emitted BARE so the
        // cells keep their own alignment markers and the delimiter cannot spill
        // alignment down the column. An ATTRIBUTED header cell is no longer in
        // that set - PART 9 §5 T10 binds the block after the marker run, so
        // `|={.x} a |` spells it directly and the delimiter row it used to need
        // dropped both the marker and the cell's own alignment.
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
                if ($cell->getSpanMarker() !== null) {
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
                    && !$cell->hasExplicitAlignment()
                    && ($headerAligns[$column] ?? null) === $cell->getAlignment();
                $cells[] = $this->renderTableCell($cell, $markHeader, $inherited);
                $column++;
            }
            $rows[] = $this->renderTableRow($cells, $this->renderAttrs($row));
        }
        if ($needsDelimiter) {
            $headerCells = count(array_filter(
                $tableRows[0]->getChildren(),
                static fn (Node $child): bool => $child instanceof TableCell,
            ));
            array_splice($rows, 1, 0, '|' . implode('|', array_fill(0, max(1, $headerCells), '---')) . '|');
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
     * @param list<string> $cells Rendered cells, each already padded.
     * @param string $attrs Row attributes.
     */
    protected function renderTableRow(array $cells, string $attrs): string
    {
        return '|' . implode('|', $cells) . '|' . $attrs;
    }

    /**
     * A cell's written form: its PREFIX glued to the opening pipe, then one
     * space, then the content, then one space before the closing pipe.
     *
     * The prefix has to touch the pipe - a space in front of `=` or of an
     * attribute block makes it literal content - but the CONTENT does not, and
     * the padded form is the readable one. It is also the safe one: the
     * alignment scan runs right after `|` or `|=` off the UNTRIMMED cell, so a
     * glued content sigil was read as a marker nobody wrote. That used to be a
     * guard listing the characters that merge; the space covers every cell.
     *
     * An EMPTY cell takes a single space, not two, so a column does not grow a
     * space each time the document is formatted.
     */
    protected function padCell(string $prefix, string $content): string
    {
        if ($content === '') {
            return $prefix . ' ';
        }

        return $prefix . ' ' . $content . ' ';
    }

    protected function renderTableCell(TableCell $cell, bool $markHeader = true, bool $inheritedAlign = false): string
    {
        $attrs = $this->renderAttrs($cell);
        // A lone span marker keeps a SPACE before it. Glued to the opening pipe,
        // `<` is also the left-alignment sigil, and the two readings differ: the
        // executable spec reads `|<|` as alignment on an empty cell where all
        // three engines read a colspan (carve#710). `alignment_marker` is
        // defined as glued and `colspan_marker` may carry surrounding
        // whitespace, so the padded form means the same thing to every reader
        // and the writer must not emit the ambiguous one. `^` is not an
        // alignment sigil, but takes the same shape so a row of span cells stays
        // readable.
        //
        // A cell attribute block is GLUED to the pipe here, because a span cell
        // has no marker run for it to bind after (PART 9 §5 T10); the space
        // goes between it and the span marker.
        if ($cell->getSpanMarker() !== null) {
            return $this->padCell($attrs, $cell->getSpanMarker());
        }
        $align = $inheritedAlign ? '' : $this->alignMarker($cell->getAlignment());
        // MARKER RUN FIRST, BLOCK LAST (PART 9 §5 T10). Writing the block ahead
        // of the `=` produced `|{#x}=R|`, which every reader takes as a DATA
        // cell whose content starts with `=`, so a `<th id="x">R</th>` came back
        // as `<td id="x">=R</td>` and PART 11 §1 failed on it. This order is
        // meaning-preserving instead: `|={#x} R |` parses back to the node that
        // was written.
        $prefix = ($cell->isHeader() && $markHeader ? '=' : '') . $align . $attrs;

        $this->tableCellDepth++;
        try {
            $content = $this->renderInlines($cell->getChildren());
        } finally {
            $this->tableCellDepth--;
        }

        return $this->padCell($prefix, $content);
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

    /**
     * The canonical writer emits the AUTHORED form (grammar PART 11 §10g): the
     * bare `::: figure` opener - a figure_group has no title or label to spell
     * - the children with one blank line between them, the closer at the
     * opener's width, and the group caption as a `^ ` line after the closer.
     * The caret is NOT escaped there: the group caption is the caption the
     * closer hosts, not text in that position, and `\^ ` would re-parse as a
     * paragraph and break `parse(fmt(x)) == parse(x)`.
     */
    protected function renderFigureGroup(FigureGroup $node): string
    {
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);
        $out = $fence . ' figure' . self::fencedDivBody($body) . $fence;

        $caption = $node->getCaption();
        if ($caption !== null) {
            $out .= "\n^ " . $this->renderInlines($caption->getChildren());
        }

        return $out;
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
        if ($node->isDelimited()) {
            return '{% ' . $content . ' %}';
        }
        $recorded = $node->getFenceLength();
        if ($recorded === null && !str_contains($content, "\n")) {
            return '%% ' . $content;
        }

        // A fence must be WIDER than any run of `%` inside it - a nested `%%%`
        // inside a `%%%` block closes it early - and that is the ONLY thing
        // that widens it. The author's own width is not reproduced: PART 12 §3
        // records no run length for any delimiter, so `%%%` and `%%%%` are one
        // spelling exactly as `***` and `*****` are one thematic break, and
        // this writer already normalizes the colon, backtick and tilde fences
        // the same way.
        //
        // The recorded width used to be a floor here. That made a `%%%%` around
        // a body needing no width the one construct whose authored delimiter
        // this writer reproduced - and since the wire carries blockness rather
        // than a width (§3, carve#1000), the same document written from a
        // decoded tree came back at `%%%`. Corpus 339 is where the two answers
        // met: one document, two spellings, depending on whether it had been
        // through JSON.
        preg_match_all('/%+/', $content, $matches);
        $longest = 0;
        foreach ($matches[0] as $match) {
            $longest = max($longest, strlen($match));
        }
        $fence = str_repeat('%', max(3, $longest + 1));

        return $fence . "\n" . $this->protectVerbatim($content) . "\n" . $fence;
    }

    /**
     * Write an authored `[label]: /url "title" {attrs}` line back as written.
     *
     * The destination and title are emitted verbatim; the trailing attribute
     * block is the node's own attributes (PART 9 §15 A2b), which transfer to
     * every link or image resolving the label rather than styling this line.
     */
    protected function renderLinkReferenceDefinition(LinkReferenceDefinition $node): string
    {
        $out = '[' . $node->getLabel() . ']: ' . $node->getHref();
        $title = $node->getTitle();
        if ($title !== null) {
            $out .= ' "' . str_replace('"', '\\"', $title) . '"';
        }
        $attrs = $this->renderAttrs($node);
        if ($attrs !== '') {
            $out .= ' ' . $attrs;
        }

        return $out;
    }

    /**
     * A body holding NO blocks takes the SENTINEL `{empty}` (PART 11 §7b).
     *
     * `[^f]:` with nothing after the colon is not a definition at all - MARKER
     * REQUIRES CONTENT (PART 2) - so writing it degrades the definition to a
     * paragraph and every reference to it to literal text. PART 11 §1a is why
     * the writer may depart from the per-construct spelling here: the emitted
     * bytes have to re-parse to the tree they came from.
     *
     * The sentinel has to be a VALID ATTRIBUTE BLOCK, which is why it is not
     * `{ }` or `{}`: a block-attribute line requires at least one attribute,
     * so both of those stay literal text inside the note. `{empty}` is a
     * boolean attribute, collected on the definition line and discarded with
     * the rest of the body's pending attributes, so it reaches neither the
     * endnote item nor anything after it.
     */
    protected function renderFootnote(Footnote $node): string
    {
        $body = $this->trimNonNbsp($this->renderBlocks($node->getChildren()));
        if ($body === '') {
            return '[^' . $this->writeFlatBracketRun($node->getLabel()) . ']: {empty}';
        }

        $lines = explode("\n", $body);
        $out = '[^' . $this->writeFlatBracketRun($node->getLabel()) . ']: ' . array_shift($lines);
        foreach ($lines as $line) {
            // TWO spaces, the body's own column (PART 9 §16). A wider indent is
            // legal continuation but puts the body's blocks at a relative column
            // above zero, and an indented block opener does not open a block - so
            // a table or list written at three came back as a paragraph.
            $out .= "\n" . $this->indentContinuationLine($line, '  ');
        }

        return $out;
    }

    /**
     * The opener SPELLS THE FORMAT TOKEN OUT, `yaml` included
     * (markup-carve/carve#961 ruling 2).
     *
     * The grammar uses the word "canonical" for the exact string `---yaml`, and
     * this is the canonical writer. `yaml` used to be the one format written as
     * a bare `---`, which made the default format the single case where the
     * writer did not say what it had parsed; `---toml` and `---json` were
     * already spelled out. One rule across formats replaces the special case.
     */
    protected function renderFrontmatter(Frontmatter $node): string
    {
        return '---' . $this->escapeFormat($node->getFormat()) . "\n" . $this->protectVerbatim($node->getContent()) . "\n---";
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
            $captionCanOpen = $this->paragraphStartsAfterCaptionHost && $this->inlineDepth === 1;
            $isFirstInlineLine = true;
            $lineNodeCount = 0;
            $lineHostsCaption = false;
            $lineEndsInComment = false;
            for ($i = 0; $i < $count; $i++) {
                $node = $nodes[$i];
                if ($node instanceof HardBreak && $this->inLineBlock > 0) {
                    // ONLY THE STANZA'S OWN LAST NODE ENDS THE PARAGRAPH. A
                    // break nested inside an emphasis run ends that run's list
                    // instead, and dropping its newline there closed the
                    // emphasis with an escaped delimiter (`/a\/`). The parser
                    // never builds that tree - the promotion in
                    // BlockParser::convertParagraphSoftBreaksToHardBreaks()
                    // reaches direct children only - but an imported AST can.
                    $out .= $this->verseLineBreak(
                        $out,
                        $i === $count - 1 && $this->inlineDepth === 1,
                        $lineEndsInComment,
                    );
                    $captionCanOpen = false;
                    $isFirstInlineLine = false;
                    $lineNodeCount = 0;
                    $lineHostsCaption = false;
                    $lineEndsInComment = false;

                    continue;
                }
                if ($node instanceof InlineNode) {
                    $out .= $this->renderInline(
                        $node,
                        $this->lastBoundary($nodes[$i - 1] ?? null),
                        $this->firstBoundary($nodes[$i + 1] ?? null),
                        $captionCanOpen,
                        self::opensAVerbatimRun($nodes[$i + 1] ?? null),
                    );
                    if ($node instanceof SoftBreak) {
                        $captionCanOpen = $isFirstInlineLine && $lineNodeCount === 1 && $lineHostsCaption;
                        $isFirstInlineLine = false;
                        $lineNodeCount = 0;
                        $lineHostsCaption = false;

                        continue;
                    }
                    $lineNodeCount++;
                    $lineHostsCaption = $lineNodeCount === 1 && self::inlineHostsACaption($node);
                    $captionCanOpen = false;
                    $lineEndsInComment = false;
                } elseif ($node instanceof Comment) {
                    // THE SEPARATOR SPACE IS ONLY A SEPARATOR. §21 recognizes
                    // `%%` after whitespace OR at the start of its line, so a
                    // comment that already STARTS its line has nothing to
                    // separate from and must not be given a space it did not
                    // have.
                    //
                    // Everywhere else that space was cosmetic, which is why it
                    // went unnoticed: leading whitespace is stripped on the way
                    // back in. A LINE BLOCK is the one place it is not - there
                    // leading whitespace is preserved CONTENT (§23), so the
                    // space pushes the marker off column 0, the reparse reads
                    // `%%` as ordinary verse, and `carve fmt` both breaks its
                    // own `toHtml(fmt(x)) == toHtml(x)` invariant and PUBLISHES
                    // the comment text the author hid. carve-rs emits no space
                    // here and round-trips; carve-js emits one and does not.
                    //
                    // NO SEPARATOR WITHOUT SOMETHING TO SEPARATE FROM, and an
                    // EMPTY comment is the marker alone. The trailing space was
                    // cosmetic everywhere but a line block, where PART 11 §7c
                    // protects a line's last column with a backslash - and a
                    // backslash after the marker is comment CONTENT, so the
                    // note came back holding one (corpus 346-3).
                    $body = $node->getContent() === '' ? '%%' : '%% ' . $node->getContent();

                    // IN VERSE THAT IS WHAT PUTS THE COMMENT BACK ON THE LINE
                    // IT EMPTIED. PART 9 §23 removes a comment-only body line
                    // at the BLOCK layer, and a `comment` node survives only
                    // where the boundary that OPENS its line survives - so the
                    // walk is standing at the start of that line when it gets
                    // here, `$out` ends with the boundary's newline, and no
                    // separator is what writes the marker at column 0.
                    $separator = ($out === '' || str_ends_with($out, "\n")) ? '' : ' ';
                    $out .= $node->isDelimited()
                        ? $this->renderComment($node)
                        : $separator . $body;
                    $lineEndsInComment = !$node->isDelimited();
                    $lineNodeCount++;
                    $lineHostsCaption = false;
                    $captionCanOpen = false;
                }
            }

            return $out;
        } finally {
            $this->inlineDepth--;
        }
    }

    /**
     * How a line block spells a `hard_break` (PART 11 §7c).
     *
     * A line block hardens every line boundary of its own accord (PART 9 §23),
     * so the break is a BARE NEWLINE - right for most lines and wrong for the
     * two where §7's precondition fails. §7 may strip a line's trailing
     * whitespace only because the parser discards it too, and the parser does
     * NOT discard it when a backslash follows: PART 7 makes that run INTERIOR.
     *
     * So the backslash is written where a bare newline would be RE-READ:
     *
     *  - the line's content is EMPTY. A blank body line ends the stanza, so one
     *    stanza is written back as two.
     *  - the line's content ends in a LONE space, which PART 2 then drops. A
     *    run of two or more columns is already NBSP content (§23 MEDIAL GAPS)
     *    and needs no backslash, and neither does an ESCAPED space: `a\ ` is
     *    one non-breaking space, not line-trailing whitespace.
     *
     * A break that ENDS the paragraph writes the backslash and no newline at
     * all: {@see self::renderLineBlock()} adds the line ending before the
     * closing fence, and a second one would be the blank line this exists to
     * avoid - which is how a block's last line lost its `<br>` and the space
     * in front of it (markup-carve/carve#1334).
     */
    protected function verseLineBreak(string $out, bool $endsTheParagraph, bool $lineEndsInComment): string
    {
        // A LINE WHOSE LAST NODE IS A COMMENT IS EXEMPT -- and the rule is
        // keyed on the NODE, not on where the line sits. `%%` runs to the END
        // OF ITS LINE, so a trailing space there is INSIDE the note rather
        // than content PART 2 is about to take: stripping it leaves the same
        // node, and protecting it does not, because the block layer claims the
        // whole line before the inline parser sees it and the backslash lands
        // in the comment's own content. An EMPTY comment line is where this
        // bites, since the writer used to spell one as the marker plus a
        // separator space (corpus 346-3).
        if ($lineEndsInComment) {
            return $endsTheParagraph ? '' : "\n";
        }

        if ($endsTheParagraph) {
            return '\\';
        }

        $lineStart = strrpos($out, "\n");
        $line = $lineStart === false ? $out : substr($out, $lineStart + 1);

        return (self::verseLineNeedsBackslash($line) ? '\\' : '') . "\n";
    }

    /**
     * Whether a bare newline after this line's bytes would be read back as
     * something else.
     */
    private static function verseLineNeedsBackslash(string $line): bool
    {
        if ($line === '') {
            return true;
        }

        // ONE TRAILING COLUMN, IN EITHER OF ITS TWO SPELLINGS.
        //
        // A plain space is the obvious one. An ESCAPED space is the other, and
        // it is not exempt for looking like content: the line block drops a
        // lone trailing COLUMN before the inline reader ever sees the escape,
        // so `a\ ` comes back as `a\` - a hard break with the non-breaking
        // space gone. The backslash is what stops the column being dropped, so
        // the test is on the column and not on what put it there.
        //
        // A MEDIAL GAP OF TWO OR MORE COLUMNS matches NEITHER, and that is the
        // whole of its exemption (§23 MEDIAL GAPS). By the time a line reaches
        // here {@see self::resolveIndentPlaceholder()} has rewritten such a run
        // to the writer's own protected-space sentinel, which is not a space
        // and is not the escape placeholder - so it needs no branch of its own,
        // and a branch spelled against plain spaces would never run.
        return str_ends_with($line, ' ') || str_ends_with($line, "\u{E000}");
    }

    protected function renderInline(
        InlineNode $node,
        string $prevChar = '',
        string $nextChar = '',
        bool $captionCanOpen = false,
        bool $nextOpensVerbatim = false,
    ): string {
        $withAttrs = fn (string $body): string => $body . $this->renderAttrs($node);
        // An unresolved reference renders as the source the author
        // wrote, never as a link (PART 12 section 3a).
        $rawReference = UnresolvedReference::sourceOf($node);

        return match (true) {
            $node instanceof Text => $this->escapeText(
                $this->resolveIndentPlaceholder($node->getContent()),
                // Does this node's first character sit at the start of a block
                // line? Only there can `^ ` be read back as a caption marker.
                $captionCanOpen && $this->tableCellDepth === 0,
                // Does a trailing `!` abut the next node's backtick run? Only
                // there does PART 9 §27 bind it to an inline literal.
                $nextOpensVerbatim,
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
            $node instanceof InlineFootnote => $withAttrs('^[' . $this->renderInlineNoteContent($node) . ']'),
            $node instanceof FootnoteRef => $withAttrs('[^' . $this->writeFlatBracketRun($node->getLabel()) . ']'),
            $node instanceof SoftBreak => "\n",
            // A line block's own spelling is decided in renderInlines(), which
            // is the only place that can see the line the break ends
            // (PART 11 §7c) - see verseLineBreak().
            $node instanceof HardBreak => "\\\n",
            $node instanceof Insert => $withAttrs('{+' . $this->renderInlines($node->getChildren()) . '+}'),
            $node instanceof Delete => $withAttrs('{-' . $this->renderInlines($node->getChildren()) . '-}'),
            $node instanceof Substitution => '{~' . $this->escapeCriticText($node->getOldText()) . '~>' . $this->escapeCriticText($node->getNewText()) . '~}',
            $node instanceof HeadingRef => '</#' . $this->escapeCrossrefTarget($node->getTargetId()) . '>',
            $node instanceof CaptionNumber => '#',
            $node instanceof CitationGroup => $node->getRaw(),
            default => $this->renderInlines($node->getChildren()),
        };
    }

    private static function inlineHostsACaption(InlineNode $node): bool
    {
        if ($node instanceof Image) {
            return UnresolvedReference::sourceOf($node) === null;
        }

        return $node instanceof Math && $node->isDisplay();
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
        // AN EXPLICIT DEFINITION NOW WRITES THE REFERENCE TOO. This used to
        // write the resolved link, on the reasoning that "the definition line is
        // dropped either way, so the authored pair is not reproducible from the
        // tree". PART 12 §10 removed that premise: the definition IS in the
        // tree, so both halves are reproducible and the pair round-trips.
        //
        // Inlining satisfied `toHtml(fmt(x)) == toHtml(x)` and broke PART 11
        // §1: `ref` and `rawRef` - which §3a keeps precisely so `[a][r]` and
        // `[a](/u)` stay distinguishable - were absent from the reparse. It also
        // duplicated a destination the definition form exists to write once, so
        // one URL became N after a single `fmt` (carve#642).
        $referenceLabel = $node->getReferenceLabel();
        if (!$node->isFromHeadingReference() && $referenceLabel !== null && $referenceLabel !== '') {
            // `rawRef` is the authored source VERBATIM and already includes any
            // attribute block the author wrote at the reference, so appending
            // renderAttrs() here wrote `{.own}` twice.
            $raw = $node->getRawReferenceLabel();
            if ($raw !== null) {
                return $raw;
            }

            return '[' . $text . '][' . $referenceLabel . ']'
                . $this->renderAttrsExcept($node, $this->definitionAttributes[$referenceLabel] ?? []);
        }

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

    /**
     * The block-attribute line a REFERENCE image needs, or '' when it needs none.
     *
     * `renderImage()` writes a reference image as the authored `rawRef`, which is
     * the reference source verbatim - `![a][r]`. An attribute block the author
     * wrote AT the reference is already inside that string; one that came from
     * the block-attribute line above is not, and returning `rawRef` alone
     * dropped it. Emitting the line back is the only spelling that survives a
     * re-parse, since appending the block to `rawRef` would attach it to the
     * image's own slot instead (carve-php#831).
     *
     * The DEFINITION's own attributes are excluded. They reach every link that
     * resolves the label (PART 9R R1) and are already written once on the
     * definition line, so treating the copy on this node as authored here wrote
     * the same `{#id}` twice - the same reason the reference site itself uses
     * renderAttrsExcept().
     */
    protected function referenceImageAttributeLine(Image $node): string
    {
        if ($node->getAttributes() === []) {
            return '';
        }
        $raw = UnresolvedReference::sourceOf($node) ?? $node->getRawReferenceLabel();
        if ($raw === null) {
            return '';
        }
        // A block the author wrote AT the reference is inside `rawRef` already, so
        // only what it does NOT state becomes a line. Bailing out whenever `rawRef`
        // ended in `}` dropped the block-attribute line wholesale for
        // `{#f}` + `![a][r]{.c}`, where the two blocks are different sets
        // (carve-php#831 follow-up); carve-js and carve-rs both keep the `{#f}`.
        $atReference = $this->trailingAttributesOf(rtrim($raw));
        $label = $node->getReferenceLabel();

        $claimed = $label === null ? [] : ($this->definitionAttributes[$label] ?? []);
        // `+` keeps the LEFT side's value for a shared key, which would drop the
        // reference's own classes whenever the definition also carried one. Class
        // tokens from both are subtracted, everything else keeps the union.
        $subtract = $claimed + $atReference;
        $classes = trim(($claimed['class'] ?? '') . ' ' . ($atReference['class'] ?? ''));
        if ($classes !== '') {
            $subtract['class'] = $classes;
        }

        return $this->renderAttrsExcept($node, $subtract);
    }

    /**
     * Attributes an authored inline already states in its trailing `{...}` block.
     *
     * QUOTE-AWARE: a value may itself contain a brace (`{k="{y}"}`), so the block's
     * opening brace is the last one seen OUTSIDE quotes - `strrpos()` finds the one
     * inside the value and mis-parses the payload (corpus 71).
     *
     * @return array<string, string>
     */
    protected function trailingAttributesOf(string $text): array
    {
        if (!str_ends_with($text, '}')) {
            return [];
        }
        $quote = null;
        $open = null;
        foreach (str_split($text) as $i => $ch) {
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;

                continue;
            }
            if ($ch === '{') {
                $open = $i;
            }
        }
        if ($open === null) {
            return [];
        }
        $payload = substr($text, $open + 1, -1);
        // The INLINE surface, so the writer reads back what the parser reads:
        // a trailing block whose interior holds a tab is literal text now, and
        // treating it as attributes here would re-attach on the way out what
        // the parser had just declined (PART 4, markup-carve/carve#906).
        //
        // NOT DEMONSTRABLE FROM SOURCE, and that is stated rather than hidden.
        // The only caller needs a reference IMAGE that already carries
        // attributes, and after the parser change a tab-bearing block yields
        // none - so this branch never sees a payload the parser accepted, and a
        // mutation putting the general gate back survives. It is kept because
        // the two surfaces have to agree, and a tree reaching the writer from
        // the AST codec rather than from source is not bound by the parser.
        if (!AttributeParser::isValidInlinePayload($payload)) {
            return [];
        }

        return AttributeParser::parse($payload);
    }

    protected function renderImage(Image $node): string
    {
        // An UNRESOLVED reference image round-trips via its verbatim source.
        // The guard used to live in renderInline()'s dispatch, so it covered an
        // image in a paragraph and not one inside a figure: `![a][nope]` with a
        // caption came out `![a]()`, the label gone and the destination empty,
        // and the re-parse was a different document - PART 11 §1's invariant
        // broken inside this engine alone (carve-php#751).
        //
        // It belongs HERE, where every caller is covered, which is where
        // carve-js keeps it.
        $raw = UnresolvedReference::sourceOf($node);
        if ($raw !== null) {
            return $raw;
        }

        $title = $node->getTitle() === null ? '' : ' "' . $this->escapeQuoted($node->getTitle()) . '"';

        // A RESOLVED reference image writes the reference, for the same reason a
        // reference link does (PART 12 §10): the definition is in the tree now,
        // so both halves round-trip. Inlining here while the definition is also
        // emitted wrote the destination TWICE - once folded into the image and
        // once as the definition line (carve#642).
        $referenceLabel = $node->getReferenceLabel();
        if ($referenceLabel !== null && $referenceLabel !== '') {
            $rawRef = $node->getRawReferenceLabel();
            if ($rawRef !== null) {
                return $rawRef;
            }

            return '![' . $this->escapeImageAlt($node->getAlt()) . '][' . $referenceLabel . ']'
                . $this->renderAttrsExcept($node, $this->definitionAttributes[$referenceLabel] ?? []);
        }

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
        // A code span is verbatim too, so an authored U+E000 is the CHARACTER
        // here as much as inside a fence - and normalize() would otherwise
        // rewrite it to `\ `, a literal backslash and a space inside backticks
        // (carve-php#829). Same sentinel protectVerbatim() uses.
        $content = str_replace("\u{E000}", $this->verbatimSentinels[4], $content);
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

    /**
     * renderAttrs(), minus keys the DEFINITION already carries.
     *
     * Resolution copies a definition's attributes onto every link resolving the
     * label so HTML can render them (PART 9R R1). They belong to the definition
     * on the wire (PART 12 §10), so writing them at the reference too says the
     * same thing twice and does not re-parse to the same tree.
     *
     * @param \MarkupCarve\Carve\Node\Node|null $node
     * @param array<string, string> $definitionAttributes
     */
    protected function renderAttrsExcept(?Node $node, array $definitionAttributes): string
    {
        if ($node === null || $definitionAttributes === []) {
            return $this->renderAttrs($node);
        }

        $own = $node->getAttributes();
        foreach ($definitionAttributes as $key => $value) {
            // CLASSES SUBTRACT PER TOKEN. `class` is the one attribute that
            // MERGES rather than replaces: a `{.lead}` line above and a `{.trail}`
            // block at the reference arrive on the node as the single string
            // `lead trail`, which equals neither source. Comparing whole values
            // therefore subtracted nothing, and the writer emitted `{.lead
            // .trail}` beside a reference that already said `.trail` - the
            // duplicate growing by one on every pass (carve-php#839).
            if ($key === 'class') {
                $stated = preg_split('/\s+/', trim((string)$value)) ?: [];
                $mine = preg_split('/\s+/', trim((string)($own['class'] ?? ''))) ?: [];
                $left = array_values(array_filter(
                    $mine,
                    static fn (string $class): bool => $class !== '' && !in_array($class, $stated, true),
                ));
                if ($left === []) {
                    unset($own['class']);
                } else {
                    $own['class'] = implode(' ', $left);
                }

                continue;
            }
            if (($own[$key] ?? null) === $value) {
                unset($own[$key]);
            }
        }
        if ($own === $node->getAttributes()) {
            return $this->renderAttrs($node);
        }

        // NOT via a clone. Both `setAttributes()` and `setAttributesWithOrder()`
        // MERGE into what the node already holds, so setting the subtracted list
        // on a copy put every removed key straight back - this subtraction could
        // never fire, on any input, for as long as it has existed
        // (carve-php#831). The attribute list is rendered directly instead.
        return $this->renderAttrList($own, $node->getAttributeOrder());
    }

    protected function renderAttrs(?Node $node): string
    {
        if ($node === null) {
            return '';
        }

        return $this->renderAttrList($node->getAttributes(), $node->getAttributeOrder());
    }

    /**
     * The `{...}` block for an attribute list, in the author's slot order.
     *
     * Takes the LIST rather than the node so a caller can render a subset, which
     * renderAttrsExcept() needs and could not express through a node copy.
     *
     * @param array<string, string> $attrs
     * @param list<string> $order
     */
    protected function renderAttrList(array $attrs, array $order): string
    {
        if ($attrs === []) {
            return '';
        }
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
            $value = $attrs[$slot];
            // EXACT key match, not case-insensitive: `LANG` and `lang` are
            // different attribute names, so folding here rewrote
            // `[x]{LANG=fr}` into `[x]{:fr}` and changed the name, which
            // breaks PART 11 SS1 (carve#1137).
            if ($slot === 'lang' && ($value === '' || preg_match('/^[A-Za-z0-9]{1,8}(?:-[A-Za-z0-9]{1,8})*$/D', $value) === 1)) {
                $parts[] = ':' . $value;
            } elseif ($value === '' && $this->isAttrIdentifier($slot)) {
                // PART 11 SS6c: a value-less attribute comes back as the bare
                // name, which is the production the language has for it. A key
                // needing escaping has no bare spelling to fall back to.
                $parts[] = $this->escapeAttrKey($slot);
            } else {
                $parts[] = $this->escapeAttrKey($slot) . '=' . $this->quoteAttrValue($value);
            }
        };

        if ($order !== []) {
            foreach ($order as $slot) {
                $emit($slot);
            }
            foreach ($attrs as $key => $_value) {
                // An id with no `#id` slot is a GENERATED one - since carve#750
                // a heading's slugged id is on the wire, so a decoded node
                // carries it - and a writer reproduces what the author wrote.
                // Emitting it here put `{#Notes}` into 39 corpus documents
                // whose source has no attribute block at all.
                if ((string)$key === 'id' && !in_array('#id', $order, true)) {
                    continue;
                }
                $emit((string)$key);
            }
        } else {
            // NO SLOTS AT ALL. An `id` here is a GENERATED one - since carve#750
            // a heading's slugged id is published, and an AUTHORED id always
            // carries its `#id` slot - so emitting it writes `{#Welcome}` above
            // a heading whose source has no attribute block. A programmatic
            // tree that wants the id in the source records the slot.
            if (!array_key_exists('id', $attrs)) {
                $emit('#id');
            }
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
                $lines[$i] = $this->verbatimSentinels[3] . $line;
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
            fn (array $m): string => str_repeat($this->verbatimSentinels[0], (int)mb_strlen($m[0], 'UTF-8')),
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
            // THE RUN IS `whitespace`, A SPACE OR A TAB, AND NOTHING ELSE.
            // `\S` under `/u` is the Unicode property, so `[^\S<NBSP>]` reached
            // U+000B, U+000C, U+0085, U+1680, U+2000, U+2009, U+200A, U+202F,
            // U+205F and U+2028 - every one of them CONTENT under carve#890,
            // where `whitespace = ' ' | '\t'`. The writer DELETED them, so a
            // paragraph whose lines carry an invisible character came back a
            // character short and `to_html(fmt(x)) == to_html(x)` failed on it.
            // Excluding NBSP by name was the tell: the class was wide enough to
            // need an exception carved out of it, and NBSP was only the member
            // anybody had noticed.
            $next = $lines[$i + 1] ?? null;
            if ($next !== null && preg_replace('/[\s\x{00A0}]+/u', '', $next) !== '') {
                continue;
            }
            $lines[$i] = (string)preg_replace('/[ \t]+$/', '', $line);
        }
        $text = implode("\n", $lines);
        $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);

        $out = $this->restoreVerbatim($this->trimNonNbsp($text));

        // A DOCUMENT MAY NOT BEGIN WITH U+FEFF, because the parser reads a BOM
        // at byte 0 as the file's encoding mark and removes it (PART 12). A
        // paragraph whose first character is a BOM is ordinary content - it
        // only reaches byte 0 because the writer dropped the indentation run in
        // front of it - so writing it bare turns `<p>&#65279;</p>` into an
        // empty document on the next read. One leading SPACE restores the
        // distinction: it is an indentation run the parser strips again, and it
        // keeps the BOM off byte 0. There is no escape for U+FEFF to reach for
        // instead.
        if (str_starts_with($out, "\u{FEFF}")) {
            $out = ' ' . $out;
        }

        return $out . "\n";
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
        // An authored U+E000 inside verbatim content is the CHARACTER, not an
        // escape. normalize() rewrites every U+E000 to `\ `, which is right
        // outside verbatim and wrong inside it - escapes do not resolve in a code
        // block, so `\ ` there is a literal backslash and a space, and
        // toHtml(fmt(x)) != toHtml(x) (carve-php#829). Carrying it under its own
        // sentinel keeps it out of that rewrite; restoreVerbatim puts the
        // character back. carve-rs already emits it as itself.
        $content = str_replace("\u{E000}", $this->verbatimSentinels[4], $content);
        $content = (string)preg_replace_callback(
            '/[ \t]+(?=\n|$)/',
            fn (array $m): string => strtr(
                $m[0],
                [' ' => $this->verbatimSentinels[0], "\t" => $this->verbatimSentinels[1]],
            ),
            $content,
        );
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if ($line === '') {
                $lines[$i] = $this->verbatimSentinels[2];
            }
        }

        return implode("\n", $lines);
    }

    protected function restoreVerbatim(string $text): string
    {
        $result = strtr($text, [
            $this->verbatimSentinels[0] => ' ',
            $this->verbatimSentinels[1] => "\t",
            $this->verbatimSentinels[2] => '',
            // Back to the character itself - see protectVerbatim().
            $this->verbatimSentinels[4] => "\u{E000}",
        ]);

        // U+E004 marks a paragraph line that must not begin at column 0. It
        // resolves AFTER normalize()'s trims, which would otherwise strip a
        // plain leading space when the paragraph is the document's first block.
        return str_replace($this->verbatimSentinels[3], ' ', $result);
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

    /**
     * Trim the document's own leading and trailing whitespace.
     *
     * `whitespace` is a space or a tab (PART 1, markup-carve/carve#890), plus
     * the line endings this is trimming at a document boundary. Every other
     * invisible character is CONTENT: the class was `[^\S<NBSP>]` under `/u`,
     * i.e. the Unicode property with NBSP carved out by name, and it ate a
     * trailing U+000C, U+0085, U+1680, U+2000 or U+2028 off the end of the
     * document - so `fmt` deleted the last character of a document that ended
     * in one. Excluding NBSP by name was the tell; NBSP was only the member
     * anybody had noticed.
     */
    protected function trimNonNbsp(string $text): string
    {
        return trim($text, " \t\n\r");
    }

    protected function trimEndNonNbsp(string $text): string
    {
        return rtrim($text, " \t\n\r");
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

    /**
     * The sigil for an alignment, read off the parser's own set so the writer
     * and the reader cannot drift.
     */
    protected function alignMarker(string $align): string
    {
        $marker = array_search($align, BlockParser::TABLE_ALIGNMENT_MARKERS, true);

        return $marker === false ? '' : $marker;
    }

    protected function escapeText(
        string $text,
        bool $opensBlockLine = false,
        bool $nextOpensVerbatim = false,
    ): string {
        // ONLY WHAT WOULD CHANGE THE RE-PARSE. The class here was every C0
        // control but tab and newline, plus DEL and the whole C1 block - a
        // blanket sanitizer, and the writer was the only artifact applying it.
        // The parser keeps those characters, the AST carries them, and the HTML
        // renderer emits them (corpus 261), so `fmt` was the one place an
        // author's byte disappeared: `to_html(fmt(x)) == to_html(x)` failed on
        // any document holding a vertical tab, a form feed or a C1 character.
        //
        // `\r` is the exception and keeps being stripped, because it is not
        // inert: `newline = '\n' | '\r\n' | '\r'`, so emitting one would end
        // the line and re-parse the rest of the text node as a following block.
        // A parse never produces one - line endings are normalized first - but
        // PART 12 lets an ingested tree carry any string at all.
        $text = str_replace("\r", '', $text);
        if (preg_match('/^\[\^[^\]\n]+\]$/u', $text) === 1) {
            return $text;
        }

        $minimal = $this->escapeMode === self::ESCAPE_MODE_MINIMAL;
        // `!` AND `$` JOIN THEIR CLASSES SO THE BINDING CASE CAN BE FORCED.
        // Both are returned bare below wherever they do not bind, so the only
        // renders that change are the ones where the escape is structural: `!`
        // was already in the conservative class and is new to the minimal one,
        // and `$` was in neither.
        $pattern = $minimal
            ? '/([\\\\`"\'^!$])/'
            : '/([\\\\`*_{}\[\]()#+\-.!~^\/<>@%|=:;"\'$])/';
        $insideNote = $this->inlineNoteDepth > 0;

        return (string)preg_replace_callback(
            $pattern,
            static function (array $match) use (
                $text,
                $opensBlockLine,
                $insideNote,
                $minimal,
                $nextOpensVerbatim,
            ): string {
                $char = $match[1][0];
                $offset = $match[1][1];
                if ($char === '^' && self::caretOpensACaption($text, $offset, $opensBlockLine)) {
                    // Forced in BOTH modes - see the note on the method.
                    return '\\^';
                }
                // `!` stays in the conservative class, which escaped it before
                // this guard existed and still does; the guard decides the
                // MINIMAL pass, which is the one that has to be winnable.
                if ($char === '!' && $minimal && !self::sigilBindsToAVerbatimRun($text, $offset, $nextOpensVerbatim)) {
                    return '!';
                }
                // `$` was in NEITHER class, so both passes wrote it bare and
                // both are unchanged away from the binding case.
                if ($char === '$' && !self::sigilBindsToAVerbatimRun($text, $offset, $nextOpensVerbatim)) {
                    return '$';
                }
                if ($char === '^' && !self::caretOpensAConstruct($text, $offset, $insideNote)) {
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
     * Does a `!` or `$` here BIND to the verbatim run that follows it?
     *
     * TWO SIGILS PREFIX A VERBATIM RUN and no others: `!` opens an inline
     * literal (PART 9 §27) and `$` opens inline math, which §27 names as the
     * shape the literal mirrors. Written bare in front of a backtick run either
     * one stops being text and becomes the construct's marker.
     *
     * §27 names the `!` case outright: "A literal `!` immediately before a
     * backtick run is therefore written `\!` - the single case this construct
     * reinterprets."
     *
     * FORCED IN THE MINIMAL MODE FOR THE SAME REASON THE CAPTION CARET IS
     * FORCED IN BOTH {@see self::caretOpensACaption()}. The minimal/conservative
     * decision is per DOCUMENT. Written bare in the minimal pass the `!` binds,
     * so the minimal render re-parses with a `literal_inline` where the tree has
     * a text `!` beside a code span - a difference the text and escaped-text
     * merge cannot absorb, unlike an ordinary escape - and the WHOLE document
     * escalates to conservative, which then escapes every candidate in it. A
     * paragraph of `foo (bar) #baz 50% a-b` that round-trips bare on its own
     * came back as `foo \(bar\) #baz 50\% a\-b` because of a `!` in an unrelated
     * paragraph below it (markup-carve/carve-php#1412). That is the
     * over-escaping PART 11 §4 forbids.
     *
     * THE `$` CASE IS NOT REACHABLE FROM A PARSE and is a defect all the same.
     * `$` sat in neither escape class, so an INGESTED tree (PART 12) holding a
     * text node that ends in `$` beside a code span was written as `a $` plus a
     * backtick run and read back as MATH - `toHtml(fmt(x)) == toHtml(x)` broken
     * outright, not merely over-escaped. The `!` case reaches the same seam from
     * a parse, because an unclosed run is written back CLOSED and the adjacency
     * the source did not have appears in the output.
     *
     * ONLY THE NODE'S LAST CHARACTER can abut the run. A sigil with more text
     * after it is followed by that text, and a backtick INSIDE this node is
     * escaped by this same pass, so no run forms there and the bare sigil is
     * already correct. A doubled `$$` needs only its last one escaped for the
     * same reason: the first is then followed by a backslash rather than a run.
     *
     * @param string $text
     * @param int $offset
     * @param bool $nextOpensVerbatim Whether the next sibling renders as a
     *   backtick run. A code span is the only inline whose written form opens
     *   with one; an inline literal opens with its own `!` and math with its
     *   own `$`.
     */
    private static function sigilBindsToAVerbatimRun(string $text, int $offset, bool $nextOpensVerbatim): bool
    {
        return $nextOpensVerbatim && $offset === strlen($text) - 1;
    }

    /**
     * Does this node's written form OPEN with a backtick run a sigil binds to?
     *
     * TWO NODES ARE WRITTEN THROUGH {@see self::renderCode()}, not one: a code
     * span, and a raw inline, which is the same run with a `{=format}` suffix.
     * Reading only the code span left an ingested `text("a $")` beside a raw
     * inline written as `a $` plus a run, which came back as MATH holding the
     * format block - the `toHtml(fmt(x)) == toHtml(x)` break this guard exists
     * to close, on the node that is easy to forget because its type name says
     * nothing about backticks.
     *
     * AN EMPTY CODE SPAN IS THE EXCEPTION. It writes as a bare `` `` ``, and a
     * sigil does not bind to it: `` !`` `` parses as a text `!` beside an empty
     * code node, so escaping there would add a `\!` that PART 11 §2 forbids -
     * the same over-escaping this change removes, one shape smaller.
     *
     * An empty RAW inline is NOT exempt, and not because it round-trips: it
     * does not, with or without a sigil beside it. `` ``{=html} `` reads back
     * as a code span holding the format block, so an empty raw inline has no
     * source spelling at all - filed as markup-carve/carve-php#1419, and out of
     * this guard's reach. The escape is kept there because it preserves the
     * sigil's own text, which is strictly more than the bare form keeps.
     */
    private static function opensAVerbatimRun(?Node $node): bool
    {
        if ($node instanceof RawInline) {
            return true;
        }

        return $node instanceof Code && $node->getContent() !== '';
    }

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
        if ($next !== ' ') {
            return false;
        }

        return $offset === 0 && $opensBlockLine;
    }

    /**
     * A note's content, rendered with footnote recognition off.
     *
     * The reader turns it off for the whole content at every depth, so the
     * writer has to hold the same frame while it walks the children: in
     * `^[a ^[b ^[c] d] e]` the parse finds ONE note and two runs of ordinary
     * text, and the writer that escaped the inner carets wrote a document that
     * no longer said that (markup-carve/carve#1191).
     */
    protected function renderInlineNoteContent(InlineFootnote $node): string
    {
        $this->inlineNoteDepth++;
        try {
            return $this->renderInlines($node->getChildren());
        } finally {
            $this->inlineNoteDepth--;
        }
    }

    private static function caretOpensAConstruct(string $text, int $offset, bool $insideNote): bool
    {
        $next = $text[$offset + 1] ?? '';
        // `^[` opens an inline footnote - but only where a note can open at
        // all. PART 9 §16 rules out three positions, and none of them needs an
        // escape because the bare spelling re-parses as the same text
        // (markup-carve/carve#1191).
        if ($next === '[') {
            return !$insideNote && self::inlineNoteCouldOpen($text, $offset + 1);
        }

        // `{^` opens a braced superscript and `^}` closes one. Either half
        // bare would let the pair form around content it does not own.
        return ($text[$offset - 1] ?? '') === '{' || $next === '}';
    }

    /**
     * Would the bracketed run at $openPos give a note a body to hold?
     *
     * "Empty or whitespace-only (`^[]`, `^[ ]`) is literal; an unclosed `^[…`
     * is literal." Both are decided by the run itself, so ask the reader's own
     * scan where it closes and look at what is inside. A run this text node
     * does not close is one the document does not close either: the caret sits
     * in a text node precisely because no note formed around it.
     *
     * @param string $text
     * @param int $openPos
     */
    private static function inlineNoteCouldOpen(string $text, int $openPos): bool
    {
        $close = BracketScanner::balancedBracketEnd($text, $openPos);
        if ($close === null) {
            return false;
        }

        // The parser's whitespace set, not PHP's. `trim()` also strips a
        // vertical tab and a NUL, which `parseInlineFootnote` does not, so an
        // ingested `^[<VT>]` is a real note there and would have been written
        // bare here - the one direction this rule must never get wrong.
        $body = substr($text, $openPos + 1, $close - $openPos - 1);

        return trim($body, StringUtil::WHITESPACE_CHARS) !== '';
    }

    /**
     * An image's alt text, written between the `![` and its closing `]`.
     *
     * The run is RAW: it lands in an HTML attribute, nothing inside it is
     * inline-parsed, and no escape inside it is resolved - `![t\]z](/i.png)`
     * gives `alt="t\]z"`, backslash included. So the writer cannot neutralize
     * anything here, only write the run or not write it, and it asks the
     * READER'S OWN SCAN which it is. `![t[z]](/i.png)` came back written
     * `t\[z\]` on the premise the run stops at the first `]`, which is the
     * premise markup-carve/carve#1206 removed from the grammar, and the
     * backslash then compounded one per pass.
     *
     * An alt text with no Carve spelling at all - a bare unbalanced `]`, or a
     * run ending inside an unclosed code span - keeps the escape. `parse`
     * cannot produce one; an ingested AST can. The escape is not a faithful
     * representation of that value either, but it is better than none:
     * `![t]z](/i.png)` written verbatim is a paragraph of literal text where
     * the escaped spelling is still an image, and it settles, because the
     * escaped alt is itself representable and the next pass writes the same
     * bytes.
     */
    protected function escapeImageAlt(string $text): string
    {
        if (BracketScanner::rawRunCloses($text)) {
            return $text;
        }

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

    /**
     * An abbreviation, written between the `*[` and `]:` of its definition.
     *
     * Deliberately NOT one of the raw bracketed runs below. The definition is
     * read as `*[([A-Za-z0-9]+)]: `, so neither a backslash nor a bracket can
     * reach this from a parse, and an ingested one carrying either has no
     * definition spelling with or without the escape. A shared shape, not a
     * shared rule.
     */
    protected function escapeBracketText(string $text): string
    {
        return str_replace(['\\', ']'], ['\\\\', '\\]'], $text);
    }

    /**
     * A run written between brackets whose reader scans it FLAT: a container
     * label, a code-fence label, a footnote id.
     *
     * Written as authored, with no escape, because there is nothing an escape
     * could buy. Each of these readers anchors on the whole of what follows and
     * stops at the first `]` without resolving anything, so a label holding a
     * `]` fails to match with a backslash exactly as it fails without one - the
     * construct is not a label either way. What the escape did do was survive
     * into the label a reader that DID match handed back, so a backslash grew
     * one more backslash on every format pass and two of the five sites changed
     * what the document says (a container label is rendered). See
     * markup-carve/carve#1197 and markup-carve/carve-js#1068.
     */
    protected function writeFlatBracketRun(string $text): string
    {
        return $text;
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
