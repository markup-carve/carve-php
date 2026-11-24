<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use Closure;
use JsonException;
use MarkupCarve\Carve\Diagnostics\Cap;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Resolver;
use MarkupCarve\Carve\Writer\HeapPages;
use MarkupCarve\Carve\Writer\Writer;

/**
 * The HTML importer of import-html.md: HTML in, a 0.2 tree, its canonical source and a diagnostics record out (C2-M-001).
 */
final class HtmlImporter
{
    /**
     * Heap the DOM or the document arrays leave free that is worth handing back before the next tree is built.
     *
     * @var int
     */
    private const FREED = 4 * 1024 * 1024;

    /**
     * Plain opening tags that always add one imported block level in a flow.
     *
     * @var array<string, true>
     */
    private const PLAIN_BLOCK_OPENERS = [
        '<blockquote>' => true,
        '<div>' => true,
        '<aside>' => true,
    ];

    /**
     * HTML flow hosts whose descendants are imported as block children.
     *
     * @var array<string, true>
     */
    private const DEPTH_FLOW_HOSTS = [
        'html' => true,
        'body' => true,
        'blockquote' => true,
    ];

    /**
     * Hosts that contribute at least one imported block level.
     *
     * @var array<string, true>
     */
    private const DEPTH_HTML_CONTAINERS = [
        'blockquote' => true,
    ];

    /**
     * @param string $html
     * @param array<string, mixed> $options `adapter` (C2-M-102), `rawHtml` (C2-M-066), `labels` (C2-M-101), `strictLosses` (C2-M-082),
     *   `allowLosses` and `maxLosses`
     *   (C2-H-204, C2-H-205).
     * @param bool $legacyParser Read with the libxml parser even where `Dom\HTMLDocument` exists.
     * @param \Closure(\MarkupCarve\Carve\Import\HtmlNode): void|null $prepare The Markdown and Djot hub's carriers, set on the parsed HTML.
     * @param \Closure(array<array<string, mixed>>): array<array<string, mixed>>|null $finish The hub's changes to the imported children.
     * @param \Closure(array<\MarkupCarve\Carve\Diagnostics\Diagnostic>): array<\MarkupCarve\Carve\Diagnostics\Diagnostic>|null $report
     *   The hub's changes to the final diagnostics.
     *
     * @throws \MarkupCarve\Carve\Import\ImportInputError C2-M-006, or without `ext-dom`.
     * @throws \MarkupCarve\Carve\OptionError ! An option the importer reads has a value it refuses.
     * @throws \MarkupCarve\Carve\Json\TreeError ! A tree the reader refuses for another reason: an engine defect.
     *
     * @return \MarkupCarve\Carve\Import\ImportResult
     */
    public static function import(
        string $html,
        array $options = [],
        bool $legacyParser = false,
        ?Closure $prepare = null,
        ?Closure $finish = null,
        ?Closure $report = null,
    ): ImportResult {
        if (!HtmlParser::available()) {
            throw new ImportInputError('HTML import requires the PHP DOM extension (ext-dom)');
        }
        $opts = Options::from(array_intersect_key(
            $options,
            ['adapter' => true, 'rawHtml' => true, 'labels' => true, 'strictLosses' => true, 'allowLosses' => true, 'maxLosses' => true],
        ));
        $direct = $opts->adapter === 'generic' && $prepare === null && $finish === null;
        if ($direct && self::exceedsPlainBlockPrefix($html)) {
            throw new ImportInputError('The input exceeds the nesting limits of a tree (C2-A-171)');
        }
        $root = HtmlParser::parse($html, $legacyParser);
        if ($direct && self::exceedsForeignBlockDepth($root)) {
            throw new ImportInputError('The input exceeds the nesting limits of a tree (C2-A-171)');
        }
        SourceLocations::assign($html, $root);
        Adapters::apply($root, $opts->adapter);
        if ($prepare !== null) {
            $prepare($root);
        }
        $context = new ImportContext($opts->rawHtml, $opts->labels);
        self::fragmentWrapperAttributes($html, $context);
        Notes::collect($root->children, $context);
        self::rootLanguage($root, $context);
        foreach ($root->children as $child) {
            self::audit($child, $context);
        }
        Templates::collect($root->children, $context);
        $reader = new BlockReader(new InlineReader($context));
        $children = $root->children === [] ? [] : $reader->flow($root->children, $root, true);
        $children = array_merge($children, self::rebuilt($context, $children), $context->footnotes);
        if ($finish !== null) {
            $children = $finish($children);
        }
        $texts = [];
        self::shareTexts($children, $texts);
        unset($texts);
        // The DOM is done with. Its parent links are cycles, so it is collected before the tree is decoded.
        unset($root, $reader, $child);
        gc_collect_cycles();
        HeapPages::release(self::FREED);
        $document = ['type' => 'document', 'astVersion' => '2.0', 'children' => $children];
        if ($context->rootLang !== null) {
            $document['lang'] = $context->rootLang;
        }
        if ($context->rootDir !== null) {
            $document['dir'] = $context->rootDir;
        }
        if (TreeReader::exceedsDepth($document)) {
            throw new ImportInputError('The input exceeds the nesting limits of a tree (C2-A-171)');
        }
        $diagnostics = $context->diagnostics;
        // C2-M-074: the writer's loss entries follow the importer's.
        $written = Writer::writeWithDiagnostics($document);
        $source = $written->source;
        $diagnostics = array_merge($diagnostics, $written->diagnostics);
        unset($written);
        // The array and the Node tree are never held together: the tree is read from the JSON text alone.
        $json = self::encode($document);
        unset($document, $children);
        // Return the pages the arrays held, so the tree's nodes can take them.
        HeapPages::release(self::FREED);
        $tree = self::readJson($json);
        [$diagnostics, $suppressed] = Cap::apply($diagnostics, $opts, true);
        if ($opts->strictLosses) {
            $diagnostics = array_map(static fn (Diagnostic $d): Diagnostic => new Diagnostic(
                $d->code,
                'error',
                $d->channel,
                $d->message,
                $d->path,
                $d->startOffset,
                $d->endOffset,
                $d->file,
                $d->foreignType,
            ), $diagnostics);
        }

        if ($report !== null) {
            $diagnostics = $report($diagnostics);
        }

        return new ImportResult($tree, [], $diagnostics, $source, $suppressed, $json);
    }

    /**
     * Gives equal short texts one shared array. The tree is a value, so a later change to one copies it; a paragraph
     * repeats the same spaces between its inline nodes thousands of times (carve-php#5722).
     *
     * @param array<mixed> $values
     * @param array<string, array<mixed>> $texts
     */
    private static function shareTexts(array &$values, array &$texts): void
    {
        // By key, not by value: a foreach copy of the list would make every write below copy the whole list.
        foreach (array_keys($values) as $key) {
            $value = $values[$key];
            if (!is_array($value)) {
                continue;
            }
            $text = $value['value'] ?? null;
            if (
                count($value) === 2 && array_key_first($value) === 'type' && $value['type'] === 'text'
                && is_string($text) && strlen($text) <= 32
            ) {
                $values[$key] = $texts[$text] ??= $value;

                continue;
            }
            // Taken out of the list, the value is its only holder and changes in place.
            $values[$key] = null;
            self::shareTexts($value, $texts);
            $values[$key] = $value;
        }
    }

    /**
     * A run of plain block openers at the start of the input is nested in every HTML parser.
     * Stop after that run; all other HTML shapes go through the parsed-tree checks.
     */
    private static function exceedsPlainBlockPrefix(string $html): bool
    {
        $length = strlen($html);
        $at = strspn($html, " \t\r\n");
        $depth = 0;
        while ($at < $length) {
            $found = false;
            foreach (self::PLAIN_BLOCK_OPENERS as $opener => $_) {
                if (substr_compare($html, $opener, $at, strlen($opener)) !== 0) {
                    continue;
                }
                $at += strlen($opener);
                $depth++;
                if ($depth > Tables::BLOCK_DEPTH) {
                    return true;
                }
                $at += strspn($html, " \t\r\n", $at);
                $found = true;

                break;
            }
            if (!$found) {
                break;
            }
        }

        return false;
    }

    /**
     * Refuse a proven over-deep flow before scanning locations or building a tree.
     * A host outside the safe flow set leaves the decision to the tree reader.
     */
    private static function exceedsForeignBlockDepth(HtmlNode $root): bool
    {
        $stack = [[$root, 0]];
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if ($node->isElement()) {
                if (!isset(self::DEPTH_FLOW_HOSTS[$node->name]) || ($node->name === 'blockquote' && $node->attrs !== [])) {
                    continue;
                }
                $depth += isset(self::DEPTH_HTML_CONTAINERS[$node->name]) ? 1 : 0;
                if ($depth > Tables::BLOCK_DEPTH) {
                    return true;
                }
            }
            foreach ($node->children as $child) {
                $stack[] = [$child, $depth];
            }
        }

        return false;
    }

    /**
     * C2-M-001, C2-M-006: the tree passes the reader; one deeper than its limits is refused.
     *
     * @param array<string, mixed> $document
     *
     * @throws \MarkupCarve\Carve\Import\ImportInputError
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function read(array $document): Node
    {
        if (TreeReader::exceedsDepth($document)) {
            throw new ImportInputError('The input exceeds the nesting limits of a tree (C2-A-171)');
        }

        return self::readJson(self::encode($document));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws \MarkupCarve\Carve\Import\ImportInputError
     */
    private static function encode(array $document): string
    {
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE, 100000);
        if ($json === false) {
            throw new ImportInputError('The input exceeds the nesting limits of a tree (C2-A-171)');
        }

        return $json;
    }

    /**
     * @throws \MarkupCarve\Carve\Import\ImportInputError
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function readJson(string $json): Node
    {
        try {
            return TreeReader::read($json);
        } catch (TreeError $e) {
            if (
                $e->reason === 'depth'
                || ($e->pointer === '' && $e->getPrevious() instanceof JsonException)
            ) {
                throw new ImportInputError('The input exceeds the nesting limits of a tree (C2-A-171)');
            }

            throw $e;
        }
    }

    private static function rootLanguage(HtmlNode $root, ImportContext $context): void
    {
        foreach ($root->children as $html) {
            if (!$html->isElement('html')) {
                continue;
            }
            $body = $html->firstElement('body');
            $lang = $body?->attr('lang') ?? $html->attr('lang');
            $dir = $body?->attr('dir') ?? $html->attr('dir');
            if ($lang !== null && $lang !== '') {
                $context->rootLang = $lang;
            }
            if (in_array($dir, ['ltr', 'rtl', 'auto'], true)) {
                $context->rootDir = $dir;
            }
        }
    }

    /**
     * C2-M-004: fragment parsing ignores a literal body opener, but its unused attributes still draw losses.
     */
    private static function fragmentWrapperAttributes(string $html, ImportContext $context): void
    {
        if (HtmlParser::isDocument($html) || preg_match('/^\s*<body\b([^>]*)>/i', $html, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return;
        }
        $body = new HtmlNode(HtmlNode::ELEMENT, 'body');
        $body->sourceStart = $match[0][1];
        $body->sourceEnd = $body->sourceStart + strlen($match[0][0]);
        $body->contentStart = $body->sourceEnd;
        preg_match_all('/([A-Za-z_:][A-Za-z0-9_:.-]*)\s*=\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s>]+)/', $match[1][0], $attributes);
        foreach ($attributes[1] as $name) {
            $name = strtolower($name);
            if ($name !== 'lang' && $name !== 'dir') {
                $context->report($body, 'import-attribute-dropped', 'Attribute ' . $name . ' of body dropped');
            }
        }
    }

    /**
     * C2-M-052, C2-M-054: every attribute the tree cannot keep, once; markup inside code and math is dropped unread.
     */
    private static function audit(HtmlNode $node, ImportContext $context): void
    {
        if (!$node->isElement() || self::silent($node)) {
            return;
        }
        foreach ($node->attrs as $name => $value) {
            $reason = Attrs::dropped((string)$name, $value);
            if ($reason !== null) {
                $context->report($node, 'import-attribute-dropped', 'Attribute ' . $name . ' dropped: ' . $reason);
            }
        }
        if (in_array($node->name, ['math', 'pre', 'code'], true)) {
            return;
        }
        foreach ($node->children as $child) {
            self::audit($child, $context);
        }
    }

    /**
     * The elements that never reach the tree as elements, with their content: their attributes give no entry (C2-M-066).
     */
    private static function silent(HtmlNode $node): bool
    {
        $kind = Elements::treatment($node);

        return $kind === 'drop' || $kind === 'raw' || Elements::isRawSpan($node) || $node->name === 'input';
    }

    /**
     * C2-H-191, C2-H-198: the rebuilt definitions; none for a key whose first `<a>` links `#ID` where the heading lookup
     * of the key finds that heading in the imported tree, which is resolved without those definitions to see.
     *
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     * @param array<array<string, mixed>> $children
     *
     * @return array<array<string, mixed>>
     */
    private static function rebuilt(ImportContext $context, array $children): array
    {
        $all = array_values($context->definitions);
        if ($context->anchorKeys === []) {
            return $all;
        }
        $others = array_values(array_filter($all, static fn (array $d): bool => !isset($context->anchorKeys[J::str($d, 'key')])));
        $probe = array_merge($children, $others, $context->footnotes);
        $probe = self::read(['type' => 'document', 'astVersion' => '2.0', 'children' => $probe]);
        Resolver::resolve($probe, null, Options::from([]));
        $found = [];
        $pending = [$probe];
        while ($pending !== []) {
            $node = array_pop($pending);
            $key = $node->f['key'] ?? null;
            if ($node->type === 'link' && is_string($key) && isset($context->anchorKeys[$key]) && !array_key_exists($key, $found)) {
                $found[$key] = $node->f['href'] ?? null;
            }
            foreach (array_reverse(self::slots($node)) as $child) {
                $pending[] = $child;
            }
        }

        return array_values(array_filter($all, static fn (array $d): bool => !isset($context->anchorKeys[J::str($d, 'key')])
            || ($found[J::str($d, 'key')] ?? null) !== $d['href']));
    }

    /**
     * The child nodes of every slot of a node, in document order.
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function slots(Node $node): array
    {
        $out = $node->children ?? [];
        foreach ($node->f as $value) {
            if ($value instanceof Node) {
                $out[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $out[] = $item;
                    }
                }
            }
        }

        return $out;
    }
}
