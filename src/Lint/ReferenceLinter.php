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
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Extension\LowercaseHeadingIdsExtension;
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\HeadingReferenceCollector;
use MarkupCarve\Carve\Parser\LabelKey;
use MarkupCarve\Carve\Renderer\CrossReferenceResolver;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Util\StringUtil;

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
        [$document, $tracker, $nodes] = $this->resolve($source, $options['extensions'] ?? []);
        $targets = $this->labelTargets($document, $nodes);
        $map = SourceOffsets::map($source);
        $length = strlen($source);
        $warnings = [];
        $definitions = [];
        $referenced = [];
        $usedIds = [];
        $idKinds = [];
        $idKindsFolded = [];
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
                $idKinds[$id] ??= [$id, str_replace('_', ' ', $node->getType())];
                $idKindsFolded[$this->foldId($id)] ??= $idKinds[$id];
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
                $caseOnly = $tracker->idsDifferingOnlyInCase($target);
                $elsewhere = $idKinds[$target] ?? $idKindsFolded[$this->foldId($target)] ?? null;
                if ($caseOnly !== []) {
                    $message = 'Cross-reference </#' . $target . '> matches no id; ' . $this->differOnlyInCase('the id', 'the ids', $caseOnly)
                        . ', and cross-references are case-sensitive, so it renders as the literal text "</#' . $target . '>".';
                } elseif ($elsewhere !== null) {
                    $message = 'Cross-reference </#' . $target . '> names the id "' . $elsewhere[0] . '", which is on a ' . $elsewhere[1]
                        . '; a cross-reference reaches only headings and numbered captions, so it renders as the literal text "</#' . $target
                        . '>". Link to it with [text](#' . $elsewhere[0] . ').';
                } else {
                    $message = 'Cross-reference </#' . $target . '> has no matching heading id.';
                }
                $warnings[] = $this->warning($node, 'broken-crossref', $message, $map, $length);
            } elseif ($node instanceof Link && $node->getReferenceLabel() !== null && ($node->getDestination() ?? '') === '') {
                $bracket = $this->labelBracket($node, $source, $map, $length);
                $caseOnly = $this->labelsDifferingOnlyInCase($node, $targets, ($bracket[0] ?? null) === '][]');
                $message = $caseOnly === []
                    ? 'Reference has no matching definition or heading.'
                    : 'Reference ' . $node->getRawReferenceLabel() . ' matches no definition or heading; '
                        . $this->differOnlyInCase('the label', 'the labels', array_column($caseOnly, 0))
                        . ', and reference labels are case-sensitive, so it renders as literal text.';
                $warnings[] = $this->warning($node, 'unresolved-reference-link', $message, $map, $length);
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
     * Rewrite each `</#id>` cross-reference and reference label that resolves
     * nothing, but matches exactly one target when letter case is ignored, to
     * that target's exact spelling (`carve fmt --migrate`, CARVE-P9R-010). A
     * reference with several such targets is left for lint to report.
     */
    public function rewriteCaseOnlyReferences(string $source): string
    {
        [$document, $tracker, $nodes] = $this->resolve($source);
        $targets = $this->labelTargets($document, $nodes);
        $map = SourceOffsets::map($source);
        $length = strlen($source);
        $edits = [];
        foreach ($nodes as $node) {
            $pos = $node->getPos();
            if ($pos === null) {
                continue;
            }
            $start = SourceOffsets::toByte($pos->startOffset, $map, $length);
            $span = substr($source, $start, SourceOffsets::toByte($pos->endOffset, $map, $length) - $start);
            if ($node instanceof HeadingRef && $node->getHref() === null) {
                $target = $node->getTargetId();
                $caseOnly = $tracker->idsDifferingOnlyInCase($target);
                if (count($caseOnly) === 1 && str_starts_with($span, '</#' . $target . '>')) {
                    $edits[] = [$start + 3, strlen($target), $caseOnly[0]];
                }
            } elseif ($node instanceof Link && $node->getReferenceLabel() !== null && ($node->getDestination() ?? '') === '') {
                $bracket = $this->labelBracket($node, $source, $map, $length);
                $caseOnly = $bracket === null ? [] : $this->labelsDifferingOnlyInCase($node, $targets, $bracket[0] === '][]');
                $edit = $bracket !== null && count($caseOnly) === 1 ? $this->labelEdit($node, $source, $start, $bracket, $caseOnly[0][0]) : null;
                if ($edit !== null) {
                    $edits[] = $edit;
                }
            } elseif ($node instanceof Image && $node->getReferenceLabel() !== null && $node->getSource() === '') {
                $edit = $this->imageLabelEdit($node, $span, $targets);
                if ($edit !== null) {
                    $edits[] = [$start + $edit[0], $edit[1], $edit[2]];
                }
            }
        }
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$at, $width, $replacement]) {
            $source = substr_replace($source, $replacement, $at, $width);
        }

        return $source;
    }

    /**
     * Resolves with the caller's heading-id extensions, so references are
     * checked against the ids the render produces.
     *
     * @param string $source
     * @param list<string|\MarkupCarve\Carve\Extension\ExtensionInterface> $extensions
     *
     * @return array{0: \MarkupCarve\Carve\Node\Document, 1: \MarkupCarve\Carve\Renderer\HeadingIdTracker, 2: list<\MarkupCarve\Carve\Node\Node>}
     */
    private function resolve(string $source, array $extensions = []): array
    {
        $headingIds = array_values(array_filter(
            $extensions,
            static fn (string|ExtensionInterface $extension): bool => $extension instanceof LowercaseHeadingIdsExtension
                || $extension instanceof AsciiHeadingIdsExtension,
        ));
        $converter = (new CarveConverter())->addExtensions($headingIds);
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $tracker = $converter->getHeadingIdTracker();
        (new CrossReferenceResolver())->resolveCrossReferenceTargets($document, $tracker);
        $nodes = [];
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            $nodes[] = $node;
            foreach (array_reverse($node->getChildren()) as $child) {
                $pending[] = $child;
            }
        }

        return [$document, $tracker, $nodes];
    }

    /**
     * Folded label => the labels a reference can reach: `definitions` for any
     * reference, `headings` only for the collapsed `[text][]` (PART 9R R1).
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     *
     * @return array{definitions: array<string, array<string, string>>, headings: array<string, array<string, string>>}
     */
    private function labelTargets(Document $document, array $nodes): array
    {
        $targets = ['definitions' => [], 'headings' => []];
        foreach ($nodes as $node) {
            if ($node instanceof LinkReferenceDefinition) {
                // Kept in the spelling the definition lookup compares, which is
                // not NFC-normalized, so a rewrite to it resolves.
                $label = LabelKey::normalize($node->getLabel());
                $targets['definitions'][$this->foldId($label)][$label] = $label;
            }
        }
        foreach ((new HeadingReferenceCollector(new HeadingIdTracker()))->collect($document) as $key => [$label]) {
            $targets['headings'][$this->foldId((string)$key)][$key] ??= $label;
        }

        return $targets;
    }

    /**
     * The targets an unresolved reference link would reach if case were
     * ignored, each as [exact label, kind]. Definitions compare as their
     * lookup does, without NFC; headings with it (PART 9R R1).
     *
     * @param \MarkupCarve\Carve\Node\Inline\Link|\MarkupCarve\Carve\Node\Inline\Image $link
     * @param array{definitions: array<string, array<string, string>>, headings: array<string, array<string, string>>} $targets
     * @param bool $collapsed
     *
     * @return list<array{0: string, 1: string}>
     */
    private function labelsDifferingOnlyInCase(Link|Image $link, array $targets, bool $collapsed): array
    {
        if (!LabelKey::isSingleLine((string)$link->getReferenceLabel())) {
            // A multiline label misses for a reason other than case.
            return [];
        }
        $label = LabelKey::normalize((string)$link->getReferenceLabel());
        $found = [];
        // Array keys here can be ints (a numeric label), hence the casts.
        foreach ($targets['definitions'][$this->foldId($label)] ?? [] as $definition) {
            if ($definition !== $label) {
                $found['s' . $definition] ??= [$definition, 'definition'];
            }
        }
        if ($collapsed) {
            $written = $this->labelKey($label);
            foreach ($targets['headings'][$this->foldId($written)] ?? [] as $key => $heading) {
                if ((string)$key !== $written) {
                    $found['s' . $heading] ??= [$heading, 'heading'];
                }
            }
        }

        return array_values($found);
    }

    /**
     * The label bracket of an authored reference (`][]` or `][label]`) and the
     * byte offset of its `]`, read where the parsed link text ends so a `][`
     * inside the text or an attribute value is never taken for it.
     *
     * @param \MarkupCarve\Carve\Node\Inline\Link $link
     * @param string $source
     * @param array<int, int>|null $map
     * @param int $length
     *
     * @return array{0: string, 1: int}|null
     */
    private function labelBracket(Link $link, string $source, ?array $map, int $length): ?array
    {
        $pos = $link->getPos();
        if ($pos === null) {
            return null;
        }
        $start = SourceOffsets::toByte($pos->startOffset, $map, $length);
        $children = $link->getChildren();
        $last = $children === [] ? null : $children[count($children) - 1]->getPos();
        if ($children !== [] && $last === null) {
            return null;
        }
        $at = $last === null ? $start + 1 : SourceOffsets::toByte($last->endOffset, $map, $length);
        if (($source[$start] ?? '') !== '[' || substr($source, $at, 2) !== '][') {
            return null;
        }
        $close = strpos($source, ']', $at + 2);

        return $close === false ? null : [substr($source, $at, $close - $at + 1), $at];
    }

    /**
     * The [offset, width, replacement] that respells the label, or null when
     * no edit is safe: a collapsed reference is respelled only when its text is
     * plain and the new spelling differs from it in case alone, so the edit
     * cannot introduce markup.
     *
     * @param \MarkupCarve\Carve\Node\Inline\Link $link
     * @param string $source
     * @param int $start
     * @param array{0: string, 1: int} $bracket
     * @param string $replacement
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function labelEdit(Link $link, string $source, int $start, array $bracket, string $replacement): ?array
    {
        [$spelled, $at] = $bracket;
        if ($spelled !== '][]') {
            $label = (string)$link->getReferenceLabel();

            return $spelled === '][' . $label . ']' ? [$at + 2, strlen($label), $replacement] : null;
        }
        $text = substr($source, $start + 1, $at - $start - 1);
        $plain = '';
        foreach ($link->getChildren() as $child) {
            if (!$child instanceof Text) {
                return null;
            }
            $plain .= $child->getContent();
        }

        return $plain === $text && $this->foldId($this->labelKey($text)) === $this->foldId($this->labelKey($replacement))
            ? [$start + 1, strlen($text), $replacement]
            : null;
    }

    /**
     * The edit, relative to $span, that respells an unresolved reference
     * image's label. An image has no positioned text nodes, so the bracket is
     * the `][` run that ends the reference or precedes its attribute block,
     * and only an image whose alt is plain text is respelled.
     *
     * @param \MarkupCarve\Carve\Node\Inline\Image $image
     * @param string $span
     * @param array{definitions: array<string, array<string, string>>, headings: array<string, array<string, string>>} $targets
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function imageLabelEdit(Image $image, string $span, array $targets): ?array
    {
        $label = (string)$image->getReferenceLabel();
        $collapsed = $this->endingBracketAt($span, '][]');
        $at = $collapsed ?? $this->endingBracketAt($span, '][' . $label . ']');
        if ($at === null || !str_starts_with($span, '![')) {
            return null;
        }
        $caseOnly = $this->labelsDifferingOnlyInCase($image, $targets, $collapsed !== null);
        if (count($caseOnly) !== 1) {
            return null;
        }
        $replacement = $caseOnly[0][0];
        // Only a plain alt proves the bracket found is the reference's own:
        // markup in the alt (a code span holding `][`) could hide another.
        $text = substr($span, 2, $at - 2);
        if ($text !== $image->getAlt()) {
            return null;
        }
        if ($collapsed === null) {
            return [$at + 2, strlen($label), $replacement];
        }

        return $this->foldId($this->labelKey($text)) === $this->foldId($this->labelKey($replacement))
            ? [2, strlen($text), $replacement]
            : null;
    }

    private function endingBracketAt(string $span, string $bracket): ?int
    {
        $from = 0;
        while (($at = strpos($span, $bracket, $from)) !== false) {
            $next = $at + strlen($bracket);
            if ($next === strlen($span) || $span[$next] === '{') {
                return $at;
            }
            $from = $at + 1;
        }

        return null;
    }

    /**
     * NFC-normalized and whitespace-collapsed, the key PART 9R R1 compares.
     */
    private function labelKey(string $label): string
    {
        return StringUtil::normalizeNfc(LabelKey::normalize($label));
    }

    /**
     * @param string $one
     * @param string $many
     * @param array<string> $names
     */
    private function differOnlyInCase(string $one, string $many, array $names): string
    {
        $quoted = array_values(array_map(static fn (string $name): string => '"' . $name . '"', $names));
        if (count($quoted) === 1) {
            return $one . ' ' . $quoted[0] . ' differs only in case';
        }
        $last = array_pop($quoted);

        return $many . ' ' . implode(', ', $quoted) . ' and ' . $last . ' differ only in case';
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
        $rendered = [];
        foreach ($extensions as $extension) {
            if ($extension === 'citations' || $extension instanceof CitationsExtension) {
                $citations = true;
                $rendered[] = $extension instanceof CitationsExtension ? $extension : new CitationsExtension();
            } elseif ($extension instanceof LowercaseHeadingIdsExtension || $extension instanceof AsciiHeadingIdsExtension) {
                $headingIds[] = $extension;
                $rendered[] = $extension;
            } elseif ($extension === 'semantic-span' || $extension instanceof SemanticSpanExtension) {
                $rendered[] = $extension instanceof SemanticSpanExtension ? $extension : new SemanticSpanExtension();
            } else {
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
        // The document the caller renders, not a core one: an extension drops an
        // unused definition and every id and link inside it.
        [$ids, $linked] = $this->renderedIds((new CarveConverter())->addExtensions($rendered)->convert($source));
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
            // A link the render leaves out reaches no reader, so it goes nowhere
            // for a reason this rule does not own.
            if (!isset($linked[$fragment]) && !isset($linked[$decoded])) {
                continue;
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
     * an id quoted in a comment or another attribute's value does not count,
     * and the fragments of the links that render.
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function renderedIds(string $html): array
    {
        $values = [];
        /** @var list<string|null> $hrefs */
        $hrefs = [];
        if (class_exists(HTMLDocument::class)) {
            $document = HTMLDocument::createFromString('<body>' . $html, LIBXML_NOERROR, 'UTF-8');
            foreach ($document->querySelectorAll('[id], a[name]') as $element) {
                $values[] = $element->getAttribute('id');
                $values[] = strtolower($element->localName) === 'a' ? $element->getAttribute('name') : null;
            }
            foreach ($document->querySelectorAll('a[href]') as $element) {
                $hrefs[] = $element->getAttribute('href');
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
            $anchors = (new DOMXPath($document))->query('//a[@href][not(ancestor::template)]');
            foreach ($anchors ?: [] as $element) {
                if ($element instanceof DOMElement) {
                    $hrefs[] = $element->getAttribute('href');
                }
            }
        }
        $ids = [];
        foreach ($values as $value) {
            if ($value !== null) {
                $ids[$value] = true;
            }
        }
        $linked = [];
        foreach ($hrefs as $href) {
            if ($href === null || !str_starts_with($href, '#')) {
                continue;
            }
            $fragment = explode(':~:', substr($href, 1), 2)[0];
            $linked[$fragment] = true;
            $decoded = rawurldecode($fragment);
            $linked[mb_check_encoding($decoded, 'UTF-8') ? $decoded : $fragment] = true;
        }

        return [$ids, $linked];
    }

    /**
     * Per-code-point lowercase fold, for diagnostics that name a case-only
     * mismatch. No lookup resolves through it (CARVE-P9R-010).
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
