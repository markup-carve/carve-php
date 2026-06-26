<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\CodeBlock;
use Carve\Node\Block\Paragraph;
use Carve\Node\Document;
use Carve\Node\Inline\Text;
use Carve\Node\Node;
use Carve\Renderer\HtmlRenderer;
use Carve\Util\StringUtil;
use SplObjectStorage;

/**
 * CodeCallouts (#88, Tier-2). `<n>` markers at the end of fenced-code lines
 * render as `<b class="callout">` bubbles, and an immediately-following
 * paragraph of `<n> text` lines binds as `<ol class="callouts">`. Off by
 * default; optional-corpus pinned when enabled. See docs/extensions.md §10.
 */
class CodeCalloutsExtension implements ExtensionInterface, BeforeRenderExtensionInterface, ResettableExtensionInterface
{
    /**
     * A `<n>` that is the last non-whitespace content on its line.
     *
     * @var string
     */
    private const MARKER_RE = '/^(.*?)(\s*)<(\d+)>[ \t]*$/';

    /**
     * A callout-list line: `<n> text` (marker, one space, prose) at the start.
     *
     * @var string
     */
    private const ITEM_RE = '/^<(\d+)> /';

    /**
     * Identity set of bound callout-list paragraphs - keeps the marker out of
     * the AST so it never leaks into HTML or non-HTML output.
     *
     * @var \SplObjectStorage<\Carve\Node\Node, null>
     */
    private SplObjectStorage $calloutLists;

    public function __construct()
    {
        $this->calloutLists = new SplObjectStorage();
    }

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.code_block', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if ($node instanceof CodeBlock && $this->hasMarkers($node->getContent())) {
                $event->setHtml($this->renderCode($node, $renderer));
            }
        });

        $converter->on('render.paragraph', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if ($node instanceof Paragraph && $this->calloutLists->contains($node)) {
                $event->setHtml($this->renderCalloutList($node, $renderer));
            }
        });
    }

    public function clear(): void
    {
        $this->calloutLists = new SplObjectStorage();
    }

    public function beforeRender(Document $document): Document
    {
        // Bind on the document that is actually rendered, tagging its paragraph
        // nodes by identity so the render listeners match them.
        $this->calloutLists = new SplObjectStorage();
        $this->bind($document);

        return $document;
    }

    /**
     * Tag callout-list paragraphs: a `<n> text` paragraph immediately following
     * a code block that contains at least one marker. Recurse into containers.
     */
    private function bind(Node $node): void
    {
        $children = $node->getChildren();
        foreach ($children as $i => $child) {
            $this->bind($child);
            if (!$child instanceof CodeBlock || !$this->hasMarkers($child->getContent())) {
                continue;
            }
            $next = $children[$i + 1] ?? null;
            if ($next instanceof Paragraph && $this->isCalloutCandidate($next)) {
                $this->calloutLists->attach($next);
            }
        }
    }

    private function hasMarkers(string $content): bool
    {
        foreach (explode("\n", $content) as $line) {
            if (preg_match(self::MARKER_RE, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isCalloutCandidate(Paragraph $p): bool
    {
        $lines = $this->splitLines($p);
        if ($lines === []) {
            return false;
        }
        foreach ($lines as $line) {
            $head = $this->firstText($line);
            if ($head === null || preg_match(self::ITEM_RE, $head) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function renderCode(CodeBlock $node, HtmlRenderer $renderer): string
    {
        $lines = explode("\n", $node->getContent());
        $body = '';
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $body .= "\n";
            }
            if (preg_match(self::MARKER_RE, $line, $m) === 1) {
                $body .= $this->escapeCode($m[1]) . $m[2]
                    . '<b class="callout" data-callout="' . $m[3] . '">' . $m[3] . '</b>';
            } else {
                $body .= $this->escapeCode($line);
            }
        }

        $tabWidth = $renderer->getCodeBlockTabWidth();
        if ($tabWidth !== null) {
            $body = str_replace("\t", str_repeat(' ', $tabWidth), $body);
        }
        $body .= "\n";

        $attrs = $renderer->renderAttributesExcluding($node, []);
        // Preserve round-trip source exactly like core renderCodeBlock: the
        // data-djot-src carries the ORIGINAL fence + literal `<n>` markers so a
        // marked block round-trips identically to an unmarked one.
        $djotSrcAttr = '';
        if ($renderer->isRoundTripMode()) {
            $djotSrc = $renderer->reconstructCodeBlockSource($node);
            $djotSrcAttr = ' data-djot-src="' . $renderer->escapeAttribute($djotSrc) . '"';
        }
        $language = $node->getLanguage();
        if ($language !== null) {
            $langClass = 'class="language-' . $renderer->escapeAttribute($language) . '"';

            return '<pre' . $attrs . $djotSrcAttr . '><code ' . $langClass . '>' . $body . "</code></pre>\n";
        }

        return '<pre' . $attrs . $djotSrcAttr . '><code>' . $body . "</code></pre>\n";
    }

    private function renderCalloutList(Paragraph $p, HtmlRenderer $renderer): string
    {
        $items = [];
        foreach ($this->splitLines($p) as $line) {
            $head = $line[0];
            if (!$head instanceof Text || preg_match(self::ITEM_RE, $head->getContent(), $m) !== 1) {
                continue; // unreachable: only bound (all-item) paragraphs arrive here
            }
            $n = $m[1];
            // Strip the leading `<n> ` from the first text node and render it
            // through the renderer's normal text path (same escaping + bidi-
            // control stripping core applies to ordinary paragraph prose), then
            // the remaining inline nodes.
            $stripped = new Text(preg_replace(self::ITEM_RE, '', $head->getContent()) ?? '');
            $html = $renderer->renderNodeFragment($stripped);
            $count = count($line);
            for ($k = 1; $k < $count; $k++) {
                $html .= $renderer->renderNodeFragment($line[$k]);
            }
            $items[] = '  <li value="' . $n . '">' . $html . '</li>';
        }

        return '<ol' . $this->openAttributes($p, $renderer) . ">\n"
            . implode("\n", $items) . "\n</ol>\n";
    }

    /**
     * Split the paragraph's inline children into per-line segments at each
     * soft-break (empty segments dropped).
     *
     * @return list<list<\Carve\Node\Node>>
     */
    private function splitLines(Paragraph $p): array
    {
        $lines = [[]];
        foreach ($p->getChildren() as $child) {
            if ($child->getType() === 'soft_break') {
                $lines[] = [];
            } else {
                $lines[count($lines) - 1][] = $child;
            }
        }

        return array_values(array_filter($lines, static fn (array $l): bool => $l !== []));
    }

    /**
     * @param list<\Carve\Node\Node> $line
     */
    private function firstText(array $line): ?string
    {
        $first = $line[0] ?? null;

        return $first instanceof Text ? $first->getContent() : null;
    }

    private function escapeCode(string $text): string
    {
        $escaped = htmlspecialchars(StringUtil::stripBidiControls($text), ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\u{E000}", "\u{00A0}"], '&nbsp;', $escaped);
    }

    /**
     * The paragraph's authored attributes with `callouts` as the leading class.
     */
    private function openAttributes(Paragraph $p, HtmlRenderer $renderer): string
    {
        $classes = ['callouts'];
        foreach ($p->getClassList() as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = $p->getAttributes();
        unset($attrs['class'], $attrs['title']);
        $attrs = $renderer->sanitizeAttributes($attrs);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        $out = '';
        if (isset($attrs['id'])) {
            $out .= ' id="' . $renderer->escapeAttribute((string)$attrs['id']) . '"';
            unset($attrs['id']);
        }
        $out .= ' class="' . $renderer->escapeAttribute(implode(' ', $classes)) . '"';

        return $out . $renderer->renderAttributeArray($attrs);
    }
}
