<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use InvalidArgumentException;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Util\StringUtil;
use RuntimeException;
use Throwable;

/**
 * Converts HTML to Djot markup
 *
 * Useful for importing HTML content from CMS systems, WYSIWYG editors,
 * or web scraping into Djot format.
 *
 * Key Djot requirements handled:
 * - Blank lines required around block elements (headings, code blocks, lists)
 * - Nested lists require blank line before the nested portion
 *
 * SECURITY: this converter is NOT a sanitizer. Its output is Djot/Carve markup
 * that may still contain content derived from the input; render untrusted input
 * with safe mode enabled on the downstream renderer. By default the converter
 * IGNORES any `data-djot-src` round-trip attribute on the input (it would
 * otherwise be emitted verbatim as raw Carve, allowing a crafted attribute to
 * inject a raw-HTML block). Only enable round-trip extraction via the
 * constructor flag when the HTML is TRUSTED (e.g. produced by carve itself).
 */
class HtmlToCarve
{
    use EscapesCarveConstructs;

    /**
     * Stands in for the HARD LIST BOUNDARY (PART 9 §11 N1a) until the
     * final clean-up pass, which expands it into the three blank lines the
     * boundary is spelled with.
     *
     * It cannot be emitted as three blank lines directly: `cleanup()` collapses
     * every run of blank lines to one, which is right for the layout the walk
     * produces and would erase the one place the run carries meaning. A
     * sentinel line survives that collapse and is expanded once it is over.
     *
     * The delimiter is `\x01` rather than a NUL, because `trim()` and
     * `ltrim()` count a NUL as whitespace: the leading byte was stripped by the
     * time the expansion ran, so the sentinel no longer matched and LEAKED into
     * the output as literal text. Neither is a byte any HTML document hands
     * back as text.
     *
     * @var string
     */
    protected const LIST_BOUNDARY = "\x01carve-list-boundary\x01";

    /**
     * The stand-in a description with no spelling takes on the AST exit only.
     *
     * `\x01` cannot appear in a document - the same reason LIST_BOUNDARY above
     * uses it - so the line this writes parses as an ordinary description and
     * nothing an author can type collides with it. `emptyTheStoodInDescriptions`
     * takes the content back out; the string never reaches a caller.
     *
     * @var string
     */
    protected const EMPTY_DESCRIPTION = "\x01carve-empty-description\x01";

    /**
     * @var list<string>
     */
    protected const ADMONITION_TYPES = ['note', 'tip', 'warning', 'danger', 'info', 'success', 'example', 'quote'];

    /**
     * The seven HTML elements the compact semantic span spells exactly.
     *
     * `abbr`, `time` and `kbd` are core names; `samp`, `var`, `cite` and `dfn`
     * only render as elements when SemanticSpanExtension is registered, so a
     * core render returns `<span samp="">` rather than `<samp>`. That is still a
     * kept semantic a reader can recover, where unwrapping discarded it.
     *
     * @var list<string>
     */
    protected const SEMANTIC_SPAN_ELEMENTS = ['abbr', 'time', 'kbd', 'samp', 'var', 'cite', 'dfn'];

    /**
     * Elements dropped whole, with everything under them.
     *
     * Named once so the walk that reports the drop and the content key that
     * must not count the dropped text read the same list.
     *
     * @var list<string>
     */
    protected const ACTIVE_ELEMENTS = ['script', 'style', 'template', 'noscript'];

    /**
     * Elements that write something with no text of their own to write.
     *
     * The companion to `ACTIVE_ELEMENTS` for `writesNothing()`: an empty
     * `<div>` writes nothing, an empty `<img>` writes its alternative text and
     * an `<hr>` writes a rule. Anything not named here and holding no text is
     * taken to write nothing.
     *
     * @var list<string>
     */
    protected const SELF_STANDING_ELEMENTS = [
        'img', 'br', 'hr', 'input', 'textarea', 'select', 'button', 'iframe',
        'embed', 'object', 'video', 'audio', 'canvas', 'svg', 'math', 'picture',
        'progress', 'meter', 'output',
    ];

    /**
     * The block tags `roundtrip` preserves as a raw HTML BLOCK.
     *
     * Every other element the mode preserves takes the inline span, and the
     * split is not a taste: these are BLOCK-level and carry blocks, so an
     * inline span around them would put block markup inside a paragraph. They
     * are also the only block-level names this converter has no Carve
     * construct for - `<article>` and its neighbours map to containers and go
     * on mapping (`markup-carve/carve-php#1713`).
     *
     * Matched against carve-js per tag rather than ported from memory.
     *
     * @var list<string>
     */
    protected const RAW_PRESERVED_BLOCK_ELEMENTS = ['address', 'fieldset', 'figure', 'form', 'hgroup'];

    /**
     * The HTML attribute each semantic span name carries its value in.
     *
     * A name absent here has no value to carry and is always the bare boolean.
     *
     * @var array<string, string>
     */
    protected const SEMANTIC_SPAN_VALUE_ATTRIBUTE = [
        'abbr' => 'title',
        'dfn' => 'title',
        'time' => 'datetime',
    ];

    /**
     * The import adapters whose input can carry footnote-shaped HTML.
     *
     * Word and Google Docs are the two the portable adapter list names for
     * word-processor exports, and the recognition below is shape-driven, so
     * the same pass reads LibreOffice's and pre-3.x Pandoc's spellings too.
     * `generic` deliberately stays out: it takes arbitrary HTML, where a
     * mutually linked anchor pair is not proof of a footnote, and the caller
     * naming an adapter is the declaration of provenance that makes the
     * recognition safe.
     *
     * @var list<string>
     */
    protected const FOOTNOTE_SHAPED_ADAPTERS = ['word', 'google-docs'];

    /**
     * The elements a footnote definition body can be spelled as.
     *
     * @var list<string>
     */
    protected const FOOTNOTE_DEFINITION_BLOCKS = ['li', 'div', 'section', 'aside', 'p', 'td', 'blockquote'];

    /**
     * The elements a per-footnote wrapper can be spelled as.
     *
     * Word wraps each definition in `<div style='mso-element:footnote' id=ftn1>`
     * and LibreOffice in `<div id="sdfootnote1">`, so the block holding the
     * body is one level above the paragraph the back-anchor sits in.
     *
     * @var list<string>
     */
    protected const FOOTNOTE_WRAPPER_BLOCKS = ['div', 'li', 'section', 'aside'];

    /**
     * The `<annotation>` encodings that declare TeX, lowercased.
     *
     * Matched exactly against the whole value, never as a substring: `tex` is a
     * substring of `text/plain`, so the substring test this replaces read a
     * plain-text payload as an equation. `MathType-MTEF` is the same mistake
     * from the other side - a declared encoding that is emphatically not TeX.
     *
     * @var list<string>
     */
    protected const MATH_TEX_ENCODINGS = ['application/x-tex', 'text/x-tex', 'latex'];

    /**
     * An element the renderer names not at all - see `derivedElementNaming()`.
     *
     * @var array{role: list<string>, aria-label: list<string>}
     */
    protected const DERIVES_NOTHING = ['role' => [], 'aria-label' => []];

    /**
     * The roles a tab set or a code group is written with.
     *
     * TWO SPELLINGS OF ONE SHAPE: `TabsExtension` and `CodeGroupExtension` put
     * `group` on the wrapper under their CSS mode and `tablist` under their
     * ARIA one. Which mode produced a document is not readable from it, so both
     * are the renderer's.
     *
     * @var list<string>
     */
    protected const DERIVED_GROUP_ROLES = ['group', 'tablist'];

    /**
     * The roles a tab or code-group PANEL is written with, the same two modes
     * apart: `group` beside a name the CSS mode reads off the panel's own
     * control, `tabpanel` beside the `aria-labelledby` the ARIA mode writes.
     *
     * @var list<string>
     */
    protected const DERIVED_PANEL_ROLES = ['group', 'tabpanel'];

    /**
     * When true, trust and re-emit a `data-djot-src` round-trip attribute on the
     * input. Default false: untrusted HTML must not be able to smuggle raw Carve
     * (incl. a raw-HTML block) through that attribute.
     */
    protected bool $trustedRoundTrip = false;

    /**
     * Maps a CSS `text-align` value to the class name that should carry it.
     *
     * Empty by default: `style` is skipped wholesale, so block alignment is
     * dropped. Table cells are the exception - alignment there has a native
     * Carve representation and extractTableCellAlignment() always reads it.
     *
     * Editors that produce alignment as inline CSS (Tiptap's TextAlign, Word,
     * Google Docs) otherwise lose it on import. The class names stay the
     * caller's choice because they belong to the consuming stylesheet, not to
     * Carve: e.g. ['center' => 'text-center', 'right' => 'text-right'].
     *
     * @var array<string, string>
     */
    protected array $alignmentClasses = [];

    /**
     * Emit `::: list-table` for a table whose cells hold block content.
     *
     * A pipe-table cell is one line of inline content, so a cell holding two
     * paragraphs, a list or a code block has nowhere to go and degrades to its
     * text. ListTable is the construct for exactly that case (extensions §5),
     * and cells there are list items, so they hold full block content.
     *
     * OFF by default, and it has to be: pipe tables are Tier-1 core and always
     * on, while ListTable is Tier-2 and off until a processor enables it - so
     * emitting one for a consumer that has not is worse than the degradation it
     * replaces, `<div class="list-table">` around a nested list instead of a
     * table. The caller knows which processor reads the output; this converter
     * does not.
     *
     * Only a table that NEEDS it switches form. One whose cells are all inline
     * keeps the pipe form, so turning this on does not rewrite every table in a
     * document.
     */
    protected bool $listTableForBlockCells = false;

    protected string $importMode = 'safe';

    protected string $importAdapter = 'generic';

    protected int $maxDiagnostics = 1000;

    /**
     * @param bool $trustedRoundTrip
     * @param array<string, string> $alignmentClasses text-align value => class name
     * @param bool $listTableForBlockCells Emit `::: list-table` for a table with block-content cells.
     * @param string $importMode
     * @param string $importAdapter
     * @param int $maxDiagnostics
     * @param array<string, string> $labels The `labels` map the HTML was RENDERED with, keyed as in HtmlRenderer::LABEL_DEFAULTS
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        bool $trustedRoundTrip = false,
        array $alignmentClasses = [],
        bool $listTableForBlockCells = false,
        string $importMode = 'safe',
        string $importAdapter = 'generic',
        int $maxDiagnostics = 1000,
        protected array $labels = [],
    ) {
        $modes = ['safe', 'semantic', 'roundtrip'];
        $adapters = ['generic', 'tiptap', 'prosemirror', 'ckeditor', 'tinymce', 'word', 'google-docs'];
        if (!in_array($importMode, $modes, true)) {
            throw new InvalidArgumentException('Unknown HTML import mode: ' . $importMode);
        }
        if (!in_array($importAdapter, $adapters, true)) {
            throw new InvalidArgumentException('Unknown HTML import adapter: ' . $importAdapter);
        }
        if ($maxDiagnostics < 0) {
            throw new InvalidArgumentException('maxDiagnostics must not be negative');
        }
        $this->trustedRoundTrip = $trustedRoundTrip;
        $this->alignmentClasses = array_change_key_case($alignmentClasses);
        $this->listTableForBlockCells = $listTableForBlockCells;
        $this->importMode = $trustedRoundTrip ? 'roundtrip' : $importMode;
        $this->importAdapter = $importAdapter;
        $this->maxDiagnostics = $maxDiagnostics;
    }

    /**
     * Convert HTML and return an ordered report of lossy import decisions.
     *
     * THE CONVERSION RUNS FIRST, and the report is read off what it EMITTED.
     * The report used to be produced from the input DOM alone, by predicting
     * what the serializer would do with each node - and a prediction of an
     * open-ended serializer is a second, hand-maintained copy of it. Ten
     * distinct contexts were found where the two disagreed, five of them
     * patched one at a time before the next one appeared: a pipe row versus a
     * list-table item, a caption, a `<td>` with no owning table, a table nested
     * in a cell, the stored source `trustedRoundTrip` emits without converting
     * its descendants, and the footnote definitions the `word` and
     * `google-docs` adapters move out of a cell before serialization
     * (carve-php#1346).
     *
     * None of those is knowable from the input. All of them are obvious in the
     * output. So the order is inverted here: convert, then ask the result.
     */
    public function convertWithReport(string $html): HtmlImportResult
    {
        $carve = $this->convert($html);

        // Handed to the walk through a property rather than an argument:
        // `inspectImportLoss()` is protected on a non-final class, so a
        // subclass may override it, and adding a parameter would make such an
        // override a fatal incompatible-signature error at class-declaration
        // time - which no test of behavior catches, because the class never
        // loads.
        $this->inspectedCarve = $carve;

        try {
            $diagnostics = $this->inspectImportLoss($html);
            foreach ($this->captionFlattenDiagnostics as $diagnostic) {
                if (count($diagnostics) >= $this->maxDiagnostics) {
                    break;
                }
                $diagnostics[] = $diagnostic;
            }
        } finally {
            $this->inspectedCarve = null;
        }

        return new HtmlImportResult(
            $carve,
            $this->importMode,
            $this->importAdapter,
            $diagnostics,
        );
    }

    /**
     * Convert HTML to the public PART 12 AST and retain the import report.
     *
     * The AST is deliberately read from the canonical-source exit. This makes
     * the two public exits one invariant: if the writer loses structure, the
     * shared expected.ast.json fixture exposes it instead of letting an
     * independently-built tree and source each pass against separate goldens.
     */
    public function convertToAstWithReport(string $html): HtmlImportAstResult
    {
        // `finally`, because a throwing conversion must not leave the flag
        // standing: this converter is reusable and long-lived by design, and
        // the next `convertWithReport()` would then write a sentinel into
        // source a caller reads.
        $this->astExit = true;
        try {
            $source = $this->convertWithReport($html);
        } finally {
            $this->astExit = false;
        }
        $document = CarveConverter::create()->parse($source->value);

        return new HtmlImportAstResult(
            self::withoutTheWriter((new AstCodec())->encode($document)),
            $source->mode,
            $source->adapter,
            $source->diagnostics,
        );
    }

    /**
     * The published tree with the SOURCE WRITER taken back out of it.
     *
     * This engine reads its AST back from its own written Carve, which is what
     * makes the two public exits one invariant rather than two goldens nobody
     * compares. The cost is that everything the WRITER does on the way through
     * was reaching the published tree, and two of its habits did
     * (`markup-carve/carve-php#1716`).
     *
     * ONE: ITS ESCAPES. PART 12 section 1a makes `escaped_text` a node of its
     * own that never merges with `text`, "because an escape is authored form" -
     * and on this exit no escape is authored. HTML has no Carve escapes, so
     * every backslash in the source this importer just wrote was put there by
     * the writer to keep a character from meaning what it means in Carve.
     * Reading them back as nodes published the writer's bookkeeping rather than
     * the document: `<p>a :rocket: b</p>` came out as five inline nodes where
     * the document has one. Folding them back is what section 1a's own
     * coalescing rule then asks for, and no import fixture in the shared suite
     * expects an `escaped_text` node anywhere.
     *
     * THE MERGED NODE CARRIES NO POSITION. Section 1a keeps a `pos` on a merged
     * run only where the pieces are contiguous in the source, and these are
     * not: the backslash sits between them in the source and in no version of
     * the value, so the merged text is a slice at no offset.
     *
     * TWO: ITS CEILING. A description with no Carve spelling is written as
     * `EMPTY_DESCRIPTION` so the PARSER builds the `definition_description` the
     * document has, and the stand-in is taken out here. `docs/html-import.md`
     * says why the source exit's limit is not this one's: for a structure Carve
     * SOURCE cannot spell, "the AST-returning entry point loses nothing and
     * reports nothing; the one that writes source reports this". An AST exit
     * has nothing to spell.
     *
     * Matched on the WHOLE description rather than by string replacement: the
     * stand-in is the entire content of the descriptions it was written into,
     * so anything else carrying it is not one of them and is left alone.
     *
     * @param array<string, mixed> $tree
     *
     * @return array<string, mixed>
     */
    private static function withoutTheWriter(array $tree): array
    {
        foreach ($tree as $key => $value) {
            $tree[$key] = self::asPublished($value);
        }

        return $tree;
    }

    /**
     * One value of the encoded tree, with both of the writer's habits undone.
     *
     * ON THE ENCODED TREE rather than the node model, and recursing over LISTS
     * rather than over a roster of container keys: every container spells its
     * children under its own name - `children`, `items`, `rows`, `cells` - and
     * a roster is what would rot. A table cell and a span are reached by the
     * same lines that reach a paragraph.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function asPublished(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $entry) {
                $entry = self::asPublished($entry);
                if (is_array($entry) && ($entry['type'] ?? null) === 'escaped_text') {
                    $escaped = $entry['value'] ?? '';
                    $entry = ['type' => 'text', 'value' => is_string($escaped) ? $escaped : ''];
                }
                $last = $out === [] ? null : array_key_last($out);
                $previous = $last === null ? null : $out[$last];
                if (
                    $last !== null
                    && is_array($entry)
                    && ($entry['type'] ?? null) === 'text'
                    && is_array($previous)
                    && ($previous['type'] ?? null) === 'text'
                ) {
                    $head = $previous['value'] ?? '';
                    $tail = $entry['value'] ?? '';
                    $out[$last] = [
                        'type' => 'text',
                        'value' => (is_string($head) ? $head : '') . (is_string($tail) ? $tail : ''),
                    ];

                    continue;
                }
                $out[] = $entry;
            }

            return $out;
        }
        if (($value['type'] ?? null) === 'definition_description' && self::holdsOnlyTheStandIn($value)) {
            $value['children'] = [];

            return $value;
        }
        foreach ($value as $key => $inner) {
            $value[$key] = self::asPublished($inner);
        }

        return $value;
    }

    /**
     * Is this description's whole content the stand-in the writer put there?
     *
     * @param array<mixed> $description
     */
    private static function holdsOnlyTheStandIn(array $description): bool
    {
        $children = $description['children'] ?? null;
        $only = is_array($children) && count($children) === 1 ? ($children[0] ?? null) : null;
        $inlines = is_array($only) && ($only['type'] ?? null) === 'paragraph' ? ($only['children'] ?? null) : null;
        $first = is_array($inlines) && count($inlines) === 1 ? ($inlines[0] ?? null) : null;

        return is_array($first) && ($first['value'] ?? null) === self::EMPTY_DESCRIPTION;
    }

    /**
     * Convert HTML to the public PART 12 AST.
     *
     * @return array<string, mixed>
     */
    public function convertToAst(string $html): array
    {
        return $this->convertToAstWithReport($html)->value;
    }

    /**
     * @return list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic>
     */
    protected function inspectImportLoss(string $html): array
    {
        // Built on first demand, from the output of THIS conversion, and only
        // if the walk actually reaches a question that needs it.
        $this->survivingImportAttributes = null;
        $this->emittedImportValues = [];

        $isDocument = preg_match('/^\s*(<!doctype|<html|<body)/i', $html) === 1;
        $wrapped = $isDocument ? $html : '<div>' . $html . '</div>';
        $doc = new DOMDocument();
        $doc->encoding = 'UTF-8';
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $diagnostics = [];
        $root = $doc->documentElement ?? $doc;

        try {
            if ($isDocument) {
                $this->inspectImportDocumentContainers($root, $diagnostics);
            }
            $this->inspectImportNodes($this->importTopLevelNodes($root, $isDocument), '', $diagnostics);
        } finally {
            // RELEASED HERE, not merely reset on the next call. A converter is
            // reusable and long-lived by design, so a tally left standing would
            // answer for the PREVIOUS document if a later call somehow reached
            // the pool before rebuilding it. `finally`, so a throwing walk
            // cannot leave one behind. The observation read off the same
            // render goes with it, for the same reason.
            $this->survivingImportAttributes = null;
            $this->emittedImportValues = [];
        }

        return $diagnostics;
    }

    /**
     * The nodes a reported path counts from.
     *
     * A path names the fragment the importer was handed, so neither the `<div>`
     * this method's caller wraps a fragment in to give libxml a single root nor
     * an authored `<html>`/`<head>`/`<body>` may appear in one: the wrapper is
     * the importer's own invention, and the document elements are a shape the
     * other engines' fragment parser never builds. Both are removed here, so
     * the walk itself has one rule for every node it reaches.
     *
     * `<head>` and `<body>` children run into a single sequence, which is what
     * a fragment parse of the same document produces.
     *
     * @param \DOMNode $root
     * @param bool $isDocument
     *
     * @return list<\DOMNode>
     */
    protected function importTopLevelNodes(DOMNode $root, bool $isDocument): array
    {
        $top = [];
        foreach ($root->childNodes as $child) {
            $tag = $child instanceof DOMElement ? strtolower($child->tagName) : '';
            if ($isDocument && ($tag === 'head' || $tag === 'body')) {
                foreach ($child->childNodes as $inner) {
                    $top[] = $inner;
                }

                continue;
            }
            $top[] = $child;
        }

        return $top;
    }

    /**
     * Walk a run of sibling nodes, numbering each one among ALL of them.
     *
     * The index is a position among every child node, text and comments
     * included, not among the element children alone - the other engines count
     * it that way, so an element after a text node is the second child and not
     * the first. Only elements are descended into; a text node still takes its
     * number.
     *
     * @param iterable<\DOMNode> $nodes
     * @param string $parentPath
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     * @param list<string> $skipTags Tag names numbered by a caller instead.
     */
    protected function inspectImportNodes(iterable $nodes, string $parentPath, array &$diagnostics, array $skipTags = []): void
    {
        $index = 0;
        foreach ($nodes as $child) {
            $index++;
            if ($child instanceof DOMComment) {
                // AN HTML COMMENT WITH NO INLINE SPELLING IS DROPPED, and this
                // is where the row for it is added (`markup-carve/carve#1709`).
                //
                // HERE rather than beside the conversion that decides it,
                // because this walk is the one that numbers a path in DOCUMENT
                // ORDER - and `docs/html-import.md` orders the report by the
                // position of the losing node, not by when the row was built.
                if (
                    !$this->commentStandsAmongBlocks($child)
                    && $this->commentHasNoInlineSpelling($child->textContent)
                ) {
                    $why = str_contains($child->textContent, '%}')
                        ? 'holds the comment closer'
                        : 'holds a blank line';
                    $this->addImportDiagnostic(
                        $diagnostics,
                        'element-dropped',
                        'Dropped an HTML comment: its text ' . $why
                            . ', which ends a Carve inline comment early, and the comment is not moved out of the run to make it spellable',
                        'warning',
                        $parentPath . '/comment()[' . $index . ']',
                    );
                }

                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            if (in_array(strtolower($child->tagName), $skipTags, true)) {
                continue;
            }
            $this->inspectImportNode($child, $this->importChildPath($parentPath, $child, $index), $diagnostics);
        }
    }

    /**
     * @param string $parentPath
     * @param \DOMElement $node
     * @param int $index
     *
     * @return string
     */
    protected function importChildPath(string $parentPath, DOMElement $node, int $index): string
    {
        return $parentPath . '/' . strtolower($node->tagName) . '[' . $index . ']';
    }

    /**
     * @param \DOMNode $node
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectImportNode(DOMNode $node, string $path, array &$diagnostics): void
    {
        if (!$node instanceof DOMElement) {
            return;
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::ACTIVE_ELEMENTS, true)) {
            $this->addImportDiagnostic($diagnostics, 'element-dropped', 'Dropped active <' . $tag . '> element', 'warning', $path);

            return;
        }

        if (isset($this->rawPreservedElements[$path])) {
            // THE ELEMENT WAS KEPT BYTE FOR BYTE (`markup-carve/carve-php#1713`).
            //
            // Its own refused attributes are restated rather than rolled back:
            // they are IN the output, inside the preserved bytes, and the row
            // saying an event handler survived is the one a consumer of this
            // mode might act on (`markup-carve/carve-js#1468`). Calling them
            // dropped would be a false statement about a success.
            //
            // AND THE WALK STOPS HERE. Every row from inside the element would
            // name a loss that did not happen - the subtree is in the output
            // exactly as it was written - so the descent that would produce
            // them does not run at all.
            $this->inspectImportAttributeList($node, $tag, $path, $diagnostics, true);
            // The figure says WHY in its own words, matching carve-js: it is
            // not an unsupported element - Carve has figures - it is a figure
            // around a target no `^ ` line reproduces.
            $this->addImportDiagnostic(
                $diagnostics,
                'raw-preserved',
                $tag === 'figure'
                    ? 'Preserved a <figure> as raw HTML: no Carve spelling reproduces a figure around this target'
                    : 'Preserved unsupported <' . $tag . '> element as raw HTML',
                'warning',
                $path,
            );

            return;
        }

        if ($tag === 'colgroup' && $this->isDirectTableChild($node)) {
            // A table's column description has nowhere to land: Carve has no
            // column model, and whether it should get one is a language
            // question (`markup-carve/carve#1092`) rather than this importer's
            // to answer. The drop stands, and now says so instead of claiming
            // an unwrapping that does not happen - the table walk reads rows,
            // so the element and everything under it left the document while
            // the report called it `element-unwrapped` and put a second row
            // under each `<col>` naming span metadata that is never written.
            //
            // Reported before the attribute loop, like the active elements
            // above: the whole element goes, and its own attributes go with it.
            //
            // Wording verbatim from `markup-carve/carve-rs#1006`, so the three
            // engines report the drop in the same words.
            $this->addImportDiagnostic(
                $diagnostics,
                'element-dropped',
                'Dropped <colgroup>: Carve has no column model, and a table\'s columns are only the cells its rows carry',
                'warning',
                $path,
            );

            return;
        }

        // A CONSUMED CHECKBOX ANSWERS FOR ITSELF, and for nothing else.
        //
        // The two questions below ask the OUTPUT whether this element's own
        // values reappear in it. For the `<input>` the writer turned into a task
        // marker the answer is yes and always was - the marker IS the element -
        // but the re-render spells `type="checkbox"` in lowercase, so an
        // authored `CHECKBOX` matched nothing and the report called a success a
        // drop (carve-php#1705).
        //
        // SCOPED TO THIS ELEMENT'S INSPECTION rather than skipping the walk over
        // it, because the rest of the walk is RIGHT. An `onclick` on that same
        // input is a real loss and reports today; so do a `name` and a `value`.
        // Returning early here would take all three rows out to remove two -
        // the lowercase spelling reports them, and this is the spelling that is
        // supposed to match it.
        if (isset($this->unwrappedBlockContainers[$path])) {
            // A SECTIONING WRAPPER LEFT THE DOCUMENT AND NOW SAYS SO. It used
            // to write a `::: name` fence, which renders as `<div class="name">`
            // - so the element was gone AND a class the document never carried
            // was in the output, with nothing reported either way
            // (carve-php#1721). An addition is the worse half: a reader cannot
            // tell it was not authored.
            //
            // Wording and severity are carve-js's and carve-rs's, which agree
            // here byte for byte, and they are this file's own for every other
            // unwrapped element.
            //
            // BEFORE THE ATTRIBUTE LOOP, so the row naming what happened to the
            // element stands ahead of the rows naming what happened to what it
            // carried, which is the order both sibling engines report.
            //
            // WHICH OF THE TWO ROWS IT EARNS FOLLOWS THE CONTENT, the same
            // question {@see self::reportImportElementOutcome()} already asks
            // of an element with no mapping at all (markup-carve/carve#1738).
            // An empty `<form>` had nothing an unwrap could preserve, so
            // `element-unwrapped` - which says the wrapper went and the
            // children stayed - states something about content that did not
            // happen, and this was the one path in this file still saying it
            // unconditionally.
            if ($this->hasImportContentToUnwrap($node)) {
                $this->addImportDiagnostic(
                    $diagnostics,
                    'element-unwrapped',
                    'Unwrapped unsupported <' . $tag . '> element',
                    'info',
                    $path,
                );
            } else {
                $this->addImportDiagnostic(
                    $diagnostics,
                    'element-dropped',
                    'Dropped empty <' . $tag . '> element',
                    'warning',
                    $path,
                );
            }
        }

        if ($tag === 'figure' && isset($this->captionedTableFigures[$path])) {
            // THE CAPTION SURVIVES AND THE FIGURE DOES NOT. `<table><caption>`
            // is the idiomatic HTML for a captioned table, so this shape
            // rebuilds instead of preserving (`markup-carve/carve#1704`) - and
            // what it rebuilds into is a captioned TABLE, which is a different
            // element from the one the author wrote.
            //
            // A declared loss is a ceiling, not a licence, and this row is the
            // declaration. Without it the rebuild was a silent structural
            // change, and before the rebuild existed the caption left the figure
            // entirely and came back as body prose (carve-php#1722).
            //
            // WORDING IS carve-js's, verbatim. carve-rs reports the same code at
            // the same severity but says the written table carries the figure's
            // ATTRIBUTES as well, which is not true here: this engine drops a
            // figure's own attributes on every rebuild arm and reports each one
            // separately, so carve-rs's sentence would be a false statement
            // about this output.
            $this->addImportDiagnostic(
                $diagnostics,
                'structure-unspellable',
                'A figure wrapping a table has no Carve spelling; the caption is written on the table, '
                    . 'which renders <caption> inside it',
                'warning',
                $path,
            );
        }

        if ($tag === 'figcaption' && isset($this->detachedFigureCaptions[$path])) {
            // THE CAPTION KEEPS ITS TEXT AND LOSES ITS ROLE (ruling
            // `markup-carve/carve-js#1488`). The table's own `<caption>` fills
            // Carve's one caption slot, so the figure's caption is written as
            // the paragraph after it - which is a loss worth a row and not the
            // corruption it replaces: this arm used to write a second `^ ` line
            // that re-read as a literal paragraph, so the caret was IN the
            // rendered text.
            //
            // NOT `structure-unspellable`, which is the row for the wrapper that
            // disappears when a figure around a table is BUILT - nothing is
            // built here - and not `table-degraded`, which says a table was
            // degraded and nothing about where a caption went.
            //
            // WORDING AND PATH ARE carve-js's, verbatim: this ruling landed in
            // both engines at once, so there is no older spelling to keep.
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Detached a <figcaption> into a paragraph after the table: the table\'s own <caption> fills '
                    . "Carve's one caption slot, so the figure's caption keeps its text and loses its role",
                'warning',
                $path,
            );
        }

        if ($tag === 'figure' && isset($this->unwrappedFigures[$path])) {
            // A FIGURE THAT WROTE NO CAPTION LINE IS NOT A FIGURE ANY MORE
            // (PART 9 §4b). The target is in the output and the wrapper is not,
            // which is what `element-unwrapped` says - and this engine said
            // nothing, for every one of the arms that unwraps: an uncaptioned
            // wrapper around an image, a quote, a code block or anything else,
            // and a captioned one outside `roundtrip`, where there is no
            // preserved block to keep it (carve-php#1723).
            //
            // WORDING AND SEVERITY ARE carve-rs's, byte for byte, and they are
            // this file's own for every other unwrapped element. carve-js says
            // something figure-specific at `warning` instead, and splits it
            // into two messages by whether the target was one it can write a
            // caption line for - a split that follows carve-js's target set
            // rather than this one's, so copying the words would import a
            // distinction this engine does not draw.
            //
            // BEFORE THE ATTRIBUTE LOOP, so the row naming what happened to the
            // element stands ahead of the rows naming what happened to its
            // attributes, which is the order both sibling engines report.
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Unwrapped unsupported <figure> element',
                'info',
                $path,
            );
        }

        $outerConsumedCheckbox = $this->inspectedConsumedCheckbox;
        $this->inspectedConsumedCheckbox = $tag === 'input' && isset($this->consumedCheckboxInputs[$path])
            ? $path
            : null;

        try {
            // THE ELEMENT'S OWN OUTCOME IS REPORTED FIRST, ahead of the rows
            // naming what it carried. A consumer reads the rows in order, and
            // in the other order it was told what happened to a `<video>`'s
            // `src` before it was told the `<video>` was gone - attributes
            // reported against an element nothing had yet said anything about
            // (carve-php#1737).
            //
            // This was the last site in this file writing the element row
            // AFTER the attribute rows. Every other one - the sectioning
            // wrappers, the unwrapped figures, the active elements, a
            // `<colgroup>`, an orphan caption - already reports the element
            // first, and both sibling engines report the element first for
            // every one of these shapes too.
            //
            // THE TWO BUDGETS ARE INDEPENDENT, which is what makes the order a
            // free choice rather than a behavior change: the element question
            // spends from `emittedImportValues` and the attribute questions
            // spend from `survivingImportAttributes`, so neither can consume
            // the other's survivor whichever runs first.
            if (!$this->isKnownImportElement($tag) && $tag !== 'math') {
                $this->reportImportElementOutcome($node, $tag, $path, $diagnostics);
            }

            $this->inspectImportAttributes($node, $tag, $path, $diagnostics);
        } finally {
            $this->inspectedConsumedCheckbox = $outerConsumedCheckbox;
        }

        if ($tag === 'math') {
            // Report the element, then stop - AFTER the attribute loop above,
            // so a `<math onclick=...>` still reports its handler. What stops
            // is the descent: the token stream below is consumed whole rather
            // than unwrapped, and walking it produced a row per `<mi>` and
            // `<mn>` claiming span metadata that is never emitted, with none
            // of those rows naming `<math>` as the thing at stake.
            $this->inspectMath($node, $path, $diagnostics);

            return;
        }

        if ($this->isOrphanImportCaption($node, $tag) && !$this->importContentSurvived($node)) {
            // A CAPTION WITH NOTHING TO CAPTION. Both tags are mapped, and
            // correctly so - inside their own container they come through - so
            // the outcome above is never asked of them and the walk went on to
            // their children. The writer has no slot for this one, so its text
            // left the document and the report had no arm that fired
            // (carve-php#1386).
            //
            // Reported and then STOPPED, like the other drops above it: the
            // element went and everything under it went with it, so a row per
            // descendant would name losses inside a loss already reported.
            $this->addImportDiagnostic(
                $diagnostics,
                'element-dropped',
                'Dropped <' . $tag . '>: a caption outside its own container has nothing to caption',
                'warning',
                $path,
            );

            return;
        }

        if ($tag === 'table') {
            $this->inspectTableStructure($node, $path, $diagnostics);
        }

        if ($tag === 'details' && $this->isInsideTableCell($node)) {
            // A pipe-table cell is one line of inline content, so the colon
            // fence a disclosure needs cannot open inside one and the whole
            // container degrades to its text (carve-php#1164). The degradation
            // stands - a cell has no lines to give it - but the disclosure
            // going missing is worth a line in the report.
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Replaced <details> with its content inside a table cell; a pipe-table cell cannot hold a colon fence',
                'info',
                $path,
            );
        }

        if ($tag === 'summary' && trim($node->textContent) !== '' && $this->detailsSummaryTitle($node) === null) {
            // The label role is what goes: the text becomes ordinary block
            // content inside the disclosure, and the widget comes back with
            // the extension's default summary instead of this one.
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Kept the <summary> text as block content; its label needs a quoted opener title, which cannot hold a quote or a line break',
                'info',
                $path,
            );
        }

        if ($tag === 'dl' && $this->definitionListSplits($path)) {
            // THE GROUPING IS A REAL LOSS AND TAKES ITS OWN ROW
            // (markup-carve/carve#1636). A `<dd>` that writes nothing ends the
            // list it is in, because one list would give the term above it the
            // NEXT entry's description - an ADDITION, which no row can declare
            // and which the ceiling forbids outright.
            //
            // NOT `structure-unspellable`: that code is for a shape the syntax
            // cannot spell at all, and here every part is spellable, present and
            // exact. What the source cannot say is that they were ONE list.
            //
            // It is a SERIALIZATION loss, so it belongs to the exit that writes
            // source; the tree keeps one list with the empty description in it.
            $this->addImportDiagnostic(
                $diagnostics,
                'structure-split',
                'A <dd> that writes nothing ends the list it is in; the entries after it are written as a second <dl>, '
                    . 'because one list would give the term above it the next entry\'s description',
                'warning',
                $path,
            );
        }

        if ($tag === 'dd' && $this->definitionDescriptionIsDropped($path)) {
            // A DECLARED LOSS IS A CEILING, NOT A LICENCE
            // (`docs/html-import.md`). Carve has no spelling for an empty
            // definition description - every candidate leaks a colon into
            // the text, folds into the term, or renders a non-breaking
            // space - so the description is dropped and the term kept.
            // That is the loss the clause permits, and permitting it is
            // conditional on DECLARING it: the writer already dropped the
            // description and said nothing, which is the half the ceiling
            // does not cover (carve-php#1615).
            //
            // `structure-unspellable` is the code the shared fixture
            // carries, and the message is the sibling engine's, so the two
            // reports say the same thing about the same shape.
            $this->addImportDiagnostic(
                $diagnostics,
                'structure-unspellable',
                'A <dd> that writes nothing has no Carve spelling; the empty description is dropped, '
                    . 'because the only line that could carry it is read as more of the term above it',
                'warning',
                $path,
            );
        }

        if ($tag === 'p' && isset($this->loneImageParagraphs[$path])) {
            // A DECLARED LOSS IS A CEILING, NOT A LICENCE
            // (`docs/html-import.md`). Carve source has no spelling for a
            // paragraph whose whole content is one image - `![G](g.jpg)`
            // re-reads as a BLOCK image, and the indented reading a writer
            // might reach for does not exist inside a list item or a
            // definition description, where the marker absorbs the padding at
            // every width. So there is no other output to write, and what was
            // missing is the row: the writer already dropped the `<p>` and said
            // nothing, which is exactly the half the ceiling does not cover
            // (carve-php#1667, ported from markup-carve/carve-js#1422).
            $lost = $this->loneImageParagraphs[$path];
            $head = 'A paragraph holding nothing but an image has no Carve spelling; '
                . 'the image is written as a block';
            // THREE OUTCOMES, AND THE MESSAGE SAYS WHICH ONE HAPPENED. The
            // plain one loses the `<p>` and nothing else. An attributed one
            // re-attaches what the paragraph carried to the image, which is a
            // different element to carry it. And where the image sets the SAME
            // name its own value wins, so the paragraph's is gone too - a
            // message that stopped at "written on the image instead" would
            // leave that loss undeclared.
            if (!$lost['attributed']) {
                $message = $head . ', which renders without the <p> around it';
            } elseif ($lost['overwritten'] === []) {
                $message = $head . ', so the <p> is lost and the attributes it carried '
                    . 'are written on the image instead';
            } else {
                $message = $head . ', so the <p> is lost and the attributes it carried '
                    . 'are written on the image - except ' . implode(', ', $lost['overwritten'])
                    . ', which the image\'s own value overwrites';
            }
            $this->addImportDiagnostic($diagnostics, 'structure-unspellable', $message, 'warning', $path);
        }

        if ($tag === 'p' && $this->holdsOnlyLayoutCharacters($node)) {
            // PART 11 §7 DECIDES WHAT AN IMPORT KEEPS, and it draws the line
            // at the two-character `whitespace` terminal. A block whose
            // every character is layout builds nothing - a lone space or
            // tab line is a blank line, so a paragraph there is a node no
            // Carve source spells. This engine already wrote nothing for
            // it; what it did not do was SAY so, and an element that left
            // the document is what `element-dropped` is for.
            //
            // A block holding a character §7 calls content keeps it and
            // keeps its paragraph, which this engine already gets right: a
            // NO-BREAK space, U+202F and U+3000 all survive, and each reads
            // back as a paragraph.
            $this->addImportDiagnostic(
                $diagnostics,
                'element-dropped',
                'Dropped whitespace-only <' . $tag . '> holding no content character',
                'warning',
                $path,
            );
        }

        if (($tag === 'ins' || $tag === 'del') && !$this->hasImportContentToUnwrap($node)) {
            // AN EMPTY ONE HAS NOTHING TO MARK, and Carve spells the pair
            // AROUND its content, so there is no marker to write and the
            // element is dropped. Dropping is the right half of the answer -
            // the other engine wrote an empty brace pair, which is not a
            // construct and renders as characters the HTML never held - but a
            // silent drop is still an element that left the document, and
            // `element-dropped` is what says so (carve-php#1615).
            $this->addImportDiagnostic(
                $diagnostics,
                'element-dropped',
                'Dropped an empty <' . $tag . '>: Carve spells the pair around its content, and an empty brace pair is not a construct',
                'warning',
                $path,
            );
        }

        if ($tag === 'a' && $this->importDestinationIsEmpty($node->getAttribute('href'))) {
            // A LINK THAT COMES BACK AS PROSE IS A LOSSY DECISION, and this
            // page requires those to be observable. It is not the bare `<div>`'s
            // case, where nothing was lost because nothing was carried: an
            // anchor has a slot for a destination, and this one is standing
            // empty.
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Unwrapped <a> with no destination',
                'info',
                $path,
            );
        }

        if ($tag === 'img' && $this->importDestinationIsEmpty($node->getAttribute('src'))) {
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Unwrapped <img> with no source',
                'info',
                $path,
            );
        }

        $this->inspectImportChildren($node, $tag, $path, $diagnostics);
    }

    /**
     * Name what became of an element the importer has no mapping for.
     *
     * THE CODE IS DECIDED BY THE OUTCOME, not by which arm of the walk ran.
     * `element-unwrapped` used to be written for every unsupported element,
     * and it says something specific: the wrapper went and the children
     * stayed. That is true of a `<button>`, whose label comes through, and
     * false of a `<canvas>`, an `<object>` or an `<iframe>`, whose emitted
     * Carve is empty - nothing was unwrapped there, the subtree was dropped,
     * and `element-dropped` is what `<math>` and `<colgroup>` already say
     * (carve-php#1377).
     *
     * WHAT SEPARATES THEM IS CONTENT TO UNWRAP. An element that has any is
     * unwrapped, because that is what this importer does with one - measured
     * over every unsupported tag, the content came through in all of them. An
     * element that has none cannot have been unwrapped whatever else is true:
     * there was nothing to put in its place.
     *
     * THE THIRD ANSWER IS SILENCE, and it is why the childless case cannot be
     * settled from the tag either. An `<input type="checkbox">` at the head of
     * a list item is not lost: it comes back as the task marker `- [ ]`. Its
     * own attributes are in the emitted document, so it survived as an element
     * and there is nothing to report. The same `<input>` anywhere else leaves
     * nothing, and it is that difference - not a table of types - that decides.
     *
     * ONLY THE CHILDLESS CASE IS ASKED OF THE DOCUMENT, deliberately. Asking it
     * of content too means searching the emitted document for an element's
     * words, and a document-wide search answers yes for words that belong to
     * somebody else. The direction of the error matters: a missed
     * `element-dropped` leaves the report where it already was, while a
     * `element-dropped` on an element whose content is right there in the
     * output is a new false statement. A `<button>...</button>` whose content
     * is all punctuation, and a `<button><hr></button>` whose child carries
     * neither words nor attributes, are both unwrapped and both would have been
     * called dropped by a search of that kind.
     *
     * SO THIS DOES NOT REACH an element whose content its CONTEXT discards - a
     * `<button>` inside a `<table>`, whose label never reaches the output. It
     * is still reported as unwrapped. Telling that apart needs the trace to be
     * correlated with the element it came from, which is the same correlation
     * `importAttributeSurvived()` does not have.
     *
     * @param \DOMElement $node
     * @param string $tag
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function reportImportElementOutcome(DOMElement $node, string $tag, string $path, array &$diagnostics): void
    {
        if ($this->hasImportContentToUnwrap($node)) {
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Replaced unsupported <' . $tag . '> element with Carve span metadata',
                'info',
                $path,
            );

            return;
        }

        if ($this->importElementSurvivedItself($node)) {
            return;
        }

        $this->addImportDiagnostic(
            $diagnostics,
            'element-dropped',
            'Dropped unsupported <' . $tag . '> element',
            'warning',
            $path,
        );
    }

    /**
     * A `<figcaption>` or `<caption>` written outside the container it captions.
     *
     * NOT A PREDICATE FOR WHICH CAPTION THE SERIALIZER CONSUMED. That question
     * has three routes through this importer and reading the input to answer it
     * is what withdrew carve-php#1347. This one asks whether the element is in a
     * position where ANY route could take it, and outside `<figure>` or
     * `<table>` none can: there is no caption slot to compete for.
     *
     * The HTML content model is what makes it decidable. `<figcaption>` belongs
     * to `<figure>` and `<caption>` to `<table>`, so an orphan is degenerate
     * input whose text this importer has nowhere to put.
     *
     * IT CANNOT CURRENTLY CHANGE THE OUTCOME, and is kept for the MESSAGE. Every
     * caption this writer places emits its text, so the survival test beside it
     * suppresses the row for a placed one whatever this answers - dropping this
     * test moves no document today. What it buys is that the row cannot LIE: if
     * a placed caption ever stopped emitting, calling it "outside its own
     * container" would be a false statement about a real loss, where staying
     * silent is a known gap. A wrong reason is worse than a missing row.
     *
     * A DIRECT CHILD, for both, because that is what the content model says and
     * what the writer reads. An ancestor WALK was written first and was too
     * lenient in exactly the way that matters: `<figure><div><figcaption>` has
     * a figure above it, is not a direct child of one, and its text leaves the
     * document - so the walk called it placed and said nothing, which is the
     * silence this whole change is about.
     *
     * @param \DOMElement $node
     * @param string $tag
     */
    protected function isOrphanImportCaption(DOMElement $node, string $tag): bool
    {
        $container = match ($tag) {
            'caption' => 'table',
            'figcaption' => 'figure',
            default => null,
        };
        if ($container === null) {
            return false;
        }

        $parent = $node->parentNode;

        return !$parent instanceof DOMElement || strtolower($parent->tagName) !== $container;
    }

    /**
     * Did this element's own text reach the emitted document?
     *
     * ASKED OF THE OUTPUT, so a row is never written about text that is right
     * there - the direction carve-php#1377 rates as a new false statement. The
     * cost of asking it this way is a false NEGATIVE where the words happen to
     * appear elsewhere in the document, which leaves the report where it
     * already was.
     *
     * Both sides are reduced to letters and digits, the same reduction
     * {@see self::importElementContentKey()} uses for the attribute survivors:
     * the two are not written by the same hand, and a caption comes back behind
     * a `^` marker with the renderer's own spacing around it.
     *
     * An element carrying no letters or digits at all is treated as surviving,
     * because an empty key is contained in every string and asking the question
     * of one answers yes for nothing.
     *
     * @param \DOMElement $node
     */
    protected function importContentSurvived(DOMElement $node): bool
    {
        $key = $this->importElementContentKey($node);
        if ($key === '') {
            return true;
        }

        $emitted = (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $this->inspectedCarve ?? '');

        return str_contains($emitted, $key);
    }

    /**
     * Is there anything here for an unwrapping to leave behind?
     *
     * Whitespace is not content: a `<canvas>` written across two lines holds a
     * text node and still has nothing to put in its own place. Neither is a
     * subtree the importer drops whole, which never reaches the output at all.
     *
     * Reads this element's OWN children rather than its whole subtree, so a
     * nesting of unsupported wrappers costs one pass over each level rather
     * than one pass over everything below it.
     *
     * @param \DOMElement $node
     *
     * @return bool
     */
    protected function hasImportContentToUnwrap(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (!in_array(strtolower($child->tagName), self::ACTIVE_ELEMENTS, true)) {
                    return true;
                }

                continue;
            }

            if (trim($child->textContent) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Did a childless element come back as an element of its own?
     *
     * ASKED OF THE OUTPUT: does the emitted document carry one of this
     * element's attribute values, in an ATTRIBUTE position? A task-list
     * checkbox does - its `type="checkbox"` is right there on the marker the
     * renderer wrote - and a discarded `<input>` does not.
     *
     * SPENT FROM A BUDGET, not read from a set, so one surviving element
     * answers for exactly one input element. Two `<input type="checkbox">`
     * where only the first becomes a marker would otherwise both point at the
     * one checkbox in the output, and the second's loss would go unreported.
     *
     * The budget is its OWN, separate from the one the attribute rows spend:
     * an element asking whether it survived must not consume the survivor an
     * attribute row is about to ask for.
     *
     * @param \DOMElement $node
     *
     * @return bool
     */
    protected function importElementSurvivedItself(DOMElement $node): bool
    {
        $this->importEmittedDocument();

        // THE CONSUMED CHECKBOX DID SURVIVE, and the writer said so rather than
        // the output being searched for its spelling (carve-php#1705).
        //
        // IT STILL SPENDS A CREDIT, which is the whole reason this is not a
        // plain `return true`. The budget models how many inputs came back as
        // elements, and exactly one did: this one. Leaving its credit unspent
        // would let a SECOND checkbox in the document claim the marker as its
        // own survivor and go unreported - the false negative the budget was
        // introduced to prevent.
        //
        // The keyword is the one the MARKER emitted, not the one the author
        // typed. That is not a value comparison deciding which element this is -
        // the path already decided that - it is accounting for what the writer
        // put in the document, which is `type="checkbox"` however the source
        // spelled it.
        if ($this->inspectedConsumedCheckbox !== null) {
            if (($this->emittedImportValues['checkbox'] ?? 0) > 0) {
                $this->emittedImportValues['checkbox']--;
            }

            return true;
        }

        foreach ($node->attributes as $attribute) {
            $value = trim($attribute->value);
            if ($value === '' || ($this->emittedImportValues[$value] ?? 0) < 1) {
                continue;
            }
            $this->emittedImportValues[$value]--;

            return true;
        }

        return false;
    }

    /**
     * Report what an element's own attributes lose.
     *
     * Split out of the walk because the document elements are inspected for
     * their attributes without being walked into.
     *
     * @param \DOMElement $node
     * @param string $tag
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectImportAttributes(DOMElement $node, string $tag, string $path, array &$diagnostics): void
    {
        $outerElement = $this->inspectedElement;
        $outerContent = $this->inspectedElementContent;
        $this->inspectedElement = $node;
        $this->inspectedElementContent = null;

        try {
            $this->inspectImportAttributeList($node, $tag, $path, $diagnostics);
        } finally {
            $this->inspectedElement = $outerElement;
            $this->inspectedElementContent = $outerContent;
        }
    }

    /**
     * The element's own content, reduced to what a round trip cannot change.
     *
     * COMPUTED ON DEMAND, because only a value that repeats its own name is
     * keyed by it. Reading `textContent` walks the whole subtree, and the
     * inspection descends, so doing it for every element would read the same
     * text once per ancestor - quadratic in nesting depth on a document whose
     * report never asks the question.
     *
     * ONLY LETTERS AND DIGITS ARE KEPT, because the two sides are not written
     * by the same hand and the difference is never in the words.
     *
     * The layout differs: the input is the author's HTML and the emitted
     * document is the renderer's, which indents block children onto lines of
     * their own, so a `<div><p>a</p><p>b</p></div>` carries `ab` on the way in
     * and comes back with each of them on an indented line of its own.
     *
     * And the punctuation differs, because a mapping is allowed to spell marks
     * of its own around the content it keeps: a `<q cite="u">quoted</q>` comes
     * back as `<span cite="u">"quoted"</span>` with the quote characters the
     * mapping exists to add.
     *
     * THE TEXT OF A DROPPED SUBTREE IS NOT COUNTED, because the emitted
     * document cannot carry it and the two keys would never meet. A
     * `<blockquote disabled><script>bad</script><p>good</p></blockquote>` keeps
     * its `disabled`, and counting the script's text called the surviving
     * blockquote a different element and reported a loss that did not happen.
     * The same list the walk drops those elements by is the one read here.
     *
     * @param \DOMElement $node
     *
     * @return string
     */
    protected function importElementContentKey(DOMElement $node): string
    {
        $text = [];
        $this->collectImportContentText($node, $text);

        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', implode('', $text));
    }

    /**
     * Gather an element's carried text, skipping the subtrees that are dropped.
     *
     * Collected into a list and joined once by the caller, so the reduction
     * runs on the whole string a single time rather than once per level.
     *
     * @param \DOMElement $node
     * @param list<string> $text
     */
    protected function collectImportContentText(DOMElement $node, array &$text): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (!in_array(strtolower($child->tagName), self::ACTIVE_ELEMENTS, true)) {
                    $this->collectImportContentText($child, $text);
                }

                continue;
            }
            $text[] = $child->textContent;
        }
    }

    /**
     * @param \DOMElement $node
     * @param string $tag
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */

    /**
     * Would this importer have refused to write this attribute as a Carve one?
     *
     * Asked only of a PRESERVED element, where the ordinary oracle - did the
     * attribute come back? - answers yes for everything and so decides nothing.
     *
     * DERIVED FROM THE POLICIES THAT ALREADY EXIST, not enumerated: the strip
     * policy `isStrippedImportAttribute()` is the one every write site asks,
     * and the identifier rule is the WRITER's, so this cannot admit a name the
     * writer would have rewritten into a different one. A second roster is what
     * drifts, which this file has said four times.
     */
    protected function importWouldRefuseAttribute(string $tag, string $name): bool
    {
        if ($this->importAttributeIsReadNotWritten($tag, $name)) {
            return false;
        }

        return $this->isStrippedImportAttribute($name)
            || preg_match('/^[A-Za-z_][\w-]*$/', $name) !== 1;
    }

    /**
     * One attribute of an element the mode kept BYTE FOR BYTE.
     *
     * It is not a loss and must not be reported as one: `attribute-dropped`
     * beside preserved bytes that still carry the attribute is a false
     * statement about a success, which is the failure this repository rates
     * worst (`markup-carve/carve-js#1468`). `attribute-preserved` is the code
     * the format added for exactly this row, in `markup-carve/carve#1710`.
     *
     * SEVERITY IS RULED, NOT COPIED. `error` where the attribute is one a
     * renderer refuses for SAFETY - an event handler, an injection sink, a
     * value carrying a denied URL scheme - and `info` otherwise. A dropped
     * handler already spends `warning`, so a preserved one spending `warning`
     * too would tell a filter nothing about which of the two it is looking at,
     * and `roundtrip` is the mode `docs/html-import.md` calls unsafe for
     * untrusted input, so this is the row somebody might act on. The `error` is
     * not a failed import; it is the strongest thing the report can say.
     *
     * The safety test is DERIVED from the strip policy this importer already
     * asks everywhere else, so it cannot admit a sink that policy knows about.
     *
     * @param string $tag
     * @param string $name
     * @param string $value
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function reportPreservedAttribute(
        string $tag,
        string $name,
        string $value,
        string $path,
        array &$diagnostics,
    ): void {
        $handler = str_starts_with($name, 'on');
        $sink = $name === 'srcdoc' || $name === 'formaction';
        $live = $handler || $sink || $this->valueCarriesADeniedScheme($value);
        $subject = $handler
            ? 'event-handler attribute ' . $name
            : ($sink ? 'injection-sink attribute ' . $name : 'attribute ' . $name);

        $this->addImportDiagnostic(
            $diagnostics,
            'attribute-preserved',
            'Preserved ' . $subject . ' on <' . $tag . '> in the raw HTML this element is kept as',
            $live ? 'error' : 'info',
            $path,
        );
    }

    /**
     * Does this value carry a URL scheme a renderer refuses?
     *
     * PART 9 section 25 blanks a value whose scheme LEADS it, and a list-valued
     * attribute hides one past its head. In preserved raw bytes the renderer
     * never runs at all, so either shape is live in the output and the row says
     * so.
     */
    protected function valueCarriesADeniedScheme(string $value): bool
    {
        foreach (preg_split('/[\s,]+/', $value) ?: [] as $token) {
            $colon = strpos($token, ':');
            if ($colon === false) {
                continue;
            }
            $scheme = strtolower(preg_replace('/[\s\x00-\x1f]/', '', substr($token, 0, $colon)) ?? '');
            if (in_array($scheme, ['javascript', 'vbscript', 'data'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \DOMElement $node
     * @param string $tag
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     * @param bool $preserved Whether the element was kept byte for byte.
     */
    protected function inspectImportAttributeList(
        DOMElement $node,
        string $tag,
        string $path,
        array &$diagnostics,
        bool $preserved = false,
    ): void {
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if ($preserved) {
                // ONLY THE ATTRIBUTES THIS IMPORTER WOULD HAVE REFUSED.
                //
                // Every attribute of a preserved element reached the output, so
                // the loop's other arms - each of which asks whether this one
                // came back - answer "kept" for all of them and say nothing.
                // The row that is owed is the one for an attribute the policy
                // would NOT have written, because that is the one whose
                // presence in the document is news: an `id` would have been
                // kept either way and is not.
                if ($this->importWouldRefuseAttribute($tag, $name)) {
                    $this->reportPreservedAttribute($tag, $name, $attribute->value, $path, $diagnostics);
                }

                continue;
            }
            if (str_starts_with($name, 'on')) {
                $this->addImportDiagnostic($diagnostics, 'attribute-dropped', 'Dropped event-handler attribute ' . $name . ' on <' . $tag . '>', 'warning', $path);
            } elseif ($this->importAttributeIsReadNotWritten($tag, $name)) {
                // Read as instruction or as content, never written back as an
                // attribute - so asking the output for it is the wrong
                // question. See the predicate for why each family qualifies.
                continue;
            } elseif ($name === 'style') {
                // ONLY THE DECLARATIONS THAT WENT NOWHERE. `style` used to be
                // reported wholesale, so a cell carrying `text-align:right`
                // came back with a row naming a loss that this engine does not
                // take - the alignment reaches the cell either way, and
                // `docs/html-import.md` makes a declared loss a ceiling rather
                // than a licence (markup-carve/carve#1741).
                if ($this->unmappedStyleDeclarations($node) !== []) {
                    $this->addImportDiagnostic($diagnostics, 'style-unmapped', 'CSS declarations may not have a Carve mapping', 'info', $path);
                }
            } elseif ($name === 'scope' && $tag === 'th' && in_array('scope', $this->tableCellSkipAttributes($node), true)) {
                // The value this cell's position generates. It is skipped so a
                // round trip does not write the renderer's own output back as
                // if the author had typed it, and it comes back from the
                // position on the way out - so it is reproduced, not dropped.
                // Same predicate the converter uses, rather than a second one.
                continue;
            } elseif ($name === 'alt' && $tag === 'img' && $this->importDestinationIsEmpty($node->getAttribute('src'))) {
                // AN IMAGE'S CONTENT IS ITS ALTERNATIVE TEXT, and an image with
                // no source is written as that content: the alt value is in the
                // emitted document as prose, not in an attribute position, so
                // the output oracle below correctly finds no `alt=` and would
                // call preserved text a loss. Same shape as `<math alttext>`
                // one predicate up - read as content, never written back as an
                // attribute - but node-dependent rather than tag/name, because
                // an image that HAS a source writes its alt as an attribute
                // again. The `element-unwrapped` row already names what became
                // of the element.
                continue;
            } elseif ($this->isDerivedImportAttribute($node, $name, $attribute->value)) {
                // The value the RENDERER writes for this element. It is not in
                // the emitted Carve on purpose - baking it into source makes a
                // generated string look authored and the imported copy then
                // wins over the `labels` map on every later render
                // (markup-carve/carve#1500) - and it comes back on the next
                // render regardless, so it is reproduced, not dropped.
                //
                // Same shape as the generated `scope` above, and asked through
                // the same predicates the WRITERS drop by, so the report cannot
                // disagree with the conversion about what was derived. Reading
                // the emitted document instead cannot answer this: that oracle
                // re-renders with a bare converter, and every value here is
                // written by a renderer the importer was never handed - the
                // extension that claims the fence, the one that builds the tab
                // set - so it asks a document where the attribute could not
                // have come back and calls the absence a loss
                // (markup-carve/carve#1502).
                continue;
            } elseif (!$this->importAttributeSurvived($tag, $name, $attribute->value)) {
                // THE DOCUMENT DECIDES, and nothing here knows the attribute's
                // name. A `!isRepresentedImportAttribute($tag, $name)` disjunct
                // stood in front of this and short-circuited on any name that
                // was not on its list - so `aria-label` and `foo`, which this
                // importer KEEPS, were reported as dropped while surviving into
                // the emitted Carve as `{aria-label=note}` and `{foo=bar}`
                // (carve-php#1337).
                //
                // That list was a second copy of the strip policy, which lives
                // in `$skipAttributes` plus the `on*` and `data-djot-*` prefixes
                // at the write site - and a second copy drifts, which is how the
                // first copy came to disagree with the first about `cite`
                // (carve-php#1346 deleted four predicates for the same reason).
                //
                // Measured over 495 tag/attribute pairs: removing it deleted 293
                // rows and added NONE, and every deleted row named an attribute
                // present in the emitted document. `role`, `xmlns`, `style` and
                // every `on*` handler still report, because the oracle asks
                // whether the attribute came BACK rather than whether anyone
                // listed it. That includes the ones only the RENDERER strips -
                // `srcdoc` and `formaction` are kept by this importer and blanked
                // on the way out (PART 9 §25), and asking the rendered document
                // reports them without either side having to know.
                $this->addImportDiagnostic($diagnostics, 'attribute-dropped', 'Dropped unsupported attribute ' . $name . ' on <' . $tag . '>', 'info', $path);
            }
        }
    }

    /**
     * Report what the DOCUMENT ELEMENTS themselves lose.
     *
     * `<html>`, `<head>` and `<body>` are not part of the fragment a path
     * counts from, so they never appear in the path of a node inside one. They
     * can still carry attributes the conversion drops - the handler on a
     * `<body onload=...>` is gone from the output - and a diagnostic about one
     * of these elements has to name the element, so it is named where the
     * parse put it. That is the one place a path names something outside the
     * fragment, and it is the only name available.
     *
     * The sibling engines cannot report this at all: their fragment parser
     * deletes these elements before the importer sees them, so there is no
     * spelling to converge with here - and staying silent to match would drop a
     * loss this importer really makes.
     *
     * @param \DOMNode $root
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectImportDocumentContainers(DOMNode $root, array &$diagnostics): void
    {
        if (!$root instanceof DOMElement) {
            return;
        }
        $tag = strtolower($root->tagName);
        $path = '/' . $tag . '[1]';
        $this->inspectImportAttributes($root, $tag, $path, $diagnostics);
        if ($tag !== 'html') {
            return;
        }

        $index = 0;
        foreach ($root->childNodes as $child) {
            $index++;
            if (!$child instanceof DOMElement) {
                continue;
            }
            $childTag = strtolower($child->tagName);
            if ($childTag !== 'head' && $childTag !== 'body') {
                continue;
            }
            $this->inspectImportAttributes($child, $childTag, $this->importChildPath($path, $child, $index), $diagnostics);
        }
    }

    /**
     * Number a node's children the way the CONVERSION reads them.
     *
     * A path names the importer's traversal, not the parsed tree, so the
     * containers the converter reads through a shape of their own are numbered
     * through that shape here as well:
     *
     * - a list numbers its `<li>` children among the items, so the whitespace
     *   between two items does not move the second one to `li[4]`;
     * - a table numbers its rows across the whole table and its cells among the
     *   cells of their row, so a `<tbody>` never reaches a cell's path.
     *
     * Everything else counts among all child nodes.
     *
     * @param \DOMElement $node
     * @param string $tag
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectImportChildren(DOMElement $node, string $tag, string $path, array &$diagnostics): void
    {
        if ($tag === 'table') {
            $this->inspectImportTableChildren($node, $path, $diagnostics);

            return;
        }

        if ($tag === 'ul' || $tag === 'ol') {
            $this->inspectImportListChildren($node, $path, $diagnostics);

            return;
        }

        if ($tag === 'tr') {
            $this->inspectImportRowChildren($node, $path, $diagnostics);

            return;
        }

        if (in_array($tag, ['thead', 'tbody', 'tfoot'], true) && $this->isDirectTableChild($node)) {
            // The section is named where it sits, because it carries attributes
            // of its own; its rows are numbered by the table above it.
            $this->inspectImportNodes($node->childNodes, $path, $diagnostics, ['tr']);

            return;
        }

        $this->inspectImportNodes($node->childNodes, $path, $diagnostics);
    }

    /**
     * A table's rows are flattened out of their sections and numbered across
     * the whole table, which is how the converter reads them: the row groups
     * have no Carve spelling, so a path through one would name a container the
     * output does not have.
     *
     * The sections are still walked where they sit, for the attributes they
     * carry themselves.
     *
     * @param \DOMElement $node
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectImportTableChildren(DOMElement $node, string $path, array &$diagnostics): void
    {
        $this->inspectImportNodes($node->childNodes, $path, $diagnostics, ['tr']);

        $row = 0;
        foreach ($this->getDirectTableRows($node) as $tr) {
            $row++;
            $this->inspectImportNode($tr, $path . '/tr[' . $row . ']', $diagnostics);
        }
    }

    /**
     * @param \DOMElement $node
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectImportListChildren(DOMElement $node, string $path, array &$diagnostics): void
    {
        $tag = strtolower($node->tagName);
        $item = 0;
        $index = 0;
        foreach ($node->childNodes as $child) {
            $index++;
            if (!$child instanceof DOMElement) {
                continue;
            }
            if (strtolower($child->tagName) === 'li') {
                $item++;
                $this->inspectImportNode($child, $path . '/li[' . $item . ']', $diagnostics);

                continue;
            }
            // Not an item, so it has no number in the list the converter
            // builds. It keeps its position among the child nodes rather than
            // going unreported, which would lose the diagnostics it owes.
            $childPath = $this->importChildPath($path, $child, $index);
            $this->reportStrayListChild($child, $tag, $childPath, $diagnostics);
            $this->inspectImportNode($child, $childPath, $diagnostics);
        }

        // Bare text directly inside the list is a child node too, and it is the
        // one the element walk above never reaches. It keeps every word - the
        // converter emits it as a paragraph ahead of the list - so it owes the
        // same note the elements owe.
        $index = 0;
        foreach ($node->childNodes as $child) {
            $index++;
            if ($child instanceof DOMElement) {
                continue;
            }
            if ($child instanceof DOMComment) {
                // A COMMENT BETWEEN TWO ITEMS MOVES, and now that it is KEPT
                // the move has to be said (`markup-carve/carve#1709`). It used
                // to be dropped, so there was nothing to declare and the row
                // was suppressed here.
                //
                // `info`, where the text row below is `warning`, and the split
                // is principled rather than a dial: moved TEXT changes the
                // rendered document, and a comment renders nothing in either
                // language, so the move costs a reader of the OUTPUT nothing
                // and a reader of the SOURCE one position.
                $this->addImportDiagnostic(
                    $diagnostics,
                    'element-unwrapped',
                    'An HTML comment directly inside <' . $tag . '> kept its text but not its place among the items:'
                        . ' it is emitted as a comment ahead of the list',
                    'info',
                    $path . '/comment()[' . $index . ']',
                );

                continue;
            }
            if (trim($child->textContent) === '') {
                continue;
            }
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Text directly inside <' . $tag . '> kept its content but not its place among the items:'
                    . ' it is emitted as a paragraph ahead of the list',
                'warning',
                $path . '/text()[' . $index . ']',
            );
        }
    }

    /**
     * Say that a non-`li` child of a list kept its content but not its place.
     *
     * `element-unwrapped` is the code: the vocabulary glosses it as a structural
     * note about the INPUT that loses no meaning, which is exactly what this is.
     * No engine spells "moved", and inventing a vocabulary entry for it is a
     * three-engine decision rather than this defect's
     * (markup-carve/carve-rs#1266).
     *
     * An ACTIVE element gets no note at all: the walk drops it with the
     * `element-dropped` every other site gives it, and a position note beside
     * that would tell the reader the content survived ahead of the list when it
     * did not.
     *
     * @param \DOMElement $child
     * @param string $listTag
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function reportStrayListChild(
        DOMElement $child,
        string $listTag,
        string $path,
        array &$diagnostics,
    ): void {
        $childTag = strtolower($child->tagName);
        if (in_array($childTag, self::ACTIVE_ELEMENTS, true)) {
            return;
        }

        $this->addImportDiagnostic(
            $diagnostics,
            'element-unwrapped',
            'A <' . $childTag . '> inside <' . $listTag . '> kept its content but not its place among the items:'
                . ' it is emitted as blocks ahead of the list',
            'warning',
            $path,
        );
    }

    /**
     * @param \DOMElement $node
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectImportRowChildren(DOMElement $node, string $path, array &$diagnostics): void
    {
        $cell = 0;
        $index = 0;
        foreach ($node->childNodes as $child) {
            $index++;
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'td' || $tag === 'th') {
                $cell++;
                $this->inspectImportNode($child, $path . '/' . $tag . '[' . $cell . ']', $diagnostics);

                continue;
            }
            $this->inspectImportNode($child, $this->importChildPath($path, $child, $index), $diagnostics);
        }
    }

    /**
     * Whether this element sits directly inside a `<table>`.
     *
     * The parser behind this importer is libxml's, which does not run the HTML5
     * "in table" insertion mode, so it keeps a `<colgroup>` wherever the markup
     * put one - including outside any table, where the element is genuinely
     * unwrapped rather than dropped and its children still reach the output.
     * The drop is a property of the table walk, so the report asks the same
     * question the walk answers to rather than trusting the tag name alone.
     */
    protected function isDirectTableChild(DOMElement $node): bool
    {
        $parent = $node->parentNode;

        return $parent instanceof DOMElement && strtolower($parent->tagName) === 'table';
    }

    /**
     * Report what a `<math>` element loses, off the same tier decision the
     * converter makes (`resolveMathTex()`), so the two cannot drift.
     *
     * Tier 1 is lossless and says nothing. Tier 2 read an attribute whose
     * encoding MathML never declared, which is an assumption worth recording.
     * Tier 3 has no TeX at all: `roundtrip` keeps the element verbatim and so
     * loses nothing, while `safe` and `semantic` drop it, and that is the one
     * case where the report has to name `<math>` itself.
     *
     * @param \DOMElement $node
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectMath(DOMElement $node, string $path, array &$diagnostics): void
    {
        $tier = $this->resolveMathTex($node)['tier'];
        if ($tier === 1) {
            return;
        }

        if ($tier === 2) {
            // `encoding-assumed`, which the spec added to the code set for
            // exactly this case (carve#1235): MathML never says what `alttext`
            // holds, so reading it as TeX is a guess, and the math node this
            // produces is only correct while the guess is. The spec files that
            // apart from `element-unwrapped` on purpose - unwrapping is a note
            // about the input's structure and loses no meaning, while an
            // assumed encoding is a warning about the OUTPUT, and a consumer
            // told only that an element is gone cannot tell the two apart.
            //
            // `info`, matching carve-js: the spec maps no code to a severity,
            // so raising this one would divide the engines over something
            // nothing rules on.
            $this->addImportDiagnostic(
                $diagnostics,
                'encoding-assumed',
                'Read <math> through its alttext: MathML does not declare the encoding of alttext, so TeX is assumed',
                'info',
                $path,
            );

            return;
        }

        if ($this->trustedRoundTrip) {
            return;
        }

        $this->addImportDiagnostic(
            $diagnostics,
            'element-dropped',
            'Dropped <math>: no TeX annotation and no alttext, and its children are a token stream, not an equation',
            'warning',
            $path,
        );
    }

    /**
     * Report what a table's structure loses on the way into Carve source.
     *
     * Carve 0.1 source has no spelling for the `rowGroups` partition the AST
     * can hold (PART 12 §15): a pipe table is a flat row list whose head is the
     * leading run of header rows. So a table foot, a second body group, or a
     * head the leading-run rule will not reproduce all flatten on import, and
     * until now they flattened in silence. They stay flattened - inventing a
     * spelling is a language change, not an importer change - but the report
     * now says which of them happened.
     *
     * Row-head columns are NOT in this list, and deliberately: `|= R | 1 |`
     * spells a header cell beside data cells exactly, so that one is a mapping
     * rather than a loss.
     *
     * @param \DOMElement $node
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectTableStructure(DOMElement $node, string $path, array &$diagnostics): void
    {
        $captions = 0;
        $footRows = 0;
        $bodyGroups = 0;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $childTag = strtolower($child->tagName);
            if ($childTag === 'caption') {
                $captions++;

                continue;
            }
            if ($childTag === 'tfoot') {
                $footRows += $this->countChildRows($child);

                continue;
            }
            if ($childTag === 'tbody' && $this->countChildRows($child) > 0) {
                $bodyGroups++;
            }
        }

        if ($captions > 1) {
            // The parser's own rule is first-caption-wins, and the importer
            // follows it rather than inventing a second one. The captions after
            // the first are what is lost.
            $this->addImportDiagnostic(
                $diagnostics,
                'table-degraded',
                'Kept the first of ' . $captions . ' <caption> elements; a table has one caption',
                'warning',
                $path,
            );
        }
        if ($footRows > 0) {
            $this->addImportDiagnostic(
                $diagnostics,
                'table-degraded',
                'Moved ' . $footRows . ' <tfoot> row(s) into the table body; Carve source has no table foot',
                'warning',
                $path,
            );
        }
        if ($bodyGroups > 1) {
            $this->addImportDiagnostic(
                $diagnostics,
                'table-degraded',
                'Merged ' . $bodyGroups . ' <tbody> groups into one; Carve source has no body grouping',
                'warning',
                $path,
            );
        }

        // An attributed header cell USED to be reported here as a header the
        // importer could not write: the only shape available was `|{#x}= R |`,
        // whose `=` is content, so the cell arrived as a data cell. PART 9 §5
        // T10 binds the block after the marker run, `|={#x} R |` spells it, and
        // a diagnostic naming that loss would now fire on a document the
        // grammar accepts and this importer deliberately produces.
        $this->inspectTableHeadSplit($node, $path, $diagnostics);
    }

    /**
     * Report a head the leading-run rule will not give back.
     *
     * Carve derives the head from the rows themselves - the leading run of rows
     * whose cells are all headers - so a `thead` that does not match that run
     * comes back a different size. A header row inside a `tbody` right after
     * the head joins the head on re-parse; a `thead` row holding a data cell
     * leaves it.
     *
     * @param \DOMElement $node
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectTableHeadSplit(DOMElement $node, string $path, array &$diagnostics): void
    {
        $head = $this->findFirstDirectChildByTagName($node, 'thead');
        if (!$head instanceof DOMElement) {
            return;
        }
        $declared = $this->countChildRows($head);

        $derived = 0;
        foreach ($this->getDirectTableRows($node) as $row) {
            if (!$this->isAllHeaderRow($row)) {
                break;
            }
            $derived++;
        }

        if ($declared === $derived) {
            return;
        }

        $this->addImportDiagnostic(
            $diagnostics,
            'table-degraded',
            'The table head changes from ' . $declared . ' to ' . $derived
                . ' row(s); Carve derives it from the leading run of header rows',
            'warning',
            $path,
        );
    }

    protected function countChildRows(DOMElement $section): int
    {
        $rows = 0;
        foreach ($section->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'tr') {
                $rows++;
            }
        }

        return $rows;
    }

    /**
     * A row every one of whose cells is a `th`, which is what makes a row a
     * header row rather than a row holding a row-head column.
     */
    protected function isAllHeaderRow(DOMElement $row): bool
    {
        $cells = 0;
        foreach ($row->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'td') {
                return false;
            }
            if ($tag === 'th') {
                $cells++;
            }
        }

        return $cells > 0;
    }

    /**
     * Which attributes a table cell must NOT carry back into Carve source.
     *
     * `colspan` and `rowspan` have marker spellings, so they are never authored
     * attributes. `scope` joins them CONDITIONALLY: PART 10 SST9 makes the
     * renderer emit one on every `th` - `col` in the head-row run, `row` below
     * it - so the value is GENERATED, and importing it wrote the generator's
     * own output back as if the author had typed it. A round trip produced
     * `|{scope=col} Left |` from a table whose source had no attribute block at
     * all.
     *
     * Only the value the renderer would have produced is dropped. An authored
     * `scope="colgroup"` is not reproducible from position, so it stays - which
     * is the same reason the renderer lets an authored value replace its
     * default rather than emitting both.
     *
     * @return array<int, string>
     */
    protected function tableCellSkipAttributes(DOMElement $cell): array
    {
        $skip = ['colspan', 'rowspan'];
        if (strtolower($cell->tagName) !== 'th' || !$cell->hasAttribute('scope')) {
            return $skip;
        }

        if (strcasecmp($cell->getAttribute('scope'), $this->defaultCellScope($cell)) === 0) {
            $skip[] = 'scope';
        }

        return $skip;
    }

    /**
     * The scope the renderer would emit for this cell from its position alone.
     *
     * Section elements answer it directly, and our own output always has them.
     * Foreign HTML need not: there the leading run of all-header rows is the
     * head, which is the same rule the renderer applies to the AST.
     */
    protected function defaultCellScope(DOMElement $cell): string
    {
        for ($node = $cell->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $tag = strtolower($node->tagName);
            if ($tag === 'thead') {
                return 'col';
            }
            if ($tag === 'tbody' || $tag === 'tfoot') {
                return 'row';
            }
            if ($tag === 'table') {
                break;
            }
        }

        $row = $cell->parentNode;
        if (!$row instanceof DOMElement) {
            return 'row';
        }
        $section = $row->parentNode;
        if (!$section instanceof DOMNode) {
            return 'row';
        }
        foreach ($section->childNodes as $sibling) {
            if (!$sibling instanceof DOMElement || strtolower($sibling->tagName) !== 'tr') {
                continue;
            }

            return $sibling === $row ? 'col' : 'row';
        }

        return 'row';
    }

    /**
     * Is this attribute READ by the conversion rather than written by it?
     *
     * A tag/name question, on the axis that stays one: it asks HOW an attribute
     * is represented, never WHERE the serializer happens to be. Both families
     * below are consumed by the conversion that reads them - their meaning
     * enters the document as content or as a decision, not as an attribute - so
     * looking for them in an attribute position asks something that was never
     * true, of an import that lost nothing.
     *
     * `data-djot-*` IS THE IMPORTER'S OWN PROTOCOL, not the author's content.
     * Eighteen names carry instructions to this converter: `data-djot-src`
     * re-emits stored source, `data-djot-raw` restores a raw block,
     * `data-djot-footnote-label` names a footnote. None is ever written to the
     * output, so a rule that asked the output would report all eighteen as
     * dropped on every round-tripped document. They were not dropped; they were
     * obeyed.
     *
     * `<math>`'s THREE ATTRIBUTES ARE THE EQUATION. `alttext` is the TeX the
     * tier-2 conversion emits as the math content, `display` picks the
     * delimiter that content is wrapped in, and `xmlns` declares the namespace
     * the element already is. The value of `alttext="x^2"` comes back as the
     * math `$`x^2`$` - fully preserved, and nowhere near an attribute position.
     * Reading it is what the separate `encoding-assumed` diagnostic already
     * describes.
     *
     * The generated `scope` on a `<th>` is skipped below for the same reason
     * from the other direction: an attribute the importer itself puts there and
     * takes back is not a loss the author suffered.
     */
    protected function importAttributeIsReadNotWritten(string $tag, string $name): bool
    {
        return str_starts_with($name, 'data-djot-')
            || ($tag === 'math' && in_array($name, ['display', 'alttext', 'xmlns'], true));
    }

    /**
     * Did this attribute actually survive into the emitted document?
     *
     * THE ONE RULE that replaced the position predicate. It asks the OUTPUT,
     * so it is right about every route without naming any of them - including
     * the routes that did not exist when it was written.
     *
     * The tally is consumed as it is read: each surviving `<tag name=…>` in
     * the output answers for exactly one occurrence in the input, so a document
     * with two cited quotes of which the serializer keeps one reports exactly
     * one loss. The walk runs in document order, so the occurrence a given
     * survivor is credited to may differ from the one a human would pair it
     * with when the values differ; the COUNT of reported losses is exact
     * either way, which is what the invariant is about.
     *
     * MATCHED ON THE VALUE, in an ATTRIBUTE position - not on the element it
     * landed on, and not on the name it landed under.
     *
     * Not on the TAG, because this importer re-tags constantly and correctly. A
     * `word` document's footnote definition is a `<div id="fn1">` on the way in
     * and an `<li id="fn1">` on the way out; the `id` plainly survived, and a
     * tag-keyed lookup would call it dropped purely because the element around
     * it did its job.
     *
     * Not on the NAME, because Carve spells several imported attributes under a
     * name of its own: `<dfn title="…">` is `{dfn="…"}` and reads back as a
     * `dfn` attribute, so the author's `title` survives under another name. What
     * the author wrote is the VALUE, and the value is what is looked for.
     *
     * IN AN ATTRIBUTE POSITION, which is the whole point. Characters can
     * survive into a slot that cannot hold their meaning: a cited quote in a
     * table caption is written through the caption-line slot, which carries
     * inline content only, so the rendered document reads
     * `<caption>{cite=u}</caption>` - the value `u` is right there in the text,
     * and it means nothing. Searching the output for the characters calls that
     * preserved; asking whether any element carries them AS AN ATTRIBUTE calls
     * it lost, which is the truth.
     *
     * AN EMPTY VALUE HAS NOTHING TO LOSE, so it is never a drop. `<abbr
     * title="">` and `<time datetime="">` come back as a bare `[E]{abbr}` and
     * `[T]{time}`, which render as `<abbr>` and `<time>` with no attribute at
     * all - and the shared cross-engine contract fixture
     * `html-import/semantic-span-attributes` reports neither, because an empty
     * value carried no information for the round trip to drop. Reporting them
     * would put this engine's report at odds with carve-js and carve-rs over a
     * pair of attributes that say nothing.
     *
     * SCOPED BY NAME, so an unrelated attribute cannot vouch for this one. A
     * pooled by-value lookup let a generated `scope="col"` answer for a dropped
     * `cite="col"` and report nothing, which is the false negative this whole
     * rule exists to prevent - a coincidence of values is not survival.
     *
     * A VALUE THAT REPEATS ITS NAME IS SCOPED BY THE ELEMENT'S CONTENT TOO,
     * which is the same sentence one level in: a coincidence of name AND value
     * across two DIFFERENT elements is not survival either.
     *
     * That pair collides by construction rather than by accident. libxml
     * normalizes an HTML boolean attribute to `name="name"`, so every authored
     * `disabled`, `checked`, `readonly`, `multiple`, `hidden` and `open`
     * arrives here spelled identically, and so does every one the RENDERER
     * writes of its own accord. A task list renders a generated
     * `disabled="disabled"` onto its checkbox, and with the budget tallied over
     * the whole document that stood in for the authored `disabled` on a
     * `<button>` elsewhere, so the button's real loss went unreported
     * (carve-php#1379).
     *
     * The element's content tells those two apart: the checkbox carries none
     * and the button carries its label. It is asked instead of the tag because
     * this importer re-tags, and asked only of this class because a round trip
     * may legitimately REWRITE content - a footnote's `<a href="#fnref1">back</a>`
     * comes back as the renderer's own `↩` marker, and its `href` survived on
     * an element whose words did not.
     *
     * Every other name/value pair keeps the document-wide budget it had. Two
     * elements carrying the same non-boolean value are still pooled, and the
     * COUNT of reported losses stays exact there, because both occurrences were
     * authored - the boolean case is the one where an occurrence NOBODY
     * authored joins the budget.
     *
     * WHAT THIS STILL DOES NOT SEPARATE: two CONTENTLESS elements. A
     * `<button disabled></button>` and a generated checkbox both key on the
     * empty string, so the checkbox can still answer for the button when the
     * button carries no label. Content is a witness to an element's identity,
     * not a name for it, and an element with no content leaves none. Closing
     * that corner needs the writer to record which input node produced which
     * output node, which is a correlation this report - which asks the emitted
     * document rather than the conversion - does not have.
     *
     * `class` IS COMPARED BY TOKEN, because it is a token list and the round
     * trip legitimately rewrites the string around the tokens: `{.a .b}` renders
     * `class="a b"`, so an authored class whose tokens are separated by a run
     * of spaces never matches whole, and a
     * `<details class="x">` comes back as `class="details x"` with the
     * extension's own token added. The author's tokens are what survived, and
     * asking for the string instead reported an everyday paragraph as lossy.
     */
    protected function importAttributeSurvived(string $tag, string $name, string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }

        $this->importEmittedDocument();

        // THE CONSUMED CHECKBOX'S `type` IS REPRESENTED, by the task marker the
        // writer put in its place (carve-php#1705). Only `type` - every other
        // attribute on that input keeps the ordinary treatment, so an `onclick`,
        // a `name` and a `value` still report the losses they are.
        //
        // Spent from the budget for the reason the element question above is:
        // one emitted `type="checkbox"` answers for one input.
        if ($this->inspectedConsumedCheckbox !== null && $name === 'type') {
            $this->consumeSurvivingAttribute($this->importSurvivorKey('type', 'checkbox'));

            return true;
        }

        if ($name === 'class') {
            return $this->classTokensSurvived($value);
        }

        if ($this->consumeSurvivingAttribute($this->importSurvivorKey($name, $value))) {
            return true;
        }

        // `title` IS THE ONE AUTHORED NAME CARVE RESPELLS. An element's title
        // is written under that element's own semantic-span key, so
        // `<dfn title="…">` becomes `{dfn="…"}` and reads back as a `dfn`
        // attribute carrying the author's words. Every other represented name
        // was measured coming back under its own spelling, so only this one
        // needs to look past the name.
        return $name === 'title' && $this->consumeSurvivingAttribute("\0any\0" . $value);
    }

    /**
     * The budget key an attribute occurrence spends from.
     *
     * A value that repeats its own name is the shape libxml gives every HTML
     * boolean attribute, authored or generated alike, so that key carries the
     * content of the element it sits on and answers only for that element. See
     * `importAttributeSurvived()` for why the rest stay document-wide.
     *
     * @param string $name
     * @param string $value
     * @param string|null $content Content of the element, when tallying the output.
     *
     * @return string
     */
    protected function importSurvivorKey(string $name, string $value, ?string $content = null): string
    {
        $key = $name . "\0" . $value;
        if ($value !== $name) {
            return $key;
        }

        return $key . "\0" . ($content ?? $this->inspectedContentKey());
    }

    /**
     * This element's content key, computed once it is actually needed.
     *
     * @return string
     */
    protected function inspectedContentKey(): string
    {
        if ($this->inspectedElementContent === null) {
            $this->inspectedElementContent = $this->inspectedElement === null
                ? ''
                : $this->importElementContentKey($this->inspectedElement);
        }

        return $this->inspectedElementContent;
    }

    /**
     * Every authored class token came back, so the class did.
     *
     * All of them, not any: a `class="a b"` whose `b` is gone lost something,
     * and the report should say so.
     */
    protected function classTokensSurvived(string $value): bool
    {
        $tokens = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $survived = true;
        foreach ($tokens as $token) {
            if (!$this->consumeSurvivingAttribute($this->importSurvivorKey('class', $token))) {
                $survived = false;
            }
        }

        return $survived;
    }

    /**
     * Spend one of this key's budget, reporting whether there was any.
     */
    protected function consumeSurvivingAttribute(string $key): bool
    {
        if (($this->survivingImportAttributes[$key] ?? 0) < 1) {
            return false;
        }

        $this->survivingImportAttributes[$key]--;

        return true;
    }

    /**
     * Count every attribute NAME the emitted Carve renders back to.
     *
     * RENDERING IS THE ORACLE, not a search of the Carve source. Characters
     * can survive into a slot that cannot hold their meaning: a `<blockquote
     * cite="u">` in a table caption is written through the caption-line slot,
     * which carries inline content only, so the source reads `^ {cite=u}` and
     * the characters `cite=u` are present - as caption TEXT. Grepping the
     * source calls that preserved. Rendering it shows `<caption>{cite=u}
     * </caption>` with no attribute anywhere, which is the truth.
     *
     * A rendering failure yields an EMPTY tally rather than propagating: the
     * report is a diagnostic aid, and making `convertWithReport()` throw where
     * `convert()` succeeds would be a worse failure than an imprecise report.
     * An empty tally reports represented attributes as dropped, which is the
     * direction that says "the importer could not confirm this survived"
     * rather than silently vouching for it.
     *
     * @return array<string, int>
     */
    protected function tallySurvivingAttributes(string $carve): array
    {
        // Reset FIRST, so every early return below leaves the element
        // questions looking at an empty document rather than the last one.
        $this->emittedImportValues = [];

        if (trim($carve) === '') {
            return [];
        }

        try {
            $html = (new CarveConverter())->convert($carve);
        } catch (Throwable) {
            return [];
        }

        if (trim($html) === '') {
            return [];
        }

        $doc = new DOMDocument();
        $doc->encoding = 'UTF-8';
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $counts = [];
        $values = [];
        /** @var \DOMNodeList<\DOMElement> $elements */
        $elements = $doc->getElementsByTagName('*');
        foreach ($elements as $element) {
            // Tallied WITH the content of the element it came back on, so the
            // credit is attributable to an element rather than to the document.
            // See `importAttributeSurvived()` for what a document-wide budget
            // let a generated attribute vouch for. Read on first demand, like
            // the input side: only a value that repeats its name needs it.
            $content = null;
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if ($value === '') {
                    continue;
                }

                // HOW MANY elements carry this value, for the element
                // questions. Its own budget, separate from the attribute one
                // below: asking whether an element survived must not spend a
                // credit an attribute row is about to ask for.
                $values[$value] = ($values[$value] ?? 0) + 1;

                // A class is a token LIST, so each token is tallied on its own -
                // `class="details x"` answers for an authored `x` without the
                // extension's own `details` having to be predicted.
                if ($name === 'class') {
                    foreach (preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                        if ($token === 'class') {
                            $content ??= $this->importElementContentKey($element);
                        }
                        $key = $this->importSurvivorKey('class', $token, $content ?? '');
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                } else {
                    if ($value === $name) {
                        $content ??= $this->importElementContentKey($element);
                    }
                    $key = $this->importSurvivorKey($name, $value, $content ?? '');
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }

                // The name-blind tally, consulted only for `title` - see
                // `importAttributeSurvived()`.
                $key = "\0any\0" . $value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $this->emittedImportValues = $values;

        return $counts;
    }

    /**
     * Build the observation of the emitted document, once per conversion.
     */
    protected function importEmittedDocument(): void
    {
        if ($this->survivingImportAttributes !== null) {
            return;
        }

        $this->survivingImportAttributes = $this->tallySurvivingAttributes(
            $this->inspectedCarve ?? '',
        );
    }

    protected function isKnownImportElement(string $tag): bool
    {
        // The seven the compact semantic span spells are mapped, not unwrapped,
        // so reporting `element-unwrapped` for them described a loss that had
        // already stopped happening for four of them and never happens now.
        if (in_array($tag, self::SEMANTIC_SPAN_ELEMENTS, true)) {
            return true;
        }

        return in_array($tag, [
            'html', 'body', 'div', 'section', 'article', 'main', 'header', 'footer', 'nav', 'address',
            'aside', 'dialog', 'fieldset', 'form', 'hgroup', 'menu', 'search', 'details', 'summary',
            'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em',
            'i', 'u', 's', 'strike',
            // `q` is a mapping, not an unwrapping: its content comes back
            // wrapped in quote characters, which is the representation Carve
            // has for a quoted phrase. Nothing is replaced by span metadata
            // and nothing is lost, so there was nothing to report.
            'q',
            // `ins` sits next to its `del` twin: both have a marker of their
            // own (`{+ +}` and `{- -}`) and neither is unwrapped, so reporting
            // one as replaced by Carve span metadata described a loss that
            // does not happen.
            'ins',
            'del', 'mark', 'sub', 'sup', 'code', 'pre', 'a', 'img', 'br', 'hr',
            'span', 'ul', 'ol',
            'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th',
            'td', 'caption',
            'figure', 'figcaption', 'blockquote', 'cite', 'abbr',
            // `input` IS NOT HERE, though it was. It is representable in one
            // position only - a checkbox at the head of a list item, which
            // comes back as the task marker `- [ ]` - and listing it as known
            // silenced every other one. An `<input>` in a paragraph took its
            // content out of the document and produced no row at all, the one
            // discarded element in this importer that exited clean
            // (carve-php#1377). It reaches the outcome question with
            // everything else now, and the task-list checkbox answers that
            // question by leaving a trace rather than by being named here.
        ], true);
    }

    /**
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     * @param string $path
     * @param string $severity
     * @param string $message
     * @param string $code
     *
     * @throws \MarkupCarve\Carve\Converter\HtmlImportLimitException
     */
    protected function addImportDiagnostic(array &$diagnostics, string $code, string $message, string $severity, string $path): void
    {
        if (count($diagnostics) >= $this->maxDiagnostics) {
            throw new HtmlImportLimitException('HTML import diagnostics limit exceeded');
        }
        $diagnostics[] = new HtmlImportDiagnostic($code, $message, $severity, $path);
    }

    protected int $listDepth = 0;

    protected bool $inPre = false;

    protected bool $preserveTextWhitespace = false;

    /**
     * Collected reference definitions for round-trip support
     * Maps reference label => url
     *
     * @var array<string, string>
     */
    protected array $referenceDefinitions = [];

    /**
     * Collected footnote definitions for round-trip support
     * Maps footnote label => content
     *
     * @var array<string, string>
     */
    protected array $footnoteDefinitions = [];

    /**
     * The fragments every `role="doc-noteref"` anchor in this document points
     * at, or null before the walk that collects them. See
     * {@see noteReferenceTargets()}.
     *
     * @var array<string, true>|null
     */
    protected ?array $noteReferenceTargets = null;

    /**
     * Collected abbreviation definitions for round-trip support
     *
     * Stores complete definition lines in Djot format: "*[ABBR]: Definition"
     *
     * @var array<string>
     */
    protected array $abbreviationDefinitions = [];

    /**
     * Abbreviation definition lookup for round-trip preservation.
     *
     * @var array<string, string>
     */
    protected array $abbreviationMap = [];

    /**
     * Attributes to skip when converting (these don't translate well to Djot)
     *
     * @var array<string>
     */
    protected array $skipAttributes = [
        'style', // CSS doesn't map to Djot
        'xmlns', // XML namespace
        'role', // ARIA (could be kept, but often noise)
    ];

    /**
     * The structural class a writer has temporarily lifted off the node, so the
     * derived-name test can still see what the element IS.
     */
    protected ?string $structuralClassInProgress = null;

    /**
     * The importer's strip policy, asked as ONE question.
     *
     * WHAT IT REFUSES is a rule, not a roster. `on*` is unbounded and browsers
     * keep extending it, so an enumeration of handler names cannot be complete
     * - and this list held exactly five of them (`onclick`, `onload`,
     * `onmouseover`, `onmouseout`, `onsubmit`) while four separate writers each
     * had to remember to pair it with the prefix rule. Three of them did not,
     * so `<aside class="admonition note" onfocus="steal()">` imported to Carve
     * source reading `{onfocus=steal()}` - the exact laundering the fourth
     * writer's comment says the prefix exists to prevent (carve-php#1375).
     *
     * Four call sites that must agree with nothing making them agree is the
     * defect carve-php#1346 and carve-php#1337 both came back to. So the
     * question is asked here and only here, and the five names are gone: they
     * were redundant wherever the prefix ran and the only defense where it did
     * not, which is the worst of both readings.
     *
     * `srcdoc` and `formaction` join it. PART 9 §25 has the HTML renderer blank
     * both, so keeping them on import produced Carve source carrying an
     * attribute every target has to remember to refuse - a defense that holds
     * only at the last stage is one target away from not holding. Nothing the
     * reader sees changes: the renderer already blanked them, and the import
     * report already said they were dropped, because it asks the rendered
     * document (carve-php#1337).
     *
     * PER-SITE skips - `id`, `class`, an admonition's own `data-djot-*` keys -
     * stay at their call sites. They are that writer's business; this is the
     * policy.
     */
    protected function isStrippedImportAttribute(string $name): bool
    {
        $lower = strtolower($name);

        return str_starts_with($lower, 'on')
            || $lower === 'srcdoc'
            || $lower === 'formaction'
            || str_starts_with($lower, 'data-djot-')
            || in_array($name, $this->skipAttributes, true)
            || in_array($lower, $this->skipAttributes, true);
    }

    /**
     * Convert HTML to Djot markup
     */
    public function convert(string $html): string
    {
        // Reset state
        $this->listDepth = 0;
        $this->inPre = false;
        $this->preserveTextWhitespace = false;
        $this->referenceDefinitions = [];
        $this->footnoteDefinitions = [];
        $this->noteReferenceTargets = null;
        $this->abbreviationDefinitions = [];
        $this->abbreviationMap = [];
        $this->captionFlattenDiagnostics = [];
        $this->splitDefinitionLists = [];
        $this->droppedDefinitionDescriptions = [];
        $this->loneImageParagraphs = [];
        $this->consumedCheckboxInputs = [];
        $this->rawPreservedElements = [];
        $this->unwrappedFigures = [];
        $this->captionedTableFigures = [];
        $this->detachedFigureCaptions = [];
        $this->unwrappedBlockContainers = [];

        // Wrap in a single root element unless the input is already a full
        // document. Only a leading <!doctype>/<html>/<body> counts as a root:
        // a <div> nested anywhere must NOT skip wrapping, otherwise a fragment
        // with several top-level siblings (e.g. <ul>..</ul><ul>..</ul> where an
        // item contains a <div>) loses every sibling after the first, since
        // LIBXML_HTML_NOIMPLIED makes only the first element the documentElement.
        if (!preg_match('/^\s*(<!doctype|<html|<body)/i', $html)) {
            $html = '<div>' . $html . '</div>';
        }

        // Load HTML
        $doc = new DOMDocument();
        $doc->encoding = 'UTF-8';

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Extract abbreviation definitions from template element (round-trip support)
        $this->extractAbbreviationDefinitions($doc);

        // Rewrite an editor's footnote-shaped HTML into the shape the core
        // policy below already reads. Adapter-gated; see the method.
        $this->normalizeAdapterFootnotes($doc);

        $djot = $this->processNode($doc->documentElement ?? $doc);

        // Prepend abbreviation definitions at the start
        if ($this->abbreviationDefinitions !== []) {
            $abbrevs = implode("\n", $this->abbreviationDefinitions) . "\n\n";
            $djot = $abbrevs . $djot;
        }

        // Append reference definitions collected during conversion
        if ($this->referenceDefinitions !== []) {
            // Ensure blank line before reference definitions
            $refs = "\n\n";
            foreach ($this->referenceDefinitions as $label => $url) {
                $refs .= '[' . $label . ']: ' . $url . "\n";
            }
            $djot .= $refs;
        }

        // Append footnote definitions collected during conversion
        if ($this->footnoteDefinitions !== []) {
            // Ensure blank line before footnote definitions
            $notes = "\n\n";
            foreach ($this->footnoteDefinitions as $label => $content) {
                $notes .= $this->formatFootnoteDefinition($label, $content) . "\n";
            }
            $djot .= $notes;
        }

        // Clean up
        $djot = $this->cleanup($djot);

        return $djot;
    }

    /**
     * Extract abbreviation definitions from template element
     */
    protected function extractAbbreviationDefinitions(DOMDocument $doc): void
    {
        $templates = $doc->getElementsByTagName('template');
        foreach ($templates as $template) {
            if ($template->hasAttribute('data-djot-abbreviations')) {
                $content = $template->textContent;
                $lines = explode("\n", $content);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $this->abbreviationDefinitions[] = $line;
                        if (preg_match('/^\*\[([^\]]+)\]:\s*(.*)$/', $line, $matches) === 1) {
                            $this->abbreviationMap[$matches[1]] = $matches[2];
                        }
                    }
                }
                // Remove the template element so it's not processed
                $template->parentNode?->removeChild($template);

                break;
            }
        }
    }

    /**
     * Convert an HTML file to Djot
     *
     * @throws \RuntimeException If file cannot be read
     */
    public function convertFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        return $this->convert($content);
    }

    /**
     * @phpstan-impure
     */
    protected function processNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            $text = $node->textContent;
            if (!$this->inPre && !$this->preserveTextWhitespace) {
                // Normalize whitespace outside pre blocks
                $text = preg_replace('/\s+/', ' ', $text) ?? $text;
            }

            if ($this->captionDepth > 0 && trim($text) !== '') {
                $this->captionPendingBoundary = false;
            }

            // A backslash in HTML text is a character, not an escape, so it
            // is doubled before the delimiter escaping runs. Inside `pre` the
            // text is verbatim and nothing is escaped at all.
            return $this->inPre ? $text : $this->escapeHtmlTextAsCarveProse($text);
        }

        if ($node instanceof DOMComment) {
            // AN HTML COMMENT IS A CARVE COMMENT, and this is the INLINE
            // position of it (`markup-carve/carve#1709`). The block position is
            // decided in processChildren(), which is the only walk that can see
            // whether the comment stands among blocks.
            //
            // The usual reason this importer drops something is that Carve
            // cannot express the shape. That reason never applied here: Carve
            // HAS comments, so dropping one was a choice to lose bytes the
            // format can hold, in a mode whose whole job is fidelity, made by
            // nobody. A comment renders nothing in either language, so keeping
            // it is invisible in the output and lossless in the source.
            return $this->inlineCommentSource($node);
        }

        if (!($node instanceof DOMElement)) {
            // Process children for other node types
            return $this->processChildren($node);
        }

        $tagName = strtolower($node->tagName);

        $djotSrc = $this->captionDepth > 0 ? null : $this->extractRoundTripSource($node, $tagName);
        if ($djotSrc !== null) {
            return $djotSrc;
        }

        if ($tagName === 'section' && $this->isInlineOnlyEndnotesSection($node)) {
            return '';
        }

        if ($this->captionDepth > 0 && $this->isFlattenedInACaption($tagName)) {
            // Inside a caption line, a block is its content. See
            // processCaptionChildren() for why this is a depth and not a rule
            // about the caption's direct children.
            //
            // A FLATTEN PRESERVES THE BOUNDARY IT DISSOLVES (PART 11 §1b,
            // markup-carve/carve#1325). Returning the content bare ran two
            // blocks together: `<p>one</p><p>two</p>` became `onetwo`, and
            // `<p><strong>a</strong></p><p><strong>b</strong></p>` became
            // `*a**b*`, which re-parses as literal asterisks rather than two
            // runs. The block boundary is gone either way - a caption is one
            // line - but what it SEPARATED has to survive it.
            //
            // An EMPTY block contributes no separator, because there was
            // nothing on this side of the boundary to keep apart (corpus
            // convert case 32). The trailing one is removed by
            // processCaptionChildren(), so a lone block reads exactly as before.
            $hadPending = $this->captionPendingBoundary;
            $needsSeparator = $this->captionPendingNeedsSeparator;
            $this->captionPendingBoundary = false;
            $flattened = rtrim($this->processChildren($node));
            if ($flattened === '') {
                $this->captionPendingBoundary = $hadPending;
                $this->captionPendingNeedsSeparator = $needsSeparator;

                return '';
            }

            $this->captionPendingBoundary = true;
            $this->captionPendingNeedsSeparator = preg_match('/\s$/u', $flattened) !== 1;
            $this->captionFlattenDiagnostics[] = new HtmlImportDiagnostic(
                'element-unwrapped',
                'Unwrapped unsupported <' . $tagName . '> element',
                'info',
                $this->conversionNodePath($node),
            );

            return ($hadPending && $needsSeparator ? ' ' : '') . $flattened;
        }

        return match ($tagName) {
            'section' => $this->processSection($node),
            'html', 'body' => $this->processBlock($node),
            'aside' => $this->processAside($node),
            'article', 'main', 'header', 'footer', 'nav',
            'address', 'dialog', 'fieldset', 'form', 'hgroup', 'menu', 'search' => $this->processGenericBlockContainer($node),
            'details' => $this->processDetails($node),
            'div' => $this->processDiv($node),
            'p' => $this->processParagraph($node),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->processHeading($node),
            // The five single-character delimiters (`*` strong, `/` emphasis,
            // `_` underline, `~` strike, `=` highlight) are bare when the
            // element is word-bounded (canonical) and take the forced brace
            // form intraword (Sy<strong>rup</strong>-free, H<sub>2</sub>O),
            // where a bare delimiter would not open at all and its two
            // characters would land in the prose as literal text.
            'strong', 'b' => $this->processBareInlineFormatting($node, '*'),
            'em', 'i' => $this->processBareInlineFormatting($node, '/'),
            'u' => $this->processBareInlineFormatting($node, '_'),
            's', 'strike' => $this->processBareInlineFormatting($node, '~'),
            'mark' => $this->processBareInlineFormatting($node, '='),
            'ins' => $this->processInlineFormatting($node, '{+', '+}'),
            'del' => $this->processInlineFormatting($node, '{-', '-}'),
            // Superscript and subscript have no bare form at all.
            'sup' => $this->processInlineFormatting($node, '{^', '^}'),
            'sub' => $this->processInlineFormatting($node, '{,', ',}'),
            'kbd' => $this->processSemanticSpan($node, 'kbd'),
            'dfn' => $this->processSemanticSpan($node, 'dfn'),
            'abbr' => $this->processSemanticSpan($node, 'abbr'),
            'samp' => $this->processSemanticSpan($node, 'samp'),
            'var' => $this->processSemanticSpan($node, 'var'),
            'cite' => $this->processSemanticSpan($node, 'cite'),
            'time' => $this->processSemanticSpan($node, 'time'),
            'q' => $this->processInlineQuote($node),
            'code' => $this->processCode($node),
            'pre' => $this->processPreBlock($node),
            'a' => $this->processLink($node),
            'img' => $this->processImage($node),
            'br' => $this->inPre ? "\n" : "\\\n",
            'hr' => $this->processHr($node),
            'blockquote' => $this->processBlockquote($node),
            'ul', 'ol' => $this->processList($node),
            'li' => $this->processListItem($node),
            'table' => $this->processTable($node),
            'dl' => $this->processDefinitionList($node),
            'span' => $this->processSpan($node),
            'math' => $this->processMath($node),
            'figure' => $this->processFigure($node),
            'figcaption' => '', // Handled by figure
            'caption' => '', // Handled by table
            'thead', 'tbody', 'tfoot', 'tr', 'th', 'td' => $this->processChildren($node), // Handled by table
            // READ BY A PARENT'S WALK, so none of them is markup this
            // converter cannot express and none preserves
            // (`markup-carve/carve-php#1713`). `processDefinitionList()` and
            // `processList()` read these directly; one reaching the dispatch
            // at all means it was written outside the parent that owns it, and
            // the answer there is the one it always gave. Naming them keeps
            // them out of the `default` arm below, which is now the
            // preserve arm - a `<dt>` inside a tab came back as raw HTML.
            'dt', 'dd', 'li' => $this->processChildren($node),
            'script', 'style', 'noscript' => '', // Skip these
            /*
             * THE DEFAULT ARM IS THE PRESERVE ARM (`markup-carve/carve-php#1713`),
             * and that makes it load-bearing in a way it was not before.
             *
             * BEFORE ADDING A TAG ANYWHERE IN THIS MATCH, ask whether it can
             * also arrive here on its own. A tag whose real handling lives in
             * its PARENT's walk - a `<dt>` read by `processDefinitionList()`, a
             * `<td>` read by `processTable()` - still reaches the dispatch when
             * it is written outside that parent, and it used to land on
             * `processChildren()`, which unwrapped it harmlessly. It now lands
             * on the preserve arm and comes back as raw HTML: a `<dt>` inside a
             * `::: tab` came out as `` `<dt>Term</dt>`{=html} ``, and the suite
             * caught it only because one test happened to write a definition
             * list in a container.
             *
             * So every such name is listed above with the answer it always
             * gave. Reaching this arm now MEANS "this converter has no spelling
             * for it", which is what makes the preserve rule derived rather
             * than a roster - and the cost of that is that the match has to be
             * honest about which names are handled elsewhere.
             */
            default => $this->preservedAsRawHtml($node) ?? $this->processChildren($node),
        };
    }

    /**
     * HTML text, escaped for the Carve PROSE slot it is about to land in.
     *
     * Every character Carve reads as an opener is literal in HTML text, so the
     * value carries none of the author's intent as markup and all of it as
     * characters. The four passes are one production - the backslash doubling
     * has to run FIRST so the already-escaped guard in the rest sees an even
     * run - and they are named here rather than spelled at each call site,
     * because a second copy is what drifts. `<img alt>` promoted to prose is
     * the second caller, and it reached the same slot by a different route.
     *
     * @param string $text
     */
    protected function escapeHtmlTextAsCarveProse(string $text): string
    {
        return $this->escapePlainCarveInlineSyntax(
            $this->escapeAttributeBlockOpener($this->escapeVerbatimDelimiter($this->escapeLiteralBackslashes($text))),
            self::HANDLED_PLAIN,
        );
    }

    protected function processChildren(DOMNode $node): string
    {
        $output = '';
        foreach ($node->childNodes as $child) {
            $output .= $this->processNode($child);
        }

        return $output;
    }

    /**
     * A caption's content, with block descendants UNWRAPPED to their inline run.
     *
     * A caption line is an INLINE slot - `^ ` followed by one line of inline
     * content - so a block cannot live in it. Converting a caption's children
     * the ordinary way wrote their Carve SOURCE into that slot, where it
     * re-parses as prose: a `<ul><li>a</li><li>b</li></ul>` came back as the
     * literal characters `- a` and `- b`, so the document gained text the
     * author never wrote AND lost the list they did write (carve-php#1345).
     *
     * Carrying the source into a slot that cannot hold its meaning reads as
     * preservation and is really loss - the same shape as the `{cite=u}` that
     * landed as caption text. The honest degradation is to take the content and
     * drop the structure, which is what carve-js and carve-rs both already do:
     * each renders `^ ab` here, and each reports an `element-unwrapped` row per
     * block it unwrapped. This engine was the outlier on both halves.
     *
     * A DEPTH rather than a walk of the caption's own children, because the
     * block need not be one: `<figcaption><a><ul>…</ul></a></figcaption>` is
     * valid - `<a>` is transparent - and a child-only rule left that list
     * serializing as `- a` inside the link, which is the original defect
     * surviving under a wrapper. The depth reaches every descendant while
     * still letting inline elements convert normally, so the link is kept and
     * only the list inside it is flattened.
     *
     * THIS RECORDS DIAGNOSTICS. DO NOT CALL IT TO ASK A QUESTION.
     *
     * Every block it flattens appends an `element-unwrapped` row to
     * `$captionFlattenDiagnostics`, so calling it to find out something about a
     * caption and then converting for real reports the same flatten TWICE. That
     * is not hypothetical: `markup-carve/carve-php#1713` asked it whether a
     * caption spelled anything before deciding to preserve the figure, and a
     * figure whose caption held a list gained three rows for one conversion.
     *
     * A question about a caption is asked of the DOM - see
     * `captionSpellsSomething()`, which exists because of that bug. Anything
     * this method can answer that the DOM cannot needs a side-effect-free
     * predicate of its own rather than a second call to this one.
     */
    protected function processCaptionChildren(DOMNode $node): string
    {
        $previousPending = $this->captionPendingBoundary;
        $previousNeedsSeparator = $this->captionPendingNeedsSeparator;
        $this->captionPendingBoundary = false;
        $this->captionPendingNeedsSeparator = false;
        $this->captionDepth++;
        try {
            return rtrim($this->processChildren($node));
        } finally {
            $this->captionDepth--;
            $this->captionPendingBoundary = $previousPending;
            $this->captionPendingNeedsSeparator = $previousNeedsSeparator;
        }
    }

    /**
     * How many caption slots deep the serializer is.
     *
     * Zero everywhere else, so nothing outside a caption changes: a list in an
     * ordinary table cell is a different question (carve-php#1164) and must not
     * start flattening because a caption does.
     */
    protected int $captionDepth = 0;

    protected bool $captionPendingBoundary = false;

    protected bool $captionPendingNeedsSeparator = false;

    /**
     * @var list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic>
     */
    protected array $captionFlattenDiagnostics = [];

    /**
     * The `<dl>` elements this conversion wrote as more than one list.
     *
     * RECORDED BY THE WRITER, not re-derived by the diagnostic walk. Whether an
     * entry writes nothing is answered by RENDERING it - `<dd><p> </p></dd>` and
     * `<dd><ul></ul></dd>` hold elements and still write nothing - so a second
     * predicate over the DOM answers differently from the one that actually
     * split the list, and the split goes undeclared. `convertWithReport()`
     * converts first and inspects afterwards, so what the writer saw is
     * available by the time the row is written.
     *
     * KEYED BY PATH, not by object id: `convertWithReport()` inspects a SECOND
     * parse of the same HTML, so no node object is shared between the two
     * passes. The path is what both walks agree on, and it is what the row
     * carries anyway.
     *
     * @var array<string, true>
     */
    protected array $splitDefinitionLists = [];

    /**
     * Is this conversion the one feeding the AST exit?
     *
     * The two exits differ in exactly one way and it is not a mode: where Carve
     * SOURCE has no spelling for a structure, the source exit loses it and says
     * so, and the AST exit keeps it (`docs/html-import.md`). This engine reads
     * its tree back from its own source, so the shapes with no spelling need
     * carrying across, and this flag is what turns that on. Nothing else about
     * the conversion changes, and the REPORT does not change at all.
     */
    protected bool $astExit = false;

    /**
     * The elements this conversion kept BYTE FOR BYTE, keyed by path.
     *
     * KEYED BY PATH for the reason every other record here is: the report walks
     * a SECOND parse of the same HTML, so no node object is shared between the
     * two passes, and the path is what both walks agree on.
     *
     * @var array<string, true>
     */
    protected array $rawPreservedElements = [];

    /**
     * The `<figure>` elements this conversion UNWRAPPED, keyed by path.
     *
     * A FIGURE IS ITS CAPTION (PART 9 §4b), so a wrapper that writes no `^ `
     * line did not survive as a figure whatever else came through: the target
     * is in the output, the element around it is not, and the re-render shows
     * a bare image, quote or code block where the input had a figure. That is
     * `element-unwrapped` by the definition {@see self::inspectImportNode()}
     * uses everywhere else, and this engine was the only one of the three
     * saying nothing at all (carve-php#1723).
     *
     * RECORDED BY THE WRITER rather than re-derived, because the writer is the
     * one that knows which of the five arms ran. Re-deriving it in the report
     * walk means a second copy of {@see self::processFigure()}'s decision, and
     * {@see self::convertWithReport()} exists to stop exactly that.
     *
     * KEYED BY PATH for the reason {@see self::$rawPreservedElements} is: the
     * report walks a SECOND parse of the same HTML, so no node object is
     * shared between the two passes.
     *
     * @var array<string, true>
     */
    protected array $unwrappedFigures = [];

    /**
     * The `<figure>` elements this conversion rebuilt as a CAPTIONED TABLE.
     *
     * `<table><caption>` is the idiomatic HTML for a captioned table, so a
     * figure around one rebuilds rather than preserving (`markup-carve/carve#1704`) -
     * but the rebuild is not lossless. The `^ ` line reads back as the table's
     * own `<caption>`, so what comes out is a captioned table where the input
     * had a figure, and `structure-unspellable` is what says so.
     *
     * This is the row that was missing WITH the caption line: the caption used
     * to leave the figure and land as a detached paragraph, and the report was
     * empty either way (carve-php#1722).
     *
     * KEYED BY PATH for the reason {@see self::$rawPreservedElements} is: the
     * report walks a SECOND parse of the same HTML, so no node object is
     * shared between the two passes.
     *
     * @var array<string, true>
     */
    protected array $captionedTableFigures = [];

    /**
     * The `<figcaption>` elements this conversion DETACHED into a paragraph.
     *
     * A figure around a table that captions itself has two captions for one
     * `^ ` slot (ruling `markup-carve/carve-js#1488`). The table keeps the slot
     * and the figcaption's text follows as prose, so what is lost is the caption
     * ROLE and not a byte of either caption - and this is the row that says so.
     *
     * KEYED BY THE CAPTION'S PATH, not the figure's, because the caption is what
     * the row is about and the report is ordered by the losing node's position.
     *
     * @var array<string, true>
     */
    protected array $detachedFigureCaptions = [];

    /**
     * The block containers this conversion UNWRAPPED, keyed by path.
     *
     * A sectioning wrapper - `<article>`, `<main>`, `<header>`, `<footer>`,
     * `<nav>`, `<aside>` - has no Carve block, and the container fence is not
     * one: it renders as `<div class="name">` for every name, so writing one
     * put a class in the output the document never carried while the element
     * the author wrote was gone anyway (carve-php#1721). They unwrap, and this
     * is what lets the report say so.
     *
     * RECORDED BY THE WRITER, because the unwrapping is a decision about the
     * WRITE - a table cell takes the same route for a different reason, and
     * `<aside class="admonition note">` never reaches it at all, since that one
     * really does have a construct. Asking the input DOM instead means a second
     * copy of those conditions.
     *
     * KEYED BY PATH for the reason {@see self::$rawPreservedElements} is: the
     * report walks a SECOND parse of the same HTML, so no node object is
     * shared between the two passes.
     *
     * @var array<string, true>
     */
    protected array $unwrappedBlockContainers = [];

    /**
     * The `<dd>` elements this conversion dropped for writing nothing.
     *
     * THE SAME RECORD FOR THE SAME REASON as {@see self::$splitDefinitionLists}
     * above, and it was missing for the row that sits next to that one. The
     * `structure-unspellable` row asked `hasImportContentToUnwrap()`, which
     * answers what a `<dd>` HOLDS, while the writer that drops it answers what
     * it WRITES. Those disagree on exactly the two shapes the split record's
     * own comment names: `<dd><p> </p></dd>` and `<dd><ul></ul></dd>` both hold
     * an element and both write nothing, so both were dropped with no row.
     *
     * `docs/html-import.md`'s "a declared loss is a ceiling, not a licence"
     * makes the row the thing that PERMITS the drop, so an undeclared drop is
     * the half the ceiling does not cover - which is the reasoning that added
     * this row in the first place (carve-php#1615).
     *
     * Keyed by path for the reason the split record is: `convertWithReport()`
     * inspects a SECOND parse, so no node object is shared between the passes.
     *
     * @var array<string, true>
     */
    protected array $droppedDefinitionDescriptions = [];

    /**
     * The `<p>` elements this conversion wrote as a bare block image.
     *
     * A paragraph holding nothing but an image has no Carve spelling, so the
     * writer emits the image at a block position and the `<p>` the author wrote
     * is not in the source it produced (carve-php#1667). The value carries what
     * the row has to SAY about it: whether the paragraph had attributes to
     * re-attach, and which of them the image's own attribute block overwrites.
     *
     * KEYED BY PATH for the reason {@see self::$splitDefinitionLists} is:
     * `convertWithReport()` inspects a SECOND parse of the same HTML, so no node
     * object is shared between the two passes.
     *
     * @var array<string, array{attributed: bool, overwritten: list<string>}>
     */
    protected array $loneImageParagraphs = [];

    /**
     * The `<input>` elements this conversion CONSUMED into a task marker.
     *
     * A checkbox at the head of a list item is not lost: it comes back as the
     * `- [ ]` marker, which is why the report says nothing about it. That
     * silence was produced by asking the OUTPUT whether any of the element's
     * raw attribute VALUES reappears there, and the re-render writes
     * `type="checkbox"` in lowercase - so an author who wrote `CHECKBOX`, which
     * every browser and this importer read as the same keyword, got
     * `attribute-dropped` and `element-dropped` about a marker that is right
     * there in the output (carve-php#1705).
     *
     * RECORDED BY THE WRITER, at the point of consumption. The alternative is to
     * re-derive during the report walk which `<input>` became the marker, and
     * that reintroduces a comparison on the value - the shape that caused this.
     * The writer already knows: it is holding the element it read the state off.
     *
     * NOT FIXED BY FOLDING CASE IN THE TALLY. That would change what counts as
     * survival for every element and every attribute, well past this one
     * keyword, and it could start suppressing real losses - trading a false
     * negative here for a class of false positives elsewhere is a worse report.
     *
     * KEYED BY PATH for the reason {@see self::$loneImageParagraphs} is:
     * `convertWithReport()` inspects a SECOND parse of the same HTML, so no node
     * object is shared between the two passes.
     *
     * @var array<string, true>
     */
    protected array $consumedCheckboxInputs = [];

    /**
     * The path of the consumed checkbox currently being inspected, if any.
     *
     * Set around one element's inspection so the two questions that were
     * answered wrongly can answer for it, and so that NOTHING ELSE about the
     * element changes - see {@see self::inspectImportNode()}.
     */
    protected ?string $inspectedConsumedCheckbox = null;

    /**
     * How many flattened top-level nodes stand before this `<head>`/`<body>`.
     *
     * {@see self::importTopLevelNodes()} splices the children of each into one
     * run, so a `<body>` child's number continues where the `<head>`'s children
     * stopped. Any other child of `<html>` counts as itself, since it is not
     * flattened.
     */
    private function flattenedTopLevelOffset(DOMElement $section): int
    {
        $parent = $section->parentNode;
        if (!$parent instanceof DOMElement) {
            return 0;
        }

        $offset = 0;
        foreach ($parent->childNodes as $sibling) {
            if ($sibling === $section) {
                break;
            }
            $tag = $sibling instanceof DOMElement ? strtolower($sibling->tagName) : '';
            $offset += $tag === 'head' || $tag === 'body' ? $sibling->childNodes->length : 1;
        }

        return $offset;
    }

    private function conversionNodePath(DOMElement $node): string
    {
        $parts = [];
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            $parent = $current->parentNode;
            if (!$parent instanceof DOMElement) {
                break;
            }
            $index = 0;
            foreach ($parent->childNodes as $sibling) {
                $index++;
                if ($sibling === $current) {
                    break;
                }
            }
            // A FULL DOCUMENT NUMBERS HEAD AND BODY AS ONE RUN, because
            // `importTopLevelNodes()` flattens them into one before the
            // inspection walk numbers anything - so a `<body>`'s first child is
            // not child 1 when the `<head>` contributed nodes ahead of it. The
            // two passes have to agree: the record this path keys is written by
            // the CONVERSION and read by the INSPECTION, and a path that
            // disagrees drops the row silently, which is the exact failure the
            // rows keyed this way exist to prevent. Every row on the record -
            // `structure-split`, the dropped `<dd>` and the lone-image
            // paragraph - was missing on a document with a non-empty `<head>`.
            $parentTag = strtolower($parent->tagName);
            if (
                in_array($parentTag, ['head', 'body'], true)
                && $parent->parentNode instanceof DOMElement
                && strtolower($parent->parentNode->tagName) === 'html'
            ) {
                $index += $this->flattenedTopLevelOffset($parent);
            }

            $tag = strtolower($current->tagName);
            if (!in_array($tag, ['html', 'head', 'body'], true)) {
                $parts[] = $tag . '[' . $index . ']';
            }

            // A fragment's synthetic wrapper is never part of a public path.
            if ($parent->parentNode instanceof DOMDocument && strtolower($parent->tagName) === 'div') {
                break;
            }
        }

        return '/' . implode('/', array_reverse($parts));
    }

    /**
     * Does a caption slot dissolve this element into its content?
     *
     * Every block, plus a NESTED caption. A `<figcaption>` normally returns
     * nothing because `processFigure()` is expected to consume it, so once the
     * figure around it has been unwrapped that early return silently dropped
     * the author's caption text.
     */
    protected function isFlattenedInACaption(string $tagName): bool
    {
        return in_array($tagName, $this->blockElements, true)
            || in_array($tagName, ['td', 'th', 'dt', 'dd'], true)
            || $tagName === 'caption'
            || $tagName === 'figcaption';
    }

    /**
     * Does this comment stand AMONG BLOCKS rather than inside an inline run?
     *
     * THE RUN DECIDES, NOT THE TAG IT SITS UNDER (`markup-carve/carve#1709`).
     * A run is the span of consecutive non-block siblings the comment belongs
     * to. If everything in that span is a comment or the layout between them,
     * the comment is sitting between blocks however the markup got it there,
     * and the block spelling is the honest one. If the run carries anything
     * else - a word, an inline element - the comment is inside a real inline
     * run, and emitting a block there would split the words either side of it
     * into two paragraphs, which is the document saying something it never
     * said.
     *
     * Whitespace-only text is NOT "something else". It is the layout between
     * the blocks, which is exactly what a comment between two of them sits in,
     * and counting it as content would make the answer depend on whether the
     * author indented their HTML.
     */
    protected function commentStandsAmongBlocks(DOMComment $node): bool
    {
        foreach (['previousSibling', 'nextSibling'] as $direction) {
            $sibling = $node->$direction;
            while ($sibling !== null) {
                if ($sibling instanceof DOMElement) {
                    if (in_array(strtolower($sibling->tagName), $this->blockElements, true)) {
                        break;
                    }

                    return false;
                }
                if ($sibling instanceof DOMText && !$this->isLayoutOnlyText($sibling->textContent)) {
                    return false;
                }
                $sibling = $sibling->$direction;
            }
        }

        return true;
    }

    /**
     * Every character layout, and none of it content.
     *
     * `trim()` is not this question. PHP's `trim()` default set is ASCII, so it
     * happens to agree here, but the question being asked is PART 11 section 7's
     * content-versus-layout line and naming it stops the next reader reaching
     * for a whitespace test that answers a different one.
     */
    protected function isLayoutOnlyText(string $text): bool
    {
        return $text === '' || strspn($text, " \t\n\r\f") === strlen($text);
    }

    /**
     * An HTML comment in a BLOCK position, as the fenced Carve comment.
     *
     * A FENCE MUST BE WIDER THAN ANY RUN OF `%` INSIDE IT - a nested `%%%`
     * closes it early - so it widens the way a code fence does. That is why the
     * block form has no unspellable payload and the inline form does: nothing
     * an author can write into a comment can close this one.
     *
     * The widening rule is `CarveRenderer::renderComment`'s, spelled the same
     * way, so a document that comes through this importer and then through the
     * writer does not change width.
     */
    protected function blockCommentSource(string $content): string
    {
        preg_match_all('/%+/', $content, $matches);
        $longest = 0;
        foreach ($matches[0] as $match) {
            $longest = max($longest, strlen($match));
        }
        $fence = str_repeat('%', max(3, $longest + 1));

        return $fence . "\n" . $content . "\n" . $fence;
    }

    /**
     * An HTML comment in an INLINE position, as the delimited Carve comment.
     *
     * TWO PAYLOADS HAVE NO INLINE SPELLING, and both close the comment EARLY
     * rather than being escapable:
     *
     * - text holding the closer, which ends the comment where it appears, so
     *   the rest of the payload comes back as prose;
     * - text holding a BLANK line, which ends the paragraph the run is in, so
     *   both halves come back as prose and the comment is gone.
     *
     * Those are DROPPED, with one row saying so. Not truncated and not escaped
     * into the form: a comment that came back shorter, or carrying characters
     * the author did not write, is a silent content change, and the row is the
     * point. Not relocated to the block form either - moving it would put text
     * somewhere the author did not write it.
     *
     * A single newline is NOT one of the two: it is a soft wrap inside the run
     * rather than its end, so such a comment re-reads intact.
     */
    protected function inlineCommentSource(DOMComment $node): string
    {
        $content = $node->textContent;
        $closesEarly = str_contains($content, '%}');
        $endsTheRun = preg_match('/\n[ \t]*\n/', $content) === 1;
        if ($this->commentHasNoInlineSpelling($content)) {
            // The ROW is added by the inspection walk, which is where every
            // other row is added and the only walk that numbers a path in
            // document order. See inspectImportNodes().
            return '';
        }

        return '{% ' . $content . ' %}';
    }

    /**
     * Is this comment text one of the two payloads the inline form cannot hold?
     *
     * ONE PLACE, because two walks ask it: the CONVERSION decides whether to
     * write the comment, and the INSPECTION decides whether to report it. Two
     * spellings of the same test is how a row appears for a comment that was
     * written, or fails to appear for one that was not.
     */
    protected function commentHasNoInlineSpelling(string $content): bool
    {
        return str_contains($content, '%}') || preg_match('/\n[ \t]*\n/', $content) === 1;
    }

    /**
     * Block-level elements that should break implicit paragraphs
     *
     * @var array<string>
     */
    protected array $blockElements = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'blockquote',
        'ul', 'ol', 'li', 'table', 'dl', 'hr', 'div', 'section',
        'article', 'header', 'footer', 'nav', 'aside', 'figure', 'main',
        'address', 'details', 'dialog', 'fieldset', 'form', 'hgroup', 'menu', 'search',
    ];

    /**
     * Which block, written below a tight item's lead, still opens a block there.
     *
     * A TIGHT ITEM WRITES NO BLANK LINE between its blocks (carve-php#1708), and
     * that is only safe for a block that OPENS at the item's content column. A
     * block that does not open one there is read as the lead paragraph's lazy
     * continuation (PART 9 §10 I2) and the item comes back holding ONE block
     * where the source held two - so those keep the blank line they have today.
     *
     * AN ALLOWLIST, AND THE DEFAULT IS THE BLANK LINE, because the two errors
     * are not the same size. A tag missing from this list costs a source
     * spelling that differs from carve-js, which is where this engine already
     * was; a tag wrongly ON it costs a lost block. So an unlisted tag keeps the
     * separator.
     *
     * WHAT IS LEFT OUT, and why each one folds - measured, not assumed:
     *
     *   - `figure`, and a lone `img`: both are written as a bare inline run on
     *     their own line (`![](i.png)`, plus a `^ cap` line), which at the
     *     content column is lazy continuation exactly as a paragraph is. This
     *     is the same pair `CarveRenderer::foldsIntoAnOpenParagraph()` had to
     *     carve out after measuring twenty-two constructs (carve-php#1069).
     *   - `div` and the other bare containers: the tag alone does not decide.
     *     An ATTRIBUTED `<div>` is written as a colon fence and does open a
     *     block; a bare one is DEGRADED to its content, so the part is plain
     *     text with no opener at all. One tag, two outcomes, and only the
     *     second is safe to abut - so the tag stays off this list and the
     *     attributed div keeps a blank line carve-js does not write. That is
     *     the spelling divergence this list's default direction accepts; the
     *     alternative reads the emitted text back to tell the two apart, which
     *     is a second spelling of the grammar this file already refuses
     *     elsewhere.
     *   - `p`: it never reaches the question. A direct `<p>` makes the list
     *     loose, and a loose list writes the blank line everywhere.
     *
     * @var list<string>
     */
    protected const TIGHT_ITEM_BLOCK_OPENERS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'pre', 'table', 'dl', 'hr', 'details',
    ];

    /**
     * A container's children, with a BLANK LINE at every inline-to-block seam.
     *
     * The seam is the whole point. Loose text beside a block sibling has no
     * separator of its own in the DOM, so concatenating the two writes the
     * block's opener onto the text's line, and the opener stops being one:
     * `First` beside a `<div class="tabs-panel">` came back as the paragraph
     * `First::: tabs-panel` with the panel's content lazily continued into it,
     * and the same glue turned a `<blockquote>` into a `>` mid-sentence and a
     * `<h2>` into `##` mid-sentence (carve-php#1543). Nothing is dropped, so
     * no diagnostic fires - the source simply says something else.
     *
     * `docs/html-import.md` puts the rule on the importer rather than on the
     * writer: an importer that builds source by hand "has to hold that line
     * itself", the line being that it emits what the canonical writer emits.
     *
     * @param \DOMNode $node
     * @param \Closure(\DOMNode): bool|null $skip A child to leave out entirely.
     */
    protected function processBlock(DOMNode $node, ?Closure $skip = null): string
    {
        $content = '';
        $inlineBuffer = '';
        // The last block written, for the caption-opener test below: a caption
        // line reaches back across ONE blank line, which is exactly the
        // separation this loop writes.
        $previousBlock = '';

        foreach ($node->childNodes as $child) {
            if ($skip !== null && $skip($child)) {
                continue;
            }
            $isBlock = false;

            if ($child instanceof DOMElement) {
                $tagName = strtolower($child->tagName);
                $isBlock = in_array($tagName, $this->blockElements, true);
            }

            if ($child instanceof DOMComment && $this->commentStandsAmongBlocks($child)) {
                // A COMMENT STANDING AMONG BLOCKS IS A BLOCK COMMENT
                // (`markup-carve/carve#1709`). It flushes the buffer the way a
                // block element does, because that is what it is here.
                //
                // The run around it is what decides, not the tag it sits under:
                // `<div>text <!--n--> more</div>` is ONE paragraph, and
                // splitting it at the comment would move the words either side
                // of it into two. Only a run holding nothing but comments and
                // the layout between them is a comment among blocks.
                $inlineText = trim($inlineBuffer);
                if ($inlineText !== '') {
                    $inlineText = $this->escapeDetachedCaptionOpener($previousBlock, $inlineText);
                    $content .= $inlineText . "\n\n";
                    $previousBlock = $inlineText;
                }
                $inlineBuffer = '';
                $rendered = $this->blockCommentSource($child->textContent);
                $content .= $rendered . "\n\n";
                $previousBlock = $rendered;

                continue;
            }

            if ($isBlock) {
                // Flush any accumulated inline content as an implicit paragraph
                $inlineText = trim($inlineBuffer);
                if ($inlineText !== '') {
                    $inlineText = $this->escapeDetachedCaptionOpener($previousBlock, $inlineText);
                    $content .= $inlineText . "\n\n";
                    $previousBlock = $inlineText;
                }
                $inlineBuffer = '';

                // Process the block element
                $rendered = $this->escapeDetachedCaptionOpener($previousBlock, $this->processNode($child));
                $content .= $rendered;
                if (trim($rendered) !== '') {
                    $previousBlock = trim($rendered);
                }
            } else {
                // Accumulate inline content
                $inlineBuffer .= $this->processNode($child);
            }
        }

        // Flush any remaining inline content
        $inlineText = trim($inlineBuffer);
        if ($inlineText !== '') {
            $content .= $this->escapeDetachedCaptionOpener($previousBlock, $inlineText) . "\n\n";
        }

        return trim($content);
    }

    /**
     * Does this element hold characters, and are they ALL layout?
     *
     * The divider is PART 11 §7's two-character `whitespace` terminal, plus
     * the line terminators HTML folds into it - and nothing else. U+00A0,
     * U+202F and U+3000 are CONTENT, so an element holding one of those is
     * not this shape.
     *
     * An element holding NO characters is not this shape either, and the
     * distinction is deliberate: §7 weighs the characters a block holds,
     * and an empty one holds none for the clause to call layout.
     *
     * NEITHER IS AN ELEMENT THAT HOLDS AN ELEMENT. `<p><canvas> </canvas></p>`
     * has whitespace for its text, but what left the document was the
     * `<canvas>`, and the report already says so on the element it happened
     * to. A row for the paragraph around it would name a second loss where
     * there was one. So the test reads this element's OWN children: the
     * clause is about a block whose CHARACTERS are all layout, and a block
     * holding an element is not holding characters.
     *
     * @param \DOMElement $node The element to weigh.
     *
     * @return bool True when it held characters and every one was layout.
     */
    protected function holdsOnlyLayoutCharacters(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return false;
            }
        }

        $text = $node->textContent;

        return $text !== '' && preg_match('/[^ \\t\\r\\n\\f]/u', $text) !== 1;
    }

    /**
     * Harden a caption caret that would attach to the block written before it.
     *
     * A caption line reaches BACK across one blank line and makes a figure of
     * the block above it (PART 9 §4b), and one blank line is exactly what this
     * importer writes between blocks. So a paragraph whose text happens to
     * begin `^ ` stopped being a paragraph: `<table>` followed by `<p>^ c</p>`
     * came back as the table plus a caption, and the paragraph was gone
     * (carve-php#1615).
     *
     * THE TEST IS THE ONE PART 11 §2 STATES, run rather than approximated: the
     * escape is written if and only if omitting it changes what the source
     * means. The two candidate spellings are rendered and compared, so the
     * hosts a caption attaches to are never enumerated here - a table, a quote,
     * a code block, an image, a display-math paragraph and a figure group all
     * answer for themselves, and a host added later answers too.
     *
     * That also settles the other half, which is the half a writer gets wrong
     * silently: where nothing captionable stands above, both spellings render
     * the same and the caret is left alone.
     *
     * Bounded to the PREVIOUS block rather than the document so far, because
     * that is all a caption can reach, and so a document full of caret-shaped
     * paragraphs cannot make this quadratic.
     *
     * @param string $previousBlock The block written immediately above, if any.
     * @param string $block The block about to be written.
     *
     * @return string The block, with the caret escaped if it would attach.
     */
    protected function escapeDetachedCaptionOpener(string $previousBlock, string $block): string
    {
        if (trim($previousBlock) === '') {
            return $block;
        }

        $offset = strspn($block, " \t\n\r");
        $newline = strpos($block, "\n", $offset);
        $firstLine = $newline === false ? substr($block, $offset) : substr($block, $offset, $newline - $offset);
        // A caption opener is the caret, a run of spaces, and something that is
        // not more whitespace - the parser own opener test.
        if (preg_match('/^\^ +.*\S/u', $firstLine) !== 1) {
            return $block;
        }

        $escaped = substr($block, 0, $offset) . '\\' . substr($block, $offset);
        $renderer = new CarveConverter();
        $meaning = $renderer->convert($previousBlock . "\n\n" . $block);
        if ($meaning === $renderer->convert($previousBlock . "\n\n" . $escaped)) {
            // Nothing above for the caret to reach, so the escape would be idle
            // and PART 11 §2 forbids writing it.
            return $block;
        }

        return $escaped;
    }

    /**
     * Process section elements, handling explicit IDs for round-trip support
     */
    protected function processSection(DOMElement $node): string
    {
        // Check if this is a footnotes section (doc-endnotes)
        if ($node->getAttribute('role') === 'doc-endnotes') {
            $rebuilt = $this->processEndnotesSection($node);
            if ($rebuilt !== null) {
                return $rebuilt;
            }
            // NOTHING WAS REBUILT, so this is not a footnote section as far as
            // the import goes: it falls through to the ordinary section policy
            // below, which keeps the `<hr>` and the `<ol>` it is built from.
            // See processEndnotesSection() for why.
        }

        // THE SECTION ELEMENT ITSELF IS NEVER WRITTEN. Carve has no spelling
        // for one: the renderer builds a `<section>` around a heading, so what
        // reaches the output is the heading and whatever the id could be moved
        // onto - never the element the author wrote. It left the document, and
        // the row that says an element left the document is this one.
        //
        // markup-carve/carve#1723 states the condition over the INPUT: the row
        // fires when an element did not survive into the output, and nesting
        // does not exempt it. A `<section>` that unwraps did not survive, and
        // this engine alone said nothing about it - for every shape, attributed
        // or bare, at every depth (carve-php#1737). carve-js and carve-rs report
        // it on all of them.
        //
        // NOT CONDITIONAL ON THE ID SURVIVING. An authored id does come back,
        // on the heading below, and that is a statement about the ATTRIBUTE
        // rather than about the element: `inspectImportAttributes()` asks the
        // output for it and stays correctly silent when it is there. The
        // element is gone either way, and making the element row depend on an
        // attribute would be a third answer where the siblings already agree.
        //
        // Recorded in the SAME register the other unwrapped block containers
        // use, so the row is written before the rows naming what the element
        // carried - see `inspectImportNode()`.
        //
        // AN ENDNOTES SECTION IS EXEMPT, and it is the only one. That wrapper
        // is DERIVED: the renderer writes a `<section role="doc-endnotes">`
        // around the notes whenever a document has any, so the author never
        // wrote it and nothing of theirs goes when it is unwrapped
        // (carve-php#1588, markup-carve/carve#1558). The exemption is on the
        // ROLE rather than on the tag, and it holds whichever way the import
        // then goes - rebuilt into footnote definitions above, or degraded to
        // the `<hr>` and `<ol>` it is built from below. Both sibling engines
        // scope it exactly this way.
        if ($node->getAttribute('role') !== 'doc-endnotes') {
            $this->unwrappedBlockContainers[$this->conversionNodePath($node)] = true;
        }

        // Check if section has an explicit ID from round-trip mode
        $hasExplicitId = $node->hasAttribute('data-djot-explicit-id');
        $sectionId = $node->getAttribute('id');

        // Find the first heading inside this section
        $firstHeading = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                    $firstHeading = $child;

                    break;
                }
            }
        }

        // If we have an explicit ID and a heading, combine ID with heading's attributes
        $prefix = '';
        if (
            $sectionId !== ''
            && $firstHeading !== null
            && ($hasExplicitId || $this->sectionIdLooksAuthored($sectionId, $firstHeading))
        ) {
            // Get heading's attributes (class, etc.) excluding id
            $headingAttrs = $this->getElementAttributes($firstHeading, ['id', 'data-djot-explicit-id', 'data-djot-source-level']);

            // Build combined attribute block with ID first
            $attrParts = ['#' . $sectionId];
            if ($headingAttrs !== '') {
                // Add other attributes (already formatted with . prefix for classes)
                $attrParts[] = $headingAttrs;
            }
            $prefix = '{' . implode(' ', $attrParts) . "}\n";

            // Mark that we've handled the heading's attributes
            $firstHeading->setAttribute('data-djot-attrs-handled', '1');
        }

        // Process section content as a normal block. processBlock() trims its
        // own trailing separation, so it is restored here - without it two
        // adjacent sections glued their headings into one line (`## A## B`),
        // and an attribute-line prefix could land inline on the previous
        // heading (carve-php#1289).
        $content = $this->processBlock($node);

        return $prefix . $content . "\n\n";
    }

    /**
     * Whether a section wrapper's id was authored, outside round-trip mode.
     *
     * The renderer moves a heading's id onto its `<section>`, authored and
     * generated alike, and only round-trip mode stamps which one it was. An
     * authored id used to be dropped here wholesale, so `{#custom}` came back
     * as a text-derived id and every `#custom` anchor broke after one HTML
     * round trip (carve-php#1289). The generated id is re-derivable - it is
     * the tracker's slug of the heading text - so an id that MATCHES that slug
     * is treated as generated and left to regeneration, and anything else is
     * authored and kept. A permalink or numbering extension changes the
     * heading's visible text, which makes the comparison conservative: an id
     * it cannot confirm as generated is kept, which re-renders identically
     * either way.
     */
    protected function sectionIdLooksAuthored(string $sectionId, DOMElement $heading): bool
    {
        $expected = (new HeadingIdTracker())->getIdForText(trim($heading->textContent));

        // A duplicate heading's dedup id (`-2`) differs from the slug and is
        // KEPT, deliberately: written back as an authored id it renders the
        // same HTML, while stripping it would also strip a real authored
        // `{#a-2}` and break its anchors - the ambiguity has no third reading.
        //
        // Compared EXACTLY, not case-insensitively. `normalizeId()` only folds
        // case under the `lowercase` option, so this tracker - built with
        // defaults, because the importer cannot know which mode rendered the
        // HTML - derives the case-PRESERVING slug. A case-insensitive match
        // therefore read `{#methods}` on `## Methods` as generated and dropped
        // it, and regeneration wrote `id="Methods"`: the same broken `#methods`
        // anchor carve-php#1289 fixed, in the one shape it left open. Keeping
        // it is the safe half of the ambiguity, and the rule above already says
        // so - an id that cannot be CONFIRMED generated is kept, and a kept
        // lowercase-mode id re-renders to the id it came from either way.
        return $sectionId !== $expected;
    }

    /**
     * The colon fence for a container opening at the CURRENT nesting depth.
     *
     * INWARD-WIDENING, which is the form `carve fmt` emits (grammar PART 9
     * §12, PART 11). A colon fence closes on an EXACT length match, so both
     * directions parse and the direction is a WRITER's choice - and
     * `docs/html-import.md` gives the importer no choice at all: "an importer
     * emits the source `carve fmt` emits", so that every `expected.crv` in
     * `tests/html-import` is a fixed point of the formatter too.
     *
     * This used to scan the ALREADY-WRITTEN body for colon-only lines and take
     * one more than the longest, on the superseded `len(close) >= len(open)`
     * reading where a container's fence was a quoting relation. That is a
     * bottom-up rule, so it can only widen OUTWARD - and it inverted every
     * nesting depth against the formatter, which widens by depth on the way
     * down: `<div class="tabs"><div class="tabs-panel">` imported as
     * `:::: tabs` / `::: tabs-panel` and formatted back as `::: tabs` /
     * `:::: tabs-panel` (markup-carve/carve-php#1583). Code fences keep the
     * `>=` relation (§2), where the length axis really is quoting.
     */
    protected function colonFenceFor(): string
    {
        return str_repeat(':', 3 + $this->colonFenceDepth);
    }

    /**
     * Serialize a colon-fenced container's BODY, one nesting level deeper.
     *
     * The depth has to be raised before the body is written, because the
     * inward-widening discipline is top-down: the child's width is a fact
     * about where it sits, not about what it contains.
     *
     * @param \Closure(): string $produce
     */
    protected function insideColonFence(Closure $produce): string
    {
        $this->colonFenceDepth++;
        try {
            return $produce();
        } finally {
            $this->colonFenceDepth--;
        }
    }

    protected function processDiv(DOMElement $node): string
    {
        // FIRST, before the admonition and line-block round trips below: those
        // are colon fences too, and reaching them inside a cell produced
        // `::: note d :::` and `::: | onetwo :::` as literal cell text. A fence
        // needs its own lines, which a cell does not have, so the wrapper is
        // dropped and the content kept - the same thing an attribute-less div
        // in a cell already did (carve-php#1164).
        if ($this->tableCellDepth > 0) {
            return $this->degradeToContent($node);
        }

        // Check for admonition div (round-trip support)
        if ($node->hasAttribute('data-djot-admonition-type')) {
            return $this->processAdmonition($node);
        }

        // Check for line block (round-trip support)
        if ($this->hasClass($node, 'line-block')) {
            return $this->processLineBlock($node);
        }

        // The block form of the same loss the inline math span had: pandoc and
        // the MathBlockExtension both write <div class="math display">, and
        // importing that as a colon fence turned the equation into a paragraph
        // of escaped backslashes (carve-php#1543).
        //
        // It comes back as the CORE display form - `$$` in front of a verbatim
        // span, a paragraph holding one math node - and never as a ``` math ```
        // fence (PART 9 section 18, ruled at markup-carve/carve#1514).
        //
        // THE FENCE WAS THE FIRST ANSWER HERE, on the argument that the
        // extension WRITES that div, so the fence is an exact inverse and
        // `docs/html-import.md` already accepts an inverse a reader recovers by
        // enabling an extension. That argument was weighed by the ruling and
        // lost. The fence is an EXTENSION: with it not loaded the same imported
        // document is an ordinary `language-math` code block, so the equation
        // is mathematics for one consumer and a code block for another.
        // `math_display` is core and needs nothing loaded. An importer's job is
        // to produce a document that MEANS what the HTML meant, not to
        // reconstruct the document that happened to generate it - and it cannot
        // know an extension generated it at all, since HTML from anywhere else
        // carrying those classes arrives here identically. Emitting the fence
        // only when the extension is registered was rejected on purpose: it
        // makes two runs of the same tool over the same input differ.
        $blockMath = $this->mathDelimitedContent($node, 'div');
        if ($blockMath !== null) {
            // The display flag decides the sigil, so a div spelled
            // `class="math inline"` writes the INLINE form rather than being
            // promoted to display by the block position it was found in.
            return $this->renderMath($blockMath['content'], $blockMath['display'])
                . $this->mathAttributeSuffix($node, $blockMath['classes'])
                . "\n\n";
        }

        // BEFORE the class list, because the label decides two things below it:
        // whether a bare div keeps its fence at all, and what its opener says.
        // See {@see self::liftContainerLabel()}.
        //
        // NOT ON A BARE `djot-content` WRAPPER. That one is a TRANSPORT
        // wrapper rather than a container - it unwraps unconditionally a few
        // lines down, and a label lifted here would have no opener to land on
        // and would be dropped SILENTLY, which is the same undeclared loss this
        // whole change exists to close, arriving from the other direction.
        // Refusing keeps the paragraph the HTML actually has (raised by codex
        // review).
        $label = $this->isBareDjotContentWrapper($node) ? null : $this->liftContainerLabel($node);
        $labelPart = $label === null ? '' : ' [' . $label . ']';

        $classes = $this->getElementClassList($node);
        $fenceClass = array_shift($classes);

        // Check for wrapper div unwrapping: if div has NO class but has attrs
        // and single block child, apply attrs to the child instead of fenced div
        //
        // NOT WITH A LABEL. Moving the attributes onto the child dissolves the
        // container, and the label has nowhere but a container's opener to go -
        // so a labelled div keeps its fence even where an unlabelled one would
        // hand its attributes down.
        if ($label === null && ($fenceClass === null || $fenceClass === '')) {
            $singleChild = $this->getSingleBlockChild($node);
            if ($singleChild !== null) {
                $attrs = $this->formatBlockAttributes($node);
                if ($attrs !== '') {
                    $content = trim($this->processNode($singleChild));

                    return $attrs . $content . "\n";
                }
            }
        }

        if ($fenceClass === null || $fenceClass === '') {
            $attrs = $this->formatBlockAttributes($node);
            // THE BOUNDARY IS WHAT ONLY A CONTAINER CAN HOLD, not the tag. A
            // div carrying none of it is not a container worth spelling, so it
            // unwraps and the `:::` fence is not written
            // (markup-carve/carve#1578) - the fence would buy the reader
            // nothing and cost two lines of markup nobody asked for.
            //
            // Today that means an attribute the language can hold OR a grouping
            // label. #1578 wrote the test as the attribute alone, as a proxy for
            // its own stated rationale - "then there IS something only the
            // container can hold" - and the proxy turned out narrower than the
            // principle it stood in for. When a proxy and its rationale
            // disagree the rationale governs (markup-carve/carve-rs#1315).
            //
            // What settles it is that the narrow test was not a declarable loss:
            // `::: [g]` came back as a `{.div-label}` PARAGRAPH, so the
            // container was gone and the label had become body content. That is
            // an ADDITION, and an addition cannot be declared away.
            if ($attrs === '' && $label === null) {
                return $this->degradeToContent($node);
            }

            $content = $this->insideColonFence(fn (): string => trim($this->processBlock($node)));
            $fence = $this->colonFenceFor();
            $output = $attrs . $fence . $labelPart . "\n";
            if ($content !== '') {
                $output .= $content . "\n";
            }

            return $output . $fence . "\n\n";
        }
        // ONE SPELLING, shared with the reason the label lift declined above
        // {@see self::isBareDjotContentWrapper()} - the two must agree, or a
        // label is lifted off a wrapper that then throws it away.
        if ($this->isBareDjotContentWrapper($node)) {
            return $this->degradeToContent($node);
        }

        $header = $this->extractAdmonitionTitle($node);
        $content = $this->insideColonFence(fn (): string => $header === null
            ? trim($this->processBlock($node))
            : $this->processAdmonitionContent($node));
        // The fence class is what NAMES this container - `tabs` is why the
        // wrapper is called "Tabs" - and it is written as the fence word rather
        // than kept in `$classes`, so the derived-name test is told about it.
        $this->structuralClassInProgress = $fenceClass;
        $parts = [];
        $idPart = $this->idAttributePart($node);
        if ($idPart !== null) {
            $parts[] = $idPart;
        }
        foreach ($classes as $class) {
            $parts[] = '.' . $class;
        }
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if ($name === 'id' || $name === 'class' || $this->isStrippedImportAttribute($name)) {
                continue;
            }
            $value = $attr->value;
            if (
                $this->isConsumedTitleReference($node, $name, $value)
                || $this->isDerivedAccessibleName($node, $name, $value)
            ) {
                continue;
            }
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }
        $this->structuralClassInProgress = null;
        $attrs = $parts === [] ? '' : '{' . implode(' ', $parts) . "}\n";
        $fence = $this->colonFenceFor();
        $headerPart = $header === null ? '' : ' ' . $this->quoteOpenerHeader($header);
        $output = $attrs . $fence . ' ' . $fenceClass . $headerPart . $labelPart . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . $fence . "\n\n";
    }

    /**
     * Is this the bare `djot-content` transport wrapper the importer unwraps
     * unconditionally?
     *
     * Read TWICE on purpose - once to decline the label lift and once to do the
     * unwrapping - because a lift that ran here would remove a paragraph the
     * unwrap then throws away.
     */
    protected function isBareDjotContentWrapper(DOMElement $node): bool
    {
        $classes = $this->getElementClassList($node);
        if ($classes !== ['djot-content'] || $node->getAttribute('id') !== '') {
            return false;
        }
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            if ($attr->name !== 'class' && !$this->isStrippedImportAttribute($attr->name)) {
                return false;
            }
        }

        return true;
    }

    protected function processAside(DOMElement $node): string
    {
        $classes = $this->getElementClassList($node);
        if (!in_array('admonition', $classes, true)) {
            return $this->processGenericBlockContainer($node);
        }

        $type = null;
        foreach ($classes as $class) {
            if (in_array($class, self::ADMONITION_TYPES, true)) {
                $type = $class;

                break;
            }
        }
        if ($type === null) {
            return $this->processGenericBlockContainer($node);
        }

        $parts = [];
        $idPart = $this->idAttributePart($node);
        if ($idPart !== null) {
            $parts[] = $idPart;
        }

        foreach ($classes as $class) {
            if ($class !== 'admonition' && $class !== $type) {
                $parts[] = '.' . $class;
            }
        }

        $skipAttrs = ['id', 'class'];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (in_array($name, $skipAttrs, true) || $this->isStrippedImportAttribute($name)) {
                continue;
            }
            $value = $attr->value;
            if (
                $this->isConsumedTitleReference($node, $name, $value)
                || $this->isDerivedAccessibleName($node, $name, $value)
            ) {
                continue;
            }
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        $header = $this->extractAdmonitionTitle($node);
        // BEFORE the content walk, so the paragraph the label was degraded to
        // is not also written into the body {@see self::liftContainerLabel()}.
        $label = $this->liftContainerLabel($node);
        $content = $this->insideColonFence(fn (): string => $this->processAdmonitionContent($node));
        $attrs = $parts === [] ? '' : '{' . implode(' ', $parts) . "}\n";
        $fence = $this->colonFenceFor();
        $headerPart = $header === null ? '' : ' ' . $this->quoteOpenerHeader($header);
        $labelPart = $label === null ? '' : ' [' . $label . ']';
        $output = $attrs . $fence . ' ' . $type . $headerPart . $labelPart . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . $fence . "\n\n";
    }

    /**
     * Process admonition div (with data-djot-admonition-type) for round-trip
     */
    protected function processAdmonition(DOMElement $node): string
    {
        $type = $node->getAttribute('data-djot-admonition-type');
        $customTitle = $node->getAttribute('data-djot-admonition-title');
        $header = $customTitle !== '' ? $customTitle : null;

        // Build attributes (excluding admonition-specific classes and data attributes)
        $parts = [];
        $idPart = $this->idAttributePart($node);
        if ($idPart !== null) {
            $parts[] = $idPart;
        }

        // Get remaining classes (exclude 'admonition' and the type)
        $classes = $this->getElementClassList($node);
        foreach ($classes as $class) {
            if ($class !== 'admonition' && $class !== $type) {
                $parts[] = '.' . $class;
            }
        }

        // Add other attributes (excluding special ones)
        $skipAttrs = ['id', 'class', 'data-djot-admonition-type', 'data-djot-admonition-title'];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (in_array($name, $skipAttrs, true) || $this->isStrippedImportAttribute($name)) {
                continue;
            }
            $value = $attr->value;
            if (
                $this->isConsumedTitleReference($node, $name, $value)
                || $this->isDerivedAccessibleName($node, $name, $value)
            ) {
                continue;
            }
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        // BEFORE the content walk, for the same reason
        // {@see self::liftContainerLabel()}.
        $label = $this->liftContainerLabel($node);
        // Process content, excluding the title element
        $content = $this->insideColonFence(fn (): string => $this->processAdmonitionContent($node));

        $attrs = $parts === [] ? '' : '{' . implode(' ', $parts) . "}\n";
        $fence = $this->colonFenceFor();
        $headerPart = $header === null ? '' : ' ' . $this->quoteOpenerHeader($header);
        $labelPart = $label === null ? '' : ' [' . $label . ']';
        $output = $attrs . $fence . ' ' . $type . $headerPart . $labelPart . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . $fence . "\n\n";
    }

    /**
     * Is this the accessible name the RENDERER derives for this element?
     *
     * PART 9 §16a and Extensions §13 write a name onto elements the author never
     * spelled. Re-importing it makes a generated string look authored, and §12
     * writes a name only where the author wrote NONE - so the imported copy WINS
     * on the next render and the document can no longer be localized: a source
     * carrying `{aria-label=Note}` still emits `aria-label="Note"` under
     * `labels: {admonitionNote: 'Hinweis'}` (markup-carve/carve#1500).
     *
     * Dropping it is free. The renderer regenerates the same string, so the
     * output is byte-identical with the attribute gone - measured on every shape
     * below - and only then does the `labels` map reach it again.
     *
     * MATCHED ON VALUE, NOT ON PROVENANCE. If the value equals what the renderer
     * derives, the output is identical whether the author wrote it or the engine
     * did, so this cannot repeat carve-php#1337: a name that DIFFERS is the
     * author's and is kept. Same rule the generated `scope` on a `<th>` already
     * gets.
     *
     * The residue is deliberate. A document rendered with a non-default `labels`
     * map carries a value this cannot recognize, so it is kept. Closing that
     * needs the importer to be handed the same map, which is step 2 on
     * markup-carve/carve#1500.
     */
    protected function isDerivedAccessibleName(DOMElement $node, string $name, string $value): bool
    {
        if (strtolower($name) !== 'aria-label' || $value === '') {
            return false;
        }
        $derived = $this->derivedAccessibleName($node);

        return $derived !== null && $derived === $value;
    }

    /**
     * Is this an attribute the RENDERER writes back for this element?
     *
     * Asked by the report, and only by it. Every writer already drops these -
     * the two accessible-name predicates below are the same ones the attribute
     * loops consult, and `role` is on `$skipAttributes` for every element - so
     * this answers the different question the report has: whether the drop
     * COST anything.
     *
     * IT MUST NOT BE A SECOND POLICY. `isDerivedAccessibleName()` and
     * `isConsumedTitleReference()` are called rather than re-derived, so a name
     * this importer learns to recognize is one the report stops diagnosing in
     * the same edit. A second copy is what carve-php#1337 and carve-php#1346
     * each came back to.
     *
     * `role` HAS NO SUCH PREDICATE, because no writer needs one: the strip is
     * unconditional. So the roles are read off the same shape test the name is,
     * which is why `derivedElementNaming()` returns both.
     */
    protected function isDerivedImportAttribute(DOMElement $node, string $name, string $value): bool
    {
        $name = strtolower($name);
        if ($name === 'aria-label') {
            return $this->isDerivedAccessibleName($node, $name, $value);
        }
        if ($name === 'aria-labelledby') {
            return $this->isConsumedTitleReference($node, $name, $value);
        }
        if ($name !== 'role') {
            return false;
        }

        return in_array(strtolower(trim($value)), $this->derivedElementNaming($node)['role'], true);
    }

    /**
     * The name the renderer would write for this element, or null where it
     * writes none.
     */
    protected function derivedAccessibleName(DOMElement $node): ?string
    {
        return $this->derivedElementNaming($node)['aria-label'][0] ?? null;
    }

    /**
     * WHAT THE RENDERER DERIVES for this element: the `role` values it can
     * write, and the accessible name it writes beside them.
     *
     * ONE SHAPE TEST, TWO ANSWERS. The role and the name are decided by the
     * same fact - that this element IS a claimed fence, a tab set, an endnotes
     * section - so reading them off one walk is what keeps them from
     * disagreeing about which elements those are. They are still applied
     * INDEPENDENTLY: `FencedRenderExtension::namingDefaults()` writes the role
     * whenever the fence has a name from EITHER side, so a fence carrying the
     * author's own `aria-label` keeps that name and still has its role derived
     * - which is the second half of the spec's `derived-accessible-name`
     * fixture.
     *
     * A ROLE HAS SEVERAL SPELLINGS where one shape has several renderings. A
     * tab set is `group` under the CSS mode and `tablist` under the ARIA one,
     * and a panel is `group` or `tabpanel` the same way; the importer cannot
     * see which mode produced the HTML, so both are the renderer's.
     *
     * The classes are the ones the renderers write at their DEFAULT options -
     * an importer cannot see a host's `wrapperClass`, nor whether the extension
     * that names this shape is even registered on the render that reads the
     * source back - which is the same blind spot the default-only label match
     * already accepts. A `<div class="tabs">` holding no tabs is the sharp end
     * of it: nothing reconstructs a tab set from it, so neither the role nor
     * the name comes back.
     *
     * THAT RESIDUE IS NOT DECIDED HERE. The name is already dropped from such a
     * div, by `isDerivedAccessibleName()`, and the role by `$skipAttributes` -
     * both before this method existed. Reporting the drop did not mitigate it;
     * it only made the report contradict the conversion, which is what
     * markup-carve/carve#1502 measured. If the drop is too eager the fix is in
     * the shape test below, in one place, and this map follows it.
     *
     * @return array{role: list<string>, aria-label: list<string>}
     */
    protected function derivedElementNaming(DOMElement $node): array
    {
        $tag = strtolower($node->tagName);
        $classes = $this->getElementClassList($node);
        if ($this->structuralClassInProgress !== null) {
            $classes[] = $this->structuralClassInProgress;
        }
        // THE HOST'S OWN MAP FIRST, then the English defaults.
        //
        // Matching the defaults alone catches only a document rendered in
        // English. One rendered with `labels: {admonitionNote: 'Hinweis'}`
        // carries a value no default can recognize, so the generated name was
        // kept and laundered into source - and a German document is exactly the
        // one §16a's map exists to serve (markup-carve/carve#1500 step 2).
        //
        // The host that rendered the HTML knows the map it used. Handing the
        // same map to the importer is the whole fix; a caller that passes
        // nothing keeps the previous behavior exactly.
        $labels = $this->labels + HtmlRenderer::LABEL_DEFAULTS;

        // PART 9 §12: an UNTITLED admonition is named by its type word. A titled
        // one is named by `aria-labelledby`, which `isConsumedTitleReference()`
        // handles, so only the type word is derived here.
        if ($tag === 'aside' && in_array('admonition', $classes, true)) {
            foreach ($classes as $class) {
                $key = 'admonition' . ucfirst($class);
                if (isset($labels[$key])) {
                    // No role: `<aside>` already says what it is, so the core
                    // renderer writes the name alone.
                    return ['role' => [], 'aria-label' => [$labels[$key]]];
                }
            }
        }

        // PART 9 §16: the endnotes section.
        if ($tag === 'section' && $node->getAttribute('role') === 'doc-endnotes') {
            return ['role' => ['doc-endnotes'], 'aria-label' => [$labels['endnotes']]];
        }

        // Extensions §13: a tab set and a code group are named as a whole.
        if (in_array('tabs', $classes, true)) {
            return ['role' => self::DERIVED_GROUP_ROLES, 'aria-label' => [$labels['tabsGroup']]];
        }
        if (in_array('code-group', $classes, true)) {
            return ['role' => self::DERIVED_GROUP_ROLES, 'aria-label' => [$labels['codeGroup']]];
        }

        // Extensions §13.2: a css-mode panel is named by its own tab, which is
        // the `<label>` that reveals it - the nearest preceding sibling one.
        if (in_array('tabs-panel', $classes, true) || in_array('code-group-panel', $classes, true)) {
            for ($prev = $node->previousSibling; $prev !== null; $prev = $prev->previousSibling) {
                if ($prev instanceof DOMElement && strtolower($prev->tagName) === 'label') {
                    return ['role' => self::DERIVED_PANEL_ROLES, 'aria-label' => [trim($prev->textContent)]];
                }
            }

            // A panel cut from its controls derives no NAME - guessing one
            // would drop a label nothing writes back - but it is still a panel,
            // and the role is written from the shape rather than from the tab.
            return ['role' => self::DERIVED_PANEL_ROLES, 'aria-label' => []];
        }

        // markup-carve/carve#1469: an index back-link is named by the label plus
        // the term it returns to, plus the ordinal when the term has several.
        // The term is the item's own display text and the ordinal is in the
        // href, so the whole name is reconstructible from the element.
        if ($tag === 'a' && in_array('index-backref', $classes, true)) {
            $parent = $node->parentNode;
            if (!$parent instanceof DOMElement || strtolower($parent->tagName) !== 'li') {
                return self::DERIVES_NOTHING;
            }
            $term = '';
            foreach ($parent->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    break;
                }
                $term .= $child->textContent;
            }
            $term = trim($term);
            if ($term === '') {
                return self::DERIVES_NOTHING;
            }
            $lead = $labels['indexBackref'];
            $total = 0;
            foreach ($parent->childNodes as $child) {
                if (
                    $child instanceof DOMElement
                    && strtolower($child->tagName) === 'a'
                    && in_array('index-backref', $this->getElementClassList($child), true)
                ) {
                    $total++;
                }
            }
            if ($total === 1) {
                return ['role' => [], 'aria-label' => [$lead . ' ' . $term]];
            }
            if (preg_match('/-(\d+)$/', $node->getAttribute('href'), $m) !== 1) {
                return self::DERIVES_NOTHING;
            }

            return ['role' => [], 'aria-label' => [$lead . ' ' . $term . ' ' . $m[1]]];
        }

        // A CLAIMED fence is named by its own fence word, which is its class -
        // and `FencedRenderExtension::namingDefaults()` never writes that
        // default name without `role="img"` beside it, because an `img` with
        // no accessible name is skipped entirely. An ordinary classed `<div>`
        // or `<pre>` is not a claimed fence and the renderer names it not at
        // all, so an `aria-label` that happens to equal its first class word is
        // the AUTHOR's and stays - dropping it would lose a name no re-render
        // brings back, which is the regression carve-php#1337 records.
        //
        // The role is the discriminator rather than a list of preset fence
        // words: a roster would be importer policy the three engines have to
        // agree on first, and it would still be wrong for a custom preset.
        //
        // ONE RESIDUE, DELIBERATE and measured: an author who wrote their own
        // `role` keeps it, and `namingDefaults()` still writes the default name
        // beside it - `{role=group}` on a mermaid fence renders as
        // `<pre class="mermaid" role="group" aria-label="mermaid">`, so the name
        // is not recognized here and survives the import. That fails in the SAFE
        // direction: it keeps a name rather than losing one, which is the side
        // markup-carve/carve#1502 says a value-matched drop must never get wrong.
        // Closing it needs the roster above, or the render's own `labels` map,
        // which is step 2 on markup-carve/carve#1500.
        if (
            ($tag === 'pre' || $tag === 'div')
            && $classes !== []
            && strtolower($node->getAttribute('role')) === 'img'
        ) {
            return ['role' => ['img'], 'aria-label' => [$classes[0]]];
        }

        return self::DERIVES_NOTHING;
    }

    /**
     * Does this attribute point at the admonition title this import consumes?
     *
     * A REFERENCE THE IMPORT DOES NOT KEEP IS NOT A REFERENCE. PART 9 §12 names
     * a TITLED admonition with `aria-labelledby` pointing at the id on its own
     * `<p class="admonition-title">`. That element does not survive the import:
     * its text becomes the opener's quoted title, so the id goes with it and the
     * attribute is left naming nothing.
     *
     * Keeping it was worse than noise. §12 writes a name only where the author
     * wrote NONE, so on the next render the stale attribute - by then
     * indistinguishable from an authored one - SUPPRESSES the correct name, and
     * the aside points at an id no document has. Rendering the imported source
     * shows both halves: `aria-labelledby="adm-1"` on the aside and no `id` on
     * the title at all.
     *
     * Same rule as the generated `scope` on a `<th>`: an attribute the renderer
     * DERIVES is dropped when the import cannot carry what it derives from. An
     * `aria-labelledby` pointing anywhere else names an element this import is
     * not consuming, so it is the author's and it stays.
     *
     * Asked HERE and only here because `processAside()` and
     * `processAdmonition()` each carry their own copy of the attribute loop, and
     * a second copy of a policy drifts - which is the defect carve-php#1346 and
     * carve-php#1337 both came back to.
     */
    protected function isConsumedTitleReference(DOMElement $node, string $name, string $value): bool
    {
        if (strtolower($name) !== 'aria-labelledby') {
            return false;
        }
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if (strtolower($child->tagName) !== 'p' || !$this->hasClass($child, 'admonition-title')) {
                continue;
            }
            $id = $child->getAttribute('id');

            return $id !== '' && $id === $value;
        }

        return false;
    }

    protected function extractAdmonitionTitle(DOMElement $node): ?string
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'p' && $this->hasClass($child, 'admonition-title')) {
                return trim($this->processChildren($child));
            }
        }

        return null;
    }

    /**
     * Process admonition content, excluding the title element
     */
    protected function processAdmonitionContent(DOMElement $node): string
    {
        return $this->processBlock($node, fn (DOMNode $child): bool => $this->isDegradedContainerTitle($child));
    }

    /**
     * Is this child the container's own TITLE, degraded to markup by the
     * renderer rather than written as body content?
     *
     * ONE SPELLING, because two readers ask it: the content walk skips it, and
     * {@see self::liftContainerLabel()} scans past it. A quoted title and a
     * grouping label are written on the same opener - `::: note "T" [g]` - so
     * the title's own degraded paragraph sits ahead of the label's, and a lift
     * that stopped at the first element it did not recognize refused every
     * container carrying both.
     */
    protected function isDegradedContainerTitle(DOMNode $child): bool
    {
        if (!$child instanceof DOMElement) {
            return false;
        }
        $tag = strtolower($child->tagName);

        return ($tag === 'p' && $this->hasClass($child, 'admonition-title')) || $tag === 'summary';
    }

    /**
     * `<details>` becomes the `::: details` admonition DetailsExtension renders
     * back as a disclosure widget.
     *
     * The `<summary>` is that widget's label, and the extension takes the label
     * from the QUOTED TITLE on the opener line. Letting the summary fall
     * through as ordinary block content kept its text but not its role: the
     * round trip came back with the extension's default `<summary>Details</summary>`
     * and the real label demoted to the first paragraph of the body.
     */
    protected function processDetails(DOMElement $node): string
    {
        if ($this->tableCellDepth > 0) {
            return $this->degradeToContent($node);
        }

        $summary = $this->findFirstDirectChildByTagName($node, 'summary');
        if (!$summary instanceof DOMElement) {
            return $this->processGenericBlockContainer($node);
        }
        $title = $this->detailsSummaryTitle($summary);
        if ($title === null) {
            return $this->processGenericBlockContainer($node);
        }

        $summary->parentNode?->removeChild($summary);
        $attrs = $this->formatBlockAttributes($node);
        $content = $this->insideColonFence(fn (): string => trim($this->processBlock($node)));
        $fence = $this->colonFenceFor();
        $output = $attrs . $fence . ' details "' . $title . '"' . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . $fence . "\n\n";
    }

    /**
     * Does this element stand inside a table cell?
     *
     * The converter answers the same question with a depth counter it keeps
     * while writing; the inspection walk has no such counter, so it reads the
     * ancestors. It stops at the cell, which is where the counter would have
     * been raised.
     */
    protected function isInsideTableCell(DOMElement $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->tagName), ['td', 'th'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The quoted opener title this `<summary>` can be written as, or null.
     *
     * Null keeps the summary as ordinary block content, which loses the label
     * but never the text. Two summaries cannot be written:
     *
     * - one holding a `"`. The title is delimited by quotes and the delimiter
     *   has no escape here: `::: details "He said \"hi\""` does not open a
     *   fence at all, it degrades the whole block to a paragraph.
     * - one whose content needs more than a line - a list, several paragraphs -
     *   which an opener line cannot hold.
     *
     * Inline markup is fine: the extension renders the title through the
     * inline path, so `"A *b*"` reaches the summary as emphasis.
     */
    protected function detailsSummaryTitle(DOMElement $summary): ?string
    {
        $title = trim($this->processChildren($summary));
        if ($title === '' || str_contains($title, '"') || str_contains($title, "\n")) {
            return null;
        }

        return $title;
    }

    /**
     * Is this one of the SECTIONING wrappers?
     *
     * These are the names carve-js and carve-rs treat as document STRUCTURE
     * rather than as markup they cannot express, and all three engines agree
     * on the set. What this engine did with them was the outlier: it wrote a
     * `::: name` container fence, on the premise that the fence renders back as
     * the element.
     *
     * IT DOES NOT. A container fence renders as `<div class="name">` for EVERY
     * name, sectioning ones included, so `<article id="k">` came back as
     * `<div class="article" id="k">` - the element the author wrote gone, and a
     * class they never wrote in the output. An undeclared loss is a ceiling an
     * import may sit inside; an ADDITION is the document coming back saying
     * something it never said, and only the second changes what the document
     * means (carve-php#1721).
     *
     * SO THEY UNWRAP, which is what both sibling engines do with the same input:
     * the children come through, the wrapper and its attributes are dropped, and
     * the report says both. `<section>` is not here - it goes through
     * {@see self::processSection()}, which can put an authored id back on a
     * heading and so sometimes keeps the element.
     *
     * THIS IS NOT THE PRESERVE SET EITHER. The names that map to nothing at all
     * are kept byte for byte in `roundtrip` (`markup-carve/carve-php#1713`);
     * these map to structure a Carve document genuinely has no block for, and
     * turning a page's `<header>`, `<nav>` and `<footer>` into opaque raw blocks
     * would make the most common wrappers in a document unreadable as Carve.
     */
    protected function isSectioningWrapper(string $tagName): bool
    {
        return in_array($tagName, ['article', 'main', 'header', 'footer', 'nav', 'aside'], true);
    }

    protected function processGenericBlockContainer(DOMElement $node): string
    {
        $tagName = strtolower($node->tagName);

        // `<details>` always builds a colon fence, which a cell cannot hold.
        // Every other tag here already degrades to its content once the cell
        // context has emptied its attributes; this makes the one exception
        // behave like the rest (carve-php#1164).
        if ($this->tableCellDepth > 0) {
            return $this->unwrapBlockContainer($node, $tagName);
        }

        // THE UNMAPPED NAMES ARE KEPT BYTE FOR BYTE IN `roundtrip`
        // (`markup-carve/carve-php#1713`). `<form>` and its neighbours map to
        // nothing, and a `::: form` fence is not a `<form>` - it renders as a
        // div, so the element was gone and the mode's contract with it.
        if ($tagName !== 'details' && !$this->isSectioningWrapper($tagName)) {
            $preserved = $this->preservedAsRawHtml($node);
            if ($preserved !== null) {
                return $preserved;
            }
        }

        // ONLY `<details>` REACHES THE FENCE, and that is the whole of the
        // change here. `::: details` is a real construct the extension renders
        // back as a `<details>`; every other name this handler sees renders as
        // a `<div>` wearing the name, so the fence was never a spelling for it -
        // see {@see self::isSectioningWrapper()}.
        if ($tagName !== 'details') {
            return $this->unwrapBlockContainer($node, $tagName);
        }

        $attrs = $this->formatBlockAttributes($node);
        $content = $this->insideColonFence(fn (): string => trim($this->processBlock($node)));
        $fence = $this->colonFenceFor();
        $output = $attrs . $fence . ' ' . $tagName . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . $fence . "\n\n";
    }

    /**
     * A block container's content, with the container itself declared gone.
     *
     * The unwrapping is what this handler always did for a wrapper carrying no
     * attributes; what it did not do was SAY so, for that case or for the
     * attributed one it used to write a fence for. Both engines report the row
     * (carve-php#1721), and this file reports the equivalent unwrap for every
     * element it has no mapping for - these were the exception.
     *
     * The wrapper's own attributes need no row of their own here: they are gone
     * from the output, so {@see self::inspectImportAttributes()} already asks
     * the document and finds them missing.
     *
     * @param \DOMElement $node
     * @param string $tagName
     */
    protected function unwrapBlockContainer(DOMElement $node, string $tagName): string
    {
        if ($tagName !== 'details') {
            $this->unwrappedBlockContainers[$this->conversionNodePath($node)] = true;
        }

        return $this->degradeToContent($node);
    }

    /**
     * Process line block div (with class "line-block") for round-trip
     */
    protected function processLineBlock(DOMElement $node): string
    {
        // Build attributes (excluding 'line-block' class)
        $parts = [];
        $idPart = $this->idAttributePart($node);
        if ($idPart !== null) {
            $parts[] = $idPart;
        }

        // Get remaining classes (exclude 'line-block')
        $classes = $this->getElementClassList($node);
        foreach ($classes as $class) {
            if ($class !== 'line-block') {
                $parts[] = '.' . $class;
            }
        }

        // Add other attributes (excluding special ones)
        $skipAttrs = ['id', 'class'];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (in_array($name, $skipAttrs, true) || $this->isStrippedImportAttribute($name)) {
                continue;
            }
            $value = $attr->value;
            if (
                $this->isConsumedTitleReference($node, $name, $value)
                || $this->isDerivedAccessibleName($node, $name, $value)
            ) {
                continue;
            }
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        // Extract lines from the content - handle <br> as line separators
        $lines = $this->insideColonFence(fn (): string => implode("\n", $this->extractLineBlockLines($node)));
        $lines = $lines === '' ? [] : explode("\n", $lines);

        // A CLOSER-SHAPED VERSE LINE IS ESCAPED, NOT WIDENED AROUND. The fence
        // width is the container's own (`carve fmt`'s inward-widening form, see
        // {@see colonFenceFor()}), so it cannot also carry the answer to "does
        // a verse line read as this block's closer" - and the formatter answers
        // that one with a backslash, which is the spelling that survives at any
        // width. Widening instead made the whole block one column wider for a
        // reason that is not about nesting, and left the source outside
        // `carve fmt`'s image either way (markup-carve/carve-php#1583).
        $fence = $this->colonFenceFor();
        foreach ($lines as $index => $line) {
            if (preg_match('/^:{3,}\s*$/', $line) === 1) {
                $lines[$index] = '\\' . $line;
            }
        }

        // STRICT (djot): the `:::` fence takes no inline attributes, so any
        // extra id/classes go on a PRECEDING block-attribute line.
        $attrLine = $parts === [] ? '' : '{' . implode(' ', $parts) . '}' . "\n";
        $output = $attrLine . $fence . ' |' . "\n";

        foreach ($lines as $line) {
            $output .= $line . "\n";
        }

        return $output . $fence . "\n\n";
    }

    /**
     * Extract lines from a line block, handling <br> elements as separators
     *
     * @return array<string>
     */
    protected function extractLineBlockLines(DOMElement $node): array
    {
        $lines = [];
        $currentLine = '';

        $processNode = function (DOMNode $child) use (&$lines, &$currentLine): void {
            if ($child instanceof DOMText) {
                $text = $child->textContent;
                $text = str_replace("\u{00A0}", ' ', $text);
                if ($currentLine === '') {
                    $text = preg_replace('/^\n/', '', $text) ?? $text;
                }
                $currentLine .= $text;
            } elseif ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'br') {
                    // <br> marks end of current line
                    $lines[] = rtrim($currentLine);
                    $currentLine = '';
                } else {
                    // Process other elements inline (strong, em, etc.)
                    $currentLine .= $this->processNode($child);
                }
            }
        };

        // Find inner content (may be wrapped in <p> or direct children)
        $sawParagraph = false;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'p') {
                if ($sawParagraph && ($lines === [] || end($lines) !== '')) {
                    $lines[] = '';
                }
                $sawParagraph = true;

                // Process paragraph's children
                foreach ($child->childNodes as $pChild) {
                    $processNode($pChild);
                }

                if (trim($currentLine) !== '') {
                    $lines[] = rtrim($currentLine);
                    $currentLine = '';
                }
            } elseif ($child instanceof DOMText && trim($child->textContent) === '') {
                // Structural whitespace between block children (e.g. the
                // indentation before a <p>) is not content - skip it so it does
                // not bleed into the first verse line. Real indentation lives
                // inside the <p> as NBSP and is handled above.
                continue;
            } else {
                $processNode($child);
            }
        }

        // Don't forget the last line if any content remains
        if (trim($currentLine) !== '') {
            $lines[] = rtrim($currentLine);
        }

        return $lines;
    }

    /**
     * Check if an element has a specific class
     */
    protected function hasClass(DOMElement $node, string $className): bool
    {
        $classes = $this->getElementClassList($node);

        return in_array($className, $classes, true);
    }

    /**
     * @return list<string>
     */
    protected function getElementClassList(DOMElement $node): array
    {
        $classes = trim($node->getAttribute('class'));
        if ($classes === '') {
            return [];
        }

        $classList = preg_split('/\s+/', $classes) ?: [];

        return array_values(array_filter($classList, static fn (string $class): bool => $class !== ''));
    }

    /**
     * The canonical writer's quoting rule, which is the one the importer owes.
     *
     * This is `CarveRenderer::quoteAttrValue()`'s predicate, not a second
     * opinion about it. A narrower charset here quoted `title="a=b"`,
     * `title="é"` and `data-q="a&b"` where every writer - this engine's
     * included - writes them bare, so the importer's output was rewritten the
     * moment it met `carve fmt`, and it disagreed with carve-js and carve-rs on
     * the same input.
     */
    protected function quoteAttributeValue(string $value): string
    {
        if (preg_match('/^[^\s"\'{}]+$/u', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    protected function quoteLinkTitle(string $title): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $title) . '"';
    }

    protected function quoteOpenerHeader(string $title): string
    {
        // Div/code opener headers cannot contain a double quote. When converting
        // arbitrary HTML, keep the source valid and preserve the remaining
        // inline markup rather than emitting an opener the parser cannot read.
        return '"' . str_replace('"', '', $title) . '"';
    }

    protected function processParagraph(DOMElement $node): string
    {
        $content = trim($this->processChildren($node));
        if ($content === '') {
            return '';
        }

        $attrs = $this->formatBlockAttributes($node);

        /*
         * CARVE SOURCE CANNOT SPELL A PARAGRAPH HOLDING ONLY AN IMAGE, so this
         * line writes a BLOCK image and the author's `<p>` leaves the document
         * (carve-php#1667). `resources/examples/edge-cases.md` rules the shape -
         * "a paragraph whose whole content is one image is still the standalone
         * image shape, not a wrapped one" - so there is no other output to
         * write, and `docs/html-import.md` says what is owed instead: the exit
         * that writes source reports `structure-unspellable`.
         *
         * RECORDED HERE, WHERE THE WRITER IS. The inspection walk cannot ask a
         * DOM-shaped predicate for this and get the same answer, for the reason
         * {@see self::definitionListSplits()} carries: what a `<p>` HOLDS and
         * what it WRITES are different questions. This one is the writer's.
         */
        $image = $this->loneImportImage($node, $content);
        if ($image !== null && $this->importParagraphIsWrittenAsABlock($node)) {
            $this->loneImageParagraphs[$this->conversionNodePath($node)] = [
                'attributed' => $attrs !== '',
                'overwritten' => $this->overwrittenImportImageAttributes($node, $image),
            ];
        }

        return $attrs . $content . "\n\n";
    }

    /**
     * The one `<img>` a paragraph WRITES, when it writes nothing else.
     *
     * READING THE `<p>`'S DIRECT CHILDREN WAS THE DEFECT (carve-php#1673). The
     * question is what the paragraph writes, and a wrapper that contributes no
     * characters of its own does not change that answer: `<picture>`, a bare
     * `<span>`, a `<source>` beside the image - each writes the image and
     * nothing else, so `<p><picture><img></picture></p>` writes the same bare
     * block image that `<p><img></p>` does, loses the same `<p>`, and owes the
     * same row. A shape-shaped predicate could not see any of them, and every
     * one of those losses went undeclared. carve-rs reports them, because its
     * predicate reads the built inline run rather than the DOM.
     *
     * THE OVER-BROAD FIX IS THE WORSE ONE, so the comparison is against what was
     * actually written rather than a descent through any single-element wrapper.
     * A wrapper that DOES contribute makes a paragraph the source can spell and
     * must keep reporting nothing: `<span class="x">` writes `[..]{.x}`,
     * `<a href="u">` writes a link, an `<em>` writes its own delimiters - and a
     * row on any of those would declare a loss that did not happen, which
     * `docs/html-import.md` reads as licence to stop comparing the exits.
     *
     * AN IMAGE THAT WRITES NO IMAGE IS NOT THE SHAPE EITHER, and that half was
     * wrong before this change rather than merely missing. `<p><img src=""></p>`
     * unwraps to its alt text and re-reads as the PARAGRAPH it was - nothing is
     * lost - and the old predicate reported it anyway. {@see
     * self::importImageSpelling()} carries which of `processImage()`'s four
     * returns are an image.
     *
     * Whitespace between the tags is layout, not content (PART 11 section 7), so
     * `<p>\n <img>\n</p>` is the same paragraph as `<p><img></p>`; it needs no
     * clause of its own here, because layout writes no characters either.
     *
     * `$written` is what the paragraph's own run wrote, trimmed. It is the
     * answer this predicate reads, and it is passed in rather than recomputed
     * because the caller is about to emit that exact string - asking a second
     * time would be a second opinion about the run rather than a reading of it.
     */
    protected function loneImportImage(DOMElement $node, string $written): ?DOMElement
    {
        $image = $this->soleImportImageDescendant($node);
        if ($image === null) {
            return null;
        }

        return $this->importImageSpelling($image) === $written ? $image : null;
    }

    /**
     * The slots that hold INLINE content, so a `<p>` inside one is dissolved
     * into its run rather than written as a paragraph.
     *
     * NOT A LIST OF THE CONTAINERS THAT KEEP A PARAGRAPH - that list is the one
     * that goes stale silently, because a container added later would be missed
     * and the miss reads as "the paragraph was dissolved", dropping a row that
     * was owed. This is the complement: the slots Carve gives no paragraph at
     * all, whatever the HTML puts in them. A pipe cell is one line of inline
     * content, a caption line and a definition TERM are inline runs, and a
     * details opener is a quoted title - so none of them loses a paragraph,
     * because none of them ever had one to lose.
     *
     * Measured, each one: `<td>`, `<th>`, `<caption>`, `<figcaption>`, `<dt>`
     * and `<summary>` all write `<p><img></p>` as inline content. `<dd>` does
     * NOT - it writes a block, so it is not here.
     *
     * @var array<int, string>
     */
    protected const IMPORT_INLINE_ONLY_SLOTS = ['caption', 'dt', 'figcaption', 'summary', 'td', 'th'];

    /**
     * Is this paragraph written as a block, rather than dissolved into a run?
     *
     * ANY ancestor decides, not the nearest: a `<td><div><p><img></p></div></td>`
     * still writes one line of inline content, so stopping at the `<div>` would
     * declare a loss the cell never took.
     */
    protected function importParagraphIsWrittenAsABlock(DOMElement $node): bool
    {
        for ($current = $node->parentNode; $current instanceof DOMElement; $current = $current->parentNode) {
            if (in_array(strtolower($current->tagName), self::IMPORT_INLINE_ONLY_SLOTS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The paragraph attribute names the image's own attribute block overwrites.
     *
     * The paragraph's attributes are written as a block ABOVE the image and the
     * image's own `{...}` after it, and BOTH are read onto one node - so a name
     * the image also sets is the one that survives. `<p id="p"><img id="i">`
     * writes `{#p}` above `![a](a){#i}` and reads back with `id="i"` alone, so a
     * message claiming the paragraph's attributes were written on the image
     * would leave that loss undeclared, which is the same defect one level down.
     *
     * CLASSES ARE NOT IN THIS SET: the class slot merges rather than replacing,
     * so `{.p}` and `{.i}` both reach the rendered element and nothing is lost.
     * An image's `src`, `alt` and `title` are not either - they go into the
     * destination, the label and the destination's title slot, none of which is
     * the attribute block, so they never collide with a paragraph's.
     *
     * @return list<string>
     */
    protected function overwrittenImportImageAttributes(DOMElement $paragraph, DOMElement $image): array
    {
        $imageNames = $this->writtenImportAttributeNames($image, ['src', 'alt', 'title', 'data-djot-ref']);
        if ($imageNames === []) {
            return [];
        }

        $lost = [];
        foreach ($this->writtenImportAttributeNames($paragraph) as $name) {
            if (in_array($name, $imageNames, true)) {
                $lost[] = $name;
            }
        }
        sort($lost);

        return $lost;
    }

    /**
     * The attribute NAMES an element writes into a Carve attribute block.
     *
     * The same policy {@see self::getElementAttributes()} writes by, asked for
     * the names alone: `class` is left out because the slot merges, and a name
     * the writer strips or derives never reaches the block to collide with
     * anything.
     *
     * @param \DOMElement $node
     * @param array<int, string> $skipAttrs
     *
     * @return list<string>
     */
    protected function writtenImportAttributeNames(DOMElement $node, array $skipAttrs = []): array
    {
        $names = [];
        if ($node->hasAttribute('id')) {
            // PRESENT, not non-empty, for the reason
            // {@see self::idAttributePart()} gives: an explicit `id=""` is
            // written, so the names policy has to say so too.
            $names[] = 'id';
        }
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if ($name === 'id' || $name === 'class') {
                continue;
            }
            if (in_array($name, $skipAttrs, true) || $this->isStrippedImportAttribute($name)) {
                continue;
            }
            if ($this->isDerivedAccessibleName($node, $name, $attr->value)) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    protected function processHeading(DOMElement $node): string
    {
        $level = (int)substr($node->tagName, 1);
        if ($node->hasAttribute('data-djot-source-level')) {
            $level = max(1, min(6, (int)$node->getAttribute('data-djot-source-level')));
        }
        $content = trim($this->processChildren($node));
        $prefix = str_repeat('#', $level) . ' ';

        // Check if attributes were already handled by processSection
        if ($node->hasAttribute('data-djot-attrs-handled')) {
            return $prefix . $content . "\n\n";
        }

        $skipAttrs = ['data-djot-source-level', 'data-djot-explicit-id', 'data-djot-attrs-handled'];
        if ($this->headingIdWasGenerated($node)) {
            // The renderer derives it again from the same text, so dropping it
            // is a no-op on the render and carrying it would spell an authored
            // slot the source never had.
            $skipAttrs[] = 'id';
        }
        // THE SLOT ORDER IS THE ELEMENT'S, which is only observable on a
        // heading: this is the one construct whose writer can be handed an id
        // and a class in either order and has to write them back in it.
        $attrs = $this->formatBlockAttributes($node, $skipAttrs, true);

        return $attrs . $prefix . $content . "\n\n";
    }

    /**
     * Whether this heading's `id` is one THIS ENGINE generated, so re-emitting
     * it would change the render.
     *
     * GATED TO `roundtrip`, whose input is this engine's own output BY
     * DEFINITION. In `safe` and `semantic` the HTML came from anywhere, so an
     * id there is authored input and is kept exactly as it is - losing it is a
     * real regression, and carve-js and carve-rs both fixed one.
     *
     * The re-emission is not cosmetic. `HtmlRenderer` puts a GENERATED heading
     * id after every authored attribute and an AUTHORED one in the slot it was
     * written in - it reads `#id` out of the node's attribute order to tell
     * them apart - so `{.k}` and `{.k #H}` are two different documents even
     * though they render the same bytes. Reading the id back as generated is
     * what keeps the import a fixed point (carve-rs#1354, carve-rs#1355;
     * carve-php#1699).
     *
     * TWO HALVES, AND NEITHER ALONE IS ENOUGH:
     *
     * - POSITION - {@see self::idInGeneratedPosition()}. Alone it would eat an
     *   id an author wrote LAST, as in `{.k #Other}`.
     * - VALUE - {@see self::isGeneratedHeadingId()}. Alone it could not tell
     *   `{.k}` from an id an author wrote FIRST whose value happens to be the
     *   slug, as in `{#H .k}`.
     *
     * `data-djot-explicit-id` settles it outright where the render stamped it:
     * that marker says the id was authored, and no measurement beats a
     * statement.
     */
    protected function headingIdWasGenerated(DOMElement $node): bool
    {
        if ($this->importMode !== 'roundtrip' || !$node->hasAttribute('id')) {
            return false;
        }
        if ($node->hasAttribute('data-djot-explicit-id')) {
            return false;
        }

        return $this->idInGeneratedPosition($node)
            && $this->isGeneratedHeadingId($node->getAttribute('id'), trim($node->textContent));
    }

    /**
     * Whether `id` sits where `HtmlRenderer` writes a GENERATED one: after
     * every authored attribute.
     *
     * `data-source-line` is the one thing allowed to follow it. That is a
     * render annotation rather than an authored attribute, and the renderer
     * emits it last on purpose - `HtmlRenderer::RENDER_ANNOTATIONS` and the
     * rule beside it, "A RENDER ANNOTATION IS EMITTED LAST - after the
     * GENERATED attribute, not merely after the authored ones".
     */
    protected function idInGeneratedPosition(DOMElement $node): bool
    {
        $names = [];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $names[] = $attr->name;
        }
        while ($names !== [] && end($names) === 'data-source-line') {
            array_pop($names);
        }

        return $names !== [] && end($names) === 'id';
    }

    /**
     * Whether `id` is a value this engine's renderer would derive for a heading
     * whose plain text is `text`.
     *
     * THE DEFAULT SLUG ONLY, which is the accepted limit the importer already
     * states for every other derived attribute: it cannot know which heading-id
     * options the render used, so a value no default equals is
     * indistinguishable from an authored one and KEEPING it is the safe side.
     *
     * The `-N` tail is `HeadingIdTracker::dedupe()`'s own shape, and it starts
     * at 2 because the first occurrence takes the bare base. So `-1` is never a
     * counter this engine wrote, and neither is a leading-zero run nor a tail
     * holding anything but digits.
     */
    protected function isGeneratedHeadingId(string $id, string $text): bool
    {
        $base = (new HeadingIdTracker())->normalizeId($text);
        if ($id === $base) {
            return true;
        }
        if (!str_starts_with($id, $base . '-')) {
            return false;
        }

        $count = substr($id, strlen($base) + 1);

        return $count !== ''
            && $count !== '1'
            && !str_starts_with($count, '0')
            && preg_match('/^[0-9]+$/', $count) === 1;
    }

    /**
     * The inline elements that convert to a construct of their own rather than
     * to a text run, so a neighbour of one has no character next to the
     * delimiter. Each closes with its own punctuation - `/`, `)`, `}`, `"` -
     * which is never a word character, so a bare delimiter opens beside it.
     *
     * `code` is deliberately absent: it converts to a verbatim span, whose
     * CONTENT is what the writer measures the boundary from, so it is handled
     * on its own below.
     *
     * @var array<int, string>
     */
    protected const BOUNDARY_OPAQUE_TAGS = [
        'a', 'abbr', 'b', 'br', 'cite', 'del', 'dfn', 'em', 'i', 'img',
        'ins', 'kbd', 'mark', 'math', 'q', 's', 'samp', 'strike', 'strong',
        'sub', 'sup', 'time', 'u', 'var',
    ];

    /**
     * The structural elements `$blockElements` leaves out. A sibling of any of
     * these ENDS the inline run rather than continuing it, so the delimiter
     * sits at a block boundary and has no neighbour at all - which is not the
     * same answer as descending into the block for its last word.
     *
     * @var array<int, string>
     */
    protected const BOUNDARY_BLOCK_TAGS = [
        'body', 'caption', 'dd', 'dt', 'figcaption', 'html', 'summary',
        'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
    ];

    /**
     * Convert one of the five single-character inline kinds, bare or braced.
     *
     * A bare delimiter opens and closes only away from a word character, which
     * is the whole reason the braced form exists: `Sy*rup*-free` renders the
     * two asterisks as prose and throws the emphasis away, while
     * `Sy{*rup*}-free` is the same document the HTML was. So the intraword case
     * takes `{*x*}` and every other case keeps the canonical bare `*x*`.
     */
    protected function processBareInlineFormatting(DOMElement $node, string $ch): string
    {
        $content = trim($this->processChildren($node));
        if ($content === '') {
            return '';
        }

        [$open, $close] = $this->boundaryDelimiters($node, $ch, $content);

        return $open . $content . $close . $this->formatInlineAttributes($node);
    }

    /**
     * Choose bare vs forced-brace delimiters for a single-character inline kind.
     *
     * The test is the canonical writer's, `CarveRenderer::renderEmphasis`: a
     * word character on either side, or content that itself starts or ends with
     * the delimiter, forces the braces. Anything else keeps the bare form. That
     * is what makes the importer's output a fixed point of `carve fmt` - the
     * two would otherwise disagree about which spelling this span wants, and
     * formatting an imported document would rewrite it.
     *
     * @return array{0: string, 1: string}
     */
    protected function boundaryDelimiters(DOMElement $node, string $ch, string $content = ''): array
    {
        $needsForced = $this->isWordCharacter($this->boundaryCharacter($node->previousSibling, true))
            || $this->isWordCharacter($this->boundaryCharacter($node->nextSibling, false))
            || str_starts_with($content, $ch)
            || str_ends_with($content, $ch);

        return $needsForced ? ['{' . $ch, $ch . '}'] : [$ch, $ch];
    }

    /**
     * The character a sibling puts next to the delimiter, or '' when it puts
     * none there.
     *
     * Mirrors `CarveRenderer::inlineBoundaryText`, which answers the same
     * question one layer down: only a text run and a verbatim span contribute a
     * character, and every other construct contributes its own closing
     * punctuation, which never blocks a bare delimiter. An element that is not
     * a construct here - an attribute-less `<span>`, a wrapper the walk does
     * not know - flattens to its children, so the search descends into it.
     */
    protected function boundaryCharacter(?DOMNode $sibling, bool $trailing): string
    {
        // How many flattening wrappers the walk has stepped INTO, so it can
        // step back out of exactly those and no further. Leaving the inline run
        // is a different answer from finding nothing inside a wrapper.
        $depth = 0;
        while ($sibling !== null) {
            $descend = null;
            if ($sibling instanceof DOMText) {
                if ($sibling->textContent !== '') {
                    return $this->edgeCharacter($sibling->textContent, $trailing);
                }
            } elseif ($sibling instanceof DOMComment) {
                // A WRITTEN COMMENT IS A CONSTRUCT, so it ends the search with
                // its own punctuation and never blocks a bare delimiter
                // (`markup-carve/carve#1709`).
                //
                // This walk used to step over a comment because the importer
                // DELETED it: `<p>a<!-- n --><strong>b</strong> c</p>` really
                // did put `a` next to the emphasis, so the braced form was
                // right. Now `{% n %}` stands between them and the neighbouring
                // character is `}`, so the bare form is what the canonical
                // writer emits - and an importer that still braced it was no
                // longer a fixed point of `carve fmt`, which is the property
                // boundaryDelimiters() exists to hold.
                //
                // A comment the importer does NOT write is stepped over exactly
                // as before: one standing among blocks is not in this run at
                // all, and one with no inline spelling is dropped, so neither
                // puts a character anywhere.
                if (
                    !$this->commentStandsAmongBlocks($sibling)
                    && !$this->commentHasNoInlineSpelling($sibling->textContent)
                ) {
                    return '';
                }
            } elseif ($sibling instanceof DOMElement) {
                $tag = strtolower($sibling->tagName);
                if (
                    in_array($tag, $this->blockElements, true)
                    || in_array($tag, static::BOUNDARY_BLOCK_TAGS, true)
                ) {
                    return '';
                }
                if ($tag === 'code' && !$this->inPre) {
                    // A verbatim span is a node even when it holds nothing, and
                    // its CONTENT is what the writer measures - so an empty one
                    // contributes '' and ENDS the search rather than being
                    // stepped over.
                    return $this->edgeCharacter($sibling->textContent, $trailing);
                }
                if (in_array($tag, static::BOUNDARY_OPAQUE_TAGS, true)) {
                    // An element with nothing in it renders nothing, so it is
                    // not in the tree the writer measures and the search keeps
                    // going. A link, a break and an image are nodes whatever
                    // they contain.
                    if ($sibling->textContent !== '' || in_array($tag, ['a', 'br', 'img'], true)) {
                        return '';
                    }
                } elseif ($tag === 'span' && $sibling->attributes->length > 0) {
                    return '';
                } else {
                    // Everything left flattens to its own children, so the
                    // neighbour is whatever they end with.
                    $descend = $trailing ? $sibling->lastChild : $sibling->firstChild;
                }
            }
            if ($descend !== null) {
                $sibling = $descend;
                $depth++;

                continue;
            }
            // Nothing here - a comment, an empty text node, an element that
            // renders nothing, or a wrapper with no children. Step sideways,
            // climbing back out of the wrappers this walk entered.
            $step = $trailing ? $sibling->previousSibling : $sibling->nextSibling;
            while ($step === null && $depth > 0) {
                $sibling = $sibling->parentNode;
                $depth--;
                if ($sibling === null) {
                    return '';
                }
                $step = $trailing ? $sibling->previousSibling : $sibling->nextSibling;
            }
            $sibling = $step;
        }

        return '';
    }

    protected function edgeCharacter(string $text, bool $trailing): string
    {
        if ($text === '') {
            return '';
        }

        return $trailing ? substr($text, -1) : $text[0];
    }

    /**
     * A byte from the middle of a multi-byte sequence is >= 0x80 and matches
     * nothing here, which is the right answer: the rule is ASCII-only in every
     * engine, so `é*b*é` opens.
     */
    protected function isWordCharacter(string $character): bool
    {
        return $character !== '' && preg_match('/[A-Za-z0-9_]/', $character) === 1;
    }

    protected function processInlineFormatting(DOMElement $node, string $open, string $close): string
    {
        $content = trim($this->processChildren($node));
        if ($content === '') {
            return '';
        }

        $attrs = $this->formatInlineAttributes($node);

        return $open . $content . $close . $attrs;
    }

    protected function processCode(DOMElement $node): string
    {
        // Check if inside a pre block (handled by processPreBlock)
        $parent = $node->parentNode;
        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'pre') {
            return $node->textContent;
        }

        $content = $node->textContent;

        $backticks = StringUtil::findSafeCodeFence($content, 1);
        $attrs = $this->formatInlineAttributes($node);

        // Pad the content away from the fence when it starts or ends with a
        // backtick of its own.
        //
        // BOTH sides or neither. A reader strips one space from each end only
        // when there is one at each end, so padding a single side left that
        // space in the content: `<code>`start</code>` came back as
        // `<code> `start</code>` and `<code>end `</code>` with a trailing space
        // (markup-carve/carve-php#1224). carve-js and carve-rs both pad
        // symmetrically.
        //
        // Added unconditionally once either side calls for it, because a space
        // already in the content is CONTENT and the reader eats one from each
        // end regardless. Skipping the pad on a side that already had a space
        // therefore consumed the author's own space instead of a pad.
        if (strlen($backticks) > 1) {
            $needsStartSpace = str_starts_with($content, '`');
            $needsEndSpace = str_ends_with($content, '`');

            if ($needsStartSpace || $needsEndSpace) {
                return $backticks . ' ' . $content . ' ' . $backticks . $attrs;
            }
        }

        return $backticks . $content . $backticks . $attrs;
    }

    protected function processPreBlock(DOMElement $node): string
    {
        $this->inPre = true;

        // Get content (may be wrapped in code tag)
        $code = $this->findFirstDirectChildByTagName($node, 'code');
        $content = $code ? $code->textContent : $node->textContent;

        // Detect language from class
        $language = '';
        if ($code instanceof DOMElement) {
            $classList = $this->getElementClassList($code);
            foreach ($classList as $class) {
                if (str_starts_with($class, 'language-') && $class !== 'language-') {
                    $language = substr($class, 9);

                    break;
                }
            }

            if ($language === '' && $classList !== []) {
                $language = $classList[0];
            }
        }

        $backticks = StringUtil::findSafeCodeFence($content, 3);
        $this->inPre = false;

        // Get attributes from pre element (skip class on code since used for language)
        $attrs = $this->formatBlockAttributes($node);

        // NO space between the fence and the language word. This importer had it
        // right and was aligned onto the writer's spelling instead, because the
        // writer was the one that was wrong: `fenced_code_block` states "The
        // no-space form (```php) is canonical and is what the X->Carve
        // converters emit", and an importer IS such a converter. The writer now
        // emits the same form, so the alignment `docs/html-import.md` asks for
        // holds in the direction the grammar names.
        $opener = $backticks . $language;

        // EXACTLY ONE TRAILING NEWLINE IS THE LAST LINE'S TERMINATOR, and
        // everything past it is content (markup-carve/carve#1708).
        //
        // The renderer settles it rather than taste: this engine writes
        // `<pre><code>x\n</code></pre>` for a code block whose content is `x`,
        // and `<pre><code>x\n\n</code></pre>` for one whose content ends in a
        // blank line. An importer that strips BOTH newlines does not invert its
        // own renderer, and the two documents arrive here indistinguishable.
        // The asymmetry mirrors HTML's own at the other end, where a newline
        // immediately after `<pre>` is stripped and one before `</pre>` is not.
        //
        // `rtrim()` was the whole of it before, so it took every trailing
        // newline AND every trailing space and tab. Trailing whitespace on the
        // last line is content too - a code block is bytes the author wrote -
        // and losing it is the same class of silent change, applied to the same
        // documents. Nothing is reported for the one newline this removes,
        // because nothing is lost: it was the terminator, not a line.
        //
        // A BYTE TEST RATHER THAN A REGEX, and not a style choice.
        // `preg_replace('/\n$/', '', "x\n\n")` returns `"x"`: PCRE's `$`
        // matches at the end of the subject AND just before a string-final
        // newline, and `preg_replace` replaces EVERY match, so the pattern
        // takes both newlines and reproduces the trim this rule exists to
        // remove. `\z` with a limit of 1 would work; `str_ends_with` cannot be
        // read two ways at all.
        if (str_ends_with($content, "\n")) {
            $content = substr($content, 0, -1);
        }

        // Glued, not separated - see processBlockquote() for why.
        return $attrs . $opener . "\n" . $content . "\n" . $backticks . "\n\n";
    }

    protected function extractRoundTripSource(DOMElement $node, string $tagName): ?string
    {
        // Untrusted HTML must not be able to smuggle raw Carve through a
        // `data-djot-src` attribute (it is emitted verbatim, so a crafted value
        // could inject a raw-HTML block -> live <script>). Only honor it when the
        // caller explicitly trusts the source.
        if (!$this->trustedRoundTrip) {
            return null;
        }

        if (!$node->hasAttribute('data-djot-src')) {
            return null;
        }

        if ($tagName !== 'pre' && !in_array($tagName, $this->blockElements, true)) {
            return null;
        }

        $source = html_entity_decode($node->getAttribute('data-djot-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return rtrim($source, "\n") . "\n\n";
    }

    protected function processLink(DOMElement $node): string
    {
        // The trusted verbatim escape hatch stays ahead of everything: it
        // re-emits the element as it stands, `href=""` included, so it carries
        // no destination back either.
        if ($this->linkRequiresRawHtmlFallback($node)) {
            return $this->processRawHtmlInlineElement($node);
        }

        // A DESTINATION CARVE CANNOT CARRY IS NOT A DESTINATION
        // (`docs/html-import.md`). Carve spells a link's destination in one
        // slot and has NO spelling for an empty one - `[t]()` is literal text -
        // so writing the empty slot emitted four punctuation characters the
        // HTML never held, into the middle of the prose. No link node is built:
        // the element's CONTENT stands in its place, carried by a span where an
        // attribute survives and bare where none does.
        //
        // AND THE DESTINATION IS NOT REBUILT, which is the normative half. This
        // is what Carve's own renderer emits: PART 9 §25 blanks a dangerous
        // destination and writes no provenance for it, keeping the visible
        // text, so any route from a `title`, from the anchor's text or from a
        // round-trip attribute back to a destination would reconstruct the
        // exact value a security rule removed. The round trip owes the text.
        //
        // AHEAD OF THE ROUND-TRIP BRANCHES, so the clause holds with no
        // exception to state. None of them can be reached with an empty
        // destination from this engine's own output - `HeadingReferenceExtension`
        // rewrites the placeholder to `href="#slug"` before it appends its
        // attribute, and `InlineFootnotesExtension` and the renderer's footnote
        // reference both write `href="#fnN"` before theirs - so nothing that
        // round-trips loses its route here. What the order settles is
        // hand-written input, where a `data-djot-*` attribute beside a blanked
        // destination would otherwise rebuild a link the rule says is not one.
        if ($this->importDestinationIsEmpty($node->getAttribute('href'))) {
            return $this->unwrapDestinationLessElement($node, $this->processChildren($node), ['href']);
        }

        if ($node->hasAttribute('data-djot-heading-ref')) {
            $target = $node->getAttribute('data-djot-heading-ref');
            $displayText = $node->getAttribute('data-djot-heading-ref-display');
            if ($displayText === '') {
                $displayText = trim($this->processChildren($node));
            }

            return $displayText === $target
                ? '[[' . $target . ']]'
                : '[[' . $target . '|' . $displayText . ']]';
        }

        // Check for regular footnote reference (round-trip support)
        if ($node->hasAttribute('data-djot-footnote-label')) {
            $label = $node->getAttribute('data-djot-footnote-label');

            return '[^' . $label . ']';
        }

        if ($node->hasAttribute('data-djot-inline-footnote-html')) {
            $html = $node->getAttribute('data-djot-inline-footnote-html');
            preg_match('/^\s+/u', $html, $leadingWhitespaceMatch);
            preg_match('/\s+$/u', $html, $trailingWhitespaceMatch);

            $leadingWhitespace = $leadingWhitespaceMatch[0] ?? '';
            $trailingWhitespace = $trailingWhitespaceMatch[0] ?? '';
            $trimmedHtml = preg_replace('/^\s+|\s+$/u', '', $html) ?? $html;
            $content = $this->convertInlineFragmentToDjot($trimmedHtml);
            $content = $leadingWhitespace . $content . $trailingWhitespace;
            $cssClass = $node->getAttribute('data-djot-inline-footnote-class');
            if ($cssClass === '') {
                $cssClass = 'fn';
            }

            return '[' . $this->escapeNoteReferenceLabel($content) . ']{.' . $cssClass . '}';
        }

        // The engine's own footnote reference outside round-trip mode:
        // <a id="fnrefN" href="#fnN" role="doc-noteref"><sup>N</sup></a>.
        // Without this it imported as a literal link carrying a superscript
        // span, the definition it pointed at went unused, and the endnotes
        // section vanished on the next render (carve-php#1286). The label is
        // derived from the fragment the same way the definition side derives
        // it from the list item's id, so the pair stays bound. AFTER the
        // data-djot branches: a round-trip-mode inline footnote carries the
        // same role, and its data attributes are the richer record.
        if (
            $node->getAttribute('role') === 'doc-noteref'
            && str_starts_with($node->getAttribute('href'), '#fn')
        ) {
            $label = substr($node->getAttribute('href'), 3);
            if ($label !== '' && !str_contains($label, ' ')) {
                return '[^' . $label . ']';
            }
        }

        $href = $node->getAttribute('href');
        $text = trim($this->processChildren($node));
        $title = $node->getAttribute('title');

        if ($text === '') {
            $text = $href;
        }

        $text = $this->escapeNoteReferenceLabel($this->escapeLinkOrImageLabel($text));

        // Check for @mention (round-trip support for MentionsExtension)
        if ($node->hasAttribute('data-username')) {
            $username = $node->getAttribute('data-username');
            // Verify the link text matches @username pattern
            if ($text === '@' . $username) {
                return '@' . $username;
            }
        }

        // Check for autolink (round-trip support)
        if ($node->hasAttribute('data-djot-autolink')) {
            // Skip href and data-djot-autolink since they're in the autolink syntax
            $attrs = $this->formatInlineAttributes($node, ['href', 'data-djot-autolink']);

            // Email autolinks have mailto: prefix - strip it for output
            if (str_starts_with($href, 'mailto:')) {
                $email = substr($href, 7);

                return '<' . $email . '>' . $attrs;
            }

            return '<' . $href . '>' . $attrs;
        }

        // Check for reference link (round-trip support)
        if ($node->hasAttribute('data-djot-ref')) {
            $refLabel = $node->getAttribute('data-djot-ref');
            // The COLLAPSED form. `ref` used to hold `''` for it; PART 12 §3a
            // made it the real label (carve#597), and a label equal to the link
            // text is exactly what `[text][]` means - writing it out would
            // produce `[text][text]`, which is a different construct in the
            // source even though it resolves the same.
            if ($refLabel === $text) {
                $refLabel = '';
            }
            // Skip href, title, and data-djot-ref since they're in the reference syntax
            $attrs = $this->formatInlineAttributes($node, ['href', 'title', 'data-djot-ref']);

            if ($refLabel === '' && !$this->isSafeReferenceLabel($text)) {
                if ($title !== '') {
                    return '[' . $text . '](' . $href . ' ' . $this->quoteLinkTitle($title) . ')' . $attrs;
                }

                return '[' . $text . '](' . $href . ')' . $attrs;
            }

            if ($refLabel !== '' && !$this->isSafeReferenceLabel($refLabel)) {
                if ($title !== '') {
                    return '[' . $text . '](' . $href . ' ' . $this->quoteLinkTitle($title) . ')' . $attrs;
                }

                return '[' . $text . '](' . $href . ')' . $attrs;
            }

            // Collect reference definition
            // For collapsed reference (empty label), use the link text as label
            $defLabel = $refLabel === '' ? $text : $refLabel;
            if (!isset($this->referenceDefinitions[$defLabel])) {
                $this->referenceDefinitions[$defLabel] = $href;
            }

            // Output reference link syntax
            if ($refLabel === '') {
                // Collapsed reference [text][]
                return '[' . $text . '][]' . $attrs;
            }

            return '[' . $text . '][' . $refLabel . ']' . $attrs;
        }

        // Skip href and title since they're in the link syntax
        $attrs = $this->formatInlineAttributes($node, ['href', 'title']);

        if ($title !== '') {
            return '[' . $text . '](' . $href . ' ' . $this->quoteLinkTitle($title) . ')' . $attrs;
        }

        return '[' . $text . '](' . $href . ')' . $attrs;
    }

    protected function processImage(DOMElement $node): string
    {
        $src = $node->getAttribute('src');
        $rawAlt = $node->getAttribute('alt');
        $title = $node->getAttribute('title');

        if ($this->requiresRawImageFallback($rawAlt)) {
            return $this->processRawHtmlInlineElement($node);
        }

        // The same rule as the link one layer up, and it is the SAME shape: an
        // `<img>` whose `src` names no destination the source can carry builds
        // no image node either. AN IMAGE'S CONTENT IS ITS ALTERNATIVE TEXT -
        // that is what every target with no image shows for it, and what a
        // browser shows for one it cannot load - so the alt text is what stands
        // in its place.
        if ($this->importDestinationIsEmpty($src)) {
            // ESCAPED AS PROSE, not as an image label. The alt value has not
            // been through `processNode`, so unlike the anchor's content above
            // it arrives raw - and it is landing in a slot where every Carve
            // opener is live. Emitted bare, `alt="a *bold* b"` came back as
            // markup the HTML never held.
            return $this->unwrapDestinationLessElement(
                $node,
                $this->escapeHtmlTextAsCarveProse($rawAlt),
                ['src', 'alt'],
            );
        }

        $alt = $this->escapeLinkOrImageLabel($this->escapeLiteralBackslashes($rawAlt));

        // Check for reference image (round-trip support)
        if ($node->hasAttribute('data-djot-ref')) {
            $refLabel = $node->getAttribute('data-djot-ref');
            // The COLLAPSED form - see the note on the link branch above
            // (carve#597).
            if ($refLabel === $alt) {
                $refLabel = '';
            }
            // Skip src, alt, title, and data-djot-ref since they're in the reference syntax
            $attrs = $this->formatInlineAttributes($node, ['src', 'alt', 'title', 'data-djot-ref']);

            if ($refLabel === '' && !$this->isSafeReferenceLabel($alt)) {
                if ($title !== '') {
                    return '![' . $alt . '](' . $src . ' ' . $this->quoteLinkTitle($title) . ')' . $attrs;
                }

                return '![' . $alt . '](' . $src . ')' . $attrs;
            }

            if ($refLabel !== '' && !$this->isSafeReferenceLabel($refLabel)) {
                if ($title !== '') {
                    return '![' . $alt . '](' . $src . ' ' . $this->quoteLinkTitle($title) . ')' . $attrs;
                }

                return '![' . $alt . '](' . $src . ')' . $attrs;
            }

            // Collect reference definition
            // For collapsed reference (empty label), use the alt text as label
            $defLabel = $refLabel === '' ? $alt : $refLabel;
            if (!isset($this->referenceDefinitions[$defLabel])) {
                $this->referenceDefinitions[$defLabel] = $src;
            }

            // Output reference image syntax
            if ($refLabel === '') {
                // Collapsed reference ![alt][]
                return '![' . $alt . '][]' . $attrs;
            }

            return '![' . $alt . '][' . $refLabel . ']' . $attrs;
        }

        // Skip src, alt, title since they're in the image syntax
        $attrs = $this->formatInlineAttributes($node, ['src', 'alt', 'title']);

        if ($title !== '') {
            return '![' . $alt . '](' . $src . ' ' . $this->quoteLinkTitle($title) . ')' . $attrs;
        }

        return '![' . $alt . '](' . $src . ')' . $attrs;
    }

    /**
     * Does this URL attribute name no destination at all?
     *
     * EMPTY IS A PROPERTY OF THE STRING, read the way an HTML URL attribute is
     * read: a value of zero length, or of zero length once leading and trailing
     * ASCII whitespace is stripped, because that is what a URL parser strips
     * before resolving one. A value that is merely unusual is not empty and is
     * kept - the rule is over the DESTINATION, not over the reason it is
     * missing.
     *
     * An ABSENT attribute is the same shape and reaches this the same way:
     * `DOMElement::getAttribute()` answers `''` for one, and an `<a>` with no
     * `href` names no destination just as an `<a href="">` does.
     *
     * The character list is the URL spec's ASCII whitespace rather than PHP's
     * default `trim()` set, which omits the form feed and adds a NUL and a
     * vertical tab that are not whitespace here.
     *
     * @param string $value
     */
    protected function importDestinationIsEmpty(string $value): bool
    {
        return trim($value, " \t\n\f\r") === '';
    }

    /**
     * The Carve an element with no destination leaves behind: its content, in a
     * span where an attribute survives and bare where none does.
     *
     * That is the attribute-less `<div>` boundary one layer down, and it is the
     * same boundary because it is the same question - what is the element still
     * needed to hold? Nothing here reads the destination slot: `$skipAttrs`
     * carries it out, and the caller passes the content it wants stood in the
     * element's place.
     *
     * The brackets are escaped ONLY in the span case, where an unescaped `]` in
     * the content would end the label early. Bare, the content is ordinary
     * prose and a backslash there would be a character the author never wrote.
     *
     * @param \DOMElement $node
     * @param string $content
     * @param array<string> $skipAttrs The destination slot, and anything the content already carries.
     */
    protected function unwrapDestinationLessElement(DOMElement $node, string $content, array $skipAttrs): string
    {
        $attrs = $this->formatInlineAttributes($node, $skipAttrs);
        if ($attrs === '') {
            return $content;
        }

        return '[' . $this->escapeLinkOrImageLabel($content) . ']' . $attrs;
    }

    protected function processHr(DOMNode $node): string
    {
        $char = '-';
        if ($node instanceof DOMElement && $node->hasAttribute('data-char')) {
            $char = $node->getAttribute('data-char');
        }

        return "\n\n" . str_repeat($char, 3) . "\n\n";
    }

    protected function processBlockquote(DOMElement $node): string
    {
        // Process content, preserving paragraph breaks.
        $parts = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }

            $part = rtrim($this->processNode($child), "\n");
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        $content = implode("\n\n", $parts);
        $lines = explode("\n", $content);

        $quoted = [];
        foreach ($lines as $line) {
            $quoted[] = $line === '' ? '>' : '> ' . rtrim($line);
        }

        $attrs = $this->formatBlockAttributes($node);

        // NO SEPARATOR between the attribute block and the block it attributes:
        // `formatBlockAttributes()` already ends its line, and adding a second
        // newline put a blank line between them. The shared cross-engine
        // fixture `html-import/blockquote-cite` pins the glued form, and this
        // engine's own paragraph path always wrote it that way - only the quote
        // and the code fence doubled it.
        return $attrs . implode("\n", $quoted) . "\n\n";
    }

    /**
     * Turn a `<math>` element into Carve math, or into nothing.
     *
     * Tiers 1 and 2 have TeX to write. Tier 3 does not, and the children are
     * not a substitute for it, so `roundtrip` keeps the element verbatim and
     * the untrusted modes drop it - the report names it, from the same tier
     * decision this reads (`inspectMath()`).
     */
    protected function processMath(DOMElement $node): string
    {
        $resolved = $this->resolveMathTex($node);
        if ($resolved['content'] !== '') {
            return $this->renderMath($resolved['content'], $node->getAttribute('display') === 'block');
        }

        if ($this->trustedRoundTrip) {
            return $this->processRawHtmlInlineElement($node);
        }

        // A `<math>` with no TeX anywhere is not an equation this importer can
        // build, and in `roundtrip` it is kept rather than dropped: its
        // children are a token stream whose concatenation is meaningless, so
        // the markup is the only thing left that means anything
        // (`markup-carve/carve-php#1713`).
        return $this->preservedAsRawHtml($node) ?? '';
    }

    /**
     * Resolve the TeX a `<math>` element carries. Three tiers, in this order.
     *
     * 1. An `<annotation>` whose `encoding` declares TeX exactly and which is
     *    a direct child of the element's own `<semantics>`. Its text is the
     *    content verbatim, `{\displaystyle ...}` wrapper and all: Carve math
     *    content is opaque TeX and rewriting it is a second decision.
     * 2. Else `alttext`. MathML does not declare what `alttext` holds, so
     *    reading it as TeX is an assumption - hence tier 2, and hence the
     *    `info` the report carries for it.
     * 3. Else there is no TeX in the source, and the children are not an
     *    answer. They are a token stream whose concatenation is meaningless:
     *    `<mfrac><mn>1</mn><mn>2</mn></mfrac>` concatenates to `12`, one half
     *    read back as twelve. That is a plausible wrong value rather than
     *    visible degradation, so it survives review - which is why this
     *    returns empty and the caller drops the element instead.
     *
     * Order matters and is the reverse of what this importer did before
     * (carve#1210 D6): where a declared encoding and an undeclared attribute
     * disagree, the declared one wins.
     *
     * The annotation must be a DIRECT child of a DIRECT-child `<semantics>`.
     * `getElementsByTagName()` is recursive, so the previous lookup pulled TeX
     * out of an `<annotation>` nested inside an `<annotation-xml>` payload as
     * if the element had declared it at top level.
     *
     * @param \DOMElement $node
     *
     * @return array{tier: int, content: string}
     */
    protected function resolveMathTex(DOMElement $node): array
    {
        foreach ($node->childNodes as $semantics) {
            if (!$semantics instanceof DOMElement || strtolower($semantics->tagName) !== 'semantics') {
                continue;
            }
            foreach ($semantics->childNodes as $annotation) {
                if (!$annotation instanceof DOMElement || strtolower($annotation->tagName) !== 'annotation') {
                    continue;
                }
                $encoding = strtolower(trim($annotation->getAttribute('encoding')));
                if (!in_array($encoding, self::MATH_TEX_ENCODINGS, true)) {
                    continue;
                }
                $content = trim($annotation->textContent);
                if ($content !== '') {
                    return ['tier' => 1, 'content' => $content];
                }
            }
        }

        $alttext = trim($node->getAttribute('alttext'));
        if ($alttext !== '') {
            return ['tier' => 2, 'content' => $alttext];
        }

        return ['tier' => 3, 'content' => ''];
    }

    /**
     * The TeX a Carve-rendered math element carries, or null if it is not one.
     *
     * TWO SIGNALS have to agree before this claims an element, because either
     * alone is something else. `class="math inline"` on its own is a class a
     * stylesheet could have put anywhere, and a `\(…\)` payload on its own is
     * ordinary text that happens to contain escapes. Together they are the
     * shape this engine's renderer writes - and djot.js and pandoc write it
     * too - so reading it back as math is a round trip, not a guess.
     *
     * The delimiter must MATCH the declared display mode: a `display` class
     * around `\(…\)` disagrees with itself, and guessing which half is right
     * would change the equation's typesetting on a document that never said so.
     *
     * Element children disqualify the element. `textContent` would flatten
     * `<span class="math inline">\(<em>x</em>\)</span>` to the same string as
     * the plain form, silently discarding the emphasis, and TeX has no markup
     * inside it to lose in the first place - so the shape is not this engine's
     * output and falls through to the ordinary attributed span.
     *
     * @param \DOMElement $node
     * @param string $tag The element name this shape is spelled with.
     *
     * @return array{content: string, display: bool, classes: array<string>}|null
     */
    protected function mathDelimitedContent(DOMElement $node, string $tag): ?array
    {
        if (strtolower($node->tagName) !== $tag) {
            return null;
        }

        $classes = $this->getElementClassList($node);
        $mathAt = array_search('math', $classes, true);
        if ($mathAt === false) {
            return null;
        }
        unset($classes[$mathAt]);

        $display = null;
        foreach ($classes as $index => $class) {
            if ($class === 'inline' || $class === 'display') {
                $display = $class === 'display';
                unset($classes[$index]);

                break;
            }
        }
        if ($display === null) {
            return null;
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return null;
            }
        }

        $text = trim($node->textContent);
        $open = $display ? '\\[' : '\\(';
        $close = $display ? '\\]' : '\\)';
        if (!str_starts_with($text, $open) || !str_ends_with($text, $close)) {
            return null;
        }

        // A CODE SPAN IS ONE LINE, so the payload is folded to one. Carve math
        // is a prefix on a code span (`math_inline = '$', code_span`), and a
        // payload that arrived across source lines has no other spelling: a
        // blank line inside one ENDS the paragraph, so `\(p\n\nq\)` written out
        // verbatim came back as an equation `p`, a paragraph `q` and a stray
        // code span - the document destroyed by the whitespace it carried. TeX
        // reads a whitespace run as one space, so the folded equation is the
        // same equation.
        //
        // EVERY run folds, not only the ones holding a newline, because that is
        // what carve-js and carve-rs already do and the importers' output is
        // compared across engines. The cases where TeX itself is whitespace-
        // sensitive - a `%` comment whose line break folds away, a `\verb` run
        // holding two spaces - are real and are a question for all three
        // engines at once; folding differently HERE would put three engines on
        // three spellings, which is what PART 9 section 18 exists to stop.
        $content = trim((string)preg_replace('/\s+/u', ' ', substr($text, strlen($open), -strlen($close))));
        if ($content === '') {
            return null;
        }

        return ['content' => $content, 'display' => $display, 'classes' => array_values($classes)];
    }

    /**
     * The attribute block riding a reconstructed math node, in writer slot order.
     *
     * The two classes that SPELL the math are consumed by the spelling, exactly
     * as `<abbr title>`'s title is consumed by `{abbr="…"}`. Everything the
     * renderer merged in beside them - an authored id, authored classes,
     * `data-*` - is the author's and comes back.
     *
     * @param \DOMElement $node
     * @param array<string> $classes The classes left after the math pair.
     */
    protected function mathAttributeSuffix(DOMElement $node, array $classes): string
    {
        $parts = [];
        $idPart = $this->idAttributePart($node);
        if ($idPart !== null) {
            $parts[] = $idPart;
        }
        foreach ($classes as $class) {
            $parts[] = '.' . $class;
        }
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if ($name === 'id' || $name === 'class' || $this->isStrippedImportAttribute($name)) {
                continue;
            }
            $value = $attr->value;
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    /**
     * Carve math is a PREFIX on a code span, and has no closing delimiter.
     *
     * The grammar spells it `math_inline = '$', code_span` and
     * `math_display = "$$", code_span` (§18), so the code span's own closing
     * backticks end the math. A trailing `$` after them is not part of the
     * construct: it is the next character of the paragraph, and it came back as
     * literal text sitting beside the equation - `$`x`$` rendered the math span
     * and then a stray `$` (carve-php#1543).
     */
    protected function renderMath(string $content, bool $isDisplay): string
    {
        $delimiter = $isDisplay ? '$$' : '$';
        $backticks = StringUtil::findSafeCodeFence($content, 1);

        return $delimiter . $backticks . $content . $backticks;
    }

    /**
     * The single letters a Roman numeral is built from.
     *
     * An alphabetic marker that happens to be one of them reads as the Roman
     * value when nothing else in the list contradicts it, which is why a
     * one-item alphabetic list starting at `i`, `v`, `x`, `l`, `c`, `d` or `m`
     * has no spelling of its own.
     *
     * @var list<string>
     */
    protected const ROMAN_LETTERS = ['i', 'v', 'x', 'l', 'c', 'd', 'm'];

    /**
     * @var array<string, int>
     */
    protected const ROMAN_VALUES = [
        'M' => 1000,
        'CM' => 900,
        'D' => 500,
        'CD' => 400,
        'C' => 100,
        'XC' => 90,
        'L' => 50,
        'XL' => 40,
        'X' => 10,
        'IX' => 9,
        'V' => 5,
        'IV' => 4,
        'I' => 1,
    ];

    /**
     * The numbering style this `ol` should be written with, or null for decimal.
     *
     * `<ol type="a">` used to leave a raw `{type=a}` attribute block above a
     * decimal list. That renders an `<ol type="a">` again, which is why it
     * looked done, but the tree carried `attrs.type` and never the `olType`
     * field the style belongs in - so every consumer reading the AST rather
     * than the HTML saw a decimal list. Worse, the attribute block is only
     * written for a top-level list, so a nested `<ol type="i">` lost its style
     * outright. Carve spells all four styles in the marker itself, so the
     * marker is where the style goes.
     *
     * Null keeps the previous behavior, and deliberately: the two shapes below
     * have no marker spelling at all, and a raw attribute that still renders
     * the right `<ol>` beats markers that would re-parse as a different list.
     */
    protected function orderedListNumberingStyle(DOMElement $node): ?string
    {
        $type = $node->getAttribute('type');
        if (!in_array($type, ['a', 'A', 'i', 'I'], true)) {
            return null;
        }

        $start = $node->hasAttribute('start') ? (int)$node->getAttribute('start') : 1;
        if ($start < 1) {
            return null;
        }
        $count = max(1, $this->orderedListItemCount($node));
        $last = $start + $count - 1;

        if ($type === 'i' || $type === 'I') {
            // A Roman marker is never mistaken for an alphabetic one: past the
            // first item it is more than one letter, and on the first the
            // parser resolves the overlap to Roman, which is what was meant.
            // There is no upper bound, because the additive form above 3999
            // (`MMMM.`) is one this parser reads and CarveRenderer already
            // writes; a cutoff here would be this converter's own rule.
            return $type;
        }

        // Alphabetic markers are a single letter, so the sequence has to stay
        // inside a-z; `aa.` is not a marker and would come back as a paragraph.
        if ($start > 26 || $last > 26) {
            return null;
        }

        // Two or more items settle the Roman overlap by themselves - no two
        // single-letter Roman numerals are consecutive, so `c. d.` can only be
        // alphabetic. One item cannot, and reads as the Roman value.
        if ($count < 2 && in_array(strtolower($this->alphabeticMarker($start)), self::ROMAN_LETTERS, true)) {
            return null;
        }

        return $type;
    }

    /**
     * How many items this list will actually write markers for.
     */
    protected function orderedListItemCount(DOMElement $node): int
    {
        $count = 0;
        foreach ($node->childNodes as $child) {
            if (
                $child instanceof DOMElement
                && strtolower($child->tagName) === 'li'
                && !$child->hasAttribute('data-djot-inline-footnote')
            ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The marker text for the nth item in a numbering style.
     */
    protected function orderedListMarkerText(int $number, ?string $style): string
    {
        if ($style === 'a' || $style === 'A') {
            if ($number < 1 || $number > 26) {
                // Outside a-z there is no alphabetic marker, and
                // orderedListNumberingStyle() does not choose the style there.
                // Should it ever be reached anyway, a decimal marker is a
                // marker, where a letter past `z` is not one.
                return (string)$number;
            }
            $letter = $this->alphabeticMarker($number);

            return $style === 'a' ? strtolower($letter) : $letter;
        }

        return match ($style) {
            'i' => strtolower($this->romanMarker($number)),
            'I' => $this->romanMarker($number),
            default => (string)$number,
        };
    }

    /**
     * The nth uppercase letter, counting from 1. Outside 1..26 there is no
     * letter and the caller has already chosen a different marker.
     */
    protected function alphabeticMarker(int $number): string
    {
        return substr('ABCDEFGHIJKLMNOPQRSTUVWXYZ', $number - 1, 1);
    }

    /**
     * The uppercase Roman numeral for a positive integer.
     *
     * Deliberately the same numeral CarveRenderer writes, additive form above
     * 3999 included, so a list does not get one spelling on the way in and
     * another on the way out. Its alphabetic companion is NOT copied: that one
     * wraps past `z` with a modulo, which is right for a writer whose reader
     * has the `start` alongside, and wrong here, where a wrapped letter would
     * re-parse as a list starting somewhere else.
     */
    protected function romanMarker(int $number): string
    {
        $result = '';
        foreach (self::ROMAN_VALUES as $numeral => $value) {
            while ($number >= $value) {
                $result .= $numeral;
                $number -= $value;
            }
        }

        return $result;
    }

    protected function processList(DOMElement $node): string
    {
        // A NON-`li` CHILD IS NOT DISCARDED, and it is not discarded in silence
        // either (carve-php#1589). The item loop below acts only on `li` and
        // has no `else`, so the WHOLE of anything else the list carried used to
        // leave the document: `<ul><div id="stray">z</div><li>a</li></ul>` came
        // back as one item, with the text `z` gone and nothing in the report
        // saying it had been.
        //
        // HTML5 does not allow the shape. A sliced-up editor export produces it
        // anyway, and that is the input an importer exists for.
        //
        // The content is emitted as blocks AHEAD OF THE LIST, which is the call
        // `<dd>`-with-no-`<dt>` already makes: it keeps every word and stays
        // valid Carve, where a list holding a non-item has no Carve spelling at
        // all. The stray child goes through the ORDINARY block walk rather than
        // being unwrapped by hand, so it keeps its own element and attributes
        // too - a `<div id="stray">` comes back as a Carve div still carrying
        // the id. Unwrapping it, the way the `<dd>` has to, would drop the id
        // for no reason: a `<dd>` has no standalone spelling and a div has one.
        //
        // Collected BEFORE the depth counter moves, so the stray blocks render
        // at the depth they are written at rather than one level in.
        //
        // The report says `element-unwrapped` for these, from
        // `inspectImportListChildren()`; the matching ruling is
        // markup-carve/carve-rs#1266.
        $strayBlocks = $this->processStrayListChildren($node);

        $this->listDepth++;

        // A NESTED list's stray blocks stay inside the item that holds the
        // list. Rendered at the outer depth they came out at column zero, which
        // reparses as a top-level block: it closed the parent item and split
        // the sublist off into a list of its own. They belong at the same
        // column the nested list's own markers reach.
        if ($strayBlocks !== '' && $this->listDepth > 1) {
            $strayBlocks = (string)preg_replace(
                '/^(?=.)/m',
                str_repeat('  ', $this->listDepth - 1),
                $strayBlocks,
            );
        }
        // Does a sibling list sit immediately before this one, close enough to
        // merge with it? Read BEFORE anything is emitted, because the answer
        // goes at the very front of what this list returns.
        $needsListBoundary = $this->precedingSiblingListWouldMerge($node);
        $isOrdered = strtolower($node->tagName) === 'ol';
        // Recognize both the rendered form (class="task-list") and the TipTap
        // editor form (data-type="taskList").
        $isTaskList = $this->isTaskList($node);
        $output = '';
        $counter = 1;
        $attributeLine = '';
        $hasAttributeLine = false;

        // Get start attribute for ordered lists
        if ($isOrdered && $node->hasAttribute('start')) {
            $counter = (int)$node->getAttribute('start');
        }

        // Get marker from data attribute (for round-trip fidelity), otherwise
        // the ordinary default: `-` for a bullet list, `.` for an ordered one.
        //
        // THE MARKER IS NOT THE SEPARATOR ANY MORE. Two back-to-back lists used
        // to be kept apart by ALTERNATING the marker across adjacent siblings -
        // `-`/`*` for bullets, `.`/`)` for ordered ones (carve-php#1290) -
        // because two same-marker lists parted by a single blank line reparse
        // as one list. That invented a marker the source HTML never carried,
        // and it disagreed with the other engines' importers. Carve now spells
        // the split itself: PART 9 §11 N1a makes a run of three or more
        // blank lines a HARD LIST BOUNDARY, so the lists are separated by the
        // boundary below and every list keeps the marker it was authored with.
        $marker = $isOrdered ? $this->resolveOrderedDelim($node) : $this->resolveBulletMarker($node);

        $olType = $isOrdered ? $this->orderedListNumberingStyle($node) : null;

        // Add leading newline for top-level lists to ensure blank line before
        if ($this->listDepth === 1) {
            // Add list-level attributes (skip 'start', 'data-marker', 'class' for task-list)
            $skipAttrs = $isOrdered ? ['start', 'data-marker'] : ['data-marker'];
            if ($olType !== null || ($isOrdered && $node->getAttribute('type') === '1')) {
                // The markers below carry the numbering style, so writing the
                // attribute as well would say it twice - and as a raw attribute
                // rather than as the `olType` the style belongs in. Decimal is
                // what an absent style means, so `type="1"` needs no spelling
                // at all.
                $skipAttrs[] = 'type';
            }
            if ($isTaskList) {
                $skipAttrs[] = 'class';
                $skipAttrs[] = 'data-type';
            }
            $listAttrs = $this->formatBlockAttributes($node, $skipAttrs);
            // HELD BACK, not emitted. PART 9 §17 L7 decides from the BODY
            // whether this list needs `{loose}` spelled, and the body is not
            // written yet - so the attribute line is assembled after the items
            // and spliced in at the end. See the L7 block below the loop.
            $attributeLine = $listAttrs;
            $hasAttributeLine = true;
        }

        // A LOOSE source list - an item holding an explicit <p> - stays loose:
        // the items are separated by a blank line, which is Carve's spelling
        // of looseness, so the paragraph-ness of the source survives the trip.
        // A bare-text item stays tight, which is the inverse ruling: the two
        // directions are one predicate read off the source's own markup
        // (markup-carve/carve#1210). Decided per LIST, as CommonMark does -
        // one paragraph item loosens the whole list.
        $isLoose = false;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }
            foreach ($child->childNodes as $liChild) {
                if ($liChild instanceof DOMElement && strtolower($liChild->tagName) === 'p') {
                    $isLoose = true;

                    break 2;
                }
            }
        }

        $firstItem = true;
        // COUNTED AS WRITTEN, not as present in the DOM. The loop below skips an
        // inline-footnote item, so a DOM count would say two where the body
        // holds one - and L7's whole question is what the BODY spells.
        $itemsWritten = 0;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                if ($child->hasAttribute('data-djot-inline-footnote')) {
                    continue;
                }
                $itemsWritten++;

                // The blank line between loose items, unless the previous
                // item's own trailing content (a nested list, a multi-block
                // part) already left one.
                if ($isLoose && !$firstItem && !str_ends_with($output, "\n\n")) {
                    $output .= "\n";
                }
                $firstItem = false;

                $indent = str_repeat('  ', $this->listDepth - 1);

                // Check for task list item. The checked state comes from a
                // direct <input checked> (rendered form) or from data-checked
                // (TipTap form, where the input is nested in a <label>).
                $checkbox = '';
                $checkboxInput = $this->getDirectCheckboxInput($child);
                if ($isTaskList || $checkboxInput !== null) {
                    $isChecked = $child->hasAttribute('data-checked')
                        ? $child->getAttribute('data-checked') === 'true'
                        : ($checkboxInput?->hasAttribute('checked') ?? false);
                    $checkbox = $isChecked ? '[x] ' : '[ ] ';

                    // THE POINT OF CONSUMPTION, which is the only place that
                    // knows WHICH input became this marker (carve-php#1705).
                    // Exactly one does: `getDirectCheckboxInput()` returns the
                    // first, and a second checkbox in the same item is dropped
                    // like any other - so recording only this one leaves the
                    // second's loss reported, which is what the survival budget
                    // exists to keep.
                    //
                    // A TIPTAP ITEM KEEPS ITS STATE IN `data-checked` and wraps
                    // the input in an empty `<label>` that the content loop
                    // skips whole. That input is consumed by the marker just the
                    // same, so it is recorded here too. Reaching the right-hand
                    // side at all means there was no direct checkbox, which
                    // inside this branch means the item is a task list.
                    $consumed = $checkboxInput ?? $this->labelWrappedCheckboxInput($child);
                    if ($consumed !== null) {
                        $this->consumedCheckboxInputs[$this->conversionNodePath($consumed)] = true;
                    }
                }

                // AN ITEM'S ATTRIBUTES ABUT ITS MARKER (carve-php#1587). They
                // used to be written on an indented line BELOW the marker,
                // where a block attribute floats FORWARD - so it landed on
                // whatever block came next instead of on the item, and on a
                // one-block item it left the document entirely. A degraded
                // footnote's `id` was lost that way, and on a two-block note it
                // attached to the second paragraph.
                //
                // The floating itself is correct and is not what changes here;
                // what changes is that the attribute is no longer put somewhere
                // it can float away from. `1.{#fn1} n` is the spelling carve-js
                // writes, and the abutting shape was already in this writer for
                // an item whose only content is a nested list - it simply was
                // not reached on any other path.
                //
                // The attributes go on the MARKER, ahead of a task checkbox:
                // `-{#t} [x] a`. A checkbox is item CONTENT rather than part of
                // the marker, so `- [x]{#t} a` would parse as a span carrying
                // the id around the letter `x`.
                //
                // For TipTap task items only, drop the editor's
                // data-type/data-checked markers; ordinary list items keep
                // their attributes.
                $liSkipAttrs = $isTaskList ? ['data-type', 'data-checked'] : [];
                $liAttrs = $this->getElementAttributes($child, $liSkipAttrs);
                $attrToken = $liAttrs !== '' ? '{' . $liAttrs . '}' : '';

                $barePrefix = $isOrdered
                    ? $this->orderedListMarkerText($counter, $olType) . $marker . ' '
                    : $marker . ' ';
                $markerWidth = strlen($barePrefix);
                $prefix = $isOrdered
                    ? rtrim($barePrefix) . $attrToken . ' '
                    : $marker . $attrToken . ' ' . $checkbox;

                // Process list item content, separating nested lists from other content
                $contentParts = [];
                // Aligned with `$contentParts`: whether that part still OPENS a
                // block when written at the item's content column with no blank
                // line above it. Only such a part may be written tight - see
                // `TIGHT_ITEM_BLOCK_OPENERS`.
                $partOpensBlock = [];
                $inlineBuffer = '';
                $nestedContent = '';
                // Whether the nested content BEGINS with the sublist itself. A
                // stray non-`<li>` child is hoisted in FRONT of the list, so the
                // nested run can start with a paragraph, which folds into the
                // lead if nothing separates it.
                $nestedOpensWithItsList = null;

                foreach ($child->childNodes as $liChild) {
                    if ($liChild instanceof DOMElement) {
                        $childTag = strtolower($liChild->tagName);
                        if ($childTag === 'ul' || $childTag === 'ol') {
                            // Process nested list separately
                            $nestedRendered = $this->processNode($liChild);
                            // ANSWERED BY THE FIRST LIST THAT WRITES SOMETHING,
                            // not by the first one present. An EMPTY `<ul>` ahead
                            // of the real one contributes nothing to the run, so
                            // letting it answer described a run it is not the
                            // start of: `<li>a<ul></ul><ul><p>x</p><li>b</li></ul></li>`
                            // would then abut the stray paragraph and fold `x`
                            // into the lead.
                            if ($nestedRendered !== '') {
                                $nestedOpensWithItsList ??= !$this->listHoistsStrayBlocks($liChild);
                            }
                            $nestedContent .= $nestedRendered;
                        } elseif ($childTag === 'input' && $this->isCheckboxInput($liChild)) {
                            // Skip checkbox inputs (handled via $checkbox prefix)
                            continue;
                        } elseif ($isTaskList && $childTag === 'label' && trim($liChild->textContent) === '') {
                            // TipTap wraps the checkbox in an empty <label>; the
                            // visible text lives in the sibling <div>. A label
                            // that carries text (accessibility markup) is left to
                            // fall through and be processed normally.
                            continue;
                        } elseif (in_array($childTag, $this->blockElements, true)) {
                            $this->flushListItemInlineBuffer($contentParts, $inlineBuffer);
                            // A flushed inline run is plain text, so it opens
                            // nothing at the content column.
                            $partOpensBlock = array_pad($partOpensBlock, count($contentParts), false);
                            $content = trim($this->processNode($liChild));
                            if ($content !== '') {
                                $contentParts[] = $content;
                                $partOpensBlock[] = in_array($childTag, self::TIGHT_ITEM_BLOCK_OPENERS, true);
                            }
                        } else {
                            $inlineBuffer .= $this->processNode($liChild);
                        }
                    } else {
                        $inlineBuffer .= $this->processNode($liChild);
                    }
                }

                $this->flushListItemInlineBuffer($contentParts, $inlineBuffer);
                $partOpensBlock = array_pad($partOpensBlock, count($contentParts), false);

                // Attributes are metadata and do not widen the bare marker's
                // content column (markup-carve/carve#1701).
                //
                // A TASK ITEM'S `[x] ` DOES NOT. The checkbox is CONTENT, which
                // the reader consumes as the item's task state, so it leaves the
                // content column where the bullet put it: `- [x] ` is six wide
                // and its content column is 2, `-{#k} [x] ` is ten and its is 6.
                // Indenting a continuation to the full width put a block opener
                // four columns too far in, where it opens nothing - the heading
                // in `<li><input type="checkbox" checked> <h1 id="h">h</h1></li>`
                // came back as text of the marker line's paragraph, so the
                // visible text moved from `h` to `# h` (carve-js#1450).
                //
                // This is the SECOND spelling of the rule in this engine. The
                // AST writer holds the first, in CarveRenderer::renderList();
                // the importer writes source directly and so has to say it
                // again. Both were wrong the same way (carve-php#1693).
                $continuation = $indent . str_repeat(' ', $markerWidth);

                // An item whose ONLY content is a nested list puts that list
                // on the marker line, and the nested block below skips its
                // usual blank separator. Emitting the marker alone gave `- `
                // followed by a blank line, which does not round trip: a marker
                // with nothing after it is not a marker, so it came back as a
                // paragraph reading `-` and the nested list dedented out of the
                // item. `- - a` is also what every engine's own writer emits.
                $markerCarriesNested = $contentParts === [] && $nestedContent !== '';

                if ($contentParts === [] && !$markerCarriesNested) {
                    // An EMPTY item that carries attributes needs something
                    // after the abutted brace pair: a marker line ending in
                    // `-{#x}` is not a marker at all, and comes back as a
                    // paragraph reading the braces as a tag span. `+` is the
                    // continuation marker, which is how carve-js spells an
                    // empty item here too, and it re-parses as `<li id="x">`.
                    $output .= $indent . $prefix . ($liAttrs !== '' ? '+' : '') . "\n";
                } elseif ($contentParts !== []) {
                    $firstPart = array_shift($contentParts);
                    array_shift($partOpensBlock);
                    $firstPartLines = preg_split('/\R/', $firstPart) ?: [''];
                    $firstLine = array_shift($firstPartLines);

                    // The marker line always carries the first line of the
                    // first part, whatever that part is. A multi-line part used
                    // to go BELOW the marker instead, which left `- ` alone on
                    // its line - and a marker with nothing after it is not a
                    // marker, so a `details` container as an item's only
                    // content came back as a paragraph reading `-` with the
                    // container loose beside it (markup-carve/carve-php#1224).
                    $output .= $indent . $prefix . $firstLine . "\n";
                    foreach ($firstPartLines as $line) {
                        // A blank line is kept as a blank line, not dropped: it
                        // separates the blocks inside the part, and removing it
                        // ran them together.
                        $output .= trim($line) === '' ? "\n" : $continuation . $line . "\n";
                    }

                    foreach ($contentParts as $partIndex => $part) {
                        // THE SEPARATOR INSIDE AN ITEM IS THE LIST'S TIGHTNESS,
                        // not a fixed part of the shape. A blank line here was
                        // written between every pair of parts whatever the list
                        // spelled, so an item whose lead is BARE TEXT came back
                        // with a gap the source never had: `<li>a<h1>H</h1></li>`
                        // was written `- a`, blank, `  # H` where carve-js and
                        // carve-rs write it tight (carve-php#1708).
                        //
                        // `$isLoose` is already the vote this needs, and it is
                        // the vote markup-carve/carve-js#1110 settled: only a
                        // DIRECT `<p>` loosens, decided per LIST rather than per
                        // item. So a sibling item's paragraph loosens this item's
                        // interior too, which is what the other two engines do -
                        // a heading, a quote, a code block or a sublist votes for
                        // nothing on its own.
                        //
                        // NO RENDERED BYTE MOVES. A blank line loosens an item
                        // only when a PARAGRAPH follows it, and a part written
                        // tight here OPENS ITS OWN BLOCK at the content column,
                        // so both spellings render the same HTML. This is the
                        // SOURCE agreeing with the other engines, not a
                        // rendering fix - which is also why a part that does NOT
                        // open a block keeps its blank line: written tight it
                        // would fold into the lead and the item would come back
                        // holding one block where the source held two.
                        $tight = !$isLoose && ($partOpensBlock[$partIndex] ?? false);
                        $output .= ($tight ? '' : "\n") . $this->indentListItemPart($part, $continuation) . "\n";
                    }
                }

                // Add nested list content, with a blank line before it only
                // where the list is loose (see the separator note below). The
                // recursive render indents nested content by a fixed
                // two columns per depth; a nested list must instead reach the
                // PARENT item's content column (content-column model, carve#295),
                // which for an ordered marker (`1. ` -> 3, `10. ` -> 4) is wider
                // than two. Pad every non-empty line by that surplus so the
                // nested list re-parses as a child rather than detaching. The
                // task checkbox is content, not marker, so a task/bullet item's
                // content column stays two.
                if ($nestedContent !== '') {
                    // Attributes and the task checkbox add no marker width.
                    $surplus = $markerWidth - 2;
                    if ($surplus > 0) {
                        $pad = str_repeat(' ', $surplus);
                        $nestedContent = (string)preg_replace('/^(?=.)/m', $pad, $nestedContent);
                    }

                    if ($markerCarriesNested) {
                        // The nested list is already indented to this item's
                        // content column, so its first line moves onto the
                        // marker line unchanged apart from that indent, and the
                        // rest stays where it is.
                        $nestedLines = preg_split('/\R/', rtrim($nestedContent, "\n")) ?: [];
                        $firstNested = ltrim((string)array_shift($nestedLines));
                        // Attributes go ON the marker (`-{.x} - a`), not on a
                        // line below it: the line below is now the nested
                        // list's own second item, so an attribute line there
                        // would attach to that item instead of this one. The
                        // prefix already carries them, ahead of any checkbox.
                        $output .= $indent . $prefix . $firstNested . "\n";
                        foreach ($nestedLines as $line) {
                            $output .= $line === '' ? "\n" : $line . "\n";
                        }
                    } else {
                        // A SUBLIST BELOW A LEAD IS THE SAME SEPARATOR, and it
                        // takes the same vote. A nested list is structure rather
                        // than a paragraph wrapper, so it never loosens on its
                        // own - `<li>a<ul><li>b</li></ul></li>` is a tight item
                        // with a tight child, and the blank line written here
                        // unconditionally spelled a looseness neither list had.
                        //
                        // UNLESS A STRAY BLOCK STANDS IN FRONT OF IT. A non-`<li>`
                        // child is hoisted above the list it was found in, so the
                        // run starts with a paragraph rather than with a marker,
                        // and a paragraph written tight folds into the lead.
                        $tight = !$isLoose && ($nestedOpensWithItsList ?? false);
                        $output .= ($tight ? '' : "\n") . $nestedContent;
                    }
                }

                $counter++;
            }
        }

        // PART 9 §17 L7: SPELL THE LOOSENESS THE LAYOUT CANNOT SAY.
        //
        // A blank line between items is Carve's spelling of looseness, and this
        // writer emits one between every pair - so a multi-item loose list
        // already says it. A ONE-ITEM list has no "between items" for that
        // blank line to stand in, and that is exactly the shape L7 exists for.
        // A document with a single footnote imports as exactly one item, so it
        // is the common case rather than a corner: the derived endnotes section
        // was written tight and the `<p>` the imported tree recorded was lost on
        // the way back in.
        //
        // The DECISION PROCEDURE is shared with `CarveRenderer`, which has
        // spelled this since the key landed - write the body without the key,
        // read it back, and emit the key exactly where the looseness did not
        // survive. Asking it there rather than re-deriving it here is what keeps
        // the two writers from answering differently.
        if ($hasAttributeLine) {
            $needsLoose = CarveRenderer::looseKeyIsNeededForBody($isLoose, $itemsWritten, $output);
            if ($needsLoose) {
                // `loose` goes FIRST in the slot order, which is where the
                // canonical writer puts it, so a document that round-trips
                // through both writers is stable.
                $attributeLine = $attributeLine === ''
                    ? "{loose}\n"
                    : '{loose ' . mb_substr($attributeLine, 1, null, 'UTF-8');
            }
            // THE ATTRIBUTE LINE ENDS ITS OWN LINE, so adding one here wrote a
            // BLANK line between the attribute and the list it attaches to
            // (carve-php#1653). `formatBlockAttributes()` returns `"{...}\n"`
            // and says so in its own docblock; seven of its callers use that
            // directly and three added a second newline.
            //
            // The empty case keeps its newline, which is a DIFFERENT role: a
            // top-level list with no attribute line opens with one, and the two
            // roles were conflated in the single statement this replaces.
            $output = ($attributeLine !== '' ? $attributeLine : "\n") . $output;
        }

        $this->listDepth--;

        // THE HARD BOUNDARY GOES AHEAD OF EVERYTHING THIS LIST EMITS, so that
        // three blank lines land between the previous list's last item and this
        // list's first marker. Stray blocks are the exception: they are emitted
        // ahead of the list and already stand between the two, so there is no
        // merge left to prevent.
        $boundary = $needsListBoundary && $strayBlocks === '' ? self::LIST_BOUNDARY . "\n" : '';

        // Add trailing newline for top-level lists
        return $boundary . $strayBlocks . $output . ($this->listDepth === 0 ? "\n" : '');
    }

    /**
     * Render every child of a list that is not an `<li>`, as blocks that go
     * ahead of the list.
     *
     * Delegating to the ordinary node walk settles the kinds that are not
     * elements at all: the margin between pretty-printed items is blank text
     * and produces nothing, a comment produces nothing, an ACTIVE element
     * (`script`, `style`, `template`, `noscript`) is dropped by the walk with
     * the `element-dropped` every other site gives it, and bare text directly
     * inside the list comes back as the paragraph it needs.
     *
     * @param \DOMElement $node The `<ul>` or `<ol>` element.
     *
     * @return string Blocks, blank-line separated and blank-line terminated, or
     *   the empty string when the list carries nothing but items.
     */
    protected function processStrayListChildren(DOMElement $node): string
    {
        $blocks = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                continue;
            }
            if ($child instanceof DOMComment) {
                // A COMMENT BETWEEN TWO ITEMS IS KEPT NOW
                // (`markup-carve/carve#1709`). A list holds items, so there is
                // no Carve position BETWEEN two of them - and the comment takes
                // the same answer every other stray child of a list takes here:
                // emitted ahead of the list, with the move declared by
                // inspectImportListChildren().
                $blocks[] = $this->blockCommentSource($child->textContent);

                continue;
            }
            $rendered = trim($this->processNode($child));
            if ($rendered === '') {
                continue;
            }
            $blocks[] = $rendered;
        }

        return $blocks === [] ? '' : implode("\n\n", $blocks) . "\n\n";
    }

    /**
     * Might this list write anything ABOVE its own first marker?
     *
     * {@see self::processStrayListChildren()} hoists every non-`<li>` child out
     * in front of the list, so the run this list renders to does not always
     * begin with a marker line. A tight item may only abut a nested run that
     * does begin with one (carve-php#1708).
     *
     * ASKED OF THE TREE, NOT OF A SECOND RENDER. Rendering the children again to
     * find out whether they write anything is the exact answer the writer gives,
     * and it is the wrong way to get it: `processNode()` carries state, and one
     * of the things it carries APPENDS - a flattened caption pushes onto
     * `$captionFlattenDiagnostics` every time it runs, so the report would grow
     * a duplicate row and spend the diagnostic budget twice for a question that
     * writes nothing.
     *
     * SO IT OVER-ANSWERS, deliberately, and only in the safe direction. An
     * element that renders to nothing is still counted here, which costs a blank
     * line this writer did not need - a source spelling, which is where the
     * engine already was. The opposite error abuts a block that IS written and
     * folds it into the lead, which costs the block. Whitespace between
     * pretty-printed items is not content and a comment writes nothing, so
     * neither counts.
     *
     * @param \DOMElement $node The nested `<ul>` or `<ol>`.
     *
     * @return bool Whether a stray block may be written above the list's markers.
     */
    protected function listHoistsStrayBlocks(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMComment) {
                continue;
            }
            if ($child instanceof DOMElement) {
                if (strtolower($child->tagName) !== 'li') {
                    return true;
                }

                continue;
            }
            if (trim($child->textContent) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the delimiter an `<ol>` emits: its explicit data-marker when set,
     * otherwise `.`.
     *
     * @param \DOMElement $node The `<ol>` element.
     *
     * @return string The ordered delimiter, `.` unless the source named one.
     */
    protected function resolveOrderedDelim(DOMElement $node): string
    {
        $marker = $node->getAttribute('data-marker');

        return $marker !== '' ? $marker : '.';
    }

    /**
     * Resolve the bullet marker a `<ul>` emits: its explicit data-marker when
     * set (and not the `+` continuation marker, which is not a Carve bullet),
     * otherwise `-`.
     *
     * @param \DOMElement $node The `<ul>` element.
     *
     * @return string The bullet marker, `-` unless the source named one.
     */
    protected function resolveBulletMarker(DOMElement $node): string
    {
        $marker = $node->getAttribute('data-marker');

        return $marker !== '' && $marker !== '+' ? $marker : '-';
    }

    /**
     * The nearest preceding sibling that puts anything in the emitted source.
     *
     * A node that writes nothing does not separate what stands on either side
     * of it, so it cannot be what the adjacency question is answered against
     * (carve-php#1617). Returns null when the walk runs out of siblings, and
     * null when it reaches content that IS written - the caller wants the
     * element it can compare against, and content that is not an element ends
     * the walk with the same answer as no sibling at all.
     *
     * @param \DOMNode $node The node to walk back from.
     *
     * @return \DOMNode|null The sibling that writes something, or null.
     */
    protected function precedingSiblingThatWritesSomething(DOMNode $node): ?DOMNode
    {
        for ($prev = $node->previousSibling; $prev !== null; $prev = $prev->previousSibling) {
            if ($prev instanceof DOMComment) {
                // A WRITTEN COMMENT SEPARATES THE TWO LISTS, so the hard
                // boundary is not needed behind one (`markup-carve/carve#1709`).
                //
                // This used to `continue` unconditionally, on the ground that a
                // comment is not written at all - which was true while the
                // importer deleted it. It is written now, and a `%%%` block
                // between two lists parses them as two, so keeping the boundary
                // behind one wrote three blank lines nothing needed and put this
                // engine's bytes a line apart from carve-js on the same input.
                //
                // A comment the importer does NOT write is still stepped over:
                // one standing inside this run rather than among blocks writes
                // no block, and one with no inline spelling is dropped.
                if (
                    $this->commentStandsAmongBlocks($prev)
                    && !$this->commentHasNoInlineSpelling($prev->textContent)
                ) {
                    return $prev;
                }

                continue;
            }

            if ($prev instanceof DOMElement) {
                if ($this->writesNothing($prev)) {
                    continue;
                }

                return $prev;
            }

            // A text node. Whitespace between two blocks is layout and writes
            // nothing of its own; anything else is content that separates them.
            if (trim($prev->textContent) === '') {
                continue;
            }

            return $prev;
        }

        return null;
    }

    /**
     * Does this element put NOTHING in the emitted source?
     *
     * Deliberately conservative, and one-directional in its risk. Answering
     * "writes nothing" for an element that writes something would insert a hard
     * boundary where content already separates two lists, which is visible
     * damage; answering "writes something" for an element that writes nothing
     * only leaves carve-php#1617 unfixed for that shape. So the test asks for
     * POSITIVE evidence that something reaches the output, and treats anything
     * it does not recognize as evidence.
     *
     * @param \DOMElement $node The element to test.
     *
     * @return bool True when nothing this element holds reaches the output.
     */
    protected function writesNothing(DOMElement $node): bool
    {
        // A dropped-whole subtree never reaches the output, whatever it holds -
        // a `<script>` is all text and writes none of it.
        if (in_array(strtolower($node->tagName), self::ACTIVE_ELEMENTS, true)) {
            return true;
        }

        if (trim($node->textContent) !== '') {
            return false;
        }

        // No text anywhere below. Only an element that stands for itself can
        // still write something from here.
        foreach ($node->getElementsByTagName('*') as $descendant) {
            if (in_array(strtolower($descendant->tagName), self::SELF_STANDING_ELEMENTS, true)) {
                return false;
            }
        }

        return !in_array(strtolower($node->tagName), self::SELF_STANDING_ELEMENTS, true);
    }

    /**
     * The code fence this line opens or closes, behind whatever a container
     * wrote to its left - or null when the line is not a fence delimiter.
     *
     * The blank-run collapse in `cleanup()` exempts a fence payload, because a
     * blank line there is CONTENT and collapsing it rewrites what the author
     * wrote. Recognizing the fence by `trim($line)` alone found the exemption
     * only when the delimiter began the line, and a list item does not write it
     * that way: an item's first block goes on the MARKER line, so the opener
     * arrived as ``- ```` and was not recognized at all (carve-php#1618).
     *
     * That cost the payload twice over. The opener did not arm the exemption,
     * so the item's own fence lost its blank runs; and the CLOSER, which is
     * indented rather than prefixed, WAS recognized and toggled - leaving the
     * flag inverted for the rest of the document, so the next ordinary
     * top-level fence lost its blank runs too.
     *
     * So the prefixes are stripped before the test rather than trimmed away:
     * any run of indentation, quote markers, list markers and definition
     * markers, which is what nesting writes to the left of a block at any
     * depth. A prose line that would collide is not reachable here - the
     * importer escapes a verbatim delimiter that opens a line.
     *
     * @param string $line The emitted line.
     *
     * @return string|null The fence delimiter run, or null when there is none.
     */
    protected function codeFenceDelimiter(string $line): ?string
    {
        $rest = $line;
        // One marker per nesting level: ``- - ```` is a fence two items deep.
        while (
            preg_match(
                '/^(?:[ \t]+|>[ \t]?|(?:[-*+]|\d{1,9}[.)]|[a-zA-Z][.)])[ \t]+|:[ \t]{1,2})/',
                $rest,
                $prefix,
            ) === 1
        ) {
            $rest = substr($rest, strlen($prefix[0]));
        }

        return preg_match('/^(`{3,}|~{3,})/', $rest, $fence) === 1 ? $fence[1] : null;
    }

    /**
     * Would this list MERGE with the sibling list written immediately before
     * it, if the two were only parted by the usual blank line?
     *
     * The predicate is the writer's own, from
     * `CarveRenderer::listsWouldMerge()`: same list type, same marker, same
     * numbering style. Anything the two differ on already separates them, and
     * the boundary is only needed where nothing else does.
     *
     * Adjacency is read off the DOM rather than off the emitted string, so it
     * holds at every nesting level and for a run of three or more lists: each
     * list asks only about the one before it.
     *
     * WHAT SITS BETWEEN THEM IS MEASURED BY WHAT IT WRITES, not by whether the
     * DOM holds a node (carve-php#1617). `previousElementSibling` alone read an
     * `<p></p>` between two lists as separation, so the boundary was never
     * armed and the two came back as ONE list - the empty paragraph writes
     * nothing, so nothing stood between the markers in the source that was
     * emitted. Every element that renders to nothing did it: `<div></div>`,
     * `<span></span>`, an empty `<table>`, a `<script>`, a `<p>` holding only
     * whitespace or an empty `<span>`.
     *
     * So the walk steps back over siblings that write nothing and stops at the
     * first one that writes something. It walks ALL node types rather than
     * elements only, because a text node is content too: a comment writes
     * nothing and is stepped over, while a run of text separates the lists by
     * itself and ends the walk with no boundary.
     *
     * @param \DOMElement $node The `<ul>` or `<ol>` element.
     *
     * @return bool True when the two lists need the hard boundary between them.
     */
    protected function precedingSiblingListWouldMerge(DOMElement $node): bool
    {
        $prev = $this->precedingSiblingThatWritesSomething($node);
        if (!$prev instanceof DOMElement) {
            return false;
        }

        $tag = strtolower($node->tagName);
        if (strtolower($prev->tagName) !== $tag) {
            return false;
        }

        if ($tag === 'ol') {
            return $this->resolveOrderedDelim($prev) === $this->resolveOrderedDelim($node)
                && $this->orderedListNumberingStyle($prev) === $this->orderedListNumberingStyle($node);
        }

        if ($tag !== 'ul') {
            return false;
        }

        // A TASK LIST IS ITS OWN LIST TYPE. `- [ ] a` and `- b` parse as two
        // lists however they are laid out, so the marker they share does not
        // merge them and the boundary would be noise.
        if ($this->isTaskList($prev) !== $this->isTaskList($node)) {
            return false;
        }

        return $this->resolveBulletMarker($prev) === $this->resolveBulletMarker($node);
    }

    /**
     * Is this `<ul>` a task list - the rendered `class="task-list"` form or the
     * TipTap `data-type="taskList"` one?
     *
     * @param \DOMElement $node The `<ul>` element.
     *
     * @return bool True when the list's items carry checkboxes.
     */
    protected function isTaskList(DOMElement $node): bool
    {
        return $node->getAttribute('class') === 'task-list'
            || $node->getAttribute('data-type') === 'taskList';
    }

    protected function processListItem(DOMElement $node): string
    {
        return $this->processChildren($node);
    }

    /**
     * @param list<string> $contentParts
     * @param string $inlineBuffer
     */
    protected function flushListItemInlineBuffer(array &$contentParts, string &$inlineBuffer): void
    {
        $inlineContent = trim($inlineBuffer);
        if ($inlineContent !== '') {
            $contentParts[] = $inlineContent;
        }
        $inlineBuffer = '';
    }

    protected function indentListItemPart(string $content, string $indent): string
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $output = [];

        foreach ($lines as $line) {
            $output[] = $line === '' ? '' : $indent . $line;
        }

        return implode("\n", $output);
    }

    /**
     * The checkbox a TipTap task item hides in the empty `<label>` beside it.
     *
     * TipTap keeps the state in `data-checked` on the `<li>` and wraps the input
     * in a `<label>` that carries no text, which the content loop skips whole.
     * The input inside it is therefore consumed by the marker exactly as a
     * direct one is, and has the same claim to saying nothing about itself
     * (carve-php#1705).
     *
     * The empty-label test matches the one the content loop applies, so the two
     * cannot disagree about which label was skipped: a label carrying text is
     * accessibility markup that falls through and is processed normally.
     *
     * @param \DOMElement $li The task item.
     *
     * @return \DOMElement|null The consumed input, or null when there is none.
     */
    protected function labelWrappedCheckboxInput(DOMElement $li): ?DOMElement
    {
        foreach ($li->childNodes as $child) {
            if (
                !$child instanceof DOMElement
                || strtolower($child->tagName) !== 'label'
                || trim($child->textContent) !== ''
            ) {
                continue;
            }
            $input = $this->getDirectCheckboxInput($child);
            if ($input !== null) {
                return $input;
            }
        }

        return null;
    }

    /**
     * Check if a list item contains a checkbox input
     */
    protected function getDirectCheckboxInput(DOMElement $li): ?DOMElement
    {
        foreach ($li->childNodes as $child) {
            if (
                $child instanceof DOMElement
                && strtolower($child->tagName) === 'input'
                && $this->isCheckboxInput($child)
            ) {
                return $child;
            }
        }

        return null;
    }

    /**
     * `type` on an `<input>` is an ENUMERATED attribute, and HTML matches an
     * enumerated keyword ASCII case-insensitively: `<input type="CHECKBOX">` is
     * a checkbox to every browser, and so is `Checkbox`. Compared exactly, a
     * real task list imported as an ordinary bullet list and the task state
     * left the document with nothing said.
     *
     * `strtolower()` is the ASCII fold this wants: since PHP 8.2 it is
     * locale-independent and converts only `A-Z`, which is exactly the rule
     * HTML states. A Unicode-aware fold would additionally read `CHEC`
     * + U+212A KELVIN SIGN + `BOX` as the keyword, which no browser does.
     *
     * @param \DOMElement $input The `<input>` element to test.
     *
     * @return bool Whether it is a checkbox.
     */
    protected function isCheckboxInput(DOMElement $input): bool
    {
        return strtolower($input->getAttribute('type')) === 'checkbox';
    }

    /**
     * Remove checkbox input from content when processing task list items
     */
    protected function processListItemContent(DOMElement $li, bool $isTaskList): string
    {
        $content = '';
        foreach ($li->childNodes as $child) {
            // Skip checkbox inputs in task lists
            if ($isTaskList && $child instanceof DOMElement) {
                if ($child->tagName === 'input' && $this->isCheckboxInput($child)) {
                    continue;
                }
            }
            $content .= $this->processNode($child);
        }

        return $content;
    }

    protected function processTable(DOMElement $node): string
    {
        if ($this->listTableForBlockCells && $this->tableHasBlockContentCell($node)) {
            return $this->processTableAsListTable($node);
        }

        $rows = [];
        $headerRow = null;
        $headerRowAttrs = '';
        $headerCells = [];
        /** @var array<int, true> $headerAttributedCells */
        $headerAttributedCells = [];
        /** @var array<int, string> $headerPrefixes */
        $headerPrefixes = [];
        $columnCount = 0;
        $captionText = '';
        $alignments = [];

        // Find caption element if present
        $captionElement = $this->findFirstDirectChildByTagName($node, 'caption');
        if ($captionElement instanceof DOMElement) {
            $captionText = trim($this->processCaptionChildren($captionElement));
        }

        // Find all rows
        $trElements = $this->getDirectTableRows($node);

        // Whether this table will spell a column's alignment in a marker run at
        // all. Only a first row of ALL header cells is promoted to the head,
        // and only a promoted head carries the markers - so in every other
        // table the collected alignments are written nowhere, and a cell that
        // relied on them lost its alignment in silence
        // (markup-carve/carve#1741). Asked here rather than at the write site
        // because a cell's attributes are serialized before the promotion is
        // decided.
        $emitsColumnAlignment = $this->tableHeadTakesTheAlignmentMarkers($trElements);
        // Whether the head will be written in the CANONICAL `|=` form, whose
        // marker run holds both axes. The separator form can only spell the
        // horizontal one, so a header cell's vertical alignment has to ride its
        // own prefix there instead.
        $emitsColumnValign = $emitsColumnAlignment
            && $node->getAttribute('data-djot-col-widths') === ''
            && !$this->tableHeadHasASpanMarker($trElements);
        $valignments = [];

        // $rowspanMap[colIndex] = number of remaining rows that col is spanned
        // Used to inject `^` continuation markers at the right positions.
        /** @var array<int, int> $rowspanMap */
        $rowspanMap = [];

        foreach ($trElements as $tr) {
            $cells = [];
            // Indexes into $cells whose string opens with an attribute block
            // THIS converter wrote. Kept rather than re-sniffed off the string,
            // so a cell whose content merely starts with a brace is not glued.
            $attributedCells = [];
            // The marker run each cell carries of its own - its alignment, its
            // vertical alignment, or both - keyed the same way. Kept beside the
            // cell strings rather than glued into them, because the header
            // builder and the row builder place a run differently.
            /** @var array<int, string> $cellPrefixes */
            $cellPrefixes = [];
            // Indexes into $cells the source wrote as `th`. Header is a
            // property of the CELL, not of the row: a row-head column is one
            // `th` beside ordinary data cells, and `|= R | 1 |` spells exactly
            // that. Reading it off the row instead promoted every cell in the
            // row to a header, and dropped the header from every `th` outside
            // the one row that got promoted.
            /** @var array<int, true> $headerFlags */
            $headerFlags = [];
            $realCells = 0;
            $headerCellCount = 0;

            // Logical column index, accounting for positions already occupied
            // by ongoing rowspans from previous rows.
            $logicalCol = 0;

            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($cell->tagName);
                if ($tag !== 'th' && $tag !== 'td') {
                    continue;
                }

                // Advance past columns that are still occupied by a rowspan
                // from a previous row, injecting `^` markers for each.
                while (isset($rowspanMap[$logicalCol]) && $rowspanMap[$logicalCol] > 0) {
                    $cells[] = '^';
                    $rowspanMap[$logicalCol]--;
                    if ($rowspanMap[$logicalCol] === 0) {
                        unset($rowspanMap[$logicalCol]);
                    }
                    $logicalCol++;
                }

                $colspan = max(1, (int)$cell->getAttribute('colspan'));
                $rowspan = max(1, (int)$cell->getAttribute('rowspan'));

                // Serialize content, excluding colspan/rowspan from cell attributes.
                $cellContent = $this->serializeTableCellContent($cell);
                $cellSkip = $this->tableCellSkipAttributes($cell);
                $cellAlignment = $this->extractTableCellAlignment($cell);
                $cellValign = $this->extractTableCellValign($cell);
                // THE COLUMN MARKER TAKES WHAT IT CAN. A pipe table spells a
                // column's alignment in the header cell's marker run, and the
                // cells below inherit what they do not state - so a cell
                // repeating its column's value would say a thing the document
                // already says, and the round trip would grow a marker on every
                // body row on each pass through HTML. A cell that DISAGREES
                // keeps its own run, because that is the only thing overriding
                // the column.
                //
                // AND `safe` WRITES NO RUN OF ITS OWN. The conservative mode
                // maps no CSS onto a cell (markup-carve/carve#1741), so a cell
                // the column does not cover loses its alignment there and says
                // so. The COLUMN marker is not this mapping and is not gated:
                // it is how a pipe table spells a column, and a sidecar-less
                // `carve -> html -> carve` reconstruction is pinned on it in
                // the default mode (markup-carve/carve#1344).
                $ownAlignment = $this->importMode === 'safe' || ($emitsColumnAlignment
                    && ($alignments[$logicalCol] ?? $cellAlignment) === $cellAlignment)
                    ? TableCell::ALIGN_DEFAULT
                    : $cellAlignment;
                $ownValign = $this->importMode === 'safe' || ($emitsColumnValign
                    && ($valignments[$logicalCol] ?? $cellValign) === $cellValign)
                    ? ''
                    : $cellValign;
                $cellPrefixes[count($cells)] = $this->tableCellMarkerRun($ownAlignment, $ownValign);
                $cellAttrs = $this->getElementAttributes($cell, $cellSkip);
                if ($tag === 'th') {
                    // Every `th` gets the marker now. PART 9 §5 T10 binds the
                    // attribute block AFTER the marker run, so `|={#x} R |`
                    // spells an attributed header cell; before it, the only
                    // available shape was `|{#x}= R |`, whose `=` reads as
                    // content, and the marker had to be dropped.
                    $headerFlags[count($cells)] = true;
                }
                if ($cellAttrs !== '') {
                    $attributedCells[count($cells)] = true;
                    $cells[] = '{' . $cellAttrs . '} ' . $cellContent;
                } else {
                    $cells[] = $this->escapeSpanMarkerCellPayload($cellContent);
                }

                $realCells++;
                if ($tag === 'th') {
                    $headerCellCount++;
                }
                if (!isset($alignments[$logicalCol])) {
                    $alignments[$logicalCol] = $cellAlignment;
                }
                if (!isset($valignments[$logicalCol])) {
                    $valignments[$logicalCol] = $cellValign;
                }

                // Register rowspan: only the origin column gets `^` in subsequent rows.
                // The colspan continuation columns (`<`) are not extended by the rowspan;
                // those positions in later rows are filled by real cells.
                if ($rowspan > 1) {
                    $rowspanMap[$logicalCol] = ($rowspanMap[$logicalCol] ?? 0) + ($rowspan - 1);
                }

                $logicalCol++;

                // Emit `<` continuation markers for each extra colspan column.
                for ($cs = 1; $cs < $colspan; $cs++) {
                    $cells[] = '<';
                    $logicalCol++;
                }
            }

            // Flush any trailing rowspan `^` markers for columns after the last
            // real cell in this row (e.g. a row where ALL cells are rowspan continuations).
            while (isset($rowspanMap[$logicalCol]) && $rowspanMap[$logicalCol] > 0) {
                $cells[] = '^';
                $rowspanMap[$logicalCol]--;
                if ($rowspanMap[$logicalCol] === 0) {
                    unset($rowspanMap[$logicalCol]);
                }
                $logicalCol++;
            }

            if ($cells) {
                $columnCount = max($columnCount, count($cells));

                // Get row attributes
                $rowAttrs = $this->getElementAttributes($tr);
                $rowAttrSuffix = $rowAttrs !== '' ? '{' . $rowAttrs . '}' : '';

                // Only a row whose cells are ALL headers is a header row, and
                // only the FIRST row emitted can be promoted to the head. The
                // old rule took the first row holding ANY `th` and moved it to
                // the top, so a table whose third row carried one came out with
                // that row first - the rows themselves reordered.
                $isHeaderRow = $realCells > 0 && $headerCellCount === $realCells;

                if ($isHeaderRow && $headerRow === null && $rows === []) {
                    // The promoted row's cells are written bare: in the
                    // delimiter form the row after it is what makes them
                    // headers, and in the `|=` form the builder below writes
                    // the marker itself.
                    $headerRow = $this->buildTableRowLine($cells, $attributedCells, [], $cellPrefixes) . $rowAttrSuffix;
                    $headerRowAttrs = $rowAttrSuffix;
                    $headerCells = $cells;
                    $headerAttributedCells = $attributedCells;
                    $headerPrefixes = $cellPrefixes;
                } else {
                    $rows[] = $this->buildTableRowLine($cells, $attributedCells, $headerFlags, $cellPrefixes) . $rowAttrSuffix;
                }
            }
        }

        // Table-level attributes (excluding data-djot-col-widths which is for round-trip)
        $tableAttrs = $this->formatBlockAttributes($node, ['data-djot-col-widths']);
        // Ends its own line - see the note in `processList()` (carve-php#1653).
        // The empty case keeps the newline it has always opened with.
        $output = $tableAttrs !== '' ? $tableAttrs : "\n";

        if ($headerRow !== null) {
            $colWidthsAttr = $node->getAttribute('data-djot-col-widths');

            // Fall back to the separator form when the header has span markers
            // (`<`/`^`), because `|= < |` is not valid Carve syntax for a
            // colspan continuation. An attributed header cell no longer needs
            // the fallback: T10 spells it as `|={#x} R |`.
            $headerHasSpanMarkers = false;
            foreach ($headerCells as $hc) {
                if ($hc === '<' || $hc === '^') {
                    $headerHasSpanMarkers = true;

                    break;
                }
            }

            if ($colWidthsAttr === '' && !$headerHasSpanMarkers) {
                // Canonical Carve: `|=` header cells (alignment via `<`/`>`/`~`
                // markers on the header cell), no separator row. Used unless the
                // source was a GFM table (recorded via data-djot-col-widths).
                $headerLine = '|';
                foreach ($headerCells as $i => $cell) {
                    $marker = $this->tableCellMarkerRun(
                        $alignments[$i] ?? TableCell::ALIGN_DEFAULT,
                        $valignments[$i] ?? '',
                    );
                    // The block is already at the head of an attributed cell's
                    // string and glues to the marker run (T10), so that cell
                    // takes no separating space here.
                    $headerLine .= '=' . $marker . ($headerPrefixes[$i] ?? '')
                        . (isset($headerAttributedCells[$i]) ? '' : ' ') . $cell . ' |';
                }
                $output .= $headerLine . $headerRowAttrs . "\n";
            } else {
                $output .= $headerRow . "\n";

                // Use original separator widths if available for round-trip
                $separator = [];
                if ($colWidthsAttr !== '') {
                    $colWidths = array_map('intval', explode(',', $colWidthsAttr));
                    foreach ($colWidths as $width) {
                        // Use exact width from original for round-trip fidelity
                        $separator[] = $this->buildTableSeparator($width, $alignments[count($separator)] ?? TableCell::ALIGN_DEFAULT);
                    }
                    // Fill remaining columns with default width
                    $separatorCount = count($separator);
                    while ($separatorCount < $columnCount) {
                        $separator[] = $this->buildTableSeparator(3, $alignments[$separatorCount] ?? TableCell::ALIGN_DEFAULT);
                        $separatorCount++;
                    }
                } else {
                    for ($i = 0; $i < $columnCount; $i++) {
                        $separator[] = $this->buildTableSeparator(3, $alignments[$i] ?? TableCell::ALIGN_DEFAULT);
                    }
                }

                $output .= '|' . implode('|', $separator) . '|' . "\n";
            }
        }

        $output .= implode("\n", $rows) . "\n";

        // Add caption after table
        if ($captionText !== '') {
            $output .= $this->formatCaptionText($captionText);
        }

        return $output . "\n";
    }

    /**
     * Does any cell hold content a pipe-table cell cannot express?
     *
     * A pipe cell is one line of inline content. Two or more paragraphs, a
     * list, a code block, a blockquote or a nested table all need their own
     * lines. A SINGLE paragraph does not count: a list-table collapses that to
     * inline content anyway (extensions §5.2), so it is not a reason to leave
     * the Tier-1 form.
     */
    protected function tableHasBlockContentCell(DOMElement $table): bool
    {
        foreach ($this->getDirectTableRows($table) as $row) {
            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    continue;
                }

                $paragraphs = 0;

                foreach ($cell->getElementsByTagName('*') as $descendant) {
                    $tag = strtolower($descendant->tagName);

                    if (in_array($tag, ['ul', 'ol', 'pre', 'blockquote', 'table', 'dl'], true)) {
                        return true;
                    }

                    if ($tag === 'p' && ++$paragraphs > 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Write the table as a `::: list-table` div.
     *
     * Rows are the outer list items and cells the inner ones, so a cell is a
     * list item and holds block content for free. The span markers are the
     * pipe-table ones - a lone `^` merges upward, a lone `<` leftward
     * (extensions §5.1) - so the colspan/rowspan bookkeeping is the same
     * question answered in the same spelling, just written as items.
     *
     * `{header-rows}` / `{header-cols}` sit on the line BEFORE the opener: a
     * trailing attribute block on `:::` would make the whole div literal.
     */
    protected function processTableAsListTable(DOMElement $node): string
    {
        $captionElement = $this->findFirstDirectChildByTagName($node, 'caption');
        $caption = $captionElement instanceof DOMElement
            ? trim($this->processCaptionChildren($captionElement))
            : '';

        $rows = [];
        $headerRows = 0;
        $headerCols = null;
        $sawBodyRow = false;

        foreach ($this->getDirectTableRows($node) as $row) {
            $cells = [];
            $rowIsAllHeader = true;
            $leadingHeaderCells = 0;
            $countingLeaders = true;

            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    continue;
                }

                $isHeaderCell = strtolower($cell->tagName) === 'th';
                $rowIsAllHeader = $rowIsAllHeader && $isHeaderCell;

                if ($countingLeaders && $isHeaderCell) {
                    $leadingHeaderCells++;
                } else {
                    $countingLeaders = false;
                }

                $cells[] = [
                    // ONE LEVEL DEEPER, because the cell's content sits inside
                    // the `::: list-table` fence this method is about to open
                    // (see {@see colonFenceFor()}): a container written in a
                    // cell needs the inward-widening width of its own depth,
                    // not of the document's (raised by codex review).
                    'content' => $this->insideColonFence(fn (): string => $this->listTableCellContent($cell)),
                    'attributes' => $this->getElementAttributes($cell, $this->tableCellSkipAttributes($cell)),
                    'colspan' => max(1, (int)$cell->getAttribute('colspan')),
                    'rowspan' => max(1, (int)$cell->getAttribute('rowspan')),
                ];
            }

            if ($cells === []) {
                continue;
            }

            // Only a run of header rows at the TOP is `header-rows`; a `<th>`
            // further down is an ordinary cell as far as this attribute goes.
            if ($rowIsAllHeader && !$sawBodyRow) {
                $headerRows++;
            } else {
                $sawBodyRow = true;
                // `header-cols` is the count every BODY row agrees on.
                $headerCols = $headerCols === null
                    ? $leadingHeaderCells
                    : min($headerCols, $leadingHeaderCells);
            }

            $rows[] = $cells;
        }

        if ($rows === []) {
            return '';
        }

        $attributes = [];

        // The table's OWN attributes belong on this block too. ListTable passes
        // non-structural attributes through to the rendered `<table>`, so a
        // class or id the author wrote is carried rather than dropped on the
        // way into this form (raised by codex review).
        $tableAttributes = $this->getElementAttributes($node, ['data-djot-col-widths']);
        if ($tableAttributes !== '') {
            $attributes[] = $tableAttributes;
        }

        if ($headerRows > 0) {
            $attributes[] = 'header-rows=' . $headerRows;
        }
        if ($headerCols !== null && $headerCols > 0) {
            $attributes[] = 'header-cols=' . $headerCols;
        }

        $fence = $this->colonFenceFor();
        $output = $attributes === [] ? '' : '{' . implode(' ', $attributes) . "}\n";
        $output .= $fence . ' list-table' . ($caption === '' ? '' : ' "' . str_replace('"', '\\"', $caption) . '"') . "\n";
        $output .= $this->listTableRows($rows);

        return $output . $fence . "\n\n";
    }

    /**
     * The nested list: one outer item per row, one inner item per cell.
     *
     * @param array<int, array<int, array{content: string, attributes: string, colspan: int, rowspan: int}>> $rows
     */
    protected function listTableRows(array $rows): string
    {
        // [column => remaining rows spanned], so a `^` lands in the column the
        // rowspan actually occupies rather than at the end of the row.
        $rowspanMap = [];
        $output = '';

        foreach ($rows as $cells) {
            $items = [];
            $column = 0;

            foreach ($cells as $cell) {
                while (isset($rowspanMap[$column])) {
                    $items[] = '^';
                    if (--$rowspanMap[$column] === 0) {
                        unset($rowspanMap[$column]);
                    }
                    $column++;
                }

                // The cell's own attributes are NOT written. Carve has no
                // per-list-item attribute spelling this converter could find -
                // `{.c}` on its own line before an item attaches to the LIST,
                // and after the marker it is literal text - so emitting one
                // put the class on the cell's first PARAGRAPH instead of on
                // the cell. Dropping it is the smaller loss than moving it
                // somewhere it does not belong; see carve-php#1167.
                $items[] = $cell['content'];

                if ($cell['rowspan'] > 1) {
                    $rowspanMap[$column] = ($rowspanMap[$column] ?? 0) + ($cell['rowspan'] - 1);
                }
                $column++;

                for ($span = 1; $span < $cell['colspan']; $span++) {
                    $items[] = '<';
                    $column++;
                }
            }

            // A column still spanned AFTER the row's last real cell needs its
            // `^` as well. Without it the row simply ends, the span is lost and
            // the next row gains an empty cell instead (raised by codex review).
            while (isset($rowspanMap[$column])) {
                $items[] = '^';
                if (--$rowspanMap[$column] === 0) {
                    unset($rowspanMap[$column]);
                }
                $column++;
            }

            $output .= $this->listTableRow($items);
        }

        return $output;
    }

    /**
     * One row. The first cell opens both lists on one line, every later cell is
     * an inner item indented under it, and a cell's continuation lines are
     * indented to that inner item's content column.
     *
     * @param array<int, string> $items
     */
    protected function listTableRow(array $items): string
    {
        $output = '';

        foreach ($items as $index => $item) {
            $marker = $index === 0 ? '- - ' : '  - ';
            $lines = explode("\n", rtrim($item));
            $output .= $marker . array_shift($lines) . "\n";

            foreach ($lines as $line) {
                $output .= ($line === '' ? '' : '    ' . $line) . "\n";
            }
        }

        return $output;
    }

    /**
     * A cell's own content, as blocks rather than as one collapsed line.
     */
    protected function listTableCellContent(DOMElement $cell): string
    {
        $hasBlockChildren = false;

        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), $this->blockElements, true)) {
                $hasBlockChildren = true;

                break;
            }
        }

        $content = $hasBlockChildren ? $this->processBlock($cell) : trim($this->processChildren($cell));

        return trim($content) === '' ? '' : trim($content);
    }

    /**
     * @return list<\DOMElement>
     */
    protected function getDirectTableRows(DOMElement $table): array
    {
        $rows = [];

        foreach ($table->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'tr') {
                $rows[] = $child;

                continue;
            }

            if (!in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                continue;
            }

            foreach ($child->childNodes as $row) {
                if ($row instanceof DOMElement && strtolower($row->tagName) === 'tr') {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Assemble one table row line.
     *
     * A cell attribute block parses only when it is GLUED to the opening pipe:
     * PART 7 gives `data_cell` its own `{space}` run after the `|`, so a space
     * before the brace makes the whole thing ordinary content and the class is
     * rendered as the four visible characters `{.c}` (carve-php#1164). Cells
     * this converter gave an attribute block are therefore written without the
     * separating space, and every other cell keeps it - including one whose
     * CONTENT happens to start with a brace, which must stay content.
     *
     * @param array<int, string> $cells
     * @param array<int, true> $attributed Indexes of cells opening with an attribute block.
     * @param array<int, true> $header Indexes of cells the source wrote as `th`.
     * @param array<int, string> $prefixes The marker run each cell carries of its own.
     */
    protected function buildTableRowLine(array $cells, array $attributed, array $header = [], array $prefixes = []): string
    {
        $line = '|';

        foreach ($cells as $index => $cell) {
            // `|= x |` is a header cell wherever it stands: in the leading run
            // of header rows it is a column header, below it a row header. The
            // marker is glued to the pipe, the space goes after it - and an
            // attribute block glues to the marker in turn (PART 9 §5 T10), so
            // an attributed cell takes no space between the two.
            // THE MARKER RUN COMES AFTER THE KIND MARKER and before the
            // attribute block, which is the order the grammar binds them in
            // (PART 9 §5 T10): `|=>{.x} h |` is a right-aligned attributed
            // header cell, and any other order reads as content.
            $marker = isset($header[$index]) ? '=' : '';
            $line .= $marker . ($prefixes[$index] ?? '')
                . (isset($attributed[$index]) ? '' : ' ') . $cell . ' |';
        }

        return $line;
    }

    /**
     * How many table cells enclose the node being serialized.
     *
     * A pipe-table cell is one line of inline content, so a block construct
     * cannot open inside one: a div fence collapses onto the line and survives
     * as the literal text `::: x d :::`, and a block attribute block as a
     * literal `{.c}`. Both are suppressed while this is non-zero, which is
     * what an attribute-less div in a cell already did.
     *
     * @var int
     */
    protected int $tableCellDepth = 0;

    /**
     * How many colon-fenced containers enclose the node being serialized.
     *
     * The width of a container's fence is `3 + this`, which is the
     * inward-widening form `carve fmt` writes - see {@see colonFenceFor()} for
     * why the direction is not free for an importer even though both parse.
     *
     * @var int
     */
    protected int $colonFenceDepth = 0;

    /**
     * The Carve this conversion emitted, for the inspection walk to observe.
     *
     * Set by `convertWithReport()` around the walk and cleared in its `finally`
     * - the report is a statement about THIS conversion, and a value left
     * standing would let a later walk read the previous document's output.
     */
    protected ?string $inspectedCarve = null;

    /**
     * How many of each `name`/`value`/`content` triple the emitted document has.
     *
     * Keyed `name . "\0" . value . "\0" . content` and DECREMENTED as the walk
     * credits survivors to input occurrences, so it is a budget rather than a
     * lookup. Built once per conversion, on first demand, and only if the walk
     * reaches a represented attribute at all - a document without one never
     * pays for the render.
     *
     * @var array<string, int>|null
     */
    protected ?array $survivingImportAttributes = null;

    /**
     * How many elements of the emitted document carry each attribute value.
     *
     * Read off the same render as the budget above and spent the same way, so
     * one surviving element answers for exactly one input element. Kept apart
     * from that budget on purpose: the element questions must not consume a
     * survivor an attribute row is about to ask for.
     *
     * @var array<string, int>
     */
    protected array $emittedImportValues = [];

    /**
     * The element whose attributes are being inspected.
     *
     * Handed over through a property rather than an argument for the reason
     * `$inspectedCarve` is: `importAttributeSurvived()` is protected on a
     * non-final class, and widening its signature would make an existing
     * override a fatal incompatible-declaration error at class-declaration
     * time, which no test of behavior catches.
     */
    protected ?DOMElement $inspectedElement = null;

    /**
     * That element's content key, once something has asked for it.
     *
     * Null until the first attribute that is keyed by content, and reset for
     * every element - see `importElementContentKey()` for why it is not
     * computed up front.
     */
    protected ?string $inspectedElementContent = null;

    /**
     * Degrade a wrapper to its own content, keeping the boundary it carried.
     *
     * The wrapper goes but the block break it stood for must not: processBlock()
     * appends a block child's output directly and relies on the child having
     * ended itself. A paragraph does, which is why a `<p>` pair was never
     * affected and only the wrappers that degrade were - two `<div>`s came out
     * as one run of text, `ab`, at top level and `| a()b: |` in a table cell.
     *
     * One rule, two right answers. Outside a table the separator is a real
     * block break, which is what the author wrote. Inside a pipe-table cell the
     * cell serializer collapses it to a single space, because a pipe row is one
     * line and cannot hold a break at all - an explicit `<br>` there does not
     * survive as one either. So this is a separator rather than padding, and a
     * lone wrapper still trims to `| d |` (carve-php#1164).
     */
    protected function degradeToContent(DOMElement $node): string
    {
        $content = $this->processBlock($node);

        return $content === '' ? '' : $content . "\n\n";
    }

    protected function serializeTableCellContent(DOMElement $cell): string
    {
        $hasBlockChildren = false;

        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), $this->blockElements, true)) {
                $hasBlockChildren = true;

                break;
            }
        }

        $this->tableCellDepth++;

        try {
            $content = $hasBlockChildren ? $this->processBlock($cell) : $this->processChildren($cell);
        } finally {
            $this->tableCellDepth--;
        }

        $content = trim($content);

        $content = preg_replace('/\s+/', ' ', $content) ?? $content;

        return str_replace('|', '\|', $content);
    }

    /**
     * Take PART 9 §10's grouping `[label]` back off the `<p class="div-label">`
     * the renderer degraded it to, removing that paragraph from the container.
     *
     * A LABEL HAS NO SPELLING ANYWHERE BUT ON AN OPENER, so a container that
     * keeps its fence and leaves the label in its body has not round-tripped -
     * `::: note [g]` came back as `::: note` wrapping a `{.div-label}`
     * paragraph, and the document said something it never said
     * (markup-carve/carve-php#1661, ruled at markup-carve/carve-rs#1315).
     *
     * The same fact is half of the UNWRAP BOUNDARY on a bare `<div>`: a div
     * unwraps when it carries nothing only a container can hold, and a label is
     * exactly as much "only a container can hold it" as an attribute is. Which
     * is why the lift runs BEFORE that test rather than after it.
     *
     * FOUR REFUSALS, and each one is also a control on the boundary: when the
     * lift refuses, a bare div kept nothing and must still unwrap.
     *
     *  - NOT THE FIRST THING. The paragraph is found by scanning for the first
     *    ELEMENT, which is not the first thing in the container: text ahead of
     *    it would be REORDERED behind the label on the opener, which is the one
     *    thing a lift must never do. Whitespace between tags is not text an
     *    author wrote, so a pretty-printed container still lifts.
     *  - MARKUP INSIDE IT. The field is a raw string and the writer emits it
     *    raw, so lifting `<p class="div-label"><em>g</em></p>` would flatten the
     *    emphasis and lose it without a word.
     *  - A `]` OR A NEWLINE IN IT. Neither has a spelling inside a bracket run
     *    on an opener line.
     *  - AN ATTRIBUTE RIDING IT. carve-rs lifts and declares the loss; this
     *    importer writes source text in a pass with no diagnostics channel, so
     *    the same lift here would be an UNDECLARED loss. Refusing keeps the
     *    attribute on the paragraph the HTML actually has, which is the
     *    conservative direction: a refusal never invents.
     */
    protected function liftContainerLabel(DOMElement $node): ?string
    {
        $paragraph = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (trim($child->textContent) === '') {
                    continue;
                }

                return null;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            // The container's own TITLE was degraded the same way, and it is
            // written ahead of the label on the same opener, so it is scanned
            // past rather than treated as content in front of the label.
            if ($this->isDegradedContainerTitle($child)) {
                continue;
            }
            $paragraph = $child;

            break;
        }
        if ($paragraph === null || strtolower($paragraph->tagName) !== 'p') {
            return null;
        }
        if (!$this->hasClass($paragraph, 'div-label')) {
            return null;
        }
        foreach ($paragraph->childNodes as $child) {
            if (!$child instanceof DOMText) {
                return null;
            }
        }
        /** @var \DOMAttr $attr */
        foreach ($paragraph->attributes as $attr) {
            if ($attr->name !== 'class') {
                return null;
            }
        }
        if ($this->getElementClassList($paragraph) !== ['div-label']) {
            return null;
        }
        $label = $paragraph->textContent;
        if (str_contains($label, ']') || str_contains($label, "\n")) {
            return null;
        }

        $paragraph->parentNode?->removeChild($paragraph);

        return $label;
    }

    protected function findFirstDirectChildByTagName(DOMElement $node, string $tagName): ?DOMElement
    {
        $tagName = strtolower($tagName);

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === $tagName) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Get single block child element if div is a wrapper
     *
     * Returns the child element if:
     * - There is exactly one element child (ignoring whitespace text nodes)
     * - The child is a block-level element
     */
    protected function getSingleBlockChild(DOMElement $node): ?DOMElement
    {
        $elementChild = null;

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                // Allow whitespace-only text nodes
                if (trim($child->textContent) !== '') {
                    return null; // Has significant text content, not a wrapper
                }

                continue;
            }

            if ($child instanceof DOMElement) {
                if ($elementChild !== null) {
                    return null; // More than one element child
                }
                $elementChild = $child;
            }
        }

        // Check if the child is a block element
        if ($elementChild !== null) {
            $tag = strtolower($elementChild->tagName);
            if (in_array($tag, $this->blockElements, true)) {
                return $elementChild;
            }
        }

        return null;
    }

    protected function processDefinitionList(DOMElement $node): string
    {
        // Carve definition list: `:: term` for each term, `:  definition` for
        // each definition (grammar definition_term = "::", definition_body =
        // ":  "); a multi-line definition continues on three-space-indented
        // lines. dl-level attributes attach on a preceding block-attribute line.
        // (dt/dd-level attributes have no `::` representation and are dropped.)
        $dlAttrs = $this->formatBlockAttributes($node);
        // Ends its own line - see the note in `processList()` (carve-php#1653).
        // No empty-case newline here: this caller was already conditional, so
        // the only thing the extra one ever added was the blank line.
        $output = $dlAttrs;

        // A DROPPED ENTRY BREAKS THE LIST (markup-carve/carve#1636). Consecutive
        // `::` lines SHARE the description written below them, so dropping an
        // entry that writes nothing and continuing the same list hands the
        // surviving term the NEXT entry's description - an ADDITION, which no
        // row can declare and which the ceiling forbids outright. The separator
        // is a COMMENT LINE, the only construct that both renders nothing where
        // it stands and stays where it was written; a blank line neither ends a
        // definition list nor survives the canonical writer.
        //
        // Spent on a TERM and cleared on a description: what the break prevents
        // is a term ABOVE the drop acquiring a description written BELOW it, and
        // a second description of the SAME entry is not that. An unspent mark is
        // dropped, which is the one-entry shape markup-carve/carve#1627 ruled.
        $pendingBreak = false;
        foreach ($this->definitionListEntries($node) as $child) {
            $tag = strtolower($child->tagName);
            if ($tag === 'dt') {
                if ($pendingBreak) {
                    // THE MARK IS SET EITHER WAY and only the separator is
                    // conditional. The break exists because a DROPPED entry
                    // would hand the term above it the next entry's
                    // description; on the AST exit the description is not
                    // dropped, so there is nothing to break the list around -
                    // but the loss is still what the source exit takes, and the
                    // row that declares it has to read the same from both
                    // (`markup-carve/carve-php#1716`).
                    if (!$this->astExit) {
                        $output .= "\n%%\n\n";
                    }
                    $pendingBreak = false;
                    $this->splitDefinitionLists[$this->conversionNodePath($node)] = true;
                }
                $output .= ':: ' . trim($this->processChildren($child)) . "\n";
            } elseif ($tag === 'dd') {
                $description = trim($this->processChildren($child));
                if ($description === '') {
                    // A DESCRIPTION THAT WRITES NOTHING IS DROPPED, not spelled.
                    //
                    // Carve has no spelling for an empty `<dd>`: the bare colon
                    // line this used to write is read as a continuation of the
                    // line above it, so `<dl><dt>term</dt><dd></dd></dl>` came
                    // back as a `<dt>` reading `term\n:` - the description lost
                    // AND the term damaged - and an empty description with
                    // entries after it split the list in two around a stray
                    // `<p>:</p>`. Six spellings were probed on
                    // markup-carve/carve#1608 and every one leaks a colon into
                    // the text, folds into the term, or renders `&nbsp;`.
                    //
                    // `docs/html-import.md`, "A declared loss is a ceiling, not
                    // a licence": an import may lose what it declares and no
                    // more, so the description goes and the term stays.
                    //
                    // EMPTY IS WHAT WRITES NOTHING, not what holds nothing. A
                    // `<dd>` whose only child renders to a non-breaking space
                    // writes `:` and three spaces, which round-trips exactly,
                    // so it is not this case and keeps its line.
                    //
                    // RECORDED HERE so the row that declares the drop is
                    // decided by the writer that made it. See
                    // {@see self::$droppedDefinitionDescriptions}.
                    $this->droppedDefinitionDescriptions[$this->conversionNodePath($child)] = true;
                    $pendingBreak = true;

                    // THE AST EXIT LOSES NOTHING, so it does not take the
                    // source writer's ceiling (`markup-carve/carve-php#1716`).
                    //
                    // `docs/html-import.md` says it in as many words: for a
                    // structure Carve SOURCE cannot spell, "the AST-returning
                    // entry point loses nothing and reports nothing; the one
                    // that writes source reports this". This engine derives its
                    // tree from its own source, so without a way to carry the
                    // description across, the exit that is supposed to lose
                    // nothing lost exactly what the writer did - and the entry
                    // came back as a term with no description at all.
                    //
                    // The mark above is set EITHER WAY, so the report is the
                    // same on both exits, which is what the shared fixtures
                    // assert. Only the bytes differ, and only in a string no
                    // caller ever sees.
                    if ($this->astExit) {
                        $output .= ':  ' . self::EMPTY_DESCRIPTION . "\n";

                        continue;
                    }

                    continue;
                }

                $pendingBreak = false;
                $lines = explode("\n", $description);
                $output .= ':  ' . array_shift($lines) . "\n";
                foreach ($lines as $line) {
                    $output .= '   ' . $line . "\n";
                }
            }
        }

        return $output . "\n";
    }

    /**
     * Did writing this `<dl>` split it into more than one list?
     *
     * READ OFF THE WRITER'S OWN RECORD rather than re-derived here, so the ROW
     * and the SOURCE cannot answer differently. Whether an entry writes nothing
     * is a question about the RENDERED description - `<dd><p> </p></dd>` and
     * `<dd><ul></ul></dd>` both hold elements and both write nothing - and a
     * DOM-shaped predicate gets those two wrong, which would split the list and
     * declare nothing.
     */
    protected function definitionListSplits(string $path): bool
    {
        return isset($this->splitDefinitionLists[$path]);
    }

    /**
     * Did writing this `<dd>` drop it for writing nothing?
     *
     * Read off the writer's own record, for the reason
     * {@see self::definitionListSplits()} is: the question is about the
     * RENDERED description, and a DOM-shaped predicate answers it differently
     * from the writer on `<dd><p> </p></dd>` and `<dd><ul></ul></dd>`.
     */
    protected function definitionDescriptionIsDropped(string $path): bool
    {
        return isset($this->droppedDefinitionDescriptions[$path]);
    }

    /**
     * The `dt`/`dd` elements of a definition list, in document order.
     *
     * HTML5 gives `dl` two content models: term/definition elements as direct
     * children, or one `div` per group wrapping them. Reading only the direct
     * children saw nothing at all in the second form, so a div-wrapped list
     * converted to an empty document - every term and every definition gone,
     * with no diagnostic. Word, Google Docs and several editors emit the
     * wrapped form because it is the one CSS grid can style.
     *
     * The wrapper is unwrapped transparently: it groups rows for styling and
     * carries no meaning Carve's `::` form spells. Its attributes go the same
     * way `dt`/`dd` attributes already do, since neither has a representation
     * on a definition line.
     *
     * Only one level unwraps, which is the only level HTML5 allows. A `div`
     * nested inside the wrapper is not a group and its terms stay unread,
     * rather than the converter inventing a flattening the source did not say.
     *
     * @return list<\DOMElement>
     */
    protected function definitionListEntries(DOMElement $node): array
    {
        $entries = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'dt' || $tag === 'dd') {
                $entries[] = $child;

                continue;
            }
            if ($tag !== 'div') {
                continue;
            }
            foreach ($child->childNodes as $wrapped) {
                if (!$wrapped instanceof DOMElement) {
                    continue;
                }
                $wrappedTag = strtolower($wrapped->tagName);
                if ($wrappedTag === 'dt' || $wrappedTag === 'dd') {
                    $entries[] = $wrapped;
                }
            }
        }

        return $entries;
    }

    /**
     * The class configured for this element's inline `text-align`, or null when
     * the feature is off, the element carries no alignment, or the value has no
     * configured class (an unmapped value is dropped rather than guessed at).
     */
    protected function extractAlignmentClass(DOMElement $node): ?string
    {
        if ($this->alignmentClasses === []) {
            return null;
        }

        // Cells are handled by extractTableCellAlignment(), which maps alignment
        // onto the native separator markers. Adding a class as well would emit
        // the same information twice, in two different mechanisms.
        if ($node->tagName === 'td' || $node->tagName === 'th') {
            return null;
        }

        $style = $node->getAttribute('style');
        if ($style === '' || preg_match('/text-align\s*:\s*([A-Za-z-]+)/i', $style, $matches) !== 1) {
            return null;
        }

        return $this->alignmentClasses[strtolower($matches[1])] ?? null;
    }

    /**
     * The Carve attributes this element's inline CSS maps to.
     *
     * THE TEST IS THIS ENGINE'S OWN RENDERER. `text-align: right` maps because
     * `{align=right}` is written back as `align="right"` by the same renderer
     * that would otherwise have lost the declaration, so refusing it declared a
     * loss the engine did not have to take (markup-carve/carve#1741).
     *
     * WHAT STAYS UNMAPPED, and why the list is short:
     *
     * - `vertical-align` has a cell `valign` in the AST, but no importer maps
     *   it and the reference engine does not either, so mapping it HERE would
     *   trade one divergence for another. It is a ruling of its own.
     * - `text-align: justify` / `start` / `end` are outside Carve's alignment
     *   enum (`left`, `right`, `center`), so there is no value to write.
     * - `color`, `background`, `width`, `font-*` and the rest of CSS have no
     *   presentational attribute this converter writes and no Carve construct
     *   that carries them. Their loss is a real ceiling and stays reported.
     *
     * `safe` maps NOTHING, which {@see self::unmappedStyleDeclarations()}
     * answers for the report as well, so the two cannot disagree about what
     * went nowhere.
     *
     * @param \DOMElement $node
     *
     * @return array<string, string>
     */
    protected function mappedStyleAttributes(DOMElement $node): array
    {
        if ($this->isTableCell($node)) {
            // A CELL TAKES THE MARKER RUN, NOT AN ATTRIBUTE. `|>` is written
            // back as `style="text-align: right;"` and `{align=right}` as
            // `align="right"`, so only the marker returns the declaration the
            // import was handed - and only the marker keeps
            // `carve -> html -> carve -> html` stable
            // (markup-carve/carve#1745). processTable() reads the axes off the
            // cell itself.
            return [];
        }

        if ($this->extractAlignmentClass($node) !== null) {
            // The configured class already carries this element's alignment.
            // Writing the key as well would spell one source twice, in two
            // mechanisms - the same reason cells take the native marker instead
            // of a class.
            return [];
        }

        $mapped = [];
        foreach ($this->styleDeclarations($node->getAttribute('style')) as [$property, $value]) {
            if ($this->mappedStyleSlot($node, $property, $value) === 'align') {
                $mapped['align'] = $value;
            }
        }

        return $mapped;
    }

    /**
     * The Carve attribute names this element's inline CSS fills, whichever
     * mechanism carries them.
     *
     * Asked SEPARATELY from {@see self::mappedStyleAttributes()} because a
     * cell's axes reach the marker run rather than the attribute block, and a
     * presentational `align` / `valign` beside them still has to go: CSS beats
     * the presentational attribute in HTML, and keeping both would spell one
     * axis twice from one source.
     *
     * @param \DOMElement $node
     *
     * @return array<string, true>
     */
    protected function mappedStyleSlots(DOMElement $node): array
    {
        if (!$this->isTableCell($node) && $this->extractAlignmentClass($node) !== null) {
            return [];
        }

        $slots = [];
        foreach ($this->styleDeclarations($node->getAttribute('style')) as [$property, $value]) {
            $slot = $this->mappedStyleSlot($node, $property, $value);
            if ($slot !== null) {
                $slots[$slot] = true;
            }
        }

        return $slots;
    }

    protected function isTableCell(DOMElement $node): bool
    {
        $tag = strtolower($node->tagName);

        return $tag === 'td' || $tag === 'th';
    }

    /**
     * The property names in this element's inline CSS that reach nothing the
     * converter writes.
     *
     * ASKED OF THE NODE, not of the attribute string, because the alignment has
     * TWO destinations and only the node knows which one it took: the key-value
     * above, or the caller-configured class. A string-only version reported a
     * loss for a declaration the class had just carried.
     *
     * @param \DOMElement $node
     *
     * @return array<string>
     */
    protected function unmappedStyleDeclarations(DOMElement $node): array
    {
        $carriedByClass = !$this->isTableCell($node) && $this->extractAlignmentClass($node) !== null;
        $unmapped = [];
        foreach ($this->styleDeclarations($node->getAttribute('style')) as [$property, $value]) {
            if ($carriedByClass && $property === 'text-align') {
                continue;
            }
            if ($this->mappedStyleSlot($node, $property, $value) === null) {
                $unmapped[] = $property;
            }
        }

        return $unmapped;
    }

    /**
     * The Carve slot a CSS declaration reaches on this element, or null where
     * nothing in the language spells it.
     *
     * `vertical-align` is a CELL slot and nothing else. Carve has a cell
     * `valign` and the marker run writes it back as
     * `style="vertical-align: top;"`, but `valign` is an attribute HTML defines
     * for table cells alone - putting it on a paragraph would emit something no
     * reader honours, which looks like a mapping and is not one
     * (markup-carve/carve#1746).
     *
     * @param \DOMElement $node
     * @param string $property Already lowercased.
     * @param string $value Already lowercased.
     */
    protected function mappedStyleSlot(DOMElement $node, string $property, string $value): ?string
    {
        if ($this->importMode === 'safe') {
            return null;
        }

        if ($property === 'text-align') {
            return in_array($value, [TableCell::ALIGN_LEFT, TableCell::ALIGN_RIGHT, TableCell::ALIGN_CENTER], true)
                ? 'align'
                : null;
        }

        if ($property === 'vertical-align' && $this->isTableCell($node)) {
            return in_array($value, ['top', 'middle', 'bottom'], true) ? 'valign' : null;
        }

        return null;
    }

    /**
     * A `style` attribute split into lowercased property/value pairs.
     *
     * @param string $style
     *
     * @return array<array{0: string, 1: string}>
     */
    protected function styleDeclarations(string $style): array
    {
        $declarations = [];
        foreach (explode(';', $style) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon)));
            if ($property === '') {
                continue;
            }
            $declarations[] = [$property, strtolower(trim(substr($declaration, $colon + 1)))];
        }

        return $declarations;
    }

    protected function extractTableCellAlignment(DOMElement $cell): string
    {
        // NOT MODE-GATED, and that is deliberate. `safe` maps no CSS to an
        // ATTRIBUTE - markup-carve/carve#1741 says so and
        // `mappedStyleDeclarationValue()` implements it - but the column marker
        // is not an attribute mapping. It is how a pipe table SPELLS a column,
        // it round-trips the declaration byte for byte, and
        // `TheHtmlRoundTripWithoutTheSidecarTest` pins a sidecar-less
        // `carve -> html -> carve` on it in the default mode
        // (markup-carve/carve#1344). Gating it here dropped that reconstruction.
        $style = $cell->getAttribute('style');
        if ($style !== '' && preg_match('/text-align\s*:\s*(left|right|center)\s*;?/i', $style, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return TableCell::ALIGN_DEFAULT;
    }

    /**
     * Whether this table's head will carry the column alignment markers.
     *
     * THE SAME RULE THE PROMOTION USES: only the first row holding cells is a
     * candidate, and only a row whose cells are ALL headers is promoted. A
     * table that promotes nothing writes no marker run and no separator row, so
     * a column alignment collected from its cells has nowhere to go - which is
     * why the cell keeps its own `{align=...}` there instead.
     *
     * @param array<\DOMElement> $trElements
     */
    protected function tableHeadTakesTheAlignmentMarkers(array $trElements): bool
    {
        foreach ($trElements as $tr) {
            $cells = 0;
            $headers = 0;
            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($cell->tagName);
                if ($tag !== 'th' && $tag !== 'td') {
                    continue;
                }
                $cells++;
                if ($tag === 'th') {
                    $headers++;
                }
            }
            if ($cells === 0) {
                // A row with no cells is not emitted, so the NEXT one is still
                // the candidate - the same reason the promotion tests
                // `$rows === []` rather than the row's index.
                continue;
            }

            return $headers === $cells;
        }

        return false;
    }

    /**
     * The vertical alignment this cell's inline CSS states, or `''` for none.
     *
     * A SIBLING OF {@see self::extractTableCellAlignment()}, and mapped for the
     * same reason: Carve has a cell `valign`, the marker run writes it back as
     * `style="vertical-align: top;"`, and refusing it declared a loss the
     * engine did not have to take (markup-carve/carve#1746).
     */
    protected function extractTableCellValign(DOMElement $cell): string
    {
        // Unlike the horizontal axis this is NOT carried in `safe`. Nothing
        // shipped depends on it, so it follows the ruling rather than the
        // exception the horizontal one inherited.
        if ($this->importMode === 'safe') {
            return '';
        }

        $style = $cell->getAttribute('style');
        if ($style !== '' && preg_match('/vertical-align\s*:\s*(top|middle|bottom)\s*;?/i', $style, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return '';
    }

    /**
     * The marker run a cell carrying these axes is written with.
     *
     * `?` IS THE INHERITED HORIZONTAL. The vertical marker only exists in the
     * second position of the run, so a cell stating only a vertical alignment
     * needs something in the first - and a bare `|^` is not that: it reads as
     * cell content and comes back as the literal text `^ a`. `|~` alone is the
     * CENTER horizontal marker, not a vertical one, which is the other way the
     * run is easy to misspell.
     */
    protected function tableCellMarkerRun(string $alignment, string $valign): string
    {
        $align = $this->tableAlignMarker($alignment);
        if ($valign === '') {
            return $align;
        }

        $vertical = match ($valign) {
            'top' => '^',
            'middle' => '~',
            'bottom' => 'v',
            default => '',
        };

        return ($align === '' ? '?' : $align) . $vertical;
    }

    /**
     * Whether the head row would be written with a span marker, which is what
     * sends the table to the separator form: `|= < |` is not valid Carve for a
     * colspan continuation, so the head cannot take the `|=` shape.
     *
     * Asked of the SOURCE rather than of the built cells, because a cell's
     * marker run has to be decided before the row is built.
     *
     * @param array<\DOMElement> $trElements
     */
    protected function tableHeadHasASpanMarker(array $trElements): bool
    {
        foreach ($trElements as $tr) {
            $cells = 0;
            $spanning = false;
            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($cell->tagName);
                if ($tag !== 'th' && $tag !== 'td') {
                    continue;
                }
                $cells++;
                if ((int)$cell->getAttribute('colspan') > 1) {
                    $spanning = true;
                }
            }
            if ($cells === 0) {
                continue;
            }

            return $spanning;
        }

        return false;
    }

    protected function buildTableSeparator(int $width, string $alignment): string
    {
        // Width represents the number of dashes in the separator
        // For alignment markers, we add colons around the dashes
        // Minimum is 1 dash for center, 2 for others (Djot allows 2-dash separators)
        return match ($alignment) {
            TableCell::ALIGN_LEFT => ':' . str_repeat('-', max(2, $width)),
            TableCell::ALIGN_RIGHT => str_repeat('-', max(2, $width)) . ':',
            TableCell::ALIGN_CENTER => ':' . str_repeat('-', max(1, $width)) . ':',
            default => str_repeat('-', max(2, $width)),
        };
    }

    /**
     * The tight alignment marker glued to a `|=` header cell: `<` left,
     * `>` right, `~` center, empty for default.
     */
    /**
     * The parser that reads a cell, so this converter can ask it what a cell it
     * is about to write would come back as.
     *
     * @var \MarkupCarve\Carve\Parser\Block\TableParser|null
     */
    protected ?TableParser $cellReader = null;

    /**
     * Escape a cell payload that would otherwise re-read as a SPAN MARKER.
     *
     * PADDING IS NOT AN ESCAPE WHERE THE PRODUCTION ADMITS PADDING (PART 11
     * §6f). §6e's one space in front of the content puts it out of reach of the
     * three slots that are read GLUED to the opening pipe, and that argument
     * holds only where the construct forbids the padding. The span cell is
     * written WITH the padding inside it:
     *
     *     span_cell = rowspan_marker | colspan_marker ;
     *     rowspan_marker = {space}, '^', {space} ;
     *     colspan_marker = {space}, '<', {space} ;
     *
     * so a cell whose whole payload is `^` or `<` re-reads as a span however it
     * is padded, and §2 is what applies: omitting the escape changes the
     * re-parsed AST, so the payload is escaped.
     *
     * IT LOSES THE CELL, not a byte of spelling, which is why this is a §1
     * failure rather than an under-escaped character. An ordinary
     * `<td>^</td>` under a two-cell row came back as `| ^ |`, and the cell
     * ABOVE it grew a `rowspan="2"` while the caret's own cell was deleted
     * outright. In the GFM separator form the same payload in the header row
     * emits `<th></th>` and the caret is simply gone.
     *
     * THE TEST, NOT THE TWO CHARACTERS: the payload is handed to the parser's
     * OWN span-marker predicates rather than compared against a list here.
     * Naming `^` and `<` in this file would be an enumeration that goes stale
     * the next time a cell-level marker is added, and §6e's history is what
     * that costs - each writer answered the alignment-sigil class with its own
     * slightly different set of characters.
     *
     * ONLY A CONTENT-DERIVED PAYLOAD REACHES THIS. The `^` and `<` this
     * converter writes for a real `rowspan` / `colspan` are pushed onto the row
     * directly and are markers on purpose; escaping those would destroy the
     * span the HTML actually held. An ATTRIBUTED cell is not asked either, for
     * the reason the parser does not ask it: an attribute block ahead of the
     * payload already makes the cell content.
     *
     * A cell emitted with the glued `=` header marker does not NEED the escape
     * - `|= ^ |` is already a header cell holding a caret - and it gets one
     * anyway, because which of the two header forms a row will take is decided
     * after the cells are built. That spends one idle escape on a rare cell and
     * renders identically; the other direction deletes the cell.
     *
     * @param string $payload
     */
    protected function escapeSpanMarkerCellPayload(string $payload): string
    {
        $this->cellReader ??= new TableParser();
        if (
            !$this->cellReader->isRowspanMarker($payload)
            && !$this->cellReader->isColspanMarker($payload)
        ) {
            return $payload;
        }

        return str_replace(['^', '<'], ['\^', '\<'], $payload);
    }

    protected function tableAlignMarker(string $alignment): string
    {
        return match ($alignment) {
            TableCell::ALIGN_LEFT => '<',
            TableCell::ALIGN_RIGHT => '>',
            TableCell::ALIGN_CENTER => '~',
            default => '',
        };
    }

    protected function processSpan(DOMElement $node): string
    {
        // Check for escaped text (round-trip support)
        if ($node->hasAttribute('data-djot-escaped')) {
            return '\\' . $node->textContent;
        }

        // Check for raw inline content (round-trip support). Only honor it for
        // trusted input: `data-djot-raw="html"` emits a `{=html}` raw-inline
        // verbatim, so untrusted HTML could smuggle live <script> through it.
        if ($node->hasAttribute('data-djot-raw') && $this->trustedRoundTrip) {
            return $this->processRawInline($node);
        }

        // The engine's own mention and hashtag output:
        // <span class="mention"><strong>@alice</strong></span> and the same
        // shape with class="tag" around #tag. Imported as an attributed span
        // it double-wrapped on reparse - the inner @name parsed as a mention
        // again, adding a layer per HTML round trip (carve-php#1291). The bare
        // sigil spelling is exactly what the renderer re-emits.
        $mentionClass = ['mention' => '@', 'tag' => '#'];
        $class = trim($node->getAttribute('class'));
        if (isset($mentionClass[$class])) {
            $text = trim($node->textContent);
            if (
                str_starts_with($text, $mentionClass[$class])
                && preg_match('/^[@#][\w-]+$/u', $text) === 1
            ) {
                return $text;
            }
        }

        // The engine's own math output: <span class="math inline">\(x\)</span>,
        // which is also what djot.js and pandoc write. Imported as an attributed
        // span the equation stopped being an equation - the delimiters became
        // literal text a re-render escapes as prose, so a typesetter has nothing
        // left to find (carve-php#1543). Math carries no active content, so
        // `safe` maps it exactly as `semantic` and `roundtrip` do.
        $math = $this->mathDelimitedContent($node, 'span');
        if ($math !== null) {
            return $this->renderMath($math['content'], $math['display'])
                . $this->mathAttributeSuffix($node, $math['classes']);
        }

        $content = $this->processChildren($node);

        // Use getElementAttributes to get all attributes including data-*
        $attrs = $this->getElementAttributes($node);

        // If span has any attributes, convert to Djot span syntax
        if ($attrs !== '') {
            return '[' . $this->escapeNoteReferenceLabel($content) . ']{' . $attrs . '}';
        }

        return $content;
    }

    /**
     * Process raw inline span (with data-djot-raw) for round-trip
     */
    protected function processRawInline(DOMElement $node): string
    {
        $format = $node->getAttribute('data-djot-raw');

        // For HTML format, get the innerHTML (raw HTML content)
        // For other formats, get the text content (was HTML-escaped)
        if ($format === 'html') {
            $content = $this->getInnerHtml($node);
        } else {
            $content = $node->textContent;
        }

        // Find the appropriate backtick fence
        $backticks = StringUtil::findSafeCodeFence($content, 1);

        return $backticks . $content . $backticks . '{=' . $format . '}';
    }

    /**
     * The element kept BYTE FOR BYTE, where `roundtrip` is the mode and Carve
     * has no construct for it (`markup-carve/carve-php#1713`).
     *
     * `roundtrip` exists to be faithful, and this engine was the only one of
     * the three that answered `<iframe>`, `<math>` and `<form>` by dropping,
     * unwrapping or degrading them - data loss in the mode whose whole contract
     * is fidelity. `docs/html-import.md` calls preserving a MAY, which made
     * that permitted rather than right.
     *
     * THE RULE IS DERIVED, NOT A ROSTER. An element reaches here only from the
     * arms that had given up - the dispatch's `default`, and a `<math>` with no
     * TeX in it - so what preserves is exactly what this converter has no
     * spelling for. A tag that gains a mapping later stops reaching this method
     * without anyone having to remember to take it off a list.
     *
     * `null` where the mode is not `roundtrip`, so the caller keeps the answer
     * it always gave: `safe` and `semantic` read untrusted HTML and must not
     * hand raw markup back.
     *
     * THE PATH IS RECORDED because this engine reports by walking the DOM a
     * second time rather than by watching the conversion, and that walk cannot
     * see which elements were kept. `inspectImportNode()` reads the record: it
     * writes `raw-preserved`, restates the element's own refused attributes as
     * `attribute-preserved`, and does not descend - the rows from inside an
     * element that was kept whole would name losses that did not happen.
     */

    /**
     * Does this `<figcaption>` spell anything at all?
     *
     * A CAPTION IS WHAT MAKES A FIGURE (PART 9 §4b), so a wrapper whose caption
     * contributes nothing is not a figure to preserve or to rebuild.
     *
     * ASKED OF THE DOM, and that is not a style choice: the obvious test is to
     * convert the caption and look at the result, and
     * `processCaptionChildren()` RECORDS DIAGNOSTICS as it runs. Asking it here
     * and again on the real path reported every flattened element twice, so a
     * figure whose caption held a list gained three rows for a conversion that
     * happened once (`markup-carve/carve-php#1713`).
     */
    protected function captionSpellsSomething(DOMElement $caption): bool
    {
        foreach ($caption->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
            if ($child instanceof DOMText && !$this->isLayoutOnlyText($child->textContent)) {
                return true;
            }
        }

        return false;
    }

    protected function preservedAsRawHtml(DOMElement $node): ?string
    {
        if ($this->importMode !== 'roundtrip') {
            return null;
        }
        $tag = strtolower($node->tagName);
        // A CELL CANNOT HOLD A BLOCK, and a raw HTML block is one. Inside a
        // table cell every other block already degrades to its content, so the
        // inline span is the answer there for both shapes.
        $block = in_array($tag, self::RAW_PRESERVED_BLOCK_ELEMENTS, true) && $this->tableCellDepth === 0;
        $this->rawPreservedElements[$this->conversionNodePath($node)] = true;
        if (!$block) {
            return $this->processRawHtmlInlineElement($node);
        }

        $clone = $node->cloneNode(true);
        if ($clone instanceof DOMElement) {
            $this->stripDjotDataAttributes($clone);
        }
        $html = $clone instanceof DOMElement ? $clone->ownerDocument?->saveHTML($clone) : null;
        $html = is_string($html) ? rtrim($html, "\n") : '';
        $fence = StringUtil::findSafeCodeFence($html, 3);

        return $fence . "=html\n" . $html . "\n" . $fence . "\n\n";
    }

    protected function processRawHtmlInlineElement(DOMElement $node): string
    {
        $clone = $node->cloneNode(true);
        if ($clone instanceof DOMElement) {
            $this->stripDjotDataAttributes($clone);
        }

        $html = $clone instanceof DOMElement ? $clone->ownerDocument?->saveHTML($clone) : null;
        if (!is_string($html)) {
            $html = '';
        }

        $backticks = StringUtil::findSafeCodeFence($html, 1);

        return $backticks . $html . $backticks . '{=html}';
    }

    protected function linkRequiresRawHtmlFallback(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if (
                $child instanceof DOMElement
                && strtolower($child->tagName) === 'img'
                && $this->requiresRawImageFallback($child->getAttribute('alt'))
            ) {
                return true;
            }
        }

        return false;
    }

    protected function isSafeReferenceLabel(string $label): bool
    {
        return strpbrk($label, '[]\\') === false;
    }

    protected function stripDjotDataAttributes(DOMElement $node): void
    {
        $toRemove = [];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            if (str_starts_with($attr->name, 'data-djot-')) {
                $toRemove[] = $attr->name;
            }
        }

        foreach ($toRemove as $name) {
            $node->removeAttribute($name);
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->stripDjotDataAttributes($child);
            }
        }
    }

    /**
     * Process semantic HTML elements to Djot span syntax
     *
     * Converts semantic HTML inline elements to Djot span syntax
     * for round-trip support with SemanticSpanExtension.
     *
     * @param \DOMElement $node The semantic element
     * @param string $type The element type (abbr, time, kbd, samp, var, cite, dfn)
     */
    protected function processSemanticSpan(DOMElement $node, string $type): string
    {
        $content = $this->processChildren($node);

        // Preserve definition-based abbreviations when the round-trip template
        // already restored the matching abbreviation definition.
        if ($type === 'abbr') {
            $title = $node->getAttribute('title');
            if (($this->abbreviationMap[$content] ?? null) === $title) {
                return $content;
            }
        }

        // Build attribute parts
        $attrParts = [];

        $valueAttribute = self::SEMANTIC_SPAN_VALUE_ATTRIBUTE[$type] ?? null;

        // The leftovers come FIRST. `docs/html-import.md` puts the canonical
        // writer at the end of the import pipeline and makes it the byte-exact
        // reference for a shared fixture, and the writer's slot order is
        // `#id .class key=value boolean` - the order this importer's own
        // getElementAttributes() already emits for every other element, and the
        // order the spec spells this exact construct in
        // (`[Tab]{#k .key kbd}`, blocks-and-attributes "Anything left over
        // rides the outermost element"; corpus 71-attribute-edge-cases-11).
        // Putting the consumed name first made <abbr id class title> and
        // <span id class title> disagree inside one importer.
        $skipAttributes = ['title'];
        if ($valueAttribute !== null) {
            $skipAttributes[] = $valueAttribute;
        }
        $otherAttrs = $this->getElementAttributes($node, $skipAttributes);
        if ($otherAttrs !== '') {
            $attrParts[] = $otherAttrs;
        }

        // Three of the seven names carry a value, and each carries it in its own
        // HTML attribute. That attribute becomes the span attribute's VALUE and
        // is consumed here rather than riding along as a duplicate key.
        $value = $valueAttribute !== null ? $node->getAttribute($valueAttribute) : '';
        if ($value !== '') {
            // Quoted only where the canonical writer quotes. A hand-rolled
            // always-quoted form spelled a value the writer immediately
            // rewrites, so the importer's own output was not stable under
            // `carve fmt`.
            $attrParts[] = $type . '=' . $this->quoteAttributeValue($value);
        } else {
            // A name with no value, or one whose value attribute is absent, is
            // spelled as the bare boolean attribute.
            $attrParts[] = $type;
        }

        return '[' . $this->escapeNoteReferenceLabel($content) . ']{' . implode(' ', $attrParts) . '}';
    }

    /**
     * Process inline quote element to Djot
     *
     * Converts <q> to quoted text. If the q element has a cite attribute,
     * it's preserved as an attribute on a span.
     */
    protected function processInlineQuote(DOMElement $node): string
    {
        $content = $this->processChildren($node);
        $escapedContent = str_replace(['\\', '"'], ['\\\\', '\\"'], $content);

        // Wrap in quotes
        $quoted = '"' . $escapedContent . '"';

        // If there's a cite attribute, wrap in span with the attribute
        $cite = $node->getAttribute('cite');
        if ($cite !== '') {
            $escapedCite = str_replace(['\\', '"'], ['\\\\', '\\"'], $cite);

            return '[' . $quoted . ']{cite="' . $escapedCite . '"}';
        }

        return $quoted;
    }

    /**
     * Get the innerHTML of an element
     */
    protected function getInnerHtml(DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    protected function processFigure(DOMElement $node): string
    {
        // A composite figure this converter's own HTML renderer produced
        // (PART 9 §4c) goes back to its `::: figure` source.
        if ($this->hasClass($node, 'carve-figure-group')) {
            return $this->processFigureGroup($node);
        }

        $output = "\n";

        // Find img, blockquote, pre, and figcaption
        $img = $this->figureImageTarget($node);
        $blockquote = $this->findFirstDirectChildByTagName($node, 'blockquote');
        $pre = $this->findFirstDirectChildByTagName($node, 'pre');
        $caption = $this->findFirstDirectChildByTagName($node, 'figcaption');

        if ($img instanceof DOMElement) {
            $this->recordFiguresPassedOverForTheTarget($node, $img);
            $output .= $this->processImage($img) . "\n";
        } elseif ($this->hasOnlySupportedFigureContent($node) && $blockquote instanceof DOMElement) {
            $output .= $this->processBlockquote($blockquote);
            // Remove the trailing blank line since caption follows immediately
            $output = rtrim($output) . "\n";
        } elseif ($this->hasOnlySupportedFigureContent($node) && $pre instanceof DOMElement) {
            // A captioned code block is a figure whose target is the fence -
            // the engine's own output shape. Importing it as a bare fence plus
            // a plain paragraph lost the `^` association (carve-php#1288).
            $output .= $this->processPreBlock($pre);
            $output = rtrim($output) . "\n";
        } elseif ($this->figureRebuildsAsCaptionedTable($node)) {
            /** @var \DOMElement $table */
            $table = $this->findFirstDirectChildByTagName($node, 'table');
            // TWO CAPTIONS AND ONE SLOT (ruling `markup-carve/carve-js#1488`).
            // A table that captions itself has taken the slot, so no Carve
            // spelling reproduces the figure and `roundtrip` keeps the element
            // whole rather than writing something else.
            if ($this->tableCaptionsItself($table)) {
                $preserved = $this->preservedAsRawHtml($node);
                if ($preserved !== null) {
                    return $preserved;
                }
            }
            // WRITTEN ONCE. The lossy exit needs to know whether the table
            // actually WROTE a caption line, and re-running the conversion to
            // ask would report everything inside the table twice.
            $written = rtrim($this->processTable($table)) . "\n";
            if ($caption instanceof DOMElement) {
                $detached = $this->figureCaptionDetachedFromTheTable($node, $caption, $written);
                if ($detached !== null) {
                    return $detached;
                }
            }
            // THE CAPTION GOES ON THE TABLE, which is where it stays a caption.
            // The rebuild used to reach the generic fallback, which writes a
            // caption's content as ordinary blocks - so `Cap` left the figure
            // and landed as its own paragraph, the association gone and the
            // report empty (carve-php#1722). A `^ ` line after the pipe rows
            // reads back as the table's `<caption>`, which is the closest the
            // syntax comes and what carve-js and carve-rs both write.
            //
            // The figure itself is still lost - the row below declares it - so
            // this is a ceiling, not a lossless spelling.
            $this->captionedTableFigures[$this->conversionNodePath($node)] = true;
            $output .= $written;
            $output = rtrim($output) . "\n";
        } else {
            // NO CARVE SPELLING REPRODUCES THIS FIGURE, so `roundtrip` keeps
            // the element instead of writing something else
            // (`markup-carve/carve#1704`, `markup-carve/carve-php#1713`).
            //
            // The three targets above each write a `^ ` line the parser reads
            // back as the same figure, so they rebuild and lose nothing.
            // Everything else does not: a figure around a bare PARAGRAPH came
            // back as the body text with a detached `Cap` paragraph under it -
            // the figure gone and the caption no longer merely lost but turned
            // into prose the document never said.
            //
            // A CAPTION IS WHAT MAKES IT A FIGURE (PART 9 §4b), so an
            // uncaptioned `<figure>` is not one to preserve; it unwraps in
            // every mode, exactly as before.
            //
            // THE ONE CARVE-OUT IS THE ARM ABOVE, and it is exactly as wide
            // as its own rebuild - see {@see self::figureRebuildsAsCaptionedTable()}.
            // A figure a table merely stands IN, beside a paragraph or a second
            // table, rebuilds nothing, so it is preserved here like any other
            // shape with no spelling rather than falling through to the generic
            // fallback and losing its caption to a paragraph (carve-php#1722).
            $caption = $this->findFirstDirectChildByTagName($node, 'figcaption');
            if (
                $caption instanceof DOMElement
                && $this->captionSpellsSomething($caption)
            ) {
                $preserved = $this->preservedAsRawHtml($node);
                if ($preserved !== null) {
                    return $preserved;
                }
            }

            $this->unwrappedFigures[$this->conversionNodePath($node)] = true;

            return $this->processGenericFigureContent($node);
        }

        // A FIGURE IS ITS CAPTION (PART 9 §4b), AND NO CAPTION LINE IS NO
        // FIGURE. The three arms above write the target and then this line;
        // without it the output is a bare image, quote or code block, and the
        // re-render has no `<figure>` in it at all. The target came through, so
        // the outcome is an unwrapping rather than a drop - and it is the one
        // outcome of this handler that used to leave the report empty
        // (carve-php#1723). carve-js and carve-rs both report the row here.
        $captionLine = $caption instanceof DOMElement
            ? $this->formatCaptionText(trim($this->processCaptionChildren($caption)))
            : '';
        if (trim($captionLine) === '') {
            // NOTHING TO HANG THE ATTRIBUTES ON. The figure is gone and the
            // target is a bare image, quote or fence, so a block attribute
            // line here would land on the TARGET and say the document carried
            // an id it never carried. Both sibling engines drop them here and
            // declare the drop, and `element-unwrapped` above is the row that
            // names what became of the element.
            $this->unwrappedFigures[$this->conversionNodePath($node)] = true;

            return $output . $captionLine . "\n\n";
        }

        // THE FIGURE'S OWN ATTRIBUTES HAVE SOMEWHERE TO GO, so dropping them
        // was a ceiling with a spelling available - which `docs/html-import.md`
        // calls a licence nobody granted (carve-php#1728). The caption line
        // makes a figure of the target again, and a block attribute line ABOVE
        // that target lands on the rebuilt node: `{#f .c}`, then `![A](a.png)`,
        // then `^ Cap` re-renders as `<figure id="f" class="c">`. carve-js and
        // carve-rs write this line byte for byte, on every arm and every mode.
        //
        // ONE LINE FOR EVERY ARM, because it is one cause rather than one per
        // arm: the arms differ in what they write for the TARGET, never in
        // whether the figure around it carried attributes. Neither arm that
        // returns early needs it - a preserved figure still HAS its attributes
        // in the bytes it was kept as, and a composite group writes its own
        // line in {@see self::processFigureGroup()}.
        //
        // THE TABLE ARM WRITES THEM ONTO THE TABLE rather than onto a figure,
        // because that is what its rebuild is: pipe rows under `{#f .c}` with a
        // caption line render `<table id="f" class="c"><caption>`. That is the
        // sentence carve-rs's `structure-unspellable` message has always
        // carried and this engine could not say.
        //
        // THE TARGET'S LEADING BLANK LINE IS THE SEAM. A table writes one, and
        // it was invisible while nothing stood above it; between the attribute
        // line and the rows it detaches the attributes into a paragraph of
        // their own, so the body starts at its first written line.
        return "\n" . $this->formatBlockAttributes($node) . ltrim($output, "\n") . $captionLine . "\n\n";
    }

    /**
     * `<figure class="carve-figure-group">` back to `::: figure` source
     * (PART 9 §4c; own-output round trip). The structural classes are
     * render-time vocabulary, not authored, so they are dropped; everything
     * else goes back on the attribute lines. The trailing `<figcaption>` is
     * the group caption and comes back as the `^ ` line after the closer.
     */
    protected function processFigureGroup(DOMElement $node): string
    {
        $attrs = $this->formatBlockAttributesWithoutClass($node, 'carve-figure-group');

        // FLAT shape: panels and preserved stray content are DIRECT children
        // of the group figure - no wrapper element - and the group's own
        // `<figcaption>` is the direct child handled below (a panel's caption
        // sits inside the panel figure, so it never matches here).
        $content = $this->insideColonFence(function () use ($node): string {
            $body = '';
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                    continue;
                }
                if (
                    $child instanceof DOMElement
                    && strtolower($child->tagName) === 'figure'
                    && $this->hasClass($child, 'carve-figure-panel')
                ) {
                    $body .= $this->processFigurePanel($child);
                } else {
                    $body .= $this->processNode($child);
                }
            }

            return trim($body);
        });

        $fence = $this->colonFenceFor();
        $output = "\n" . $attrs . $fence . " figure\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }
        $output .= $fence;

        $caption = $this->findFirstDirectChildByTagName($node, 'figcaption');
        if ($caption instanceof DOMElement) {
            $captionText = rtrim($this->formatCaptionText(trim($this->processCaptionChildren($caption))), "\n");
            if ($captionText !== '') {
                $output .= "\n" . $captionText;
            }
        }

        return $output . "\n\n";
    }

    /**
     * One panel of a composite figure: the attribute line, the host content,
     * then the panel caption's `^ ` line - the shape the inner caption rules
     * re-attach on parse. A table panel's host keeps its own `<caption>`
     * handling; the wrapper contributed nothing but the structural class.
     */
    protected function processFigurePanel(DOMElement $node): string
    {
        $attrs = $this->formatBlockAttributesWithoutClass($node, 'carve-figure-panel');

        $body = '';
        $captionText = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                $captionText = $this->formatCaptionText(trim($this->processCaptionChildren($child)));

                continue;
            }
            $body .= $this->processNode($child);
        }

        $output = $attrs . trim($body) . "\n";
        if ($captionText !== '') {
            $output .= $captionText;
        }

        return $output . "\n";
    }

    /**
     * The element's block-attribute line with ONE structural class removed.
     */
    protected function formatBlockAttributesWithoutClass(DOMElement $node, string $structuralClass): string
    {
        $classes = array_values(array_diff($this->getElementClassList($node), [$structuralClass]));
        $originalClass = $node->getAttribute('class');
        $node->setAttribute('class', implode(' ', $classes));
        // The structural class is what NAMES the element - `tabs` is why the
        // wrapper is called "Tabs" - so the derived-name test has to see it even
        // while it is lifted off the node for the attribute writer's benefit.
        // Without this the name is unrecognizable exactly on the constructs that
        // carry one (markup-carve/carve#1500).
        $this->structuralClassInProgress = $structuralClass;
        try {
            return $this->formatBlockAttributes($node);
        } finally {
            $node->setAttribute('class', $originalClass);
            $this->structuralClassInProgress = null;
        }
    }

    /**
     * The `<img>` a figure captions, found by what its body WRITES.
     *
     * THE DIRECT-CHILD SEARCH WAS THE DEFECT (carve-php#1672). A `<figure>`
     * whose image sits inside a `<p>` - which is what every WYSIWYG editor
     * produces - had no direct `<img>` child, so the whole figure fell through
     * to {@see self::processGenericFigureContent()} and the `<figcaption>` came
     * back as an ORDINARY PARAGRAPH beside the image. That is not a loss inside
     * a declared ceiling: `![a](i.png)` then a blank line then `cap` re-reads as
     * a block image and an unrelated paragraph, so the caption is bound to
     * nothing and the document says something the HTML never said.
     *
     * ASK WHAT THE BODY WRITES, NOT WHAT SHAPE IT IS. A wrapper is transparent
     * exactly when it contributes no characters of its own, and the only
     * authority on that is the writer: `<p>`, `<picture>` and `<div>` all write
     * their image and nothing else, so the figure's target is the image behind
     * them. A wrapper that DOES contribute is not the shape and keeps the
     * generic path - `<a href="u">` writes a link, `<p class="x">` writes an
     * attribute line above the image, and unwrapping either would drop
     * something the HTML held.
     */
    protected function figureImageTarget(DOMElement $node): ?DOMElement
    {
        $direct = $this->findFirstDirectChildByTagName($node, 'img');
        if ($this->hasOnlySupportedFigureContent($node) && $direct instanceof DOMElement) {
            // GUARDED, and it could not be until the generic path stopped
            // running an inline body into the caption (carve-php#1676). A
            // caption binds to the BLOCK above it, so a target that wrote no
            // image swallowed the marker as ordinary text:
            // `<figure><img src=""><figcaption>cap</figcaption></figure>` wrote
            // `a` and then `^ cap`, and re-read as ONE paragraph holding the
            // literal characters `^ cap`. While carve-php#1672 was in flight
            // this branch was left alone, because the generic path would have
            // written `acap` - one invented word - which is a worse addition,
            // not a fix. Both ends are closed now, so the guard applies to the
            // direct spelling and the wrapped one alike.
            return $this->importImageSpelling($direct) === null ? null : $direct;
        }

        $body = $this->soleFigureBodyElement($node);
        if ($body === null || strtolower($body->tagName) === 'img') {
            return null;
        }

        $image = $this->soleImportImageDescendant($body);
        if ($image === null) {
            return null;
        }

        // THE WHOLE PROBE IS THE TRIAL, not just the body write. Asking an
        // `<img>` what it writes registers a reference definition when it
        // carries one, so the spelling question has to sit inside the trial too
        // - see the note on `importTrialWrite()` for the dangling definition
        // that escaped when it did not.
        return $this->importTrialWrite(function () use ($body, $image): ?DOMElement {
            $spelling = $this->importImageSpelling($image);

            return $spelling !== null && trim($this->processNode($body)) === $spelling ? $image : null;
        });
    }

    /**
     * Record the `<figure>` elements the target was reached THROUGH.
     *
     * {@see self::figureImageTarget()} looks past a transparent wrapper to the
     * image behind it, and the wrapper is then never written: the arm above
     * writes the IMAGE and the caption line, so anything between the two is
     * gone from the output. When one of those wrappers is itself a `<figure>`,
     * the element that vanished is an unwrapped figure like any other, and
     * nothing else in this file would ever hear about it - the nested element
     * is not walked by the writer at all, so neither call site in
     * {@see self::processFigure()} can record it (ruling markup-carve/carve#1723).
     *
     * `<figure><figure><img></figure><figcaption>Cap</figcaption></figure>`
     * writes one image and one caption line, so ONE figure comes back out of
     * the two that went in. The outer one is the survivor - it is the caption
     * that makes a figure (PART 9 §4b), and the caption was the outer one's -
     * and the inner one is reported here. carve-js and carve-rs both report
     * exactly that node, and an element that did not survive is reported
     * whether or not it sits inside another one that did not either: an
     * uncaptioned pair reports both, and a three-deep nest reports the two
     * inside the captioned outer.
     *
     * ONLY THE FIGURE WRAPPERS, because `$unwrappedFigures` answers for
     * `<figure>` alone {@see self::inspectImportNode()}. A `<p>` or a
     * `<picture>` passed over the same way is a different question this row
     * does not decide: the paragraph is written nowhere and reported by nobody,
     * and the `<picture>` already collects the generic row every element with
     * no construct collects. Neither sibling engine names a FIGURE for either,
     * so neither may this.
     */
    protected function recordFiguresPassedOverForTheTarget(DOMElement $figure, DOMElement $target): void
    {
        for ($current = $target->parentNode; $current instanceof DOMElement; $current = $current->parentNode) {
            if ($current === $figure) {
                return;
            }
            if (strtolower($current->tagName) === 'figure') {
                $this->unwrappedFigures[$this->conversionNodePath($current)] = true;
            }
        }
    }

    /**
     * What an `<img>` writes, and `null` when what it writes is not an IMAGE.
     *
     * A CAPTION NEEDS SOMETHING TO BIND TO. `^ cap` attaches to the block above
     * it, so a target that wrote no block swallows the marker as ordinary text:
     * `<figure><img src=""><figcaption>cap</figcaption></figure>` wrote
     * `a` then `^ cap` and re-read as ONE PARAGRAPH holding the literal
     * characters `^ cap`. That is an ADDITION, which markup-carve/carve#1636 forbids
     * outright - a declared ceiling covers what an import LOSES, never text it
     * invents - so a figure whose body writes no image takes the generic path
     * and loses the binding instead.
     *
     * {@see self::processImage()} has four returns and only two of them are an
     * image: the inline `![alt](src)` and the reference `![alt][label]`. The
     * other two write no image at all - a `src` naming no destination unwraps to
     * the alt text, and an alt the source cannot carry falls back to raw HTML -
     * so the prefix is the test rather than a copy of those two conditions,
     * which would go stale the moment a third return was added.
     */
    protected function importImageSpelling(DOMElement $image): ?string
    {
        $written = trim($this->processImage($image));

        return str_starts_with($written, '![') ? $written : null;
    }

    /**
     * The figure's one content child, when it has exactly one.
     *
     * Whitespace between the tags is layout rather than content (PART 11 §7),
     * and the `<figcaption>` is the caption slot rather than the body, so
     * neither disqualifies a figure from having a single body element.
     */
    protected function soleFigureBodyElement(DOMElement $node): ?DOMElement
    {
        $body = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (strtolower($child->tagName) === 'figcaption') {
                    continue;
                }
                if ($body !== null) {
                    return null;
                }
                $body = $child;

                continue;
            }
            if ($child instanceof DOMText && trim($child->wholeText) !== '') {
                return null;
            }
        }

        return $body;
    }

    /**
     * The one `<img>` in a subtree, when the subtree holds exactly one.
     *
     * AN EARLY EXIT, NOT A BOUND, and the distinction was measured rather than
     * assumed: relaxing this to "the first image" changes nothing any test can
     * see, because a body holding two images does not write one image's
     * spelling and the comparison in {@see self::figureImageTarget()} rejects
     * it anyway. What this saves is the trial write on a subtree that obviously
     * cannot be the shape. The bound itself is the comparison.
     */
    protected function soleImportImageDescendant(DOMElement $node): ?DOMElement
    {
        $images = $node->getElementsByTagName('img');
        if ($images->length !== 1) {
            return null;
        }
        $image = $images->item(0);

        return $image instanceof DOMElement ? $image : null;
    }

    /**
     * Ask what a node WRITES, and leave nothing behind for having asked.
     *
     * A trial write is a QUESTION, not an exit, and a conversion accumulates two
     * kinds of answer as it runs. It RECORDS the losses it takes - a lone-image
     * paragraph, a split definition list, a dropped description, a flattened
     * caption - which the inspection walk turns into report rows. And it
     * COLLECTS the trailing definitions its output needs - a reference, a
     * footnote, an abbreviation - which are written out at the end of the
     * document. A trial that kept either would answer a question by changing the
     * document.
     *
     * BOTH KINDS BIT, and the second is the worse one. Leaving a loss record
     * behind declares a loss for a node the figure went on to unwrap, and
     * doubles the list-shaped records when the real write follows. Leaving a
     * definition behind is an ADDITION, which markup-carve/carve#1636 forbids outright:
     * probing `<figure><noscript><img data-djot-ref="r"></noscript>` asked the
     * image what it wrote, the generic path then wrote no image at all, and the
     * conversion still emitted a dangling `[r]: g.jpg` the input never held.
     *
     * SO THE RULE IS MECHANICAL RATHER THAN JUDGED: everything
     * {@see self::convert()} resets at the top of a conversion is restored here,
     * in the same order. A state added there belongs here too, and a reader can
     * check that by putting the two lists side by side.
     *
     * @template TResult
     *
     * @param \Closure(): TResult $ask
     *
     * @return TResult
     */
    protected function importTrialWrite(Closure $ask): mixed
    {
        $listDepth = $this->listDepth;
        $inPre = $this->inPre;
        $preserveTextWhitespace = $this->preserveTextWhitespace;
        $referenceDefinitions = $this->referenceDefinitions;
        $footnoteDefinitions = $this->footnoteDefinitions;
        $noteReferenceTargets = $this->noteReferenceTargets;
        $abbreviationDefinitions = $this->abbreviationDefinitions;
        $abbreviationMap = $this->abbreviationMap;
        $captionFlattenDiagnostics = $this->captionFlattenDiagnostics;
        $splitDefinitionLists = $this->splitDefinitionLists;
        $droppedDefinitionDescriptions = $this->droppedDefinitionDescriptions;
        $loneImageParagraphs = $this->loneImageParagraphs;
        $unwrappedFigures = $this->unwrappedFigures;
        $captionedTableFigures = $this->captionedTableFigures;
        $unwrappedBlockContainers = $this->unwrappedBlockContainers;

        try {
            return $ask();
        } finally {
            $this->listDepth = $listDepth;
            $this->inPre = $inPre;
            $this->preserveTextWhitespace = $preserveTextWhitespace;
            $this->referenceDefinitions = $referenceDefinitions;
            $this->footnoteDefinitions = $footnoteDefinitions;
            $this->noteReferenceTargets = $noteReferenceTargets;
            $this->abbreviationDefinitions = $abbreviationDefinitions;
            $this->abbreviationMap = $abbreviationMap;
            $this->captionFlattenDiagnostics = $captionFlattenDiagnostics;
            $this->splitDefinitionLists = $splitDefinitionLists;
            $this->droppedDefinitionDescriptions = $droppedDefinitionDescriptions;
            $this->loneImageParagraphs = $loneImageParagraphs;
            $this->unwrappedFigures = $unwrappedFigures;
            $this->captionedTableFigures = $captionedTableFigures;
            $this->unwrappedBlockContainers = $unwrappedBlockContainers;
        }
    }

    /**
     * The tag names of a figure's content children, or null if it holds stray text.
     *
     * The caption is not content - it is what the content is captioned WITH -
     * so it is skipped, and layout-only text between the children is skipped
     * too. Anything else at text level means the figure is holding words of its
     * own, which no target arm can carry, and null says so rather than a list
     * that looks clean.
     *
     * @param \DOMElement $node
     *
     * @return list<string>|null
     */
    protected function figureContentChildren(DOMElement $node): ?array
    {
        $contentChildren = [];
        foreach ($node->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                if (trim($child->textContent) !== '') {
                    return null;
                }

                continue;
            }

            if (strtolower($child->tagName) === 'figcaption') {
                continue;
            }

            $contentChildren[] = strtolower($child->tagName);
        }

        return $contentChildren;
    }

    protected function hasOnlySupportedFigureContent(DOMElement $node): bool
    {
        $contentChildren = $this->figureContentChildren($node);

        return $contentChildren !== null
            && count($contentChildren) === 1
            && in_array($contentChildren[0], ['img', 'blockquote', 'pre'], true);
    }

    /**
     * Does this `<table>` already fill Carve's one caption slot?
     *
     * A `^ ` line on a table becomes the table's OWN `<caption>` rather than a
     * `<figcaption>` beside it, so a table that arrived with a `<caption>` has
     * taken the slot before the wrapping figure gets to ask for it. Empty is
     * not taken: `<caption></caption>` writes no `^ ` line, so the slot is free
     * and the ordinary rebuild below applies unchanged.
     */
    protected function tableCaptionsItself(DOMElement $table): bool
    {
        $caption = $this->findFirstDirectChildByTagName($table, 'caption');

        return $caption instanceof DOMElement && $this->captionSpellsSomething($caption);
    }

    /**
     * TWO CAPTIONS AND ONE SLOT (ruling `markup-carve/carve-js#1488`).
     *
     * A `<figure>` around a `<table>` that carries its own `<caption>` arrives
     * with two captions, and Carve has one `^ ` line to spell them with. The
     * ordinary table rebuild wrote BOTH, and the second re-read as a literal
     * paragraph - so the document came back holding a `^` its author never
     * typed, in every mode. That is `markup-carve/carve-php#1731`'s failure one
     * construct over: a lossy mode may lose the figure, and no mode may add a
     * character.
     *
     * NOR MAY THE `<figcaption>` SIMPLY GO. It is authored TEXT rather than
     * structure, and text is the one thing an import may not spend to reach a
     * simpler shape. carve-js dropped it and said `table-degraded`, which names
     * neither the caption nor where it went; that arm is gone with this ruling.
     *
     * SO THE TWO EXITS SPLIT, exactly as `markup-carve/carve#1704` splits every
     * other figure. `roundtrip` PRESERVES the element - the caller does that
     * before this is reached - and both captions survive byte for byte. Here,
     * `safe` and `semantic` keep the table's own `<caption>` and write the
     * figcaption's text as a following PARAGRAPH: the association is gone, which
     * the row declares, and neither author's words are.
     *
     * THE FIGURE'S ATTRIBUTES STILL RIDE ONTO THE TABLE, unchanged from the
     * ordinary rebuild - `{#f}` above the pipe rows renders `<table id="f">`.
     *
     * NULL MEANS THE SLOT WAS FREE AFTER ALL, and the caller writes the ordinary
     * rebuild. It is decided on what the table WROTE rather than on what its
     * `<caption>` holds, because the two disagree: a `<caption>` holding only an
     * empty `<span>` answers yes to a DOM test - which is the test that has to
     * be used before converting - and writes no `^ ` line at all. Reading the
     * DOM answer as final detached a caption the table had left room for.
     *
     * @return string|null
     */
    protected function figureCaptionDetachedFromTheTable(
        DOMElement $node,
        DOMElement $caption,
        string $written,
    ): ?string {
        if (!$this->writtenTableCarriesACaption($written)) {
            return null;
        }

        // THE CAPTION'S CONTENT AS ORDINARY BLOCKS, which is what this engine
        // already does wherever a `<figcaption>` lands outside a caption slot
        // ({@see self::processGenericFigureContent()}): the paragraph it becomes
        // can hold a list, and flattening one to inline would destroy structure
        // the output can carry.
        $detached = trim($this->processChildren($caption));

        // A CAPTION THAT CONVERTS TO NOTHING WAS NOT DETACHED, so it does not
        // get the row saying it was. What happened is the ORDINARY rebuild: the
        // table keeps its caption, the wrapper is gone, and
        // `structure-unspellable` is the row for that.
        if ($detached === '') {
            $this->captionedTableFigures[$this->conversionNodePath($node)] = true;

            return "\n" . $this->formatBlockAttributes($node) . ltrim($written, "\n") . "\n";
        }

        $this->detachedFigureCaptions[$this->conversionNodePath($caption)] = true;

        return "\n" . $this->formatBlockAttributes($node) . ltrim($written, "\n")
            . "\n" . $detached . "\n\n";
    }

    /**
     * Did the written table take Carve's one caption slot?
     *
     * A pipe row starts with `|` and a row attribute line with `{`, so a line
     * opening with `^ ` in a written table is its caption line and nothing else.
     */
    protected function writtenTableCarriesACaption(string $written): bool
    {
        return preg_match('/^\^ /m', $written) === 1;
    }

    /**
     * Does this figure rebuild as a table carrying its caption?
     *
     * THE ONE DELIBERATE CARVE-OUT in the figure rule (`markup-carve/carve#1704`).
     * A `<figure>` around a table has no Carve spelling that reproduces it - the
     * rebuild reads back as a table carrying its own `<caption>` rather than as
     * a figure - so strictly it would preserve. It rebuilds anyway, because
     * `<table><caption>` is the idiomatic HTML for a captioned table and
     * preserving would throw the `| a |` spelling away for a common shape.
     *
     * THE CARVE-OUT IS EXACTLY AS WIDE AS THE REBUILD. It used to be a bare
     * "is there a table", which excluded from preservation every figure a table
     * merely stood in - a figure holding a table AND a paragraph, or two tables,
     * neither of which rebuilds - and those fell through to the generic
     * fallback, where the caption came back as a detached paragraph and nothing
     * said so (carve-php#1722). A figure that cannot rebuild is preserved like
     * any other, which is what carve-rs does with the same input.
     *
     * A CAPTION IS WHAT MAKES A FIGURE (PART 9 §4b), so an uncaptioned wrapper
     * around a table is not one to rebuild either: it unwraps to the bare
     * table, exactly as carve-js and carve-rs do.
     *
     * @param \DOMElement $node
     */
    protected function figureRebuildsAsCaptionedTable(DOMElement $node): bool
    {
        $caption = $this->findFirstDirectChildByTagName($node, 'figcaption');
        if (!$caption instanceof DOMElement || !$this->captionSpellsSomething($caption)) {
            return false;
        }

        return $this->figureContentChildren($node) === ['table'];
    }

    protected function processGenericFigureContent(DOMElement $node): string
    {
        $output = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                // NOT a caption slot. This fallback writes the caption's
                // content as ORDINARY BLOCKS rather than through a `^` line, so
                // a list here is representable and must be kept - flattening it
                // would destroy structure the output can hold.
                $captionText = trim($this->processChildren($child));
                if ($captionText !== '') {
                    // AND IT IS A BLOCK OF ITS OWN, which is what was missing
                    // (carve-php#1676). A figure body that writes INLINE content
                    // leaves no block boundary behind it, so appending the
                    // caption ran the two together: `<figure><span>b</span>` and
                    // a caption of `cap` wrote `bcap`, one invented word, and a
                    // link-wrapped image wrote `[![a](i.png)](u)cap`. A loss
                    // inside a declared ceiling is permitted; text the input
                    // never held is not (markup-carve/carve#1636). A BLOCK body
                    // already ended in a blank line, so this changes nothing
                    // there.
                    $output = $this->appendImportBlock($output, $captionText);
                }

                continue;
            }

            $output .= $this->processNode($child);
        }

        return $output;
    }

    /**
     * Put one block after whatever has been written, with a boundary between.
     *
     * SEPARATE ONLY AT THE JOIN, never between everything. Consecutive INLINE
     * children of a figure body are ONE run - `<span>b</span><em>c</em>` writes
     * `b{/c/}` and must keep writing it - so a helper that blank-lined every
     * contribution would break the body apart while fixing the caption. This
     * inserts a boundary at the one place a block genuinely starts.
     */
    protected function appendImportBlock(string $output, string $block): string
    {
        if ($output !== '' && !str_ends_with($output, "\n\n")) {
            $output = rtrim($output, "\n") . "\n\n";
        }

        return $output . $block . "\n\n";
    }

    /**
     * The `#id` slot's key in the writer's slot map.
     *
     * Neither key can collide with a key-value slot, because a key-value slot
     * is keyed by an HTML attribute NAME and no HTML attribute name may hold a
     * `#` or a `.`.
     *
     * @var string
     */
    protected const ATTR_SLOT_ID = '#id';

    /**
     * The `.class` slot's key in the writer's slot map. One slot, however many
     * classes it writes - they merge into a single run.
     *
     * @var string
     */
    protected const ATTR_SLOT_CLASS = '.class';

    /**
     * Format element attributes as Djot block attribute syntax.
     * Returns empty string if no relevant attributes.
     *
     * @param \DOMElement $node The element to extract attributes from
     * @param array<string> $skipAttrs Additional attributes to skip for this element
     * @param bool $elementSlotOrder Take the slot order from the element's own attribute order
     *
     * @return string Djot attribute block like "{#id .class key=value}\n" or ""
     */
    protected function formatBlockAttributes(DOMElement $node, array $skipAttrs = [], bool $elementSlotOrder = false): string
    {
        // Inside a cell there is no line for a block attribute block to sit on,
        // so it would be written as literal text. The attribute is dropped
        // instead (carve-php#1164); the cell's OWN attributes are unaffected -
        // they are written by processTable(), glued to the opening pipe.
        if ($this->tableCellDepth > 0) {
            return '';
        }

        $attrs = $this->getElementAttributes($node, $skipAttrs, $elementSlotOrder);
        if (!$attrs) {
            return '';
        }

        return '{' . $attrs . "}\n";
    }

    /**
     * Format element attributes as Djot inline attribute syntax.
     * Returns empty string if no relevant attributes.
     *
     * @param \DOMElement $node The element to extract attributes from
     * @param array<string> $skipAttrs Additional attributes to skip for this element
     *
     * @return string Djot inline attributes like "{#id .class}" or ""
     */
    protected function formatInlineAttributes(DOMElement $node, array $skipAttrs = []): string
    {
        $attrs = $this->getElementAttributes($node, $skipAttrs);
        if (!$attrs) {
            return '';
        }

        return '{' . $attrs . '}';
    }

    /**
     * Extract and format attributes from a DOM element.
     *
     * @param \DOMElement $node The element to extract attributes from
     * @param array<string> $skipAttrs Additional attributes to skip
     * @param bool $elementSlotOrder Take the slot order from the element's own attribute order
     *
     * @return string Formatted attributes (without braces) or empty string
     */
    protected function getElementAttributes(DOMElement $node, array $skipAttrs = [], bool $elementSlotOrder = false): string
    {
        $slots = [];
        $allSkip = $skipAttrs;

        // Process id first
        if (!in_array('id', $allSkip, true)) {
            $idPart = $this->idAttributePart($node);
            if ($idPart !== null) {
                $slots[self::ATTR_SLOT_ID] = [$idPart];
            }
        }

        // Process class (if not skipped)
        if (!in_array('class', $allSkip, true)) {
            $classParts = [];
            $class = $node->getAttribute('class');
            if ($class !== '') {
                $classes = preg_split('/\s+/', trim($class));
                if ($classes) {
                    foreach ($classes as $c) {
                        if ($c !== '') {
                            $classParts[] = '.' . $c;
                        }
                    }
                }
            }

            $alignmentClass = $this->extractAlignmentClass($node);
            if ($alignmentClass !== null) {
                $classParts[] = '.' . $alignmentClass;
            }
            if ($classParts !== []) {
                $slots[self::ATTR_SLOT_CLASS] = $classParts;
            }
        }

        // The keys a mapped CSS declaration fills, read BEFORE the loop. CSS
        // beats the presentational attribute in HTML, and it has to beat it in
        // BOTH source orders - reading it as the loop reached `style` let
        // `<td align="right" style="text-align:left">` keep both, because the
        // attribute had already been written by then.
        $styleMapped = in_array('style', $allSkip, true) ? [] : $this->mappedStyleSlots($node);

        // Process other attributes
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;

            // Skip already processed and this call's own skips; the POLICY is
            // one question, asked the same way by every writer.
            if ($name === 'id' || $name === 'class') {
                continue;
            }
            if (strtolower($name) === 'style' && !in_array($name, $allSkip, true)) {
                // A DECLARATION THIS ENGINE CAN SPELL IS NOT A LOSS. `style` was
                // refused wholesale, so a cell carrying `text-align:right` came
                // back unaligned - for a value this converter's own renderer
                // writes from `{align=right}`, byte for byte
                // (markup-carve/carve#1741). A cell whose column marker already
                // carries the alignment passes `style` in $skipAttrs and lands
                // above, so the same information is never spelled twice.
                foreach ($this->mappedStyleAttributes($node) as $key => $mapped) {
                    $slots[$key] = [$key . '=' . $this->quoteAttributeValue($mapped)];
                }

                continue;
            }
            if (in_array($name, $allSkip, true) || $this->isStrippedImportAttribute($name)) {
                continue;
            }
            if (isset($styleMapped[$name])) {
                continue;
            }

            $value = $attr->value;
            if ($this->isDerivedAccessibleName($node, $name, $value)) {
                continue;
            }
            if ($value === '') {
                // Boolean attribute
                $slots[$name] = [$name];
            } else {
                $slots[$name] = [$name . '=' . $this->quoteAttributeValue($value)];
            }
        }

        $order = $elementSlotOrder ? $this->slotOrderFromElement($node, $slots) : array_keys($slots);

        $parts = [];
        foreach ($order as $slot) {
            foreach ($slots[$slot] as $part) {
                $parts[] = $part;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * The `#id` slot's written form, or null when the element carries no id.
     *
     * PRESENT, NOT NON-EMPTY. An explicit `id=""` is not an absent id: it wins
     * verbatim and SUPPRESSES the auto slug, and this engine's own renderer
     * writes `<h1 id="">` back for it. Asking `getAttribute('id') !== ''` could
     * not tell the two apart - `getAttribute()` answers `''` for both - so the
     * import dropped the empty id and the re-render gave the heading the anchor
     * its source explicitly suppressed (carve-php#1698). The loss was in the
     * VALUE test, which is why it happened beside a class too, where carve-js's
     * own truthiness defect did not reach.
     *
     * An empty id has no `#` spelling, so it rides the key-value slot as
     * `id=""` - the form carve-js and carve-rs write, and the one this engine's
     * parser reads back into an explicit empty id.
     */
    protected function idAttributePart(DOMElement $node): ?string
    {
        if (!$node->hasAttribute('id')) {
            return null;
        }

        $id = $node->getAttribute('id');

        return $id === '' ? 'id=' . $this->quoteAttributeValue($id) : '#' . $id;
    }

    /**
     * The writer's slot order for $slots, READ OFF THE ELEMENT'S OWN ATTRIBUTE
     * ORDER.
     *
     * A fixed id-then-class-then-keys order writes `<h1 class="k" id="x">` back
     * as `{#x .k}`, which re-renders as `<h1 id="x" class="k">` - attributes the
     * input never had in that order. carve-rs ruled it in carve-rs#1354 and
     * carve-js ported it; carve-php spelled the fixed order
     * (carve-php#1699).
     *
     * A NON-EMPTY ORDER IS EXHAUSTIVE, so a slot the element did not spell
     * under its own name - an attribute folded or renamed on the way in, an
     * alignment class read off something other than `class` - still has to
     * appear, or the writer drops it silently. Those follow the slots the
     * element did name, keeping their own order among themselves.
     *
     * @param \DOMElement $node
     * @param array<string, array<int, string>> $slots
     *
     * @return list<string>
     */
    protected function slotOrderFromElement(DOMElement $node, array $slots): array
    {
        $order = [];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            $slot = match ($name) {
                'id' => self::ATTR_SLOT_ID,
                'class' => self::ATTR_SLOT_CLASS,
                default => $name,
            };
            if (isset($slots[$slot]) && !in_array($slot, $order, true)) {
                $order[] = $slot;
            }
        }

        foreach (array_keys($slots) as $slot) {
            if (!in_array($slot, $order, true)) {
                $order[] = $slot;
            }
        }

        return $order;
    }

    protected function formatCaptionText(string $captionText): string
    {
        $lines = preg_split('/\R/', $captionText) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        if ($lines === []) {
            return '';
        }

        $firstLine = array_shift($lines);
        $output = '^ ' . $firstLine . "\n";

        foreach ($lines as $line) {
            $output .= $line . "\n";
        }

        return $output;
    }

    protected function convertInlineFragmentToDjot(string $html): string
    {
        // Propagate the trust setting so a trusted round-trip parent keeps
        // honoring inner round-trip attributes in the recursive sub-conversion
        // (and an untrusted parent keeps ignoring them).
        $converter = new self($this->trustedRoundTrip);
        $converter->preserveTextWhitespace = true;

        $doc = new DOMDocument();
        $doc->encoding = 'UTF-8';

        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><span>' . $html . '</span>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $doc->documentElement;
        if (!$root instanceof DOMElement) {
            return '';
        }

        return $converter->processChildren($root);
    }

    protected function isInlineOnlyEndnotesSection(DOMElement $node): bool
    {
        if ($node->getAttribute('role') !== 'doc-endnotes') {
            return false;
        }

        $ol = $this->findFirstDirectChildByTagName($node, 'ol');
        if (!$ol instanceof DOMElement) {
            return false;
        }

        $listItems = $this->getDirectChildElementsByTagName($ol, 'li');
        if ($listItems === []) {
            return false;
        }

        foreach ($listItems as $listItem) {
            if (!$listItem->hasAttribute('data-djot-inline-footnote')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rebuild the footnote definitions an endnotes section holds, or refuse the
     * section entirely.
     *
     * A DEFINITION IS REBUILT ONLY WHERE ITS REFERENCE IS PRESENT. That is the
     * shape a rendered document has - `docs/html-import.md`: "A footnote whose
     * `role="doc-noteref"` reference IS present rebuilds as a footnote, which
     * is the shape a rendered document has" - and for that shape the round trip
     * is exact.
     *
     * A REFERENCE-LESS SECTION IS NOT ONE, and rebuilding it deleted the note:
     * an unreferenced definition renders to the EMPTY STRING, so
     *
     *     <section role="doc-endnotes"><hr><ol><li id="fn1"><p>n</p></li></ol></section>
     *
     * imported as `[^1]: n` and re-rendered through this engine's own converter
     * as `""` - the note's text gone from the document, with the only
     * diagnostic being about the `id` (markup-carve/carve-php#1582). A `<li>`
     * with no `fn`-shaped id was worse: it matched no label, the section
     * returned empty, and the note left with no diagnostic at all.
     *
     * So a section that rebuilt NOTHING returns null and takes the ordinary
     * section policy, which keeps the `<hr>` and the `<ol>` the section is built
     * from - `docs/html-import.md` again: "a loss where the degraded form keeps
     * every byte a reader could see". markup-carve/carve#1558 records the
     * degraded form as the contract and pins it in carve-js and carve-rs, which
     * have always read it that way.
     *
     * A PARTIALLY referenced section rebuilds what it can and emits the rest as
     * the list it is, which is what carve-js does: the notes that left are gone
     * from the `<ol>`, the separator goes with the first of them, and every
     * remaining item is still on the page.
     *
     * @return string|null The source for this section, or null when no note was
     *   rebuilt and the ordinary section policy should have it.
     */
    protected function processEndnotesSection(DOMElement $node): ?string
    {
        // Find the <ol> containing footnote definitions
        $ol = $this->findFirstDirectChildByTagName($node, 'ol');
        if (!$ol instanceof DOMElement) {
            return null;
        }

        // Process each <li> footnote definition
        $listItems = $this->getDirectChildElementsByTagName($ol, 'li');
        $rebuilt = [];
        foreach ($listItems as $index => $li) {
            // Skip inline footnotes (handled separately). They are CONSUMED, not
            // refused: the reference site carries the note's whole content, so
            // the item here is a copy of something already in the document and
            // leaving it behind would print it twice.
            if ($li->hasAttribute('data-djot-inline-footnote')) {
                $rebuilt[$index] = true;

                continue;
            }

            // Get footnote label from data attribute
            $label = $li->getAttribute('data-djot-footnote-label');
            if ($label === '') {
                // Fallback: extract from id attribute (fn1 -> 1)
                $id = $li->getAttribute('id');
                if (str_starts_with($id, 'fn')) {
                    $label = substr($id, 2);
                } else {
                    continue;
                }
            }

            if (!$this->endnoteHasReference($li, $label)) {
                continue;
            }

            // Extract content, removing the backlink
            $content = $this->processFootnoteContent($li);
            if ($content !== '') {
                $this->footnoteDefinitions[$label] = $content;
                $rebuilt[$index] = true;
            }
        }

        if ($rebuilt === []) {
            return null;
        }

        if (count($rebuilt) === count($listItems)) {
            // Every note left; the definitions are appended at the end and the
            // separator went with them.
            //
            // THE POSITION IS MEANING, so it is kept. Definitions collect to
            // document level whatever the source said, so a section with
            // content after it would otherwise re-render past that content -
            // the same characters in the wrong order, with nothing to say so.
            // Carve spells the position, and `docs/html-import.md` - "An
            // endnotes section keeps the position it was written at" - has the
            // import write the placement directive where the section sat.
            //
            // Nothing is reported: this is not `structure-unspellable`, since
            // the language HAS the spelling, which is the whole argument.
            //
            // A SECTION THAT IS LAST WRITES NO DIRECTIVE. The definitions
            // already render there, so the directive would put a construct in
            // the source that the input did not distinguish.
            if ($this->hasContentAfterEndnotesSection($node)) {
                return "::: footnotes\n\n:::\n\n";
            }

            return '';
        }

        return $this->processNode($this->endnotesRemainder($ol, $rebuilt));
    }

    /**
     * Does anything a reader would see follow this endnotes section?
     *
     * The question the placement directive turns on. A section with nothing
     * after it already renders where the definitions land, so the import writes
     * no directive; a section with content after it needs one, or the re-render
     * puts the notes past that content.
     *
     * WALKED UP THE ANCESTORS, not only across the section's own siblings: a
     * section wrapped in a `<div>` that has a paragraph after the wrapper is
     * still not last, and reading one level would have called it last and moved
     * the notes past that paragraph.
     *
     * WHAT IS WRITTEN, not what is present. Whitespace-only text, comments and
     * an element `writesNothing()` recognizes - a `<script>`, an empty `<p>` -
     * all put nothing in the source, so a section they follow is still last and
     * still writes no directive. This is the same question
     * `precedingSiblingThatWritesSomething()` asks in the other direction, and
     * it is asked through the same helper so the two cannot drift.
     *
     * `writesNothing()` is conservative the way this caller needs: it treats
     * what it does not recognize as writing something. Reading a written
     * element as silent would move the notes past content a reader can see,
     * where the other error only writes a directive the input did not need.
     */
    protected function hasContentAfterEndnotesSection(DOMElement $node): bool
    {
        $current = $node;
        while ($current instanceof DOMElement) {
            for ($sibling = $current->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
                if ($sibling instanceof DOMComment) {
                    continue;
                }

                if ($sibling instanceof DOMText) {
                    if (trim($sibling->textContent) === '') {
                        continue;
                    }

                    return true;
                }

                if ($sibling instanceof DOMElement && $this->writesNothing($sibling)) {
                    continue;
                }

                return true;
            }

            $parent = $current->parentNode;
            $current = $parent instanceof DOMElement ? $parent : null;
        }

        return false;
    }

    /**
     * The endnotes `<ol>` with the items that became footnote definitions taken
     * out of it, so what is left is emitted as the list it is.
     *
     * A CLONE, because the items are only gone from THIS reading: the loss
     * report walks the original tree to ask what each element's attributes did,
     * and an element removed from under it would be an element it could not
     * find.
     *
     * @param \DOMElement $ol
     * @param array<int, true> $rebuilt Indexes of the direct `<li>` children
     *   that became footnote definitions.
     */
    protected function endnotesRemainder(DOMElement $ol, array $rebuilt): DOMElement
    {
        $clone = $ol->cloneNode(true);
        if (!$clone instanceof DOMElement) {
            return $ol;
        }

        foreach ($this->getDirectChildElementsByTagName($clone, 'li') as $index => $li) {
            if (isset($rebuilt[$index])) {
                $li->parentNode?->removeChild($li);
            }
        }

        return $clone;
    }

    /**
     * Is there a `role="doc-noteref"` reference in this document for the note
     * this `<li>` holds?
     *
     * THE ROLE, not the shape of the anchor. A reference is authored semantics
     * (PART 9 §16a writes it, and every producer whose HTML imports as
     * footnotes without an adapter writes it), where an anchor pointing at the
     * item is only a link - so the same signal the rest of this converter reads
     * decides whether the definition has a reader.
     *
     * The `#fn{label}` spelling is checked beside the item's own `id` for the
     * round-trip case, where `data-djot-footnote-label` carries an authored
     * label and the rendered `id` is derived from it.
     */
    protected function endnoteHasReference(DOMElement $li, string $label): bool
    {
        $targets = $this->noteReferenceTargets($li->ownerDocument);
        $id = $li->getAttribute('id');

        return ($id !== '' && isset($targets['#' . $id])) || isset($targets['#fn' . $label]);
    }

    /**
     * Every fragment a `role="doc-noteref"` anchor in this document points at,
     * as a set.
     *
     * Collected ONCE per document rather than per note: a section of N notes
     * asked about M references is N*M anchor reads, and an endnotes section is
     * exactly the document that has many of both.
     *
     * @return array<string, true>
     */
    protected function noteReferenceTargets(?DOMDocument $document): array
    {
        if ($this->noteReferenceTargets !== null) {
            return $this->noteReferenceTargets;
        }
        if ($document === null) {
            return [];
        }

        $targets = [];
        $xpath = new DOMXPath($document);
        /** @var \DOMNodeList<\DOMElement> $anchors */
        $anchors = $xpath->query('//a[@role="doc-noteref"]');
        foreach ($anchors as $anchor) {
            $href = $anchor->getAttribute('href');
            if (str_starts_with($href, '#')) {
                $targets[$href] = true;
            }
        }
        $this->noteReferenceTargets = $targets;

        return $targets;
    }

    /**
     * @return list<\DOMElement>
     */
    protected function getDirectChildElementsByTagName(DOMElement $node, string $tagName): array
    {
        $matches = [];
        $tagName = strtolower($tagName);

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === $tagName) {
                $matches[] = $child;
            }
        }

        return $matches;
    }

    /**
     * Process footnote content, removing backlinks
     */
    protected function processFootnoteContent(DOMElement $li): string
    {
        // Clone the node so we can remove backlinks without affecting the original
        $clone = $li->cloneNode(true);

        // Remove all backlink elements
        $ownerDocument = $clone->ownerDocument;
        if ($ownerDocument !== null) {
            $xpath = new DOMXPath($ownerDocument);
            /** @var \DOMNodeList<\DOMElement> $backlinks */
            $backlinks = $xpath->query('.//a[@role="doc-backlink"]', $clone);
            foreach ($backlinks as $backlink) {
                $backlink->parentNode?->removeChild($backlink);
            }
        }

        // Process the remaining content
        $content = trim($this->processBlock($clone));

        return $content;
    }

    protected function formatFootnoteDefinition(string|int $label, string $content): string
    {
        $lines = explode("\n", $content);
        $firstLine = $lines[0];
        $lines = array_slice($lines, 1);
        $formatted = '[^' . $label . ']: ' . $firstLine;

        foreach ($lines as $line) {
            $formatted .= "\n  " . $line;
        }

        return $formatted;
    }

    /**
     * Rewrite an editor's footnote-shaped HTML into the shape the core policy
     * already reads: `<a role="doc-noteref" href="#fnN">` in the body, and a
     * `<section role="doc-endnotes">` holding one `<li id="fnN">` per note.
     *
     * Word, Google Docs, LibreOffice and pre-3.x Pandoc all spell the same
     * structure, and none of them with the DPUB-ARIA roles this importer
     * recognizes, so their footnotes imported as a literal link beside an
     * orphaned list: the reference kept its `#fn1` href and the note body
     * became an ordinary list item or paragraph.
     *
     * What all of them DO share is a MUTUALLY LINKED ANCHOR PAIR - the body
     * reference points at the definition and the definition points back at the
     * reference. That pair is the signature matched here, so nothing depends on
     * a vendor class name or on the `fn1`/`fnref1` id convention. LibreOffice's
     * `sdfootnote1anc`/`sdfootnote1sym` and Word's `_ftnref1`/`_ftn1` pair by
     * exactly the same rule as Pandoc's `fnref1`/`fn1`.
     *
     * The spec permits this shape of work - "Adapters may normalize
     * editor-specific markup before the core policy" (docs/html-import.md,
     * "Required API surface") - but it does NOT rule on footnote import, so
     * every decision below is this importer's, written down rather than left
     * silent.
     */
    protected function normalizeAdapterFootnotes(DOMDocument $doc): void
    {
        if (!in_array($this->importAdapter, self::FOOTNOTE_SHAPED_ADAPTERS, true)) {
            return;
        }

        $elements = $this->documentElements($doc);
        $order = [];
        foreach ($elements as $index => $element) {
            $order[spl_object_id($element)] = $index;
        }

        $targets = $this->footnoteFragmentTargets($elements);
        $candidates = $this->resolveFootnotePairDirection(
            $this->footnotePairCandidates($elements, $targets),
            $order,
        );
        if ($candidates === []) {
            return;
        }

        $this->rewriteFootnoteSites($doc, $this->attachRemainingFootnoteReferences(
            $elements,
            $this->groupFootnoteDefinitions($candidates, $order),
        ));
    }

    /**
     * Every element in the document, in document order.
     *
     * Snapshotted into an array because the caller mutates the tree, and
     * because holding the DOMElement objects is what keeps `spl_object_id()`
     * stable for the identity maps built from them.
     *
     * @return list<\DOMElement>
     */
    protected function documentElements(DOMDocument $doc): array
    {
        $elements = [];
        /** @var \DOMNodeList<\DOMElement> $all */
        $all = $doc->getElementsByTagName('*');
        foreach ($all as $element) {
            $elements[] = $element;
        }

        return $elements;
    }

    /**
     * Map every same-document fragment name to the element it addresses.
     *
     * `id` first and `name` second, in two passes rather than one, so an `id`
     * always wins over the legacy `<a name>` form when both spell the same
     * fragment.
     *
     * @param list<\DOMElement> $elements
     *
     * @return array<string, \DOMElement>
     */
    protected function footnoteFragmentTargets(array $elements): array
    {
        $targets = [];
        foreach ($elements as $element) {
            $id = $element->getAttribute('id');
            if ($id !== '' && !isset($targets[$id])) {
                $targets[$id] = $element;
            }
        }

        foreach ($elements as $element) {
            if (strtolower($element->tagName) !== 'a') {
                continue;
            }
            $name = $element->getAttribute('name');
            if ($name !== '' && !isset($targets[$name])) {
                $targets[$name] = $element;
            }
        }

        return $targets;
    }

    /**
     * Every anchor that could be a footnote reference, with the block it would
     * bind to.
     *
     * @param list<\DOMElement> $elements
     * @param array<string, \DOMElement> $targets
     *
     * @return list<array{ref: \DOMElement, block: \DOMElement, fragment: string, mutual: bool}>
     */
    protected function footnotePairCandidates(array $elements, array $targets): array
    {
        $anchors = [];
        $used = [];
        foreach ($elements as $element) {
            if (strtolower($element->tagName) !== 'a') {
                continue;
            }
            $href = $element->getAttribute('href');
            if (!str_starts_with($href, '#')) {
                continue;
            }
            $fragment = substr($href, 1);
            if ($fragment === '' || !isset($targets[$fragment])) {
                continue;
            }
            $anchors[] = [$element, $fragment];
            $used[$fragment] = true;
        }

        $candidates = [];
        foreach ($anchors as [$anchor, $fragment]) {
            $block = $this->resolveFootnoteDefinitionBlock($targets[$fragment], $used);
            if ($block === null || $this->nodeContains($block, $anchor)) {
                continue;
            }

            $identity = $this->anchorIdentity($anchor);
            $mutual = $identity !== '' && $this->blockLinksTo($block, $identity);
            if (!$mutual && !$this->isFootnoteReferenceMarked($anchor)) {
                continue;
            }

            $candidates[] = ['ref' => $anchor, 'block' => $block, 'fragment' => $fragment, 'mutual' => $mutual];
        }

        return $candidates;
    }

    /**
     * The block a reference's target belongs to.
     *
     * The target itself when it is already a block (Pandoc's `<li id="fn1">`),
     * otherwise the nearest block ancestor of the anchor the fragment names.
     * Then ONE guarded climb, because Word and LibreOffice wrap each note in a
     * dedicated `<div id=...>` and the body can be several paragraphs inside
     * it: the climb only happens into a wrapper that carries an id and holds
     * exactly one referenced target, which is what keeps a shared container
     * (Google Docs' one trailing `<div>` around every note) from swallowing
     * its siblings.
     *
     * @param \DOMElement $target
     * @param array<string, bool> $used
     */
    protected function resolveFootnoteDefinitionBlock(DOMElement $target, array $used): ?DOMElement
    {
        $block = $target;
        while (!in_array(strtolower($block->tagName), self::FOOTNOTE_DEFINITION_BLOCKS, true)) {
            $parent = $block->parentNode;
            if (!$parent instanceof DOMElement) {
                return null;
            }
            $block = $parent;
        }

        $parent = $block->parentNode;
        if (
            $parent instanceof DOMElement
            && in_array(strtolower($parent->tagName), self::FOOTNOTE_WRAPPER_BLOCKS, true)
            && $parent->getAttribute('id') !== ''
            && $this->countFootnoteTargets($parent, $used) === 1
        ) {
            $block = $parent;
        }

        // The root itself is never a note: taking it would move every block in
        // the document into one. `body` and `html` need no test of their own -
        // neither is a definition block, so the loop above climbs past them and
        // runs off the top instead of stopping there.
        $owner = $block->ownerDocument;
        if ($owner !== null && $block === $owner->documentElement) {
            return null;
        }

        return $block;
    }

    /**
     * How many referenced fragment targets this element holds, itself included.
     *
     * @param \DOMElement $node
     * @param array<string, bool> $used
     */
    protected function countFootnoteTargets(DOMElement $node, array $used): int
    {
        $count = $this->isFootnoteFragmentTarget($node, $used) ? 1 : 0;
        /** @var \DOMNodeList<\DOMElement> $descendants */
        $descendants = $node->getElementsByTagName('*');
        foreach ($descendants as $descendant) {
            if ($this->isFootnoteFragmentTarget($descendant, $used)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param \DOMElement $node
     * @param array<string, bool> $used
     */
    protected function isFootnoteFragmentTarget(DOMElement $node, array $used): bool
    {
        $id = $node->getAttribute('id');
        if ($id !== '' && isset($used[$id])) {
            return true;
        }

        if (strtolower($node->tagName) !== 'a') {
            return false;
        }

        $name = $node->getAttribute('name');

        return $name !== '' && isset($used[$name]);
    }

    /**
     * Keep one side of every mutually linked anchor pair.
     *
     * The pair is symmetric, so both directions produce a candidate and one of
     * them is the back-link reading as a reference. An explicit marker decides
     * where there is one; otherwise document order does, because a footnote
     * reference precedes the note it opens in every export shape measured.
     *
     * @param list<array{ref: \DOMElement, block: \DOMElement, fragment: string, mutual: bool}> $candidates
     * @param array<int, int> $order
     *
     * @return list<array{ref: \DOMElement, block: \DOMElement, fragment: string, mutual: bool}>
     */
    protected function resolveFootnotePairDirection(array $candidates, array $order): array
    {
        $byReference = [];
        foreach ($candidates as $index => $candidate) {
            $byReference[spl_object_id($candidate['ref'])] = $index;
        }

        $kept = [];
        foreach ($candidates as $candidate) {
            $inverse = $this->inverseFootnoteCandidate($candidates, $byReference, $candidate);
            if ($inverse !== null && $this->footnoteReferenceSideWins($inverse, $candidate, $order)) {
                continue;
            }

            $kept[] = $candidate;
        }

        return $kept;
    }

    /**
     * The candidate that reads the same mutual pair from the other end.
     *
     * Found through the back anchor the candidate's own block holds rather
     * than by comparing every candidate with every other: a document with a
     * thousand notes made that scan a thousand times a thousand containment
     * walks, and the anchor names the inverse directly.
     *
     * @param list<array{ref: \DOMElement, block: \DOMElement, fragment: string, mutual: bool}> $candidates
     * @param array<int, int> $byReference
     * @param array{ref: \DOMElement, block: \DOMElement, fragment: string, mutual: bool} $candidate
     *
     * @return array{ref: \DOMElement, block: \DOMElement, fragment: string, mutual: bool}|null
     */
    protected function inverseFootnoteCandidate(array $candidates, array $byReference, array $candidate): ?array
    {
        $identity = $this->anchorIdentity($candidate['ref']);
        if ($identity === '') {
            return null;
        }

        /** @var \DOMNodeList<\DOMElement> $anchors */
        $anchors = $candidate['block']->getElementsByTagName('a');
        foreach ($anchors as $anchor) {
            if ($anchor->getAttribute('href') !== '#' . $identity) {
                continue;
            }

            $index = $byReference[spl_object_id($anchor)] ?? null;
            if ($index === null) {
                continue;
            }
            if ($this->nodeContains($candidates[$index]['block'], $candidate['ref'])) {
                return $candidates[$index];
            }
        }

        return null;
    }

    /**
     * @param array{ref: \DOMElement, block: \DOMElement, fragment: string, mutual: bool} $first
     * @param array{ref: \DOMElement, block: \DOMElement, fragment: string, mutual: bool} $second
     * @param array<int, int> $order
     */
    protected function footnoteReferenceSideWins(array $first, array $second, array $order): bool
    {
        $firstMarked = $this->isFootnoteReferenceMarked($first['ref']);
        $secondMarked = $this->isFootnoteReferenceMarked($second['ref']);
        if ($firstMarked !== $secondMarked) {
            return $firstMarked;
        }

        $firstBack = $this->isFootnoteBacklinkMarked($first['ref']);
        $secondBack = $this->isFootnoteBacklinkMarked($second['ref']);
        if ($firstBack !== $secondBack) {
            return $secondBack;
        }

        return ($order[spl_object_id($first['ref'])] ?? 0) < ($order[spl_object_id($second['ref'])] ?? 0);
    }

    /**
     * One entry per definition block, carrying every reference bound to it.
     *
     * @param list<array{ref: \DOMElement, block: \DOMElement, fragment: string, mutual: bool}> $candidates
     * @param array<int, int> $order
     *
     * @return list<array{block: \DOMElement, refs: list<\DOMElement>, fragments: list<string>}>
     */
    protected function groupFootnoteDefinitions(array $candidates, array $order): array
    {
        $groups = [];
        foreach ($candidates as $candidate) {
            $key = spl_object_id($candidate['block']);
            if (!isset($groups[$key])) {
                $groups[$key] = ['block' => $candidate['block'], 'refs' => [], 'fragments' => []];
            }
            $groups[$key]['refs'][] = $candidate['ref'];
            if (!in_array($candidate['fragment'], $groups[$key]['fragments'], true)) {
                $groups[$key]['fragments'][] = $candidate['fragment'];
            }
        }

        // A block that contains another definition block is a container, not a
        // note: keeping both would move a subtree into two places at once. The
        // containers are found by climbing from each block, which costs one
        // walk per note rather than one per PAIR of notes.
        $byBlock = [];
        foreach ($groups as $key => $group) {
            $byBlock[spl_object_id($group['block'])] = $key;
        }
        foreach ($groups as $group) {
            $ancestor = $group['block']->parentNode;
            while ($ancestor !== null) {
                $key = $byBlock[spl_object_id($ancestor)] ?? null;
                if ($key !== null) {
                    unset($groups[$key]);
                }
                $ancestor = $ancestor->parentNode;
            }
        }

        return $this->sortFootnoteDefinitions(array_values($groups), $order);
    }

    /**
     * Bind every remaining anchor that addresses a confirmed note.
     *
     * Once a block IS a footnote definition, an anchor pointing at it is a
     * reference to it whatever it looks like. This matters for the second and
     * later reference to one note: only one of them can be the back-link's
     * target, so the mutual pair that confirmed the note cannot confirm them,
     * and without this they stayed literal links beside a `[^1]`.
     *
     * @param list<\DOMElement> $elements
     * @param list<array{block: \DOMElement, refs: list<\DOMElement>, fragments: list<string>}> $definitions
     *
     * @return list<array{block: \DOMElement, refs: list<\DOMElement>, fragments: list<string>}>
     */
    protected function attachRemainingFootnoteReferences(array $elements, array $definitions): array
    {
        $byFragment = [];
        foreach ($definitions as $index => $definition) {
            foreach ($definition['fragments'] as $fragment) {
                $byFragment[$fragment] = $index;
            }
        }

        // Which elements sit inside a note, computed once: asking each anchor
        // whether it is inside any note walked the tree once per anchor and
        // per note, which is quadratic on a document that is mostly notes.
        $inside = [];
        foreach ($definitions as $definition) {
            $inside[spl_object_id($definition['block'])] = true;
            /** @var \DOMNodeList<\DOMElement> $descendants */
            $descendants = $definition['block']->getElementsByTagName('*');
            foreach ($descendants as $descendant) {
                $inside[spl_object_id($descendant)] = true;
            }
        }

        /** @var array<int, list<\DOMElement>> $extra */
        $extra = [];

        foreach ($elements as $element) {
            if (strtolower($element->tagName) !== 'a') {
                continue;
            }
            $href = $element->getAttribute('href');
            if (!str_starts_with($href, '#')) {
                continue;
            }
            $index = $byFragment[substr($href, 1)] ?? null;
            if ($index === null) {
                continue;
            }

            if (isset($inside[spl_object_id($element)])) {
                continue;
            }

            if (!in_array($element, $definitions[$index]['refs'], true)) {
                $extra[$index][] = $element;
            }
        }

        $bound = [];
        foreach ($definitions as $index => $definition) {
            $bound[] = [
                'block' => $definition['block'],
                'refs' => array_merge($definition['refs'], $extra[$index] ?? []),
                'fragments' => $definition['fragments'],
            ];
        }

        return $bound;
    }

    /**
     * @param list<array{block: \DOMElement, refs: list<\DOMElement>, fragments: list<string>}> $definitions
     * @param array<int, int> $order
     *
     * @return list<array{block: \DOMElement, refs: list<\DOMElement>, fragments: list<string>}>
     */
    protected function sortFootnoteDefinitions(array $definitions, array $order): array
    {
        usort($definitions, function (array $first, array $second) use ($order): int {
            return ($order[spl_object_id($first['block'])] ?? 0) <=> ($order[spl_object_id($second['block'])] ?? 0);
        });

        return $definitions;
    }

    protected function anchorIdentity(DOMElement $anchor): string
    {
        $id = $anchor->getAttribute('id');

        return $id !== '' ? $id : $anchor->getAttribute('name');
    }

    protected function blockLinksTo(DOMElement $block, string $fragment): bool
    {
        /** @var \DOMNodeList<\DOMElement> $anchors */
        $anchors = $block->getElementsByTagName('a');
        foreach ($anchors as $anchor) {
            if ($anchor->getAttribute('href') === '#' . $fragment) {
                return true;
            }
        }

        return false;
    }

    /**
     * `footnoteRef` is Pandoc 1.x's spelling of `footnote-ref`, which it used
     * together with a back-link carrying no attributes at all.
     */
    protected function isFootnoteReferenceMarked(DOMElement $anchor): bool
    {
        return $anchor->getAttribute('role') === 'doc-noteref'
            || $this->hasClass($anchor, 'footnote-ref')
            || $this->hasClass($anchor, 'footnoteRef');
    }

    protected function isFootnoteBacklinkMarked(DOMElement $anchor): bool
    {
        return $anchor->getAttribute('role') === 'doc-backlink'
            || $this->hasClass($anchor, 'footnote-back');
    }

    protected function nodeContains(DOMNode $ancestor, DOMNode $node): bool
    {
        $current = $node->parentNode;
        while ($current !== null) {
            if ($current === $ancestor) {
                return true;
            }
            $current = $current->parentNode;
        }

        return false;
    }

    /**
     * Move the recognized notes into one `<section role="doc-endnotes">` and
     * point every reference at it.
     *
     * Labels are assigned 1..N over the definitions in document order rather
     * than parsed out of the ids: an id is generated navigation an engine
     * regenerates on the way out, and `_ftn1` or `sdfootnote1sym` is not a
     * label any Carve source could carry anyway.
     *
     * @param \DOMDocument $doc
     * @param list<array{block: \DOMElement, refs: list<\DOMElement>, fragments: list<string>}> $definitions
     */
    protected function rewriteFootnoteSites(DOMDocument $doc, array $definitions): void
    {
        $section = $doc->createElement('section');
        $section->setAttribute('role', 'doc-endnotes');
        $list = $doc->createElement('ol');
        $section->appendChild($list);

        $containers = [];
        $label = 0;
        foreach ($definitions as $index => $definition) {
            $label++;
            $block = $definition['block'];
            if ($index === 0) {
                $this->removeFootnoteSeparator($block);
            }

            $identities = [];
            foreach ($definition['refs'] as $reference) {
                $identity = $this->anchorIdentity($reference);
                if ($identity !== '') {
                    $identities[] = $identity;
                }
            }
            $this->stripFootnoteBacklinks($block, $identities, $definition['fragments']);

            $item = $doc->createElement('li');
            $item->setAttribute('id', 'fn' . $label);
            while ($block->firstChild !== null) {
                $item->appendChild($block->firstChild);
            }
            $list->appendChild($item);

            foreach ($definition['refs'] as $reference) {
                $site = $this->footnoteReferenceSite($reference);
                $replacement = $doc->createElement('a');
                $replacement->setAttribute('role', 'doc-noteref');
                $replacement->setAttribute('href', '#fn' . $label);
                $replacement->appendChild($doc->createElement('sup', (string)$label));
                $site->parentNode?->replaceChild($replacement, $site);
            }

            $container = $block->parentNode;
            $block->parentNode?->removeChild($block);
            if ($container instanceof DOMElement) {
                $containers[spl_object_id($container)] = $container;
            }
        }

        // Keyed by identity, because every note in one list names the SAME
        // container: pruning it once per note walked that list's children once
        // per note, which is quadratic on a document that is mostly notes.
        foreach ($containers as $container) {
            $this->pruneEmptyFootnoteContainer($container);
        }

        $host = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        $host?->appendChild($section);
    }

    /**
     * Remove the rule that separates the notes from the body.
     *
     * Every producer measured emits one, and it is chrome rather than content:
     * Pandoc puts `<hr />` inside the section, Word `<br clear=all><hr ...>`
     * inside the footnote-list div, Google Docs a bare `<hr class="cN">` as a
     * sibling of the notes. Only the first two would be swept up by pruning an
     * emptied container, so the separator is looked for explicitly - at the
     * first note, and at each of its ancestors, taking only what immediately
     * precedes it.
     */
    protected function removeFootnoteSeparator(DOMElement $first): void
    {
        $node = $first;
        while (true) {
            $previous = $node->previousSibling;
            while ($previous !== null && $this->isFootnoteChromeText($previous)) {
                $previous = $previous->previousSibling;
            }

            if ($previous instanceof DOMElement && in_array(strtolower($previous->tagName), ['hr', 'br'], true)) {
                $previous->parentNode?->removeChild($previous);

                continue;
            }

            if ($previous !== null) {
                return;
            }

            $parent = $node->parentNode;
            if (!$parent instanceof DOMElement || in_array(strtolower($parent->tagName), ['body', 'html'], true)) {
                return;
            }
            $node = $parent;
        }
    }

    /**
     * Whether a node is part of the separator's packaging rather than content.
     *
     * Word's downlevel-revealed conditionals - `<![if !supportFootnotes]>` and
     * the matching `<![endif]>` - are not comments, so an HTML parser hands
     * them back as TEXT nodes. They bracket the `<br clear=all><hr>` inside the
     * footnote-list div, so without recognizing them the emptied container
     * keeps text, survives pruning, and imports as a paragraph that spells the
     * conditional out.
     */
    protected function isFootnoteChromeText(DOMNode $node): bool
    {
        if ($node instanceof DOMComment) {
            return true;
        }

        if (!$node instanceof DOMText) {
            return false;
        }

        $text = trim($node->textContent);

        return $text === '' || preg_match('/^(<!\[if[^\]]*\]>|<!\[endif\]>)+$/i', $text) === 1;
    }

    /**
     * Remove the navigation an engine regenerates: the back-link, and the
     * marker anchor Word, Google Docs and LibreOffice put it on.
     *
     * Carried into the note body it would render as a stray link to a fragment
     * that no longer exists, and the visible marker it wraps (`[1]`, `1`, the
     * return arrow) would be written into the note's own text.
     *
     * @param \DOMElement $block
     * @param list<string> $referenceIdentities
     * @param list<string> $fragments
     */
    protected function stripFootnoteBacklinks(DOMElement $block, array $referenceIdentities, array $fragments): void
    {
        $anchors = [];
        /** @var \DOMNodeList<\DOMElement> $found */
        $found = $block->getElementsByTagName('a');
        foreach ($found as $anchor) {
            $anchors[] = $anchor;
        }

        foreach ($anchors as $anchor) {
            $href = $anchor->getAttribute('href');
            $pointsBack = $href !== '' && in_array(substr($href, 1), $referenceIdentities, true) && str_starts_with($href, '#');
            $isMarker = str_starts_with($href, '#') && in_array($this->anchorIdentity($anchor), $fragments, true);
            if (!$this->isFootnoteBacklinkMarked($anchor) && !$pointsBack && !$isMarker) {
                continue;
            }

            $parent = $anchor->parentNode;
            $anchor->parentNode?->removeChild($anchor);
            if (
                $parent instanceof DOMElement
                && in_array(strtolower($parent->tagName), ['sup', 'span'], true)
                && trim($parent->textContent) === ''
                && $parent->getElementsByTagName('*')->length === 0
            ) {
                $parent->parentNode?->removeChild($parent);
            }
        }
    }

    /**
     * The node a reference occupies: the anchor, or the `<sup>` that holds
     * nothing but the anchor.
     *
     * Google Docs and Pandoc put the `<sup>` outside the anchor, so replacing
     * only the anchor would leave `{^...^}` wrapped around the reference.
     */
    protected function footnoteReferenceSite(DOMElement $reference): DOMElement
    {
        $parent = $reference->parentNode;
        if (!$parent instanceof DOMElement || strtolower($parent->tagName) !== 'sup') {
            return $reference;
        }

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child !== $reference) {
                return $reference;
            }
            if ($child instanceof DOMText && trim($child->textContent) !== '') {
                return $reference;
            }
        }

        return $parent;
    }

    /**
     * Drop a container the notes left empty, so the `<hr>` and the `<ol>` that
     * held them do not import as a thematic break beside an empty list.
     */
    protected function pruneEmptyFootnoteContainer(?DOMNode $node): void
    {
        while ($node instanceof DOMElement) {
            $owner = $node->ownerDocument;
            if ($owner !== null && $node === $owner->documentElement) {
                return;
            }
            if (in_array(strtolower($node->tagName), ['body', 'html'], true)) {
                return;
            }
            foreach ($node->childNodes as $child) {
                if ($this->isFootnoteChromeText($child)) {
                    continue;
                }
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['hr', 'br'], true)) {
                    continue;
                }

                return;
            }

            $parent = $node->parentNode;
            $node->parentNode?->removeChild($node);
            $node = $parent;
        }
    }

    /**
     * Escape the brackets that would end a link or image label early.
     *
     * Takes text that has already been through `processNode`, so its literal
     * backslashes are doubled already; doubling them here as well produced
     * `[a \\\\ b]` for a label containing one backslash. The raw `alt` attribute
     * has NOT been through that path, so its call site doubles first.
     */

    /**
     * Harden a label whose text would open a NOTE REFERENCE.
     *
     * A span and an inline link both write their content in a bracket run,
     * and `[^x]` is a note reference (PART 11 §2). So an element whose text
     * begins with a caret came back as a reference instead of as itself:
     * `<abbr title="y">^1</abbr>` was written `[^1]{abbr=y}`, which renders
     * `<p>[^1]</p>` - the span gone and the attribute block literal text
     * (carve-php#1615).
     *
     * ONLY THE LABELED HALF COLLIDES, which is the other half of §2 and the
     * half that is wrong silently, because an idle escape passes every gate
     * aimed at the missing one. The parser's rule is `[^` followed by at
     * least one character that is not `]` or a line break, so `[^]` is not a
     * reference and keeps no escape, and a caret anywhere but the first
     * position is ordinary punctuation.
     *
     * An IMAGE label is not this slot: `![^1](u)` is an image whose
     * alternative text is `^1`, because the `!` takes the `[` first.
     *
     * @param string $text The label text, already escaped as prose.
     *
     * @return string The label, with a colliding caret escaped.
     */
    protected function escapeNoteReferenceLabel(string $text): string
    {
        return preg_match('/^\\^[^\\]\\r\\n]/u', $text) === 1 ? '\\' . $text : $text;
    }

    protected function escapeLinkOrImageLabel(string $text): string
    {
        return str_replace(
            ['[', ']'],
            ['\[', '\]'],
            $text,
        );
    }

    protected function requiresRawImageFallback(string $alt): bool
    {
        // The raw-HTML fallback re-emits the original element verbatim (a
        // `{=html}` block), so untrusted HTML could smuggle live script /
        // event handlers (`<img onerror=...>`) through it. Only trusted input
        // may use it; otherwise fall through to safe `![alt](src)` processing.
        if (!$this->trustedRoundTrip) {
            return false;
        }

        return strpbrk($alt, '[]\\') !== false;
    }

    /**
     * Turn each boundary sentinel line into the three blank lines PART 9
     * §11 N1a spells the hard list boundary with.
     *
     * WHATEVER SITS TO THE SENTINEL'S LEFT IS THE PREFIX, and the blank lines
     * are written with it: inside a block quote the boundary is three `>` lines,
     * not three empty ones, which would end the quote instead of splitting the
     * list inside it. That is why the sentinel opens a line rather than joining
     * two - every container that indents line by line has already prefixed it
     * by the time this runs.
     *
     * The blank line the walk left on either side is absorbed, so the run is
     * exactly three however the two lists were laid out - a nested pair arrives
     * with none, a top-level pair with one on each side.
     *
     * @param list<string> $lines The cleaned-up lines.
     *
     * @return list<string> The lines with every sentinel expanded.
     */
    protected function expandListBoundaries(array $lines): array
    {
        $expanded = [];
        $dropBlank = null;
        foreach ($lines as $line) {
            if ($dropBlank !== null) {
                $blank = $dropBlank;
                $dropBlank = null;
                if (rtrim($line) === $blank) {
                    continue;
                }
            }

            $at = strpos($line, self::LIST_BOUNDARY);
            if ($at === false) {
                $expanded[] = $line;

                continue;
            }

            $prefix = rtrim(substr($line, 0, $at));
            if ($expanded !== [] && rtrim((string)end($expanded)) === $prefix) {
                array_pop($expanded);
            }
            $expanded[] = $prefix;
            $expanded[] = $prefix;
            $expanded[] = $prefix;
            $dropBlank = $prefix;
        }

        return $expanded;
    }

    protected function cleanup(string $djot): string
    {
        // Remove leading whitespace from lines (except in code blocks and indented content)
        $lines = explode("\n", $djot);
        $inCodeBlock = false;
        $inDefinitionList = false;
        $inList = false;
        $inFootnote = false;
        $lineBlockFence = 0;
        // The width of the `%%%` comment fence currently open, or 0.
        $commentFence = 0;
        $result = [];
        // Which emitted lines sit inside a fence, so the blank-line collapse
        // below can leave their blanks alone.
        $verbatim = [];

        foreach ($lines as $line) {
            // The boundary sentinel passes through untouched, whatever a
            // container prefix put to its left. It has to be caught ahead of
            // every other branch: those trim and re-indent, and the expansion
            // at the end of this method reads the prefix off this line.
            if (str_contains($line, self::LIST_BOUNDARY)) {
                $result[] = $line;

                continue;
            }

            // Track line blocks (::: line-block ... :::) so verse indentation
            // is preserved verbatim - the default branch below ltrims lines.
            if ($lineBlockFence > 0) {
                $verbatim[count($result)] = true;
                $result[] = $line;
                if (preg_match('/^(:{3,})\s*$/', $line, $lbm) === 1 && strlen($lbm[1]) >= $lineBlockFence) {
                    $lineBlockFence = 0;
                }

                continue;
            }
            if (preg_match('/^(:{3,})\s+\|/', $line, $lbm) === 1) {
                $lineBlockFence = strlen($lbm[1]);
                $verbatim[count($result)] = true;
                $result[] = $line;

                continue;
            }

            // A `%%%` COMMENT FENCE IS VERBATIM, like the code fence below and
            // the line block above (`markup-carve/carve#1709`).
            //
            // The default branch of this loop LTRIMS, and a comment's body is
            // the author's bytes: `<!-- c -->` imports as a fence around ` c `,
            // and without this the leading space was gone before the source was
            // returned - the same silent content change the comment rule exists
            // to stop, one layer down in the writer.
            //
            // It could not have shown up before, because no importer path wrote
            // a `%%%` fence until comments were kept.
            if ($commentFence > 0) {
                $verbatim[count($result)] = true;
                $result[] = $line;
                if (preg_match('/^(%{3,})[ \t]*$/', $line, $cfm) === 1 && strlen($cfm[1]) >= $commentFence) {
                    $commentFence = 0;
                }

                continue;
            }
            if (preg_match('/^(%{3,})[ \t]*$/', $line, $cfm) === 1) {
                $commentFence = strlen($cfm[1]);
                $verbatim[count($result)] = true;
                $result[] = $line;

                continue;
            }

            // Track code blocks
            if ($this->codeFenceDelimiter($line) !== null) {
                $inCodeBlock = !$inCodeBlock;
                $result[] = $line;

                continue;
            }

            if ($inCodeBlock) {
                $verbatim[count($result)] = true;
                $result[] = $line;

                continue;
            }

            if (preg_match('/^\[\^[^\]]+\]:\s*/', $line) === 1) {
                $result[] = $line;
                $inDefinitionList = false;
                $inList = false;
                $inFootnote = true;

                continue;
            }

            // Track definition lists (`:: term` / `:  definition`)
            if (str_starts_with($line, ':: ') || str_starts_with($line, ':  ')) {
                $inDefinitionList = true;
                $inList = false;
                $inFootnote = false;
                $result[] = $line;

                continue;
            }

            // Preserve indentation for list items and track list context.
            //
            // An ordered marker is not only decimal: `a.`, `A.`, `iv.` and
            // `IV.` are markers too, and the parser reads them as such. While
            // only `\d+\.` was recognized here, an alphabetic or Roman item
            // fell through to the ltrim branch, so a list nested under one lost
            // its indentation and dedented out of its parent - which only
            // showed up once anything emitted those markers.
            //
            // A marker may ABUT an attribute brace pair - `1.{#fn1} n` is where
            // an item's attributes go (carve-php#1587) - and the space the
            // marker is recognized by comes after that pair, not before it.
            // Without the optional group the attributed marker line fell to the
            // ltrim branch, which also closed the list context, so every
            // continuation line under the item was flattened to column zero and
            // the item's later blocks dedented out of it.
            //
            // The pair is spelled as `ListParser` spells it, quoted values and
            // all: a title an editor export carries a `}` in - and a `<li>` may
            // - is inside quotes, and a plain `[^{}]*` ended the block at it.
            if (
                preg_match(
                    '/^(\s*)([-*+]|\d+\.|[A-Za-z]\.|[ivxlcdm]{2,}\.|[IVXLCDM]{2,}\.)'
                    . '(\{(?:[^{}"\']|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')*\})?\s/',
                    $line,
                    $m,
                )
            ) {
                $result[] = $line;
                $inDefinitionList = false;
                $inList = true;
                $inFootnote = false;

                continue;
            }

            // Preserve indented attribute blocks after list items (li attributes)
            if ($inList && preg_match('/^\s+\{[^{}]+\}\s*$/', $line)) {
                $result[] = $line;

                continue;
            }

            // Preserve indented continuation lines inside list items
            if ($inList && preg_match('/^\s{2,}\S/', $line)) {
                $result[] = $line;

                continue;
            }

            // Preserve indentation for definition content (indented lines after `: term`)
            if ($inDefinitionList && preg_match('/^  /', $line)) {
                $result[] = $line;

                continue;
            }

            // Preserve standalone attribute blocks in definition lists (dt/dd attributes)
            if ($inDefinitionList && preg_match('/^\{[^{}]+\}\s*$/', $line)) {
                $result[] = $line;

                continue;
            }

            if ($inFootnote && preg_match('/^\s{2,}\S/', $line)) {
                $result[] = $line;

                continue;
            }

            // Blank line (or whitespace-only line) ends definition list context but not list context
            if (trim($line) === '') {
                $result[] = $inFootnote ? '  ' : ''; // Normalize to empty string unless footnote continuation needs indentation

                continue;
            }

            // Regular line - trim leading whitespace and reset contexts
            $result[] = ltrim($line);
            $inDefinitionList = false;
            $inList = false;
            $inFootnote = false;
        }

        // Normalize runs of blank lines to ONE, but never inside a fence: a
        // blank line there is content, and collapsing it rewrote the payload -
        // `<pre><code>a\n\n\nb</code></pre>` came back with one blank line
        // where the source had two (carve-php#1543).
        $collapsed = [];
        $previousWasBlank = false;
        foreach ($result as $index => $line) {
            $isVerbatim = isset($verbatim[$index]);
            if (!$isVerbatim && $line === '' && $previousWasBlank) {
                continue;
            }
            $previousWasBlank = !$isVerbatim && $line === '';
            $collapsed[] = $line;
        }

        $djot = implode("\n", $this->expandListBoundaries($collapsed));

        // Remove leading/trailing whitespace
        $djot = trim($djot);

        return $djot . "\n";
    }
}
