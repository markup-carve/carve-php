<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Dom\HTMLDocument;
use DOMDocument;
use DOMElement;
use DOMXPath;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\AsciiHeadingIdsExtension;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Extension\LowercaseHeadingIdsExtension;
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\LabelKey;
use MarkupCarve\Carve\Renderer\CrossReferenceResolver;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;

class ReferenceLinter
{
    /**
     * @param string $source
     * @param array{extensions?: list<string|\MarkupCarve\Carve\Extension\ExtensionInterface>} $options
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = []): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $tracker = new HeadingIdTracker();
        (new CrossReferenceResolver())->resolveCrossReferenceTargets($document, $tracker);
        $map = SourceOffsets::map($source);
        $length = strlen($source);
        $warnings = [];
        $nodes = [];
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            $nodes[] = $node;
            foreach (array_reverse($node->getChildren()) as $child) {
                $pending[] = $child;
            }
        }
        $definitions = [];
        $referenced = [];
        $usedIds = [];
        $idKinds = [];
        $fragmentLinks = [];
        $ignoredLines = [];
        $inlineSpans = [];
        foreach ($nodes as $node) {
            $pos = $node->getPos();
            if ($pos !== null && ($node instanceof CodeBlock || $node instanceof RawBlock || $node instanceof Comment)) {
                for ($line = $pos->startLine; $line <= $pos->endLine; $line++) {
                    $ignoredLines[$line] = true;
                }
            }
            if ($pos !== null && $node instanceof InlineNode) {
                $inlineSpans[] = [SourceOffsets::toByte($pos->startOffset, $map, $length), SourceOffsets::toByte($pos->endOffset, $map, $length)];
            }
            $id = $node->getAttribute('id');
            if ($id !== null) {
                $idKinds[$this->foldId($id)] ??= [$id, str_replace('_', ' ', $node->getType())];
            }
            if ($node instanceof Link && str_starts_with($node->getDestination() ?? '', '#')) {
                $fragmentLinks[] = $node;
            }
            if ($node instanceof Footnote) {
                $definitions[LabelKey::normalize($node->getLabel())] = $node;
            }
            if (!$node instanceof Heading) {
                continue;
            }
            $explicit = $node->getAttribute('id');
            $base = $explicit ?? $tracker->normalizeId($tracker->getPlainText($node));
            $collision = $explicit !== null
                ? isset($usedIds[$explicit])
                : $tracker->getIdForHeading($node) !== $base;
            if ($collision) {
                $warnings[] = $this->warning($node, 'duplicate-heading-id', 'Heading id "' . $base . '" collides with another heading or an explicit id.', $map, $length);
            }
            if ($explicit !== null) {
                $usedIds[$explicit] = true;
            }
        }
        foreach ($nodes as $node) {
            if ($node instanceof HeadingRef && $node->getHref() === null) {
                $target = $node->getTargetId();
                $elsewhere = $idKinds[$this->foldId($target)] ?? null;
                $message = $elsewhere === null
                    ? 'Cross-reference </#' . $target . '> has no matching heading id.'
                    : 'Cross-reference </#' . $target . '> names the id "' . $elsewhere[0] . '", which is on a ' . $elsewhere[1]
                        . '; a cross-reference reaches only headings and numbered captions, so it renders as the literal text "</#' . $target
                        . '>". Link to it with [text](#' . $elsewhere[0] . ').';
                $warnings[] = $this->warning($node, 'broken-crossref', $message, $map, $length);
            } elseif ($node instanceof Link && $node->getReferenceLabel() !== null && ($node->getDestination() ?? '') === '') {
                $warnings[] = $this->warning($node, 'unresolved-reference-link', 'Reference has no matching definition or heading.', $map, $length);
            } elseif ($node instanceof FootnoteRef) {
                $key = LabelKey::normalize($node->getLabel());
                if (isset($definitions[$key])) {
                    $referenced[$key] = true;
                } else {
                    $warnings[] = $this->warning($node, 'unresolved-footnote', 'Footnote reference [^' . $node->getLabel() . '] has no matching definition.', $map, $length);
                }
            }
        }
        sort($inlineSpans);
        $spanIndex = 0;
        $seen = [];
        $seenKeys = [];
        $sites = [];
        $lines = preg_split('/\r\n|\r|\n/', $source, flags: PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        foreach ($lines as $index => [$line, $offset]) {
            if (isset($ignoredLines[$index + 1]) || !preg_match('/^(?:[ \t]*> ?)*[ \t]*(?:[-+*] |[0-9]+[.)] |: )*\[\^([^\]\r\n]+)\]:/', $line, $match)) {
                continue;
            }
            $label = $match[1];
            $key = LabelKey::normalize($label);
            if (!isset($definitions[$key])) {
                continue;
            }
            $marker = strpos($line, '[^');
            if ($marker === false) {
                continue;
            }
            $start = $offset + $marker;
            while (isset($inlineSpans[$spanIndex]) && $inlineSpans[$spanIndex][1] <= $start) {
                $spanIndex++;
            }
            if (isset($inlineSpans[$spanIndex]) && $inlineSpans[$spanIndex][0] <= $start && $start < $inlineSpans[$spanIndex][1]) {
                continue;
            }
            $rule = isset($seen[$label]) ? 'duplicate-footnote-definition' : (isset($seenKeys[$key]) ? 'footnote-labels-differ-only-in-whitespace' : null);
            if ($rule !== null) {
                $message = $rule === 'footnote-labels-differ-only-in-whitespace'
                    ? 'Footnote labels [^' . $label . '] and [^' . $seenKeys[$key] . '] differ only in whitespace; the first definition wins.'
                    : 'Footnote definition [^' . $label . '] repeats an earlier definition; the first definition wins.';
                $warnings[] = new LintWarning($index + 1, mb_strlen(substr($line, 0, $marker), 'UTF-8') + 1, $rule, $message, $start, $offset + strlen($match[0]));
            }
            $seen[$label] = true;
            $seenKeys[$key] ??= $label;
            $sites[$key] ??= [$index + 1, mb_strlen(substr($line, 0, $marker), 'UTF-8') + 1, $start, $offset + strlen($match[0])];
        }
        foreach ($definitions as $key => $node) {
            if (!isset($referenced[$key])) {
                $message = 'Footnote definition [^' . $node->getLabel() . '] is never referenced and is omitted from rendered output.';
                if (isset($sites[$key])) {
                    [$line, $column, $start, $end] = $sites[$key];
                    $warnings[] = new LintWarning($line, $column, 'unused-footnote-definition', $message, $start, $end);
                } else {
                    $warnings[] = $this->warning($node, 'unused-footnote-definition', $message, $map, $length);
                }
            }
        }
        foreach ($this->brokenFragmentLinks($source, $fragmentLinks, $options['extensions'] ?? []) as [$node, $message]) {
            $warnings[] = $this->warning($node, 'broken-fragment-link', $message, $map, $length);
        }
        usort($warnings, static fn (LintWarning $a, LintWarning $b): int => $a->start <=> $b->start);

        return $warnings;
    }

    /**
     * @param string $source
     * @param list<\MarkupCarve\Carve\Node\Inline\Link> $links
     * @param list<string|\MarkupCarve\Carve\Extension\ExtensionInterface> $extensions
     *
     * @return list<array{0: \MarkupCarve\Carve\Node\Inline\Link, 1: string}>
     */
    private function brokenFragmentLinks(string $source, array $links, array $extensions): array
    {
        if ($links === [] || (!class_exists(HTMLDocument::class) && !class_exists(DOMDocument::class))) {
            return [];
        }
        $citations = false;
        $headingIds = [];
        foreach ($extensions as $extension) {
            if ($extension === 'citations' || $extension instanceof CitationsExtension) {
                $citations = true;
            } elseif ($extension instanceof LowercaseHeadingIdsExtension || $extension instanceof AsciiHeadingIdsExtension) {
                $headingIds[] = $extension;
            } elseif ($extension !== 'semantic-span' && !$extension instanceof SemanticSpanExtension) {
                // Lint cannot know which ids another extension generates.
                return [];
            }
        }
        if ($headingIds !== []) {
            // An implicit heading link resolves to the id these extensions shape.
            $parser = (new CarveConverter())->addExtensions($headingIds);
            $parser->getParser()->enablePositionTracking();
            $links = [];
            $pending = [$parser->parse($source)];
            while ($pending !== []) {
                $node = array_pop($pending);
                if ($node instanceof Link && str_starts_with($node->getDestination() ?? '', '#')) {
                    $links[] = $node;
                }
                array_push($pending, ...$node->getChildren());
            }
        }
        $ids = $this->renderedIds((new CarveConverter())->addExtensions($headingIds)->convert($source));
        $idsByFold = [];
        foreach ($ids as $id => $_) {
            $idsByFold[$this->foldId((string)$id)] ??= (string)$id;
        }
        $found = [];
        foreach ($links as $link) {
            $href = (string)$link->getDestination();
            // A browser strips a `:~:` text directive before it looks the id up.
            $fragment = explode(':~:', substr($href, 1), 2)[0];
            if ($fragment === '') {
                continue;
            }
            $decoded = rawurldecode($fragment);
            if (!mb_check_encoding($decoded, 'UTF-8')) {
                $decoded = $fragment;
            }
            if (isset($ids[$fragment]) || isset($ids[$decoded])) {
                continue;
            }
            // HTML scrolls `#top` to the start of the page without any element.
            if (strtolower($decoded) === 'top' || ($citations && preg_match('/^(?:ref|cite)-/', $decoded))) {
                continue;
            }
            $caseOnly = $idsByFold[$this->foldId($decoded)] ?? null;
            $found[] = [
                $link, $caseOnly === null
                ? 'Link to "' . $href . '" matches no id in this document, so the link goes nowhere.'
                : 'Link to "' . $href . '" matches no id; the id "' . $caseOnly . '" differs only in case, and fragment links are case-sensitive, so the link goes nowhere.',
            ];
        }

        return $found;
    }

    /**
     * The ids the rendered HTML carries, read the way a browser parses them so
     * an id quoted in a comment or another attribute's value does not count.
     *
     * @return array<string, true>
     */
    private function renderedIds(string $html): array
    {
        $values = [];
        if (class_exists(HTMLDocument::class)) {
            $document = HTMLDocument::createFromString('<body>' . $html, LIBXML_NOERROR, 'UTF-8');
            foreach ($document->querySelectorAll('[id], a[name]') as $element) {
                $values[] = $element->getAttribute('id');
                $values[] = strtolower($element->localName) === 'a' ? $element->getAttribute('name') : null;
            }
        } else {
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html, LIBXML_PARSEHUGE | LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $nodes = (new DOMXPath($document))->query('//*[@id][not(ancestor::template)] | //a[@name][not(ancestor::template)]');
            foreach ($nodes ?: [] as $element) {
                if ($element instanceof DOMElement) {
                    $values[] = $element->hasAttribute('id') ? $element->getAttribute('id') : null;
                    $values[] = $element->localName === 'a' && $element->hasAttribute('name') ? $element->getAttribute('name') : null;
                }
            }
        }
        $ids = [];
        foreach ($values as $value) {
            if ($value !== null) {
                $ids[$value] = true;
            }
        }

        return $ids;
    }

    /**
     * Same per-character fold as the cross-reference resolver.
     */
    private function foldId(string $id): string
    {
        return (string)preg_replace_callback('/./us', static fn (array $m): string => mb_strtolower($m[0], 'UTF-8'), $id);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param string $rule
     * @param string $message
     * @param array<int, int>|null $map
     * @param int $length
     */
    private function warning(Node $node, string $rule, string $message, ?array $map, int $length): LintWarning
    {
        $pos = $node->getPos();

        if ($pos === null) {
            return new LintWarning(1, 1, $rule, $message, 0, 0);
        }

        return new LintWarning(
            $pos->startLine,
            $pos->startColumn,
            $rule,
            $message,
            SourceOffsets::toByte($pos->startOffset, $map, $length),
            SourceOffsets::toByte($pos->endOffset, $map, $length),
        );
    }
}
