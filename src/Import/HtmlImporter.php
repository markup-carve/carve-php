<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use Closure;
use MarkupCarve\Carve\Diagnostics\Cap;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Resolver;
use MarkupCarve\Carve\Writer\Writer;

/**
 * The HTML importer of import-html.md: HTML in, a 0.2 tree, its canonical source and a diagnostics record out (C2-M-001).
 */
final class HtmlImporter
{
    /**
     * @param string $html
     * @param array<string, mixed> $options `rawHtml` (C2-M-066), `labels` (C2-M-101), `strictLosses` (C2-M-082),
     *   `allowLosses` and `maxLosses`
     *   (C2-H-204, C2-H-205).
     * @param bool $legacyParser Read with the libxml parser even where `Dom\HTMLDocument` exists.
     * @param \Closure(\MarkupCarve\Carve\Import\HtmlNode): void|null $prepare The Markdown and Djot hub's carriers, set on the parsed HTML.
     * @param \Closure(array<array<string, mixed>>): array<array<string, mixed>>|null $finish The hub's changes to the imported children.
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
    ): ImportResult {
        if (!HtmlParser::available()) {
            throw new ImportInputError('HTML import requires the PHP DOM extension (ext-dom)');
        }
        $opts = Options::from(array_intersect_key(
            $options,
            ['rawHtml' => true, 'labels' => true, 'strictLosses' => true, 'allowLosses' => true, 'maxLosses' => true],
        ));
        $root = HtmlParser::parse($html, $legacyParser);
        SourceLocations::assign($html, $root);
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
        $document = ['type' => 'document', 'astVersion' => '2.0', 'children' => $children];
        if ($context->rootLang !== null) {
            $document['lang'] = $context->rootLang;
        }
        if ($context->rootDir !== null) {
            $document['dir'] = $context->rootDir;
        }
        $tree = self::read($document);
        $diagnostics = $context->diagnostics;
        // C2-M-074: the writer's loss entries follow the importer's.
        $written = Writer::writeWithDiagnostics($document);
        $source = $written->source;
        $diagnostics = array_merge($diagnostics, $written->diagnostics);
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

        return new ImportResult($tree, $document, $diagnostics, $source, $suppressed);
    }

    /**
     * C2-M-001, C2-M-006: the tree passes the reader; one deeper than its limits is refused.
     *
     * @param array<string, mixed> $document
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function read(array $document): Node
    {
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE, 100000);
        try {
            return TreeReader::read((string)$json);
        } catch (TreeError $e) {
            if ($e->reason === 'depth' || $json === false) {
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
