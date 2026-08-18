<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
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
     * @param int $maxDiagnostics
     * @param string $importAdapter
     * @param string $importMode
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

        $this->inspectImportAttributes($node, $tag, $path, $diagnostics);

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

        if (!$this->isKnownImportElement($tag)) {
            $this->reportImportElementOutcome($node, $tag, $path, $diagnostics);
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
    protected function inspectImportAttributeList(DOMElement $node, string $tag, string $path, array &$diagnostics): void
    {
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if (str_starts_with($name, 'on')) {
                $this->addImportDiagnostic($diagnostics, 'attribute-dropped', 'Dropped event-handler attribute ' . $name . ' on <' . $tag . '>', 'warning', $path);
            } elseif ($this->importAttributeIsReadNotWritten($tag, $name)) {
                // Read as instruction or as content, never written back as an
                // attribute - so asking the output for it is the wrong
                // question. See the predicate for why each family qualifies.
                continue;
            } elseif ($name === 'style') {
                $this->addImportDiagnostic($diagnostics, 'style-unmapped', 'CSS declarations may not have a Carve mapping', 'info', $path);
            } elseif ($name === 'scope' && $tag === 'th' && in_array('scope', $this->tableCellSkipAttributes($node), true)) {
                // The value this cell's position generates. It is skipped so a
                // round trip does not write the renderer's own output back as
                // if the author had typed it, and it comes back from the
                // position on the way out - so it is reproduced, not dropped.
                // Same predicate the converter uses, rather than a second one.
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
            $this->inspectImportNode($child, $this->importChildPath($path, $child, $index), $diagnostics);
        }
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
        $this->abbreviationDefinitions = [];
        $this->abbreviationMap = [];

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

            // A backslash in HTML text is a character, not an escape, so it
            // is doubled before the delimiter escaping runs. Inside `pre` the
            // text is verbatim and nothing is escaped at all.
            return $this->inPre
                ? $text
                : $this->escapePlainCarveInlineSyntax(
                    $this->escapeAttributeBlockOpener($this->escapeVerbatimDelimiter($this->escapeLiteralBackslashes($text))),
                    self::HANDLED_PLAIN,
                );
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
            return $this->processChildren($node);
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
            'strong', 'b' => $this->processInlineFormatting($node, '*', '*'),
            'em', 'i' => $this->processInlineFormatting($node, '/', '/'),
            'u' => $this->processInlineFormatting($node, '_', '_'),
            'ins' => $this->processInlineFormatting($node, '{+', '+}'),
            's', 'strike' => $this->processInlineFormatting($node, '~', '~'),
            'del' => $this->processInlineFormatting($node, '{-', '-}'),
            // Single-char delimiters (`=` mark, `^` sup, `,` sub): bare when the
            // element is whitespace-bounded (canonical), forced brace form only
            // intraword (H<sub>2</sub>O, E=mc<sup>2</sup>) where a bare delimiter
            // would not open under the word-boundary rule.
            'mark' => $this->processInlineFormatting($node, ...$this->boundaryDelimiters($node, '=')),
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
            'script', 'style', 'noscript' => '', // Skip these
            default => $this->processChildren($node),
        };
    }

    protected function processChildren(DOMNode $node): string
    {
        if ($this->captionDepth > 0) {
            return $this->processFlattenedChildren($node);
        }

        $output = '';
        foreach ($node->childNodes as $child) {
            $output .= $this->processNode($child);
        }

        return $output;
    }

    /**
     * The children of a node inside an inline-only slot, with the boundary
     * between two flattened blocks kept as bytes.
     *
     * PART 11 SECTION 1b. A slot that takes inline content only has nowhere to
     * put a node for a block boundary, so the boundary survives the flatten in
     * the BYTES or not at all - and the bytes are read by a tokenizer. Where two
     * former sibling blocks each contribute at least one TOKEN to the slot, a
     * separator is required between them, and the canonical one is a single
     * space.
     *
     * THE UNIT IS THE TOKEN, NOT THE NODE. A node test passes `onetwo` and
     * `one two` alike - both are one `text` node - and the difference between
     * them is the whole defect. Joined, `onetwo` is one word, `*a**b*` is one
     * strong run holding a literal asterisk, and two code spans become one span
     * holding the delimiters that used to end and begin them. Nothing is
     * DROPPED in any of them, so no diagnostic fires either.
     *
     * A BLOCK THAT CONTRIBUTES NO TOKEN IS NOT A SIDE:
     * `<p>a</p><p></p><p>b</p>` holds three blocks and ONE join, so the caption
     * is `a b`, with ONE space and not two. A block that emitted NOTHING is
     * skipped before the join is considered; a block that emitted only
     * WHITESPACE needs no arm of its own, because bytes that are already a
     * separator satisfy the test below. A `contributes a token` flag was
     * written first and then removed: no mutation of it could be made to fail,
     * since a non-empty token-less piece begins with whitespace by definition.
     *
     * WHITESPACE ALREADY AT THE JOIN IS THE SEPARATOR. `<p>one </p><p>two</p>`
     * emits `one two` with the source's own space and no second one - the
     * clause asks that re-reading the slot draw no token from both sides, and a
     * space already there answers it.
     * This is not the neighbouring-character conditioning the clause refuses;
     * that would be emitting the separator only where a collision looks likely,
     * and this walk emits it at every join.
     *
     * ONLY BETWEEN TWO BLOCKS. The clause is written over former sibling
     * BLOCKS, so a bare text node between two of them (`<p>a</p>x<p>b</p>`)
     * takes no separator here and the shape is filed rather than decided
     * (markup-carve/carve#1325 ruled the block-block case only).
     */
    protected function processFlattenedChildren(DOMNode $node): string
    {
        $output = '';
        $afterABlock = false;

        foreach ($node->childNodes as $child) {
            $piece = $this->processNode($child);
            if ($piece === '') {
                // A block that emitted nothing is not a side. This is the
                // whole of the empty-block rule that needs stating: a block
                // that emitted only WHITESPACE is answered by the separator
                // test below, since bytes that are already a separator are
                // one.
                continue;
            }

            $isBlock = $child instanceof DOMElement
                && $this->isFormerBlockInAFlattenedSlot(strtolower($child->tagName));

            if ($isBlock && $afterABlock && !$this->joinAlreadyHasASeparator($output, $piece)) {
                $output .= ' ';
            }

            $output .= $piece;
            $afterABlock = $isBlock;
        }

        return $output;
    }

    /**
     * Do the bytes on either side of a join already separate the two sides?
     *
     * UNICODE-AWARE, because the parser is. A NO-BREAK SPACE ends a word and
     * ends a delimiter run exactly as an ordinary space does - `a<nbsp>*b* c`
     * opens a strong run and `one<nbsp>two` is two words - so an `&nbsp;` at
     * the join already answers the clause's test. Matching bytes only added a
     * breakable ASCII space beside a character the author chose for not
     * breaking, which is a wrapping change no block asked for.
     */
    protected function joinAlreadyHasASeparator(string $left, string $right): bool
    {
        // A subject that is not valid UTF-8 makes both of these return false,
        // which emits the separator - the safe direction, since a join with no
        // separator is the defect and a second space is not.
        return preg_match('/\s$/u', $left) === 1 || preg_match('/^\s/u', $right) === 1;
    }

    /**
     * Was this element a BLOCK before the slot flattened it?
     *
     * The flatten set plus the parts a table and a definition list are built
     * from. `isFlattenedInACaption()` answers a different question - which
     * elements dissolve into their content - and a `<td>` does not need to be
     * in it to have been a block: it reaches `processChildren()` through the
     * table arm instead. Two cells still meet at a block boundary, so
     * `<table><tr><td>a</td><td>b</td></tr></table>` is `a b`.
     */
    protected function isFormerBlockInAFlattenedSlot(string $tagName): bool
    {
        return $this->isFlattenedInACaption($tagName)
            || in_array($tagName, ['thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'dt', 'dd'], true);
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
     */
    protected function processCaptionChildren(DOMNode $node): string
    {
        $this->captionDepth++;
        try {
            return $this->processChildren($node);
        } finally {
            $this->captionDepth--;
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
            || $tagName === 'caption'
            || $tagName === 'figcaption';
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

    protected function processBlock(DOMNode $node): string
    {
        $content = '';
        $inlineBuffer = '';

        foreach ($node->childNodes as $child) {
            $isBlock = false;

            if ($child instanceof DOMElement) {
                $tagName = strtolower($child->tagName);
                $isBlock = in_array($tagName, $this->blockElements, true);
            }

            if ($isBlock) {
                // Flush any accumulated inline content as an implicit paragraph
                $inlineText = trim($inlineBuffer);
                if ($inlineText !== '') {
                    $content .= $inlineText . "\n\n";
                }
                $inlineBuffer = '';

                // Process the block element
                $content .= $this->processNode($child);
            } else {
                // Accumulate inline content
                $inlineBuffer .= $this->processNode($child);
            }
        }

        // Flush any remaining inline content
        $inlineText = trim($inlineBuffer);
        if ($inlineText !== '') {
            $content .= $inlineText . "\n\n";
        }

        return trim($content);
    }

    /**
     * Process section elements, handling explicit IDs for round-trip support
     */
    protected function processSection(DOMElement $node): string
    {
        // Check if this is a footnotes section (doc-endnotes)
        if ($node->getAttribute('role') === 'doc-endnotes') {
            return $this->processEndnotesSection($node);
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
        return strcasecmp($sectionId, $expected) !== 0;
    }

    /**
     * Choose a colon-fence string at least one longer than any colon-only line
     * in `$content`, so a NESTED div/admonition (whose closer is a `:::` line)
     * does not prematurely close this fence. A same-length inner fence would
     * close the outer one, so the outer must be longer (grammar §12).
     */
    protected function colonFenceFor(string $content): string
    {
        $fenceLength = 3;
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^(:{3,})\s*$/', trim($line), $m) === 1) {
                $fenceLength = max($fenceLength, strlen($m[1]) + 1);
            }
        }

        return str_repeat(':', $fenceLength);
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

        $classes = $this->getElementClassList($node);
        $fenceClass = array_shift($classes);

        // Check for wrapper div unwrapping: if div has NO class but has attrs
        // and single block child, apply attrs to the child instead of fenced div
        if ($fenceClass === null || $fenceClass === '') {
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
            if ($attrs === '') {
                return $this->degradeToContent($node);
            }

            $content = trim($this->processChildren($node));
            $fence = $this->colonFenceFor($content);
            $output = $attrs . $fence . "\n";
            if ($content !== '') {
                $output .= $content . "\n";
            }

            return $output . $fence . "\n\n";
        }
        if ($fenceClass === 'djot-content' && $classes === [] && $node->getAttribute('id') === '') {
            $hasExtraAttrs = false;
            /** @var \DOMAttr $attr */
            foreach ($node->attributes as $attr) {
                if ($attr->name !== 'class' && !$this->isStrippedImportAttribute($attr->name)) {
                    $hasExtraAttrs = true;

                    break;
                }
            }
            if (!$hasExtraAttrs) {
                return $this->degradeToContent($node);
            }
        }

        $header = $this->extractAdmonitionTitle($node);
        $content = $header === null
            ? trim($this->processChildren($node))
            : $this->processAdmonitionContent($node);
        $parts = [];
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
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
        $attrs = $parts === [] ? '' : '{' . implode(' ', $parts) . "}\n";
        $fence = $this->colonFenceFor($content);
        $headerPart = $header === null ? '' : ' ' . $this->quoteOpenerHeader($header);
        $output = $attrs . $fence . ' ' . $fenceClass . $headerPart . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . $fence . "\n\n";
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
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
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
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        $header = $this->extractAdmonitionTitle($node);
        $content = $this->processAdmonitionContent($node);
        $attrs = $parts === [] ? '' : '{' . implode(' ', $parts) . "}\n";
        $fence = $this->colonFenceFor($content);
        $headerPart = $header === null ? '' : ' ' . $this->quoteOpenerHeader($header);
        $output = $attrs . $fence . ' ' . $type . $headerPart . "\n";
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
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
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
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        // Process content, excluding the title element
        $content = $this->processAdmonitionContent($node);

        $attrs = $parts === [] ? '' : '{' . implode(' ', $parts) . "}\n";
        $fence = $this->colonFenceFor($content);
        $headerPart = $header === null ? '' : ' ' . $this->quoteOpenerHeader($header);
        $output = $attrs . $fence . ' ' . $type . $headerPart . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . $fence . "\n\n";
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
        $output = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                // Skip the title element (p.admonition-title or summary)
                if ($tag === 'p' && $this->hasClass($child, 'admonition-title')) {
                    continue;
                }
                if ($tag === 'summary') {
                    continue;
                }
            }
            $output .= $this->processNode($child);
        }

        return trim($output);
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
        $content = trim($this->processBlock($node));
        $fence = $this->colonFenceFor($content);
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

    protected function processGenericBlockContainer(DOMElement $node): string
    {
        // `<details>` always builds a colon fence, which a cell cannot hold.
        // Every other tag here already degrades to its content once the cell
        // context has emptied its attributes; this makes the one exception
        // behave like the rest (carve-php#1164).
        if ($this->tableCellDepth > 0) {
            return $this->degradeToContent($node);
        }

        $tagName = strtolower($node->tagName);
        $attrs = $this->formatBlockAttributes($node);

        if ($tagName !== 'details' && $attrs === '') {
            return $this->degradeToContent($node);
        }

        $content = trim($this->processBlock($node));
        $fence = $this->colonFenceFor($content);
        $output = $attrs . $fence . ' ' . $tagName . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . $fence . "\n\n";
    }

    /**
     * Process line block div (with class "line-block") for round-trip
     */
    protected function processLineBlock(DOMElement $node): string
    {
        // Build attributes (excluding 'line-block' class)
        $parts = [];
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
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
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        // Extract lines from the content - handle <br> as line separators
        $lines = $this->extractLineBlockLines($node);

        // Choose a fence longer than any colon-only content line, so a verse
        // line that is itself `:::` (or longer) cannot be read as the closer.
        $fenceLength = 3;
        foreach ($lines as $line) {
            if (preg_match('/^(:{3,})\s*$/', $line, $m) === 1) {
                $fenceLength = max($fenceLength, strlen($m[1]) + 1);
            }
        }
        $fence = str_repeat(':', $fenceLength);

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

        return $attrs . $content . "\n\n";
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

        // Skip ID attribute unless it was explicitly set (marked by data-djot-explicit-id)
        // Auto-generated IDs should not be preserved in round-trip
        $skipAttrs = ['data-djot-source-level', 'data-djot-explicit-id', 'data-djot-attrs-handled'];
        if (!$node->hasAttribute('data-djot-explicit-id')) {
            $skipAttrs[] = 'id';
        }
        $attrs = $this->formatBlockAttributes($node, $skipAttrs);

        return $attrs . $prefix . $content . "\n\n";
    }

    /**
     * Choose bare vs forced-brace delimiters for the highlight mark (`=`).
     * A bare delimiter parses only at a word boundary, so emit the bare form
     * (`=x=`) when the element is whitespace-bounded on both sides (or at the
     * start/end of its container) and the forced form (`{=x=}`) otherwise, so
     * an intraword mark still round-trips. Superscript/subscript do not use
     * this helper: they have no bare form and are always braced.
     *
     * @return array{0: string, 1: string}
     */
    protected function boundaryDelimiters(DOMElement $node, string $ch): array
    {
        $prev = $node->previousSibling;
        $next = $node->nextSibling;
        $prevOk = $prev === null
            || ($prev instanceof DOMText
                && ($prev->textContent === '' || ctype_space(substr($prev->textContent, -1))));
        $nextOk = $next === null
            || ($next instanceof DOMText
                && ($next->textContent === '' || ctype_space($next->textContent[0])));

        return ($prevOk && $nextOk) ? [$ch, $ch] : ['{' . $ch, $ch . '}'];
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

        // Glued, not separated - see processBlockquote() for why.
        return $attrs . $opener . "\n" . rtrim($content) . "\n" . $backticks . "\n\n";
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
        if ($this->linkRequiresRawHtmlFallback($node)) {
            return $this->processRawHtmlInlineElement($node);
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

            return '[' . $content . ']{.' . $cssClass . '}';
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

        $text = $this->escapeLinkOrImageLabel($text);

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

        return '';
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

    protected function renderMath(string $content, bool $isDisplay): string
    {
        $delimiter = $isDisplay ? '$$' : '$';
        $backticks = StringUtil::findSafeCodeFence($content, 1);

        return $delimiter . $backticks . $content . $backticks . $delimiter;
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
        $this->listDepth++;
        $isOrdered = strtolower($node->tagName) === 'ol';
        // Recognize both the rendered form (class="task-list") and the TipTap
        // editor form (data-type="taskList").
        $isTaskList = $node->getAttribute('class') === 'task-list'
            || $node->getAttribute('data-type') === 'taskList';
        $output = '';
        $counter = 1;

        // Get start attribute for ordered lists
        if ($isOrdered && $node->hasAttribute('start')) {
            $counter = (int)$node->getAttribute('start');
        }

        // Get marker from data attribute (for round-trip fidelity)
        $marker = $node->getAttribute('data-marker');
        if ($isOrdered) {
            // Same rule the bullets follow below: two back-to-back <ol>s with
            // the same numbering style would merge into one loose list on
            // reparse (`1. a` / `1. b` is one list of two items), and Carve
            // separates sibling lists by their DELIM - so the delimiter
            // alternates `.`/`)` across adjacent ordered siblings
            // (carve-php#1290).
            $marker = $marker ?: $this->alternatingOrderedDelim($node);
        } elseif ($marker === '' || $marker === '+') {
            // No explicit marker (or a stray `+`, which is the continuation
            // marker, not a Carve bullet): pick `-`/`*` by the parity of
            // preceding adjacent sibling <ul>s so that two back-to-back bullet
            // lists stay distinct in Carve instead of merging into one list.
            $marker = $this->alternatingBulletMarker($node);
        }

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
            $output .= $listAttrs . "\n";
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
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                if ($child->hasAttribute('data-djot-inline-footnote')) {
                    continue;
                }

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
                }

                $prefix = $isOrdered
                    ? $this->orderedListMarkerText($counter, $olType) . $marker . ' '
                    : $marker . ' ' . $checkbox;

                // Process list item content, separating nested lists from other content
                $contentParts = [];
                $inlineBuffer = '';
                $nestedContent = '';

                foreach ($child->childNodes as $liChild) {
                    if ($liChild instanceof DOMElement) {
                        $childTag = strtolower($liChild->tagName);
                        if ($childTag === 'ul' || $childTag === 'ol') {
                            // Process nested list separately
                            $nestedContent .= $this->processNode($liChild);
                        } elseif ($childTag === 'input' && $liChild->getAttribute('type') === 'checkbox') {
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
                            $content = trim($this->processNode($liChild));
                            if ($content !== '') {
                                $contentParts[] = $content;
                            }
                        } else {
                            $inlineBuffer .= $this->processNode($liChild);
                        }
                    } else {
                        $inlineBuffer .= $this->processNode($liChild);
                    }
                }

                $this->flushListItemInlineBuffer($contentParts, $inlineBuffer);

                // Add list item attributes on next line (indented). For TipTap
                // task items only, drop the editor's data-type/data-checked
                // markers; ordinary list items keep their attributes.
                $liSkipAttrs = $isTaskList ? ['data-type', 'data-checked'] : [];
                $liAttrs = $this->getElementAttributes($child, $liSkipAttrs);
                $continuation = $indent . str_repeat(' ', strlen($prefix));

                // An item whose ONLY content is a nested list puts that list
                // on the marker line, and the nested block below skips its
                // usual blank separator. Emitting the marker alone gave `- `
                // followed by a blank line, which does not round trip: a marker
                // with nothing after it is not a marker, so it came back as a
                // paragraph reading `-` and the nested list dedented out of the
                // item. `- - a` is also what every engine's own writer emits.
                $markerCarriesNested = $contentParts === [] && $nestedContent !== '';

                if ($contentParts === [] && !$markerCarriesNested) {
                    $output .= $indent . $prefix . "\n";
                    if ($liAttrs !== '') {
                        $output .= $continuation . '{' . $liAttrs . "}\n";
                    }
                } elseif ($contentParts !== []) {
                    $firstPart = array_shift($contentParts);
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
                    if ($liAttrs !== '') {
                        $output .= $continuation . '{' . $liAttrs . "}\n";
                    }
                    foreach ($firstPartLines as $line) {
                        // A blank line is kept as a blank line, not dropped: it
                        // separates the blocks inside the part, and removing it
                        // ran them together.
                        $output .= trim($line) === '' ? "\n" : $continuation . $line . "\n";
                    }

                    foreach ($contentParts as $part) {
                        $output .= "\n" . $this->indentListItemPart($part, $continuation) . "\n";
                    }
                }

                // Add nested list content with blank line before it (required by
                // Djot). The recursive render indents nested content by a fixed
                // two columns per depth; a nested list must instead reach the
                // PARENT item's content column (content-column model, carve#295),
                // which for an ordered marker (`1. ` -> 3, `10. ` -> 4) is wider
                // than two. Pad every non-empty line by that surplus so the
                // nested list re-parses as a child rather than detaching. The
                // task checkbox is content, not marker, so a task/bullet item's
                // content column stays two.
                if ($nestedContent !== '') {
                    $markerWidth = $isOrdered ? strlen($prefix) : 2;
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
                        // would attach to that item instead of this one.
                        $marker = $liAttrs !== ''
                            ? rtrim($prefix) . '{' . $liAttrs . '} '
                            : $prefix;
                        $output .= $indent . $marker . $firstNested . "\n";
                        // Attributes widen the marker, and the content column
                        // moves with it. Without this the following lines sit
                        // at the UNattributed column and dedent out of the
                        // item, splitting the nested list off into its own.
                        $attrSurplus = strlen($marker) - $markerWidth;
                        if ($attrSurplus > 0) {
                            $attrPad = str_repeat(' ', $attrSurplus);
                            $nestedLines = array_map(
                                static fn (string $line): string => $line === '' ? '' : $attrPad . $line,
                                $nestedLines,
                            );
                        }
                        foreach ($nestedLines as $line) {
                            $output .= $line === '' ? "\n" : $line . "\n";
                        }
                    } else {
                        $output .= "\n" . $nestedContent;
                    }
                }

                $counter++;
            }
        }

        $this->listDepth--;

        // Add trailing newline for top-level lists
        return $output . ($this->listDepth === 0 ? "\n" : '');
    }

    /**
     * Pick `-` or `*` for a bullet list so a run of adjacent sibling <ul>s
     * alternates markers. Two same-marker bullet lists separated only by a
     * blank line merge into one list in Carve; alternating keeps them separate.
     *
     * The choice is the opposite of the immediately preceding adjacent <ul>'s
     * actual marker (so an explicit data-marker="*" is respected), or `-` when
     * there is no preceding adjacent bullet list.
     *
     * @param \DOMElement $node The <ul> element
     *
     * @return string `-` or `*`
     */
    protected function alternatingOrderedDelim(DOMElement $node): string
    {
        $prev = $node->previousElementSibling;
        if ($prev instanceof DOMElement && strtolower($prev->tagName) === 'ol') {
            return $this->resolveOrderedDelim($prev) === '.' ? ')' : '.';
        }

        return '.';
    }

    /**
     * Resolve the delimiter an <ol> emits: its explicit data-marker when set,
     * otherwise the alternating default.
     */
    protected function resolveOrderedDelim(DOMElement $node): string
    {
        $marker = $node->getAttribute('data-marker');
        if ($marker !== '') {
            return $marker;
        }

        return $this->alternatingOrderedDelim($node);
    }

    protected function alternatingBulletMarker(DOMElement $node): string
    {
        $prev = $node->previousElementSibling;
        if ($prev instanceof DOMElement && strtolower($prev->tagName) === 'ul') {
            return $this->resolveBulletMarker($prev) === '*' ? '-' : '*';
        }

        return '-';
    }

    /**
     * Resolve the bullet marker a <ul> emits: its explicit data-marker when set
     * (and not the `+` continuation marker), otherwise the alternating default.
     *
     * @param \DOMElement $node The <ul> element
     *
     * @return string `-` or `*`
     */
    protected function resolveBulletMarker(DOMElement $node): string
    {
        $marker = $node->getAttribute('data-marker');
        if ($marker !== '' && $marker !== '+') {
            return $marker;
        }

        return $this->alternatingBulletMarker($node);
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
     * Check if a list item contains a checkbox input
     */
    protected function getDirectCheckboxInput(DOMElement $li): ?DOMElement
    {
        foreach ($li->childNodes as $child) {
            if (
                $child instanceof DOMElement
                && strtolower($child->tagName) === 'input'
                && $child->getAttribute('type') === 'checkbox'
            ) {
                return $child;
            }
        }

        return null;
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
                if ($child->tagName === 'input' && $child->getAttribute('type') === 'checkbox') {
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
                $cellAttrs = $this->getElementAttributes($cell, $this->tableCellSkipAttributes($cell));
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
                    $cells[] = $cellContent;
                }

                $realCells++;
                if ($tag === 'th') {
                    $headerCellCount++;
                }
                if (!isset($alignments[$logicalCol])) {
                    $alignments[$logicalCol] = $this->extractTableCellAlignment($cell);
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
                    $headerRow = $this->buildTableRowLine($cells, $attributedCells) . $rowAttrSuffix;
                    $headerRowAttrs = $rowAttrSuffix;
                    $headerCells = $cells;
                    $headerAttributedCells = $attributedCells;
                } else {
                    $rows[] = $this->buildTableRowLine($cells, $attributedCells, $headerFlags) . $rowAttrSuffix;
                }
            }
        }

        // Table-level attributes (excluding data-djot-col-widths which is for round-trip)
        $tableAttrs = $this->formatBlockAttributes($node, ['data-djot-col-widths']);
        $output = $tableAttrs . "\n";

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
                    $marker = $this->tableAlignMarker($alignments[$i] ?? TableCell::ALIGN_DEFAULT);
                    // The block is already at the head of an attributed cell's
                    // string and glues to the marker run (T10), so that cell
                    // takes no separating space here.
                    $headerLine .= '=' . $marker
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
                    'content' => $this->listTableCellContent($cell),
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

        $output = $attributes === [] ? '' : '{' . implode(' ', $attributes) . "}\n";
        $output .= '::: list-table' . ($caption === '' ? '' : ' "' . str_replace('"', '\\"', $caption) . '"') . "\n";
        $output .= $this->listTableRows($rows);

        return $output . ":::\n\n";
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
     */
    protected function buildTableRowLine(array $cells, array $attributed, array $header = []): string
    {
        $line = '|';

        foreach ($cells as $index => $cell) {
            // `|= x |` is a header cell wherever it stands: in the leading run
            // of header rows it is a column header, below it a row header. The
            // marker is glued to the pipe, the space goes after it - and an
            // attribute block glues to the marker in turn (PART 9 §5 T10), so
            // an attributed cell takes no space between the two.
            $marker = isset($header[$index]) ? '=' : '';
            $line .= $marker . (isset($attributed[$index]) ? '' : ' ') . $cell . ' |';
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
        $output = $dlAttrs !== '' ? $dlAttrs . "\n" : '';

        foreach ($this->definitionListEntries($node) as $child) {
            $tag = strtolower($child->tagName);
            if ($tag === 'dt') {
                $output .= ':: ' . trim($this->processChildren($child)) . "\n";
            } elseif ($tag === 'dd') {
                $lines = explode("\n", trim($this->processChildren($child)));
                $output .= ':  ' . array_shift($lines) . "\n";
                foreach ($lines as $line) {
                    $output .= '   ' . $line . "\n";
                }
            }
        }

        return $output . "\n";
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

    protected function extractTableCellAlignment(DOMElement $cell): string
    {
        $style = $cell->getAttribute('style');
        if ($style !== '' && preg_match('/text-align\s*:\s*(left|right|center)\s*;?/i', $style, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return TableCell::ALIGN_DEFAULT;
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

        $content = $this->processChildren($node);

        // Use getElementAttributes to get all attributes including data-*
        $attrs = $this->getElementAttributes($node);

        // If span has any attributes, convert to Djot span syntax
        if ($attrs !== '') {
            return '[' . $content . ']{' . $attrs . '}';
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

        return '[' . $content . ']{' . implode(' ', $attrParts) . '}';
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
        $img = $this->findFirstDirectChildByTagName($node, 'img');
        $blockquote = $this->findFirstDirectChildByTagName($node, 'blockquote');
        $pre = $this->findFirstDirectChildByTagName($node, 'pre');
        $caption = $this->findFirstDirectChildByTagName($node, 'figcaption');

        if ($this->hasOnlySupportedFigureContent($node) && $img instanceof DOMElement) {
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
        } else {
            return $this->processGenericFigureContent($node);
        }

        if ($caption instanceof DOMElement) {
            $output .= $this->formatCaptionText(trim($this->processCaptionChildren($caption)));
        }

        return $output . "\n\n";
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
        $content = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                continue;
            }
            if (
                $child instanceof DOMElement
                && strtolower($child->tagName) === 'figure'
                && $this->hasClass($child, 'carve-figure-panel')
            ) {
                $content .= $this->processFigurePanel($child);
            } else {
                $content .= $this->processNode($child);
            }
        }
        $content = trim($content);

        $fence = $this->colonFenceFor($content);
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
        try {
            return $this->formatBlockAttributes($node);
        } finally {
            $node->setAttribute('class', $originalClass);
        }
    }

    protected function hasOnlySupportedFigureContent(DOMElement $node): bool
    {
        $contentChildren = [];
        foreach ($node->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                if (trim($child->textContent) !== '') {
                    return false;
                }

                continue;
            }

            if (strtolower($child->tagName) === 'figcaption') {
                continue;
            }

            $contentChildren[] = strtolower($child->tagName);
        }

        if (count($contentChildren) !== 1) {
            return false;
        }

        return in_array($contentChildren[0], ['img', 'blockquote', 'pre'], true);
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
                    $output .= $captionText . "\n\n";
                }

                continue;
            }

            $output .= $this->processNode($child);
        }

        return $output;
    }

    /**
     * Format element attributes as Djot block attribute syntax.
     * Returns empty string if no relevant attributes.
     *
     * @param \DOMElement $node The element to extract attributes from
     * @param array<string> $skipAttrs Additional attributes to skip for this element
     *
     * @return string Djot attribute block like "{#id .class key=value}\n" or ""
     */
    protected function formatBlockAttributes(DOMElement $node, array $skipAttrs = []): string
    {
        // Inside a cell there is no line for a block attribute block to sit on,
        // so it would be written as literal text. The attribute is dropped
        // instead (carve-php#1164); the cell's OWN attributes are unaffected -
        // they are written by processTable(), glued to the opening pipe.
        if ($this->tableCellDepth > 0) {
            return '';
        }

        $attrs = $this->getElementAttributes($node, $skipAttrs);
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
     *
     * @return string Formatted attributes (without braces) or empty string
     */
    protected function getElementAttributes(DOMElement $node, array $skipAttrs = []): string
    {
        $parts = [];
        $allSkip = $skipAttrs;

        // Process id first
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
        }

        // Process class (if not skipped)
        if (!in_array('class', $allSkip, true)) {
            $class = $node->getAttribute('class');
            if ($class !== '') {
                $classes = preg_split('/\s+/', trim($class));
                if ($classes) {
                    foreach ($classes as $c) {
                        if ($c !== '') {
                            $parts[] = '.' . $c;
                        }
                    }
                }
            }

            $alignmentClass = $this->extractAlignmentClass($node);
            if ($alignmentClass !== null) {
                $parts[] = '.' . $alignmentClass;
            }
        }

        // Process other attributes
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;

            // Skip already processed and this call's own skips; the POLICY is
            // one question, asked the same way by every writer.
            if ($name === 'id' || $name === 'class') {
                continue;
            }
            if (in_array($name, $allSkip, true) || $this->isStrippedImportAttribute($name)) {
                continue;
            }

            $value = $attr->value;
            if ($value === '') {
                // Boolean attribute
                $parts[] = $name;
            } else {
                $parts[] = $name . '=' . $this->quoteAttributeValue($value);
            }
        }

        return implode(' ', $parts);
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
     * Process the footnotes endnotes section and extract definitions
     */
    protected function processEndnotesSection(DOMElement $node): string
    {
        // Find the <ol> containing footnote definitions
        $ol = $this->findFirstDirectChildByTagName($node, 'ol');
        if (!$ol instanceof DOMElement) {
            return '';
        }

        // Process each <li> footnote definition
        $listItems = $this->getDirectChildElementsByTagName($ol, 'li');
        foreach ($listItems as $li) {
            // Skip inline footnotes (handled separately)
            if ($li->hasAttribute('data-djot-inline-footnote')) {
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

            // Extract content, removing the backlink
            $content = $this->processFootnoteContent($li);
            if ($content !== '') {
                $this->footnoteDefinitions[$label] = $content;
            }
        }

        // Return empty - footnote definitions are appended at the end
        return '';
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

    protected function cleanup(string $djot): string
    {
        // Remove leading whitespace from lines (except in code blocks and indented content)
        $lines = explode("\n", $djot);
        $inCodeBlock = false;
        $inDefinitionList = false;
        $inList = false;
        $inFootnote = false;
        $lineBlockFence = 0;
        $result = [];

        foreach ($lines as $line) {
            // Track line blocks (::: line-block ... :::) so verse indentation
            // is preserved verbatim - the default branch below ltrims lines.
            if ($lineBlockFence > 0) {
                $result[] = $line;
                if (preg_match('/^(:{3,})\s*$/', $line, $lbm) === 1 && strlen($lbm[1]) >= $lineBlockFence) {
                    $lineBlockFence = 0;
                }

                continue;
            }
            if (preg_match('/^(:{3,})\s+\|/', $line, $lbm) === 1) {
                $lineBlockFence = strlen($lbm[1]);
                $result[] = $line;

                continue;
            }

            // Track code blocks
            if (str_starts_with(trim($line), '```')) {
                $inCodeBlock = !$inCodeBlock;
                $result[] = $line;

                continue;
            }

            if ($inCodeBlock) {
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
            if (preg_match('/^(\s*)([-*+]|\d+\.|[A-Za-z]\.|[ivxlcdm]{2,}\.|[IVXLCDM]{2,}\.)\s/', $line, $m)) {
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

        $djot = implode("\n", $result);

        // Normalize multiple blank lines to max 2 (must run after line processing)
        $djot = preg_replace("/\n{3,}/", "\n\n", $djot) ?? $djot;

        // Remove leading/trailing whitespace
        $djot = trim($djot);

        return $djot . "\n";
    }
}
