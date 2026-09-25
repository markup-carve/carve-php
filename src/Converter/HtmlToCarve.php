<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use InvalidArgumentException;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use RuntimeException;
use SplObjectStorage;
use Throwable;

/**
 * Converts HTML to Carve markup
 *
 * Useful for importing HTML content from CMS systems, WYSIWYG editors,
 * or web scraping into Carve format.
 *
 * Key Carve requirements handled:
 * - Blank lines required around block elements (headings, code blocks, lists)
 * - Nested lists require blank line before the nested portion
 *
 * SECURITY: this converter is NOT a sanitizer. Its output is Carve markup
 * that may still contain content derived from the input; render untrusted input
 * with safe mode enabled on the downstream renderer. By default the converter
 * IGNORES any `data-djot-src` round-trip attribute on the input (it would
 * otherwise be emitted verbatim as raw Carve, allowing a crafted attribute to
 * inject a raw-HTML block). Only enable round-trip extraction via the
 * constructor flag when the HTML is TRUSTED (e.g. produced by carve itself).
 *
 * @phpstan-consistent-constructor
 */
class HtmlToCarve
{
    use ReportsMigrationFidelity;

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
     * The body written for a definition description holding no blocks
     * (PART 11 §7b, markup-carve/carve#1827).
     *
     * A block-attribute line: the block it would attach to does not exist, so
     * the parse consumes the line and the description reads back empty. It is
     * what the canonical writer emits, so the source this converter produces
     * and the tree read back from it say the same thing.
     *
     * @var string
     */
    protected const EMPTY_BODY_SENTINEL = '{empty}';

    /**
     * A written table row whose every cell is blank, before its row attributes.
     *
     * @var string
     */
    protected const BLANK_TABLE_ROW = '/^\|(?:=? *\|)+$/';

    /**
     * Canonical definition marker.
     *
     * @var string
     */
    protected const DEFINITION_BODY_MARKER = ': ';

    /**
     * Continuation indent matching the canonical marker width.
     *
     * @var string
     */
    protected const DEFINITION_BODY_INDENT = '  ';

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
     * The mark pair a browser draws around a `<q>`, indexed by nesting parity:
     * double outside, single one level in, double again below that.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    protected const QUOTE_MARKS = [
        ['“', '”'],
        ['‘', '’'],
    ];

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
     * Elements an HTML parser reads the content of as TEXT rather than as
     * markup, so nothing inside them can fire.
     *
     * The HTML tokenizer's raw-text and RCDATA sets, minus the four
     * `ACTIVE_ELEMENTS` above, which never reach a walk that asks this.
     *
     * @var list<string>
     */
    protected const TEXT_CONTENT_ELEMENTS = ['textarea', 'iframe', 'title', 'xmp', 'noembed', 'noframes', 'plaintext'];

    /**
     * A `url(...)` argument in a CSS declaration value, with the quotes CSS
     * allows around it stripped.
     *
     * @var string
     */
    protected const CSS_URL_ARGUMENT = '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*?))\s*\)/i';

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

    protected bool $usedStoredRoundTripSource = false;

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
        $this->captureImportIdentity = true;
        try {
            $carve = $this->convert($html);
        } finally {
            $this->captureImportIdentity = false;
        }

        // Handed to the walk through a property rather than an argument:
        // `inspectImportLoss()` is protected on a non-final class, so a
        // subclass may override it, and adding a parameter would make such an
        // override a fatal incompatible-signature error at class-declaration
        // time - which no test of behavior catches, because the class never
        // loads.
        $this->inspectedCarve = $carve;
        $this->emittedHasRawHtml = null;

        try {
            $diagnostics = $this->inspectImportLoss($html);
        } finally {
            $this->inspectedCarve = null;
            $this->emittedHasRawHtml = null;
            $this->builtImportDocument = null;
            $this->keptRawImportElements = null;
        }

        return new HtmlImportResult(
            $carve,
            $this->importMode,
            $this->importAdapter,
            $diagnostics,
        );
    }

    public function convertWithFidelityReport(string $html): MigrationResult
    {
        return $this->htmlMigrationResult($this->convertWithReport($html));
    }

    /**
     * Convert HTML to the public PART 12 AST and retain the import report.
     *
     * The source and AST exits use the same direct HTML-to-AST builder. The AST
     * exit disables source-only renderer hints so it exposes only the public
     * PART 12 tree.
     */
    public function convertToAstWithReport(string $html): HtmlImportAstResult
    {
        $source = $this->convertWithReport($html);
        $normalized = $this->normalizeHtmlForDirectAst($html);

        return new HtmlImportAstResult(
            self::withoutTheWriter((new HtmlAstBuilder(
                $this->listTableForBlockCells,
                $this->importMode,
                $this->trustedRoundTrip,
                false,
                $this->alignmentClasses,
                $this->labels,
            ))->build($normalized, strlen($html))),
            $source->mode,
            $source->adapter,
            array_values(array_filter(
                $source->diagnostics,
                static fn (HtmlImportDiagnostic $diagnostic): bool => !($diagnostic->code === 'structure-unspellable'
                    && (str_starts_with($diagnostic->message, 'Flattened <ruby> annotations')
                        // Only a WRITER loses an ordered task item's box (PART 12
                        // section 16): this tree keeps `checked` on the item, so
                        // the row the source exit owes would be a loss that did
                        // not happen here (carve-php#2381).
                        || str_starts_with($diagnostic->message, 'Wrote an ordered task item'))),
            )),
        );
    }

    /**
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
     * One value of the encoded tree, with the writer's escapes undone.
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
        foreach ($value as $key => $inner) {
            $value[$key] = self::asPublished($inner);
        }

        return $value;
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

        if ($this->usedStoredRoundTripSource) {
            // No HTML descendants were imported; the stored source was returned verbatim.
            return [];
        }

        $isDocument = preg_match('/^\s*(<!doctype|<html|<body)/i', $html) === 1;
        $doc = $this->builtImportDocument;
        if ($doc === null) {
            $wrapped = $isDocument ? $html : '<div>' . $html . '</div>';
            $doc = HtmlDomLoader::load($wrapped);
            $this->normalizeAdapterFootnotes($doc);
        }

        $diagnostics = [];
        $root = $this->builtImportDocument !== null
            ? ($doc->getElementsByTagName('carve-import-root')->item(0) ?? $doc->documentElement ?? $doc)
            : ($doc->documentElement ?? $doc);

        try {
            if ($isDocument) {
                $document = HtmlDomLoader::load($html);
                $this->inspectImportDocumentContainers($document->documentElement ?? $document, $diagnostics);
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
        if ($tag === 'input' && $this->directAstConsumesCheckbox($node)) {
            $this->consumedCheckboxInputs[$path] = true;
            if ($this->checkboxStandsInAnOrderedItem($node)) {
                $this->orderedTaskCheckboxInputs[$path] = true;
            }
        }
        if (in_array($tag, self::ACTIVE_ELEMENTS, true)) {
            $this->addImportDiagnostic($diagnostics, 'element-dropped', 'Dropped active <' . $tag . '> element', 'warning', $path);

            return;
        }
        if ($tag === 'ruby' && !($this->importMode === 'roundtrip' && $this->rubyHasNoDirectAnnotation($node))) {
            $this->inspectRubyImport($node, $path, $diagnostics);

            return;
        }

        if ($this->inspectImportNodeStructure($node, $tag, $path, $diagnostics)) {
            return;
        }

        $outerConsumedCheckbox = $this->inspectedConsumedCheckbox;
        $outerOrderedTaskCheckbox = $this->inspectedOrderedTaskCheckbox;
        $this->inspectedConsumedCheckbox = $tag === 'input' && isset($this->consumedCheckboxInputs[$path])
            ? $path
            : null;
        $this->inspectedOrderedTaskCheckbox = $tag === 'input' && isset($this->orderedTaskCheckboxInputs[$path])
            ? $path
            : null;

        try {
            // Report the element first; element and attribute checks use separate survivor budgets.
            if ($tag === 'q') {
                $this->addImportDiagnostic(
                    $diagnostics,
                    'element-unwrapped',
                    'Read <q> as quotation marks: Carve has no quotation element, so the marks are the mapping',
                    'info',
                    $path,
                );
            } elseif ($this->inspectedOrderedTaskCheckbox !== null) {
                // The row the box's own loss owes, in place of the element and
                // attribute rows it would otherwise spend: a Carve task marker
                // is spelled behind a bullet only, so the characters survive and
                // the task-item semantics do not (carve-php#2381). Named at the
                // `<input>`'s own path, the way carve-rs#1904 pinned it.
                $this->addImportDiagnostic(
                    $diagnostics,
                    'structure-unspellable',
                    'Wrote an ordered task item\'s checkbox as its bracket text: a Carve task marker is spelled '
                        . 'behind a bullet only, so the item keeps the characters and loses the task-item semantics',
                    'warning',
                    $path,
                );
            } elseif (!$this->isKnownImportElement($tag) && $tag !== 'math') {
                $this->reportImportElementOutcome($node, $tag, $path, $diagnostics);
            }

            $this->inspectImportAttributes($node, $tag, $path, $diagnostics);
        } finally {
            $this->inspectedConsumedCheckbox = $outerConsumedCheckbox;
            $this->inspectedOrderedTaskCheckbox = $outerOrderedTaskCheckbox;
        }

        if ($tag === 'math') {
            // Check attributes first, then consume math descendants as one unit.
            $this->inspectMath($node, $path, $diagnostics);

            return;
        }

        if ($this->isOrphanImportCaption($node, $tag) && !$this->importContentSurvived($node)) {
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
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Replaced <details> with its content inside a table cell; a pipe-table cell cannot hold a colon fence',
                'info',
                $path,
            );
        }

        if ($tag === 'summary' && trim($node->textContent) !== '' && $this->detailsSummaryTitle($node) === null) {
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Kept the <summary> text as block content; its label needs a quoted opener title, which cannot hold a quote or a line break',
                'info',
                $path,
            );
        }

        $directLoneImage = $tag === 'p' ? $this->directAstLoneImage($node) : null;
        if (
            $tag === 'p'
            && $directLoneImage instanceof DOMElement
            && $this->importParagraphIsWrittenAsABlock($node)
            && !($node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'figure')
        ) {
            $paragraphAttrs = $this->writtenImportAttributeNames($node);
            $lost = [
                'attributed' => $paragraphAttrs !== [] || $node->hasAttribute('class'),
                'overwritten' => $this->overwrittenImportImageAttributes($node, $directLoneImage),
            ];
            $head = 'A paragraph holding nothing but an image has no Carve spelling; '
                . 'the image is written as a block';
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
            $this->addImportDiagnostic(
                $diagnostics,
                'element-dropped',
                'Dropped whitespace-only <' . $tag . '> holding no content character',
                'warning',
                $path,
            );
        }

        if ($tag === 'a' && $this->importDestinationIsEmpty($node->getAttribute('href'))) {
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

        if ($tag === 'br' && $this->hardBreakIsFlattened($node)) {
            $this->addImportDiagnostic(
                $diagnostics,
                'structure-unspellable',
                'Wrote a <br> in a table cell as a space: a pipe-table cell is one line and has no hard break',
                'warning',
                $path,
            );
        }

        if ($tag === 'code' && $this->emptyCodeSpanIsDropped($node)) {
            $this->addImportDiagnostic(
                $diagnostics,
                'structure-unspellable',
                'Dropped an empty <code>: its backtick run is closed by the end of a block or by a forced '
                    . 'span, and here the run would read what follows it as code instead',
                'warning',
                $path,
            );
        }

        $this->inspectImportChildren($node, $tag, $path, $diagnostics);

        if ($this->directAstCaptionFlattens($node) && $this->hasImportContentToUnwrap($node)) {
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Unwrapped unsupported <' . $tag . '> element',
                'info',
                $path,
            );
        }
        if ($this->directAstCellFlattens($node)) {
            $keepsContent = $this->directAstHasSurvivingContent($node);
            $this->addImportDiagnostic(
                $diagnostics,
                $keepsContent ? 'element-unwrapped' : 'element-dropped',
                $keepsContent ? 'Unwrapped unsupported <' . $tag . '> element' : 'Dropped empty <' . $tag . '> element',
                $keepsContent ? 'info' : 'warning',
                $path,
            );
        }
    }

    /**
     * @param \DOMElement $node
     * @param string $tag
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    private function inspectImportNodeStructure(DOMElement $node, string $tag, string $path, array &$diagnostics): bool
    {
        $parent = $node->parentNode;
        $kind = $parent instanceof DOMElement ? $this->formattingKind($node) : null;
        if (
            $parent instanceof DOMElement
            && $kind !== null
            && $kind === $this->formattingKind($parent)
        ) {
            $this->addImportDiagnostic(
                $diagnostics,
                'structure-unspellable',
                'Unwrapped a span inside a span of the same kind, which has no Carve spelling',
                'warning',
                $path,
            );
        }

        if ($this->importKeepsElementRaw($node)) {
            $this->inspectImportAttributeList($node, $tag, $path, $diagnostics, true);
            $this->addImportDiagnostic(
                $diagnostics,
                'raw-preserved',
                $tag === 'figure'
                    ? 'Preserved a <figure> as raw HTML: no Carve spelling reproduces a figure around this target'
                    : 'Preserved unsupported <' . $tag . '> element as raw HTML',
                'warning',
                $path,
            );
            $this->inspectPreservedDescendants($node, $tag, $path, $diagnostics);

            return true;
        }

        if ($tag === 'colgroup' && $this->isDirectTableChild($node)) {
            $this->addImportDiagnostic(
                $diagnostics,
                'element-dropped',
                'Dropped <colgroup>: Carve has no column model, and a table\'s columns are only the cells its rows carry',
                'warning',
                $path,
            );

            return true;
        }

        if (
            $tag === 'tr'
            && $this->directAstBlankTableRow($node)
        ) {
            $this->addImportDiagnostic(
                $diagnostics,
                'structure-unspellable',
                'Dropped a row whose every cell is empty: Carve reads such a row as text',
                'warning',
                $path,
            );
            if ($this->directAstBlankRowDropsCaption($node)) {
                $this->addImportDiagnostic($diagnostics, 'element-dropped', 'Dropped a caption whose table has no row left', 'warning', $path);
            }
        }

        if ($this->directAstUnwraps($node)) {
            $hasContent = $this->directAstHasSurvivingContent($node);
            if ($hasContent) {
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

        $figureOutcome = $tag === 'figure' ? $this->directAstFigureOutcome($node) : null;
        if ($figureOutcome === 'table-rebuild') {
            $this->addImportDiagnostic(
                $diagnostics,
                'structure-unspellable',
                'A figure wrapping a table has no Carve spelling; the caption is written on the table, '
                    . 'which renders <caption> inside it',
                'warning',
                $path,
            );
        }

        if (
            $tag === 'figcaption'
            && $node->parentNode instanceof DOMElement
            && strtolower($node->parentNode->tagName) === 'figure'
            && $this->directAstFigureOutcome($node->parentNode) === 'table-detach'
        ) {
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Detached a <figcaption> into a paragraph after the table: the table\'s own <caption> fills '
                    . "Carve's one caption slot, so the figure's caption keeps its text and loses its role",
                'warning',
                $path,
            );
        }

        if (
            $figureOutcome === 'unwrap'
        ) {
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Unwrapped unsupported <figure> element',
                'info',
                $path,
            );
        }

        return false;
    }

    /**
     * @param \DOMElement $node
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectRubyImport(DOMElement $node, string $path, array &$diagnostics): void
    {
        $base = false;
        $pendingRb = 0;
        $paired = false;
        $afterAnnotation = false;
        foreach ($node->childNodes as $index => $child) {
            if ($child instanceof DOMComment) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                if ($child->textContent !== '' && !($afterAnnotation && trim($child->textContent) === '')) {
                    $base = true;
                    $afterAnnotation = false;
                }

                continue;
            }
            $tag = strtolower($child->tagName);
            $childPath = $this->importChildPath($path, $child, $index + 1);
            if ($tag === 'rp') {
                $this->reportRubyComponentAttributes($child, $childPath, $diagnostics);
                $content = trim($child->textContent);
                if ($content !== '' && !in_array($content, ['(', ')', '（', '）'], true)) {
                    $this->addImportDiagnostic($diagnostics, 'element-dropped', 'Dropped non-standard <rp> fallback content', 'warning', $childPath);
                }

                continue;
            }
            if ($tag === 'rt') {
                $this->reportRubyComponentAttributes($child, $childPath, $diagnostics);
                if ($pendingRb > 0) {
                    $pendingRb--;
                    $paired = true;
                    $base = false;
                    $afterAnnotation = true;
                } elseif ($base) {
                    $paired = true;
                    $base = false;
                    $afterAnnotation = true;
                } else {
                    $this->addImportDiagnostic(
                        $diagnostics,
                        'element-unwrapped',
                        $afterAnnotation ? 'Flattened an additional ruby annotation level' : 'Unwrapped ruby annotation with no base',
                        'warning',
                        $childPath,
                    );
                }
                $this->inspectImportNodes($child->childNodes, $childPath, $diagnostics);

                continue;
            }
            if ($tag === 'rtc') {
                $this->reportRubyComponentAttributes($child, $childPath, $diagnostics);
                $this->addImportDiagnostic($diagnostics, 'element-unwrapped', 'Unwrapped obsolete <rtc> annotation level', 'warning', $childPath);
                foreach ($child->childNodes as $componentIndex => $component) {
                    if ($component instanceof DOMElement && strtolower($component->tagName) === 'rt') {
                        $componentPath = $this->importChildPath($childPath, $component, $componentIndex + 1);
                        $this->reportRubyComponentAttributes($component, $componentPath, $diagnostics);
                        $this->inspectImportNodes($component->childNodes, $componentPath, $diagnostics);
                    }
                }

                continue;
            }
            if ($tag === 'rb') {
                $this->reportRubyComponentAttributes($child, $childPath, $diagnostics);
                $pendingRb++;
                $base = true;
                $this->inspectImportNodes($child->childNodes, $childPath, $diagnostics);

                continue;
            }
            $base = true;
            $afterAnnotation = false;
            $this->inspectImportNode($child, $childPath, $diagnostics);
        }
        if ($base) {
            $this->addImportDiagnostic($diagnostics, 'element-unwrapped', 'Unwrapped ruby base with no annotation', 'info', $path);
        }
        if ($paired) {
            $this->addImportDiagnostic(
                $diagnostics,
                'structure-unspellable',
                'Flattened <ruby> annotations: Carve 0.1 has no source spelling for their pairing',
                'warning',
                $path,
            );
        }
        $this->inspectImportAttributes($node, 'ruby', $path, $diagnostics);
    }

    protected function rubyHasNoDirectAnnotation(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['rt', 'rtc'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \DOMElement $component
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function reportRubyComponentAttributes(DOMElement $component, string $path, array &$diagnostics): void
    {
        foreach ($component->attributes as $attribute) {
            $this->addImportDiagnostic(
                $diagnostics,
                'attribute-dropped',
                'Dropped attribute ' . $attribute->name . ' on <' . strtolower($component->tagName) . '>',
                'info',
                $path,
            );
        }
    }

    private function directAstCaptionFlattens(DOMElement $node): bool
    {
        if (!$this->isFlattenedInACaption(strtolower($node->tagName))) {
            return false;
        }
        for ($ancestor = $node->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            $tag = strtolower($ancestor->tagName);
            if ($tag === 'caption') {
                return true;
            }
            if ($tag === 'figcaption') {
                $figure = $ancestor->parentNode;

                return $figure instanceof DOMElement
                    && strtolower($figure->tagName) === 'figure'
                    && in_array($this->directAstFigureOutcome($figure), ['survives', 'table-rebuild'], true);
            }
        }

        return false;
    }

    private function directAstCellFlattens(DOMElement $node): bool
    {
        if (
            $this->usedStoredRoundTripSource
            || !$this->isFlattenedInACaption(strtolower($node->tagName))
            || $this->directAstCaptionFlattens($node)
            || $this->directAstUnwraps($node)
            || strtolower($node->tagName) === 'figure'
        ) {
            return false;
        }
        for ($ancestor = $node->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if (in_array(strtolower($ancestor->tagName), ['td', 'th'], true)) {
                return !$this->cellIsWrittenAsAListTableItem($ancestor);
            }
        }

        return false;
    }

    private function directAstUnwraps(DOMElement $node): bool
    {
        $tag = strtolower($node->tagName);
        if ($tag === 'aside') {
            $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];

            return !in_array('admonition', $classes, true) && !in_array('note', $classes, true);
        }
        if ($tag === 'section' && strtolower($node->getAttribute('role')) === 'doc-endnotes') {
            return false;
        }

        return in_array(
            $tag,
            ['article', 'main', 'header', 'footer', 'nav', 'section', 'address', 'dialog', 'fieldset', 'form', 'hgroup', 'menu', 'search'],
            true,
        );
    }

    private function directAstHasSurvivingContent(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) !== '') {
                return true;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::ACTIVE_ELEMENTS, true)) {
                continue;
            }
            if ($tag === 'hr' || $tag === 'br') {
                return true;
            }
            if ($tag === 'img' && !HtmlAstBuilder::carriesNoDestination($child->getAttribute('src'))) {
                return true;
            }
            if ($this->directAstHasSurvivingContent($child)) {
                return true;
            }
        }

        return false;
    }

    private function directAstLoneImage(DOMElement $paragraph): ?DOMElement
    {
        $find = function (DOMElement $container) use (&$find): ?DOMElement {
            $found = null;
            foreach ($container->childNodes as $child) {
                if ($child instanceof DOMText) {
                    if (trim($child->textContent) !== '') {
                        return null;
                    }

                    continue;
                }
                if (!$child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag === 'img') {
                    if ($found !== null || HtmlAstBuilder::carriesNoDestination($child->getAttribute('src'))) {
                        return null;
                    }
                    $found = $child;

                    continue;
                }
                if (!in_array($tag, ['span', 'picture', 'source', 'figure'], true)) {
                    return null;
                }
                if ($tag !== 'source' && $child->attributes->length !== 0) {
                    return null;
                }
                $nested = $find($child);
                if ($nested === null || $found !== null) {
                    return null;
                }
                $found = $nested;
            }

            return $found;
        };

        return $find($paragraph);
    }

    private function importKeepsElementRaw(DOMElement $node): bool
    {
        if (strtolower($node->tagName) === 'figure') {
            return $this->directAstFigureOutcome($node) === 'raw';
        }
        if ($this->importMode !== 'roundtrip') {
            return false;
        }

        if ($this->keptRawImportElements !== null) {
            return isset($this->keptRawImportElements[$node]);
        }

        // Stored source and standalone inspection bypass the builder's identity map.
        return $this->emittedKeepsElementBytes($node);
    }

    private function emittedKeepsElementBytes(DOMElement $node): bool
    {
        if ($this->emittedHasRawHtml === null) {
            $this->emittedHasRawHtml = str_contains($this->inspectedCarve ?? '', '=html');
        }
        if ($this->emittedHasRawHtml === false) {
            return false;
        }
        $html = $node->ownerDocument?->saveHTML($node);
        if (!is_string($html) || $html === '') {
            return false;
        }
        $lines = explode("\n", rtrim($html, "\n"));
        if (!str_contains($this->inspectedCarve ?? '', $lines[0])) {
            return false;
        }
        // A continuation line inside a list item or a block quote carries that
        // container's prefix, so the bytes are matched line by line with the
        // prefix allowed between them. Anchoring on the OPENING fence instead
        // would have to read past the same prefix and gets the harder direction
        // of the two wrong: a kept element read as dropped.
        $bytes = implode('\n[ \t>]*', array_map(
            static fn (string $line): string => preg_quote($line, '/'),
            $lines,
        ));

        // A raw inline span closes on its backtick run and `{=html}`; a raw block
        // closes on a newline and its fence.
        return preg_match('/' . $bytes . '`+\{=html\}/', $this->inspectedCarve ?? '') === 1
            || preg_match('/' . $bytes . '\n[ \t>]*`{3,}/', $this->inspectedCarve ?? '') === 1;
    }

    private function directAstFigureOutcome(DOMElement $figure): string
    {
        $keepsRaw = $this->importMode === 'roundtrip'
            && !HtmlAstBuilder::holdsADeniedDestination($figure)
            && !HtmlAstBuilder::aRowRefusesTheRegion($figure);
        $caption = null;
        $captionWrites = false;
        $body = [];
        foreach ($figure->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                if (trim($child->textContent) !== '' || $child->getElementsByTagName('*')->length > 0) {
                    $caption = $child;
                    $captionWrites = trim($child->textContent) !== '';
                }

                continue;
            }
            $body[] = $child;
        }
        if (!$caption instanceof DOMElement) {
            return 'unwrap';
        }
        if (count($body) !== 1 || !$body[0] instanceof DOMElement) {
            return $keepsRaw ? 'raw' : 'unwrap';
        }
        $target = $body[0];
        $tag = strtolower($target->tagName);
        if ($tag === 'p') {
            $meaningful = [];
            foreach ($target->childNodes as $child) {
                if ($child instanceof DOMText && trim($child->textContent) === '') {
                    continue;
                }
                $meaningful[] = $child;
            }
            if (
                count($meaningful) === 1
                && $meaningful[0] instanceof DOMElement
                && strtolower($meaningful[0]->tagName) === 'img'
                && !HtmlAstBuilder::carriesNoDestination($meaningful[0]->getAttribute('src'))
            ) {
                return 'survives';
            }
        }
        if ($tag === 'img' && !HtmlAstBuilder::carriesNoDestination($target->getAttribute('src'))) {
            return 'survives';
        }
        if ($tag === 'picture') {
            foreach ($target->getElementsByTagName('img') as $image) {
                if (!HtmlAstBuilder::carriesNoDestination($image->getAttribute('src'))) {
                    return 'survives';
                }
            }
        }
        if ($tag === 'figure') {
            foreach ($target->childNodes as $child) {
                if (
                    $child instanceof DOMElement
                    && strtolower($child->tagName) === 'figcaption'
                    && (trim($child->textContent) !== '' || $child->getElementsByTagName('*')->length > 0)
                ) {
                    return 'unwrap';
                }
            }

            return 'survives';
        }
        if (in_array($tag, ['blockquote', 'pre'], true)) {
            return 'survives';
        }
        if ($tag === 'table') {
            if (!$captionWrites) {
                return 'table-rebuild';
            }
            foreach ($target->getElementsByTagName('caption') as $tableCaption) {
                if (trim($tableCaption->textContent) !== '') {
                    return $keepsRaw ? 'raw' : 'table-detach';
                }
            }

            return 'table-rebuild';
        }

        return $keepsRaw ? 'raw' : 'unwrap';
    }

    /**
     * Does this consumed checkbox stand in an ORDERED item, where Carve has no
     * task marker to write it as?
     */
    private function checkboxStandsInAnOrderedItem(DOMElement $input): bool
    {
        $item = $input->parentNode;
        if ($item instanceof DOMElement && strtolower($item->tagName) === 'label') {
            $item = $item->parentNode;
        }
        $list = $item instanceof DOMElement ? $item->parentNode : null;

        return $list instanceof DOMElement && strtolower($list->tagName) === 'ol';
    }

    /**
     * Is the element under inspection an ordered task item whose
     * `data-task-state` the writer spelled into the item's bracket text?
     *
     * The same set `HtmlAstBuilder` consumes, on the same condition: a state of
     * `x` on an UNCHECKED box is not consumed there, stays an item attribute and
     * keeps the row it owes.
     */
    private function orderedTaskStateReachedTheBrackets(): bool
    {
        $item = $this->inspectedElement;
        if (!$item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
            return false;
        }
        $state = $item->getAttribute('data-task-state');
        if (!in_array($state, ['-', 'x', 'X', ' '], true)) {
            return false;
        }
        foreach ($item->getElementsByTagName('input') as $input) {
            $holder = $input->parentNode;
            if ($holder instanceof DOMElement && strtolower($holder->tagName) === 'label') {
                $holder = $holder->parentNode;
            }
            if ($holder !== $item || !$this->directAstConsumesCheckbox($input)) {
                continue;
            }
            if (!$this->checkboxStandsInAnOrderedItem($input)) {
                return false;
            }

            return !in_array($state, ['x', 'X'], true) || $input->hasAttribute('checked');
        }

        return false;
    }

    private function directAstConsumesCheckbox(DOMElement $input): bool
    {
        if (strtolower($input->getAttribute('type')) !== 'checkbox') {
            return false;
        }
        $container = $input->parentNode;
        if ($container instanceof DOMElement && strtolower($container->tagName) === 'label') {
            $container = $container->parentNode;
        }
        if (!$container instanceof DOMElement || strtolower($container->tagName) !== 'li') {
            return false;
        }
        foreach ($container->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }
            if ($child === $input) {
                return true;
            }
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'label') {
                foreach ($child->childNodes as $labelChild) {
                    if ($labelChild instanceof DOMText && trim($labelChild->textContent) === '') {
                        continue;
                    }

                    return $labelChild === $input;
                }
            }

            return false;
        }

        return false;
    }

    private function directAstBlankTableRow(DOMElement $row): bool
    {
        $sawCell = false;
        foreach ($row->childNodes as $cell) {
            if (
                !$cell instanceof DOMElement
                || !in_array(strtolower($cell->tagName), ['td', 'th'], true)
            ) {
                continue;
            }
            $sawCell = true;
            if (trim($cell->textContent) !== '') {
                return false;
            }
            foreach ($cell->getElementsByTagName('*') as $descendant) {
                if (strtolower($descendant->tagName) !== 'hr') {
                    return false;
                }
            }
        }

        return $sawCell;
    }

    private function directAstBlankRowDropsCaption(DOMElement $row): bool
    {
        $table = $row->parentNode;
        while ($table instanceof DOMElement && strtolower($table->tagName) !== 'table') {
            $table = $table->parentNode;
        }
        if (!$table instanceof DOMElement) {
            return false;
        }
        $lastBlank = null;
        foreach ($table->getElementsByTagName('tr') as $candidate) {
            if (!$this->directAstBlankTableRow($candidate)) {
                return false;
            }
            $lastBlank = $candidate;
        }
        if ($lastBlank !== $row) {
            return false;
        }
        foreach ($table->getElementsByTagName('caption') as $caption) {
            if (trim($caption->textContent) !== '' || $caption->getElementsByTagName('*')->length > 0) {
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
     * `style` takes its own reading of the same two classes, because what is
     * refused sits inside a declaration value rather than in the attribute's
     * name or at the head of it (markup-carve/carve#2267).
     *
     * @param string $tag
     * @param string $name
     * @param string $value
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     * @param string|null $keptTag The preserved ancestor's tag, for a descendant's row.
     */
    protected function reportPreservedAttribute(
        string $tag,
        string $name,
        string $value,
        string $path,
        array &$diagnostics,
        ?string $keptTag = null,
    ): void {
        if ($name === 'style') {
            $subject = self::preservedStyleSubject($value);
            $live = $subject !== 'style';
        } else {
            $handler = str_starts_with($name, 'on');
            $sink = $name === 'srcdoc' || $name === 'formaction';
            $denied = HtmlRenderer::attributeValueHasDeniedScheme($name, $value);
            if ($handler) {
                $subject = 'event-handler attribute ' . $name;
            } elseif ($sink) {
                $subject = 'injection-sink attribute ' . $name;
            } elseif ($denied) {
                $subject = $name . ' with a denied URL scheme';
            } else {
                $subject = 'attribute ' . $name;
            }
            $live = $handler || $sink || $denied;
        }
        $where = $keptTag === null
            ? 'in the raw HTML this element is kept as'
            : 'inside the raw HTML <' . $keptTag . '> is kept as';

        $this->addImportDiagnostic(
            $diagnostics,
            'attribute-preserved',
            'Preserved ' . $subject . ' on <' . $tag . '> ' . $where,
            $live ? 'error' : 'info',
            $path,
        );
    }

    /**
     * What a preserved `style` row names, and by naming it whether the CSS in
     * the kept bytes is LIVE (markup-carve/carve#2267).
     *
     * The reason comes from a closed set of two, so the wording is derived
     * rather than chosen per call: a denied scheme inside `url(...)`, else
     * anything the renderer's own `style` sanitizer blanks the value for.
     * Asking the sanitizer rather than restating its needles is what keeps this
     * from refusing a different set than the renderer does - including the text
     * the needles read, which is why the URL scan runs over the decoded
     * declarations rather than the raw attribute.
     *
     * @param string $value
     *
     * @return string
     */
    protected static function preservedStyleSubject(string $value): string
    {
        $declarations = HtmlRenderer::decodedStyleValue($value);
        if (preg_match_all(self::CSS_URL_ARGUMENT, $declarations, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $url = trim(($match[1] ?? '') . ($match[2] ?? '') . ($match[3] ?? ''));
                if ($url !== '' && HtmlRenderer::blankDangerousScheme($url) === '') {
                    return 'style with a denied URL scheme in a declaration value';
                }
            }
        }
        // BLANKED, not empty. The sanitizer answers `''` for `style=""` too, so
        // asking whether it CHANGED the value is what keeps an empty attribute
        // out of the refused class.
        if (HtmlRenderer::baselineAttributeValue('style', $value) !== $value) {
            return 'style with a construct the CSS sanitizer refuses';
        }

        return 'style';
    }

    /**
     * Every element inside a preserved one is in the kept bytes as well, so its
     * refused attributes get the same rows, in document order
     * (markup-carve/carve#2261).
     *
     * EXCEPT where the kept element holds its content as TEXT. An HTML parser
     * reads what is inside `<textarea>` or `<iframe>` as raw text, so the `<a
     * href="javascript:...">` a validating parser hands this walk as an element
     * is a string in the kept bytes and nothing can fire it. A row there would
     * name a danger that is not present, which is the same false statement as a
     * drop reported over kept bytes, pointing the other way.
     *
     * The element's OWN attributes are unaffected: a handler on the `<textarea>`
     * itself is live and keeps its row.
     *
     * @param \DOMElement $node
     * @param string $keptTag
     * @param string $path
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectPreservedDescendants(DOMElement $node, string $keptTag, string $path, array &$diagnostics): void
    {
        if (in_array(strtolower($node->tagName), self::TEXT_CONTENT_ELEMENTS, true)) {
            return;
        }
        $index = 0;
        foreach ($node->childNodes as $child) {
            $index++;
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $childPath = $this->importChildPath($path, $child, $index);
            foreach ($child->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if ($this->preservedAttributeIsNews($tag, $name, $attribute->value)) {
                    $this->reportPreservedAttribute($tag, $name, $attribute->value, $childPath, $diagnostics, $keptTag);
                }
            }
            $this->inspectPreservedDescendants($child, $keptTag, $childPath, $diagnostics);
        }
    }

    /**
     * A preserved attribute is reported when this importer would have refused
     * it, or when its value carries a scheme the renderer blanks.
     */
    protected function preservedAttributeIsNews(string $tag, string $name, string $value): bool
    {
        return $this->importWouldRefuseAttribute($tag, $name) || HtmlRenderer::attributeValueHasDeniedScheme($name, $value);
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
                if ($this->preservedAttributeIsNews($tag, $name, $attribute->value)) {
                    $this->reportPreservedAttribute($tag, $name, $attribute->value, $path, $diagnostics);
                }

                continue;
            }
            if ((($tag === 'a' && $name === 'href') || ($tag === 'img' && $name === 'src')) && HtmlAstBuilder::hasDeniedScheme($attribute->value)) {
                $this->addImportDiagnostic($diagnostics, 'attribute-dropped', 'Dropped ' . $name . ' with a denied URL scheme on <' . $tag . '>', 'warning', $path);
            } elseif (str_starts_with($name, 'on')) {
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
            } elseif ($name === 'alt' && $tag === 'img' && HtmlAstBuilder::carriesNoDestination($node->getAttribute('src'))) {
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
                continue;
            } elseif (!$this->importAttributeSurvived($tag, $name, $attribute->value)) {
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
     * Check whether the emitted HTML still carries this attribute value.
     * Match values in attribute positions because the converter can change
     * tags. Scope by attribute name so an unrelated attribute cannot answer
     * for this one; an authored `title` may survive under a semantic span key.
     * The tally is consumed in document order, one output occurrence per input.
     * Empty values carry no loss. Values equal to their attribute name, as
     * libxml spells boolean attributes, also use the element's content. This
     * keeps a generated checkbox from answering for a labeled control.
     * Two contentless elements can still collide without node provenance.
     * Other attributes cannot use content as a key: a round trip may rewrite
     * visible text while preserving their values.
     * Classes are compared by token because the renderer may add tokens or
     * normalize spacing.
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
            if ($this->inspectedOrderedTaskCheckbox !== null) {
                // No marker was written behind an ordered item, so there is no
                // emitted `type="checkbox"` to spend. The bracket text carries
                // what the box said, and the `structure-unspellable` row this
                // input already owns carries what it lost (carve-php#2381).
                return true;
            }
            $this->consumeSurvivingAttribute($this->importSurvivorKey('type', 'checkbox'));

            return true;
        }

        // THE BOX'S OWN STATE IS IN THE BRACKETS, on the input and on the item
        // alike. Every other attribute keeps its ordinary treatment, so a `name`
        // or a `value` on that same input still reports the loss it is.
        if ($this->inspectedOrderedTaskCheckbox !== null && in_array($name, ['checked', 'disabled'], true)) {
            return true;
        }
        if ($name === 'data-task-state' && $this->orderedTaskStateReachedTheBrackets()) {
            return true;
        }

        if ($name === 'class') {
            return $this->classTokensSurvived($value);
        }

        if ($this->consumeSurvivingAttribute($this->importSurvivorKey($name, $value))) {
            return true;
        }

        // A browser encodes a space in a URL as `%20` itself, so the writer's
        // `a%20b` is the same destination. Not tabs or newlines: a browser
        // deletes those, so their `%09`/`%0A` is a different URL.
        if ((($tag === 'a' && $name === 'href') || ($tag === 'img' && $name === 'src')) && str_contains($value, ' ')) {
            $encoded = str_replace(' ', '%20', $value);
            if ($this->consumeSurvivingAttribute($this->importSurvivorKey($name, $encoded))) {
                return true;
            }
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

        $doc = HtmlDomLoader::load('<div>' . $html . '</div>');

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
            // `q` IS NOT HERE. It was, on the reading that its marks are the
            // representation Carve has for a quoted phrase and so nothing is
            // lost - but `{+ +}` reads back as an `<ins>` and a mark pair reads
            // back as text, so the element goes where `ins` survives. The walk
            // answers for it above, ahead of this call.
            //
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
     */
    protected function addImportDiagnostic(array &$diagnostics, string $code, string $message, string $severity, string $path): void
    {
        if (count($diagnostics) >= $this->maxDiagnostics) {
            $marker = new HtmlImportDiagnostic('diagnostics-truncated', 'HTML import diagnostics limit reached', 'error');
            if ($diagnostics !== []) {
                array_pop($diagnostics);
            }
            $diagnostics[] = $marker;

            return;
        }
        $diagnostics[] = new HtmlImportDiagnostic($code, $message, $severity, $path);
    }

    protected int $listDepth = 0;

    protected bool $inPre = false;

    /**
     * How many bracketed labels the walk is inside, whose text escapes `[` and `]`.
     */
    protected int $labelDepth = 0;

    /**
     * How many `<q>` elements the walk is inside, which picks the mark pair.
     */
    protected int $quoteDepth = 0;

    /**
     * The marker and continuation indent of the container an inline run is
     * written in, for {@see escapeBlockLineOpeners()}.
     *
     * @var array{0: string, 1: string}
     */
    protected array $blockLineContext = ['', ''];

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
     * Stores complete definition lines in Carve format: "*[ABBR]: Definition"
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
     * Attributes to skip when converting (these don't translate well to Carve)
     *
     * @var array<string>
     */
    protected array $skipAttributes = [
        'style', // CSS doesn't map to Carve
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
     * Convert HTML to Carve markup
     */
    public function convert(string $html): string
    {
        $this->usedStoredRoundTripSource = false;
        $this->builtImportDocument = null;
        $this->keptRawImportElements = null;
        if (preg_match('/^\s*<!doctype\b[^>]*>\s*$/iD', $html) === 1) {
            return '';
        }
        $normalized = $this->normalizeHtmlForDirectAst($html);
        $storedSource = $this->singleStoredRoundTripSource($normalized);
        if ($storedSource !== null) {
            $this->usedStoredRoundTripSource = true;

            return rtrim($storedSource, "\n") . "\n";
        }
        $builder = new HtmlAstBuilder(
            $this->listTableForBlockCells,
            $this->importMode,
            $this->trustedRoundTrip,
            true,
            $this->alignmentClasses,
            $this->labels,
        );
        $tree = $builder->build($normalized, strlen($html));
        if ($this->captureImportIdentity) {
            $this->builtImportDocument = $builder->builtDocument();
            $this->keptRawImportElements = $builder->keptRawElements();
        }
        $document = (new AstCodec())->decodeImporterTree($tree);

        return (new CarveRenderer())->render($document);
    }

    private function singleStoredRoundTripSource(string $html): ?string
    {
        if (!$this->trustedRoundTrip) {
            return null;
        }
        $document = HtmlDomLoader::load('<carve-import-root>' . $html . '</carve-import-root>');
        $root = $document->getElementsByTagName('carve-import-root')->item(0);
        if (!$root instanceof DOMElement) {
            return null;
        }
        $element = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }
            if (!$child instanceof DOMElement || $element instanceof DOMElement) {
                return null;
            }
            $element = $child;
        }

        return $element instanceof DOMElement && $element->hasAttribute('data-djot-src')
            ? $this->reconstructStoredSource(html_entity_decode(
                $element->getAttribute('data-djot-src'),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ))
            : null;
    }

    private function reconstructStoredSource(string $source): string
    {
        return (string)preg_replace_callback(
            '/`(<(th|td|dt|dd)\b[^>]*>.*?<\/\2>)`\{=html\}/is',
            function (array $match): string {
                return trim((new static(
                    false,
                    $this->alignmentClasses,
                    $this->listTableForBlockCells,
                    $this->importMode,
                    $this->importAdapter,
                    $this->maxDiagnostics,
                    $this->labels,
                ))->convert($match[1]));
            },
            $source,
        );
    }

    private function normalizeHtmlForDirectAst(string $html): string
    {
        if (!in_array($this->importAdapter, self::FOOTNOTE_SHAPED_ADAPTERS, true)) {
            return $html;
        }
        $document = HtmlDomLoader::load('<carve-import-root>' . $html . '</carve-import-root>');
        $this->normalizeAdapterFootnotes($document);
        $root = $document->getElementsByTagName('carve-import-root')->item(0);
        if (!$root instanceof DOMElement) {
            return $html;
        }
        $normalized = '';
        foreach ($root->childNodes as $child) {
            $normalized .= $document->saveHTML($child);
        }

        return $normalized;
    }

    /**
     * Convert an HTML file to Carve
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
     * What separates two backtick runs that would otherwise merge into one:
     * an empty delimited comment, the separator the Carve writer writes
     * (markup-carve/carve-php#2107).
     *
     * @var string
     */
    protected const VERBATIM_SEPARATOR = '{%  %}';

    protected bool $captionPendingBoundary = false;

    protected bool $captionPendingNeedsSeparator = false;

    /**
     * The `<input>` elements this conversion CONSUMED into a task marker.
     *
     * @var array<string, true>
     */
    protected array $consumedCheckboxInputs = [];

    /**
     * The paths of the consumed checkboxes an ORDERED item holds, whose box
     * Carve has no marker for.
     *
     * @var array<string, true>
     */
    protected array $orderedTaskCheckboxInputs = [];

    /**
     * The path of the ordered item's checkbox currently being inspected, if any.
     *
     * @var string|null
     */
    protected ?string $inspectedOrderedTaskCheckbox = null;

    /**
     * The path of the consumed checkbox currently being inspected, if any.
     *
     * Set around one element's inspection so the two questions that were
     * answered wrongly can answer for it, and so that NOTHING ELSE about the
     * element changes - see {@see self::inspectImportNode()}.
     */
    protected ?string $inspectedConsumedCheckbox = null;

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
     * @var list<string>
     */
    protected const TIGHT_ITEM_BLOCK_OPENERS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'pre', 'table', 'dl', 'hr', 'details',
    ];

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
     * Elements whose output is their own markup rather than their text.
     *
     * @var array<int, string>
     */
    protected const MARKUP_LED_ELEMENTS = ['audio', 'canvas', 'code', 'embed', 'hr', 'iframe', 'img', 'input', 'kbd', 'math', 'object', 'picture', 'pre', 'samp', 'svg', 'textarea', 'video'];

    /**
     * Is this the accessible name the RENDERER derives for this element?
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
        $html = '';
        foreach ($summary->childNodes as $child) {
            $html .= $summary->ownerDocument?->saveHTML($child) ?? '';
        }
        $title = trim((new self())->convert($html));
        if ($title === '' || str_contains($title, '"') || str_contains($title, "\n")) {
            return null;
        }

        return $title;
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
     * The inline kinds Carve spells with a forced `X}` closer, which is one of
     * the two places an unclosed backtick run ends (PART 3, UNCLOSED RUN).
     *
     * @var array<int, string>
     */
    protected const EMPTY_CODE_CLOSING_TAGS = ['del', 'ins', 'sub', 'sup'];

    /**
     * The five bare kinds, whose closer {@see boundaryDelimiters()} braces when
     * it has to end a run.
     *
     * @var array<int, string>
     */
    protected const EMPTY_CODE_BRACEABLE_TAGS = [
        'b', 'em', 'i', 'mark', 's', 'strike', 'strong', 'u',
    ];

    /**
     * The inline kinds that close with punctuation of their own - `](u)`, `"`,
     * `]{cite}` - which an open run reads as content instead. An attributed
     * `<span>` is one too.
     *
     * @var array<int, string>
     */
    protected const EMPTY_CODE_OPEN_RUN_TAGS = [
        'a', 'abbr', 'cite', 'dfn', 'kbd', 'q', 'samp', 'time', 'var',
    ];

    /**
     * Does this `<code>` leave the document rather than be written wrong?
     *
     * An empty verbatim span is a backtick run nothing closes, so it survives
     * only where the run ITSELF ends: at the end of a block, or at the `X}`
     * closing a forced span (PART 3, UNCLOSED RUN). Anywhere else the run reads
     * what follows as its content, and PART 11 §1c makes that a declared
     * ceiling - `structure-unspellable` - rather than a spelling.
     */
    protected function emptyCodeSpanIsDropped(DOMElement $node): bool
    {
        $parent = $node->parentNode;
        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'pre') {
            return false;
        }

        return $node->textContent === '' && !$this->emptyCodeSpanIsSpellable($node);
    }

    /**
     * @see emptyCodeSpanIsDropped()
     */
    protected function emptyCodeSpanIsSpellable(DOMElement $node): bool
    {
        // A pipe-table row is one line, so only its last cell ends the run. A
        // list table writes each cell as its own block.
        for ($cell = $node->parentNode; $cell instanceof DOMElement; $cell = $cell->parentNode) {
            if (
                in_array(strtolower($cell->tagName), ['td', 'th'], true)
                && !$this->cellEndsItsWrittenRow($cell)
                && !$this->cellIsWrittenAsAListTableItem($cell)
            ) {
                return false;
            }
        }

        while ($this->endsItsImportInlineRun($node)) {
            $parent = $node->parentNode;
            if (!$parent instanceof DOMElement) {
                return true;
            }
            $tag = strtolower($parent->tagName);
            if (
                in_array($tag, static::EMPTY_CODE_OPEN_RUN_TAGS, true)
                || ($tag === 'span' && $parent->attributes->length > 0)
            ) {
                return false;
            }
            if (
                in_array($tag, $this->blockElements, true)
                || in_array($tag, static::BOUNDARY_BLOCK_TAGS, true)
                || in_array($tag, static::EMPTY_CODE_CLOSING_TAGS, true)
                || in_array($tag, static::EMPTY_CODE_BRACEABLE_TAGS, true)
            ) {
                return true;
            }
            // A wrapper with no spelling of its own flattens to its children,
            // so the run ends wherever the wrapper's own position ends it.
            $node = $parent;
        }

        return false;
    }

    /**
     * Is this the last cell {@see processTable()} writes in its row, counting
     * the `<` and `^` markers it writes after a real cell?
     */
    protected function cellEndsItsWrittenRow(DOMElement $cell): bool
    {
        $table = $cell->parentNode;
        while ($table instanceof DOMElement && strtolower($table->tagName) !== 'table') {
            $table = $table->parentNode;
        }
        if (!$table instanceof DOMElement) {
            return true;
        }

        /** @var array<int, int> $rowspanMap */
        $rowspanMap = [];
        foreach ($this->getDirectTableRows($table) as $tr) {
            $isCellRow = $tr === $cell->parentNode;
            $passedCell = false;
            $logicalCol = 0;
            foreach ($tr->childNodes as $candidate) {
                if (!$candidate instanceof DOMElement || !in_array(strtolower($candidate->tagName), ['td', 'th'], true)) {
                    continue;
                }
                if ($passedCell) {
                    return false;
                }
                while (($rowspanMap[$logicalCol] ?? 0) > 0) {
                    $rowspanMap[$logicalCol]--;
                    $logicalCol++;
                }
                $colspan = max(1, (int)$candidate->getAttribute('colspan'));
                $rowspan = max(1, (int)$candidate->getAttribute('rowspan'));
                if ($rowspan > 1) {
                    $rowspanMap[$logicalCol] = ($rowspanMap[$logicalCol] ?? 0) + ($rowspan - 1);
                }
                $logicalCol += $colspan;
                if ($candidate === $cell) {
                    if ($colspan > 1) {
                        return false;
                    }
                    $passedCell = true;
                }
            }
            if ($isCellRow) {
                return ($rowspanMap[$logicalCol] ?? 0) === 0;
            }
            while (($rowspanMap[$logicalCol] ?? 0) > 0) {
                $rowspanMap[$logicalCol]--;
                $logicalCol++;
            }
        }

        return true;
    }

    /**
     * Is this `<br>` in a pipe-table cell, where it is written as a space?
     */
    protected function hardBreakIsFlattened(DOMElement $node): bool
    {
        for ($ancestor = $node->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            $tag = strtolower($ancestor->tagName);
            if ($tag === 'pre') {
                return false;
            }
            if ($tag === 'td' || $tag === 'th') {
                return !$this->cellIsWrittenAsAListTableItem($ancestor);
            }
        }

        return false;
    }

    protected function cellIsWrittenAsAListTableItem(DOMElement $cell): bool
    {
        if (!$this->listTableForBlockCells) {
            return false;
        }
        for ($table = $cell->parentNode; $table instanceof DOMElement; $table = $table->parentNode) {
            if (strtolower($table->tagName) === 'table') {
                return $this->tableHasBlockContentCell($table);
            }
        }

        return false;
    }

    /**
     * Is there nothing after this node that its open run would swallow?
     *
     * Trailing whitespace is not something: every container this reaches trims
     * it. A comment IS, because an inline comment's `#}` closer sits inside the
     * run like any other.
     */
    protected function endsItsImportInlineRun(DOMNode $node): bool
    {
        for ($next = $node->nextSibling; $next !== null; $next = $next->nextSibling) {
            if ($next instanceof DOMText && trim($next->textContent) === '') {
                continue;
            }

            return false;
        }

        return true;
    }

    protected function formattingKind(DOMElement $node): ?string
    {
        return match (strtolower($node->tagName)) {
            'strong', 'b' => '*',
            'em', 'i' => '/',
            'u' => '_',
            's', 'strike' => '~',
            'mark' => '=',
            'ins' => '{+',
            'del' => '{-',
            'sup' => '{^',
            'sub' => '{,',
            default => null,
        };
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

    private ?DOMDocument $builtImportDocument = null;

    private bool $captureImportIdentity = false;

    /**
     * @var \SplObjectStorage<\DOMElement, null>|null
     */
    private ?SplObjectStorage $keptRawImportElements = null;

    private ?bool $emittedHasRawHtml = null;

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

    /**
     * The parser that reads a cell, so this converter can ask it what a cell it
     * is about to write would come back as.
     *
     * @var \MarkupCarve\Carve\Parser\Block\TableParser|null
     */
    protected ?TableParser $cellReader = null;

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
}
