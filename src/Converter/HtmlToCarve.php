<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use InvalidArgumentException;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Util\StringUtil;
use RuntimeException;

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
     */
    public function convertWithReport(string $html): HtmlImportResult
    {
        $diagnostics = $this->inspectImportLoss($html);

        return new HtmlImportResult(
            $this->convert($html),
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
        $wrapped = preg_match('/^\s*(<!doctype|<html|<body)/i', $html) === 1 ? $html : '<div>' . $html . '</div>';
        $doc = new DOMDocument();
        $doc->encoding = 'UTF-8';
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $diagnostics = [];
        $root = $doc->documentElement ?? $doc;
        $this->inspectImportNode($root, '', 1, $diagnostics);

        return $diagnostics;
    }

    /**
     * @param \DOMNode $node
     * @param string $parentPath
     * @param int $index
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    protected function inspectImportNode(DOMNode $node, string $parentPath, int $index, array &$diagnostics): void
    {
        if (!$node instanceof DOMElement) {
            return;
        }
        $tag = strtolower($node->tagName);
        $path = $parentPath . '/' . $tag . '[' . $index . ']';
        if (in_array($tag, ['script', 'style', 'template', 'noscript'], true)) {
            $this->addImportDiagnostic($diagnostics, 'element-dropped', 'Dropped active <' . $tag . '> element', 'warning', $path);

            return;
        }

        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if (str_starts_with($name, 'on')) {
                $this->addImportDiagnostic($diagnostics, 'attribute-dropped', 'Dropped event-handler attribute ' . $name . ' on <' . $tag . '>', 'warning', $path);
            } elseif ($name === 'style') {
                $this->addImportDiagnostic($diagnostics, 'style-unmapped', 'CSS declarations may not have a Carve mapping', 'info', $path);
            } elseif (!$this->isRepresentedImportAttribute($tag, $name)) {
                $this->addImportDiagnostic($diagnostics, 'attribute-dropped', 'Dropped unsupported attribute ' . $name . ' on <' . $tag . '>', 'info', $path);
            }
        }

        if (!$this->isKnownImportElement($tag)) {
            $this->addImportDiagnostic(
                $diagnostics,
                'element-unwrapped',
                'Replaced unsupported <' . $tag . '> element with Carve span metadata',
                'info',
                $path,
            );
        }

        $elementIndex = 0;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $elementIndex++;
            $this->inspectImportNode($child, $path, $elementIndex, $diagnostics);
        }
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

    protected function isRepresentedImportAttribute(string $tag, string $name): bool
    {
        if ($name === 'title') {
            return true;
        }
        if ($name === 'id' || $name === 'class' || str_starts_with($name, 'data-')) {
            return true;
        }

        return match ($tag) {
            // `datetime` is where <time> carries the value the span attribute
            // takes, so it is represented rather than dropped.
            'time' => $name === 'datetime',
            'a' => in_array($name, ['href', 'title', 'target', 'rel'], true),
            'img' => in_array($name, ['src', 'alt', 'title', 'width', 'height'], true),
            'ol' => in_array($name, ['start', 'type'], true),
            'input' => in_array($name, ['type', 'checked', 'disabled'], true),
            'td', 'th' => in_array($name, ['rowspan', 'colspan', 'align'], true),
            default => false,
        };
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
            'del', 'mark', 'sub', 'sup', 'code', 'pre', 'a', 'img', 'br', 'hr',
            'span', 'ul', 'ol',
            'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th',
            'td', 'caption',
            'figure', 'figcaption', 'blockquote', 'cite', 'abbr', 'input',
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
        'onclick', 'onload', 'onmouseover', 'onmouseout', 'onsubmit', // JS events
        'xmlns', // XML namespace
        'role', // ARIA (could be kept, but often noise)
    ];

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
                : $this->escapePlainCarveInlineSyntax($this->escapeAttributeBlockOpener($this->escapeVerbatimDelimiter($this->escapeLiteralBackslashes($text))));
        }

        if (!($node instanceof DOMElement)) {
            // Process children for other node types
            return $this->processChildren($node);
        }

        $tagName = strtolower($node->tagName);

        $djotSrc = $this->extractRoundTripSource($node, $tagName);
        if ($djotSrc !== null) {
            return $djotSrc;
        }

        if ($tagName === 'section' && $this->isInlineOnlyEndnotesSection($node)) {
            return '';
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
        $output = '';
        foreach ($node->childNodes as $child) {
            $output .= $this->processNode($child);
        }

        return $output;
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
        if ($hasExplicitId && $sectionId !== '' && $firstHeading !== null) {
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

        // Process section content as a normal block
        $content = $this->processBlock($node);

        return $prefix . $content;
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
                if ($attr->name !== 'class' && !in_array($attr->name, $this->skipAttributes, true) && !str_starts_with($attr->name, 'data-djot-')) {
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
            if ($name === 'id' || $name === 'class' || in_array($name, $this->skipAttributes, true) || str_starts_with($name, 'data-djot-')) {
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

        $skipAttrs = ['id', 'class', ...$this->skipAttributes];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (in_array($name, $skipAttrs, true) || str_starts_with($name, 'data-djot-')) {
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
        $skipAttrs = ['id', 'class', 'data-djot-admonition-type', 'data-djot-admonition-title', ...$this->skipAttributes];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (in_array($name, $skipAttrs, true) || str_starts_with($name, 'data-djot-')) {
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
     * Process details element (generic disclosure container)
     */
    protected function processDetails(DOMElement $node): string
    {
        return $this->processGenericBlockContainer($node);
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
        $skipAttrs = ['id', 'class', ...$this->skipAttributes];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (in_array($name, $skipAttrs, true) || str_starts_with($name, 'data-djot-')) {
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

        // One space between the fence and the language word, which is what the
        // canonical writer emits: `carve fmt` rewrites ```` ```js ```` to
        // ```` ``` js ```` in every engine. `docs/html-import.md` ends the
        // import pipeline at that writer, so an importer spelling the opener
        // its own formatter would rewrite is the same defect one construct over.
        $opener = $backticks . ($language === '' ? '' : ' ' . $language);

        return $attrs . "\n" . $opener . "\n" . rtrim($content) . "\n" . $backticks . "\n\n";
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
        // Check for attribution (footer or cite element)
        $attributionNode = $this->findBlockquoteAttribution($node);
        $attribution = $attributionNode !== null
            ? trim($this->processChildren($attributionNode))
            : null;

        // Process content excluding attribution elements, preserving paragraph breaks
        $parts = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }

            // Skip the ONE element read as the attribution. Any other footer
            // or cite is ordinary quoted content and stays in the body.
            if ($child === $attributionNode) {
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

        // A `footer`/`cite` is the quote's ATTRIBUTION, and Carve spells that as
        // a caption line BELOW the quote (PART 9 §4a, carve#1159) - not as a
        // second quoted paragraph, which is ordinary quoted content and comes
        // back as such. Without this the renderer's own `<blockquote><footer>`
        // did not survive a round trip through this importer.
        //
        // A caption folds its continuation lines like a paragraph, so a
        // multi-line attribution needs the marker on its first line only.
        if ($attribution !== null) {
            $first = true;
            foreach (explode("\n", $attribution) as $line) {
                $quoted[] = ($first ? '^ ' : '') . rtrim($line);
                $first = false;
            }
        }

        $attrs = $this->formatBlockAttributes($node);

        return $attrs . "\n" . implode("\n", $quoted) . "\n\n";
    }

    /**
     * The `footer`/`cite` child that carries the quote's attribution, if any.
     *
     * The LAST one, because that is the element this renderer emits and the one
     * an author puts after the quoted text. A quote has ONE attribution and the
     * slot holds inline content, so an earlier footer cannot join it - it stays
     * ordinary quoted content rather than being dropped, which is what taking
     * the first one and skipping every other did.
     */
    protected function findBlockquoteAttribution(DOMElement $node): ?DOMElement
    {
        $found = null;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (($tag !== 'footer' && $tag !== 'cite') || trim($this->processChildren($child)) === '') {
                continue;
            }
            // The slot holds INLINE content, so an element carrying blocks does
            // not fit it. Flattening one would run its paragraphs together with
            // no separator; leaving it an ordinary block inside the quote keeps
            // every word, which is the better answer when the shape cannot be
            // represented.
            foreach ($child->childNodes as $inner) {
                if ($inner instanceof DOMElement && in_array(strtolower($inner->tagName), $this->blockElements, true)) {
                    continue 2;
                }
            }
            $found = $child;
        }

        return $found;
    }

    /**
     * Process MathML element to Djot math syntax
     *
     * Attempts to extract LaTeX from:
     * 1. alttext attribute
     * 2. annotation element with encoding="application/x-tex" or "LaTeX"
     * 3. Falls back to text content
     */
    protected function processMath(DOMElement $node): string
    {
        $isDisplay = $node->getAttribute('display') === 'block';

        // Try alttext attribute first (common in MathJax output)
        $latex = $node->getAttribute('alttext');
        if ($latex !== '') {
            return $this->renderMath($latex, $isDisplay);
        }

        // Look for annotation element with LaTeX encoding
        $annotations = $node->getElementsByTagName('annotation');
        foreach ($annotations as $annotation) {
            $encoding = $annotation->getAttribute('encoding');
            if (stripos($encoding, 'tex') !== false || stripos($encoding, 'latex') !== false) {
                $latex = trim($annotation->textContent);
                if ($latex !== '') {
                    return $this->renderMath($latex, $isDisplay);
                }
            }
        }

        // Fall back to rendered MathML text, excluding annotation payloads.
        $text = trim($this->extractMathText($node));
        if ($text !== '') {
            return $this->renderMath($text, $isDisplay);
        }

        return '';
    }

    protected function renderMath(string $content, bool $isDisplay): string
    {
        $delimiter = $isDisplay ? '$$' : '$';
        $backticks = StringUtil::findSafeCodeFence($content, 1);

        return $delimiter . $backticks . $content . $backticks . $delimiter;
    }

    protected function extractMathText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->textContent;
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if ($tag === 'annotation' || $tag === 'annotation-xml') {
                return '';
            }
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->extractMathText($child);
        }

        return $text;
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
            $marker = $marker ?: '.';
        } elseif ($marker === '' || $marker === '+') {
            // No explicit marker (or a stray `+`, which is the continuation
            // marker, not a Carve bullet): pick `-`/`*` by the parity of
            // preceding adjacent sibling <ul>s so that two back-to-back bullet
            // lists stay distinct in Carve instead of merging into one list.
            $marker = $this->alternatingBulletMarker($node);
        }

        // Add leading newline for top-level lists to ensure blank line before
        if ($this->listDepth === 1) {
            // Add list-level attributes (skip 'start', 'data-marker', 'class' for task-list)
            $skipAttrs = $isOrdered ? ['start', 'data-marker'] : ['data-marker'];
            if ($isTaskList) {
                $skipAttrs[] = 'class';
                $skipAttrs[] = 'data-type';
            }
            $listAttrs = $this->formatBlockAttributes($node, $skipAttrs);
            $output .= $listAttrs . "\n";
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                if ($child->hasAttribute('data-djot-inline-footnote')) {
                    continue;
                }

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

                $prefix = $isOrdered ? $counter . $marker . ' ' : $marker . ' ' . $checkbox;

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
            $captionText = trim($this->processChildren($captionElement));
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
            $isHeader = false;

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
                if ($cellAttrs !== '') {
                    $attributedCells[count($cells)] = true;
                    $cells[] = '{' . $cellAttrs . '} ' . $cellContent;
                } else {
                    $cells[] = $cellContent;
                }

                if ($tag === 'th') {
                    $isHeader = true;
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

                $row = $this->buildTableRowLine($cells, $attributedCells) . $rowAttrSuffix;

                if ($isHeader && $headerRow === null) {
                    $headerRow = $row;
                    $headerRowAttrs = $rowAttrSuffix;
                    $headerCells = $cells;
                    $headerAttributedCells = $attributedCells;
                } else {
                    $rows[] = $row;
                }
            }
        }

        // Table-level attributes (excluding data-djot-col-widths which is for round-trip)
        $tableAttrs = $this->formatBlockAttributes($node, ['data-djot-col-widths']);
        $output = $tableAttrs . "\n";

        if ($headerRow !== null) {
            $colWidthsAttr = $node->getAttribute('data-djot-col-widths');

            // A header cell carrying an attribute block can't use the tight
            // `|=` form unambiguously, so fall back to the separator form.
            $headerHasCellAttrs = $headerAttributedCells !== [];

            // Also fall back to separator form when header has span markers (`<`/`^`),
            // because `|= < |` is not valid Carve syntax for a colspan continuation.
            $headerHasSpanMarkers = false;
            foreach ($headerCells as $hc) {
                if ($hc === '<' || $hc === '^') {
                    $headerHasSpanMarkers = true;

                    break;
                }
            }

            if ($colWidthsAttr === '' && !$headerHasCellAttrs && !$headerHasSpanMarkers) {
                // Canonical Carve: `|=` header cells (alignment via `<`/`>`/`~`
                // markers on the header cell), no separator row. Used unless the
                // source was a GFM table (recorded via data-djot-col-widths).
                $headerLine = '|';
                foreach ($headerCells as $i => $cell) {
                    $marker = $this->tableAlignMarker($alignments[$i] ?? TableCell::ALIGN_DEFAULT);
                    $headerLine .= '=' . $marker . ' ' . $cell . ' |';
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
            ? trim($this->processChildren($captionElement))
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
     */
    protected function buildTableRowLine(array $cells, array $attributed): string
    {
        $line = '|';

        foreach ($cells as $index => $cell) {
            $line .= (isset($attributed[$index]) ? '' : ' ') . $cell . ' |';
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

        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
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
        $output = "\n";

        // Find img, blockquote, and figcaption
        $img = $this->findFirstDirectChildByTagName($node, 'img');
        $blockquote = $this->findFirstDirectChildByTagName($node, 'blockquote');
        $caption = $this->findFirstDirectChildByTagName($node, 'figcaption');

        if ($this->hasOnlySupportedFigureContent($node) && $img instanceof DOMElement) {
            $output .= $this->processImage($img) . "\n";
        } elseif ($this->hasOnlySupportedFigureContent($node) && $blockquote instanceof DOMElement) {
            $output .= $this->processBlockquote($blockquote);
            // Remove the trailing blank line since caption follows immediately
            $output = rtrim($output) . "\n";
        } else {
            return $this->processGenericFigureContent($node);
        }

        if ($caption instanceof DOMElement) {
            $output .= $this->formatCaptionText(trim($this->processChildren($caption)));
        }

        return $output . "\n\n";
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

        return in_array($contentChildren[0], ['img', 'blockquote'], true);
    }

    protected function processGenericFigureContent(DOMElement $node): string
    {
        $output = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
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
        $allSkip = array_merge($this->skipAttributes, $skipAttrs);

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

            // Skip already processed and skip-list attributes
            if ($name === 'id' || $name === 'class') {
                continue;
            }
            if (in_array($name, $allSkip, true)) {
                continue;
            }
            // Drop ALL event-handler attributes (onerror, onfocus, …), not just
            // the few named in the skip list, so imported HTML cannot launder an
            // XSS handler into Carve attributes.
            if (str_starts_with(strtolower($name), 'on')) {
                continue;
            }
            if (str_starts_with($name, 'data-djot-')) {
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

            // Preserve indentation for list items and track list context
            if (preg_match('/^(\s*)([-*+]|\d+\.)\s/', $line, $m)) {
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
