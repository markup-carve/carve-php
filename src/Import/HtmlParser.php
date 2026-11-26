<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use Dom\Comment;
use Dom\DocumentType;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\HTMLElement;
use Dom\Node as NativeNode;
use Dom\Text;
use DOMComment;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * C2-M-002, C2-M-003: parses HTML with the parser of the 0.1 importer: `Dom\HTMLDocument` (the HTML Standard's
 * algorithm) where PHP has it, the libxml `DOMDocument` otherwise, and copies the result into one tree of `HtmlNode`.
 */
final class HtmlParser
{
    /**
     * @var string
     */
    private const XHTML = 'http://www.w3.org/1999/xhtml';

    /**
     * @var string
     */
    private const LEGACY_ROOT = 'carve-import-root';

    /**
     * Void elements libxml below 2.14 does not know: it lets them hold the content that follows.
     *
     * @var array<string>
     */
    private const LEGACY_VOIDS = ['bgsound', 'embed', 'keygen', 'source', 'track', 'wbr'];

    /**
     * @var array<string>
     */
    private const RAW_TEXT = ['iframe', 'noembed', 'noframes', 'script', 'style', 'textarea', 'title', 'xmp'];

    /**
     * Marks a `script` or `style` whose content the fallback escaped.
     *
     * @var string
     */
    private const LEGACY_RAW = 'carve-import-raw';

    private static ?bool $tagsInRawText = null;

    /**
     * The LEGACY_RAW value, so an attribute of that name in the input is not taken for the marker.
     */
    private static ?string $rawNonce = null;

    /**
     * Element names the scan gave libxml as placeholders, by placeholder.
     *
     * @var array<string, string>
     */
    private static array $foreignNames = [];

    public static function available(): bool
    {
        return class_exists(DOMDocument::class);
    }

    public static function html5(): bool
    {
        return class_exists(HTMLDocument::class);
    }

    /**
     * C2-M-002: a document starts, after ASCII whitespace and comments, with `<!doctype` or `<html`, without case.
     */
    public static function isDocument(string $html): bool
    {
        return preg_match('/\A(?:[ \t\n\f\r]|<!--.*?-->)*(?:<!doctype|<html)/is', $html) === 1;
    }

    /**
     * @param string $html
     * @param bool $legacy Use the libxml parser even where `Dom\HTMLDocument` exists.
     *
     * @return \MarkupCarve\Carve\Import\HtmlNode The root: for a document the doctype and `<html>`, for a fragment its nodes.
     */
    public static function parse(string $html, bool $legacy = false): HtmlNode
    {
        $document = self::isDocument($html);
        if (!$legacy && self::html5()) {
            return $document ? self::html5Document($html) : self::html5Fragment($html);
        }

        return self::legacy($html, $document);
    }

    private static function html5Document(string $html): HtmlNode
    {
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $root = new HtmlNode(HtmlNode::ROOT);
        self::copy($doc, $root);

        return $root;
    }

    private static function html5Fragment(string $html): HtmlNode
    {
        $doc = HTMLDocument::createEmpty();
        $body = $doc->createElement('body');
        $body->innerHTML = $html;
        $root = new HtmlNode(HtmlNode::ROOT);
        self::copy($body, $root);

        return $root;
    }

    private static function copy(NativeNode $source, HtmlNode $target): void
    {
        $pending = [[$source, $target]];
        while ($pending !== []) {
            [$from, $to] = array_pop($pending);
            foreach ($from->childNodes as $child) {
                $node = self::node($child);
                if ($node === null) {
                    continue;
                }
                $to->append($node);
                if ($child instanceof HTMLElement && $child->localName === 'template' && $child->namespaceURI === self::XHTML) {
                    $node->content = self::templateContent($child->innerHTML);
                } elseif ($child instanceof Element && $child->firstElementChild === null) {
                    // Only texts and comments below: copied here, in the order the pending list would.
                    foreach ($child->childNodes as $leaf) {
                        $leafNode = self::node($leaf);
                        if ($leafNode !== null) {
                            $node->append($leafNode);
                        }
                    }
                } elseif ($child instanceof Element) {
                    $pending[] = [$child, $node];
                }
            }
        }
    }

    private static function node(NativeNode $child): ?HtmlNode
    {
        if ($child instanceof Element) {
            $attrs = [];
            foreach ($child->attributes as $attribute) {
                $attrs[$attribute->name] = $attribute->value;
            }
            $foreign = $child->namespaceURI !== self::XHTML;

            return new HtmlNode(HtmlNode::ELEMENT, $foreign ? $child->localName : strtolower($child->localName), $attrs, $foreign);
        }
        if ($child instanceof Text) {
            return new HtmlNode(HtmlNode::TEXT, $child->data);
        }
        if ($child instanceof Comment) {
            return new HtmlNode(HtmlNode::COMMENT, $child->data);
        }

        return $child instanceof DocumentType ? new HtmlNode(HtmlNode::DOCTYPE, $child->name) : null;
    }

    /**
     * C2-H-183: the contents of a template, parsed again from their serialization as a fragment.
     *
     * @param string $inner
     *
     * @return array<\MarkupCarve\Carve\Import\HtmlNode>
     */
    private static function templateContent(string $inner): array
    {
        $holder = self::html5Fragment($inner);
        foreach ($holder->children as $child) {
            $child->parent = null;
        }

        return $holder->children;
    }

    private static function legacy(string $html, bool $document): HtmlNode
    {
        // The HTML input stream turns CR LF and a lone CR into LF before tokenizing; libxml keeps them.
        $html = self::legacyTokens(str_replace(["\r\n", "\r"], "\n", $html));
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if ($document) {
                $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_PARSEHUGE);
            } else {
                // No end tag: an unclosed raw text element, comment or attribute would read it as its content.
                $wrapped = '<?xml encoding="UTF-8"><' . self::LEGACY_ROOT . '>' . $html;
                $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        self::impliedTableBodies($dom);
        self::emptyVoids($dom, $html);
        if (str_contains($html, self::LEGACY_RAW)) {
            self::unescapeRaw($dom);
        }
        $root = new HtmlNode(HtmlNode::ROOT);
        $source = $document ? $dom : $dom->getElementsByTagName(self::LEGACY_ROOT)->item(0);
        if ($source !== null) {
            $synthetic = null;
            if ($document) {
                foreach ($dom->childNodes as $child) {
                    if ($child instanceof DOMComment && $child->data === '?xml encoding="UTF-8"') {
                        $synthetic = $child;

                        break;
                    }
                }
            }
            self::copyLegacy($source, $root, !$document, $synthetic);
        }

        return $root;
    }

    /**
     * Repairs what libxml tokenizes or builds differently. The HTML tokenizer reads `<!` that opens no comment or
     * doctype, and `</` without a letter, as a bogus comment up to the next `>`; libxml below 2.14 keeps it as text
     * (Word's `<![if !supportFootnotes]>`). The content of a raw text or RCDATA element is text up to its own end tag;
     * libxml below 2.14 parses tags and drops end tags in it, and decodes entities in all but `script` and `style`,
     * so there the content is escaped to give the same text (carve-php#6061). libxml has no foreign content: in SVG
     * and MathML raw text element names get a placeholder, CDATA becomes text, and a breakout gets the end tags of
     * the foreign elements it closes (carve-php#6068).
     *
     * @param string $html
     *
     * @return string
     */
    private static function legacyTokens(string $html): string
    {
        $rawText = self::tagsInRawText();
        $pattern = '/<!(?!--|(?i:doctype))|<\/(?![a-zA-Z])|<\/(?i:br)[\s\/>]|<(?i:svg|math)[\s\/>]'
            . ($rawText ? '|<(?i:' . implode('|', self::RAW_TEXT) . ')[\s\/>]/' : '/');
        $comment = strrpos($html, '<!--');
        $unclosed = $comment !== false && strpos($html, '-->', $comment + 2) === false;
        // More `</p>` than `<p>`: one of them closes no paragraph.
        $strayParagraph = preg_match_all('/<\/p[\s\/>]/i', $html) > preg_match_all('/<p[\s\/>]/i', $html);
        if (!$unclosed && !$strayParagraph && !preg_match($pattern, $html)) {
            return $html;
        }
        $out = '';
        $from = 0;
        $at = 0;
        $foreign = new LegacyForeignContent();
        while (($lt = strpos($html, '<', $at)) !== false) {
            $next = $html[$lt + 1] ?? '';
            if ($next === '!') {
                [$token, $end, $at] = self::markupDeclaration($html, $lt, $foreign);
            } elseif ($next === '/' && !ctype_alpha($html[$lt + 2] ?? '')) {
                [$token, $end, $at] = self::endTagWithoutName($html, $lt);
            } elseif (preg_match('/\G<(\/?)([a-zA-Z][^\s\/>]*)(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>?/', $html, $tag, 0, $lt)) {
                [$token, $end, $at] = self::scannedTag($html, $lt, $tag, $foreign, $rawText);
            } else {
                $at = $lt + 1;

                continue;
            }
            if ($token !== null) {
                $out .= substr($html, $from, $lt - $from) . $token;
                $from = $end;
            }
        }

        return $out . substr($html, $from);
    }

    /**
     * A token starting `<!`: what replaces it up to where, and where the scan goes on.
     *
     * @param string $html
     * @param int $lt
     * @param \MarkupCarve\Carve\Import\LegacyForeignContent $foreign
     *
     * @return array{0: string|null, 1: int, 2: int}
     */
    private static function markupDeclaration(string $html, int $lt, LegacyForeignContent $foreign): array
    {
        $length = strlen($html);
        if (substr($html, $lt + 2, 2) === '--') {
            $end = strpos($html, '-->', $lt + 2);

            // A comment open at the end of the input ends there; libxml 2.13 drops it.
            return $end === false ? [substr($html, $lt) . '-->', $length, $length] : [null, $end + 3, $end + 3];
        }
        if (substr($html, $lt + 2, 7) === '[CDATA[' && $foreign->cdata()) {
            // In foreign content a CDATA section is text.
            $end = strpos($html, ']]>', $lt + 9);
            $close = $end === false ? $length : $end;
            $resume = $end === false ? $length : $end + 3;

            return [strtr(substr($html, $lt + 9, $close - $lt - 9), ['&' => '&amp;', '<' => '&lt;']), $resume, $resume];
        }
        if (strncasecmp(substr($html, $lt + 2, 7), 'doctype', 7) === 0) {
            return [null, $lt + 2, $lt + 2];
        }
        $end = strpos($html, '>', $lt + 2);
        $close = $end === false ? $length : $end;
        $resume = $end === false ? $length : $end + 1;

        return ['<!--' . substr($html, $lt + 2, $close - $lt - 2) . '-->', $resume, $resume];
    }

    /**
     * `</` and no letter: `</>` is dropped, `</` at the end is text, anything else up to the next `>` is a bogus
     * comment.
     *
     * @param string $html
     * @param int $lt
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function endTagWithoutName(string $html, int $lt): array
    {
        $length = strlen($html);
        $end = strpos($html, '>', $lt + 2);
        $close = $end === false ? $length : $end;
        $resume = $end === false ? $length : $end + 1;
        $token = match (true) {
            $lt + 2 === $length => '&lt;/',
            $close === $lt + 2 => '',
            default => '<!--' . substr($html, $lt + 2, $close - $lt - 2) . '-->',
        };

        return [$token, $resume, $resume];
    }

    /**
     * A start or end tag, and for a raw text element its content.
     *
     * @param string $html
     * @param int $lt
     * @param array<string> $tag
     * @param \MarkupCarve\Carve\Import\LegacyForeignContent $foreign
     * @param bool $rawText
     *
     * @return array{0: string|null, 1: int, 2: int}
     */
    private static function scannedTag(string $html, int $lt, array $tag, LegacyForeignContent $foreign, bool $rawText): array
    {
        $at = $lt + strlen($tag[0]);
        $name = strtolower($tag[2]);
        $end = $tag[1] === '/';
        $inForeign = $foreign->foreign();
        $raw = in_array($name, self::RAW_TEXT, true);
        // In SVG and MathML raw text element names are ordinary elements: libxml gets a placeholder name for them.
        $renamed = $raw && ($end ? $foreign->closesForeign($name) : $inForeign);
        $strayParagraph = $end && $name === 'p' && !$foreign->opened('p');
        $token = '';
        // libxml has no foreign content: the end tags close what a breakout closes.
        foreach ($foreign->tag($name, $end, str_ends_with($tag[0], '/>'), $tag[0]) as $closedName) {
            $token .= '</' . self::foreignName($closedName) . '>';
        }
        if ($end && $name === 'br') {
            // The tree builder reads `</br>` as `<br>`; libxml drops it.
            return [$token . '<br>', $at, $at];
        }
        if ($strayParagraph) {
            // A `</p>` with no `p` open is an empty paragraph; libxml drops it, and would move a `<p>` out of an
            // inline parent, so it gets a placeholder name.
            $placeholder = self::placeholder('p');

            return [$token . '<' . $placeholder . '></' . $placeholder . '>', $at, $at];
        }
        if ($renamed) {
            return [$token . '<' . $tag[1] . self::foreignName($name) . substr($tag[0], 1 + strlen($tag[1]) + strlen($tag[2])), $at, $at];
        }
        if ($inForeign || $end || !$raw) {
            return [$token === '' ? null : $token . $tag[0], $at, $at];
        }
        $close = self::rawTextEnd($html, $tag[2], $at);
        $content = substr($html, $at, $close - $at);
        $escaped = $rawText ? self::escapeRawText($name, $content, $tag[0]) : $content;
        // The end tag closes the raw text element, never a foreign element of the same name.
        $endTag = '/\G<\/[^\s\/>]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>?/';
        $resume = $close;
        if (preg_match($endTag, $html, $match, 0, $close) === 1) {
            $resume += strlen($match[0]);
            $foreign->ended($name);
        }
        if ($escaped === $content) {
            return [null, $close, $resume];
        }
        $nameEnd = 1 + strlen($tag[2]);

        return [substr($tag[0], 0, $nameEnd) . self::rawMarker($name) . substr($tag[0], $nameEnd) . $escaped, $close, $resume];
    }

    /**
     * The name libxml gets for an element in foreign content: a placeholder for a raw text element name.
     *
     * @param string $name
     *
     * @return string
     */
    private static function foreignName(string $name): string
    {
        return in_array($name, self::RAW_TEXT, true) ? self::placeholder($name) : $name;
    }

    /**
     * A name libxml parses as an unknown element, which the copy reads as the given name.
     *
     * @param string $name
     *
     * @return string
     */
    private static function placeholder(string $name): string
    {
        $placeholder = 'carve-raw-' . self::nonce() . '-' . $name;
        self::$foreignNames[$placeholder] = $name;

        return $placeholder;
    }

    private static function nonce(): string
    {
        return self::$rawNonce ??= bin2hex(random_bytes(8));
    }

    /**
     * The marker attribute for an escaped `script` or `style`, whose text libxml does not decode.
     *
     * @param string $name
     *
     * @return string
     */
    private static function rawMarker(string $name): string
    {
        if (!in_array($name, ['script', 'style'], true)) {
            return '';
        }

        return ' ' . self::LEGACY_RAW . '="' . self::nonce() . '"';
    }

    /**
     * Where the content of a raw text element ends: at its own end tag, or at the end of the input.
     *
     * @param string $html
     * @param string $name
     * @param int $at
     *
     * @return int
     */
    private static function rawTextEnd(string $html, string $name, int $at): int
    {
        $found = preg_match('~</' . preg_quote($name, '~') . '[\s/>]~i', $html, $end, PREG_OFFSET_CAPTURE, $at);

        return $found === 1 ? $end[0][1] : strlen($html);
    }

    /**
     * The content of a raw text or RCDATA element, escaped so libxml below 2.14 reads it as the same text. `script`
     * and `style`, which libxml keeps raw up to `</` and a letter, are escaped only when they hold one and their start
     * tag leaves room for the LEGACY_RAW marker.
     *
     * @param string $name
     * @param string $content
     * @param string $tag
     *
     * @return string
     */
    private static function escapeRawText(string $name, string $content, string $tag): string
    {
        if ($name === 'textarea' || $name === 'title') {
            return str_replace('<', '&lt;', $content);
        }
        if ($name === 'script' || $name === 'style') {
            $names = preg_replace('/"[^"]*"|\'[^\']*\'/', '', $tag) ?? $tag;
            if (!preg_match('~</[a-zA-Z]~', $content) || preg_match('~[\s/]' . self::LEGACY_RAW . '(?![^\s/=>])~i', $names)) {
                return $content;
            }
        }

        return strtr($content, ['&' => '&amp;', '<' => '&lt;']);
    }

    /**
     * Whether this libxml parses markup inside raw text elements (below 2.14), probed once: an `xmp` whose entity
     * it decodes.
     *
     * @return bool
     */
    private static function tagsInRawText(): bool
    {
        if (self::$tagsInRawText === null) {
            $probe = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $probe->loadHTML('<xmp>&amp;</xmp>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            self::$tagsInRawText = $probe->textContent === '&';
        }

        return self::$tagsInRawText;
    }

    /**
     * Undoes the escaping of a marked `script` or `style`, whose text libxml does not decode.
     *
     * @param \DOMDocument $document
     *
     * @return void
     */
    private static function unescapeRaw(DOMDocument $document): void
    {
        foreach (['script', 'style'] as $name) {
            /** @var array<\DOMElement> $elements */
            $elements = iterator_to_array($document->getElementsByTagName($name));
            foreach ($elements as $element) {
                if (self::$rawNonce === null || $element->getAttribute(self::LEGACY_RAW) !== self::$rawNonce) {
                    continue;
                }
                $element->removeAttribute(self::LEGACY_RAW);
                foreach ($element->childNodes as $child) {
                    if ($child instanceof DOMText) {
                        $child->data = strtr($child->data, ['&lt;' => '<', '&amp;' => '&']);
                    }
                }
            }
        }
    }

    /**
     * A void element holds no content: what libxml put inside one follows it.
     *
     * @param \DOMDocument $document
     * @param string $html
     *
     * @return void
     */
    private static function emptyVoids(DOMDocument $document, string $html): void
    {
        foreach (self::LEGACY_VOIDS as $name) {
            if (stripos($html, '<' . $name) === false) {
                continue;
            }
            /** @var array<\DOMElement> $elements */
            $elements = iterator_to_array($document->getElementsByTagName($name));
            foreach ($elements as $element) {
                while ($element->lastChild !== null && $element->parentNode !== null) {
                    $element->parentNode->insertBefore($element->lastChild, $element->nextSibling);
                }
            }
        }
    }

    /**
     * libxml leaves table rows and cells where they were written; the HTML5 tree builder wraps each run of them in its
     * own `tbody`, and a bare cell in an implied `tr` (markup-carve/carve#2485).
     *
     * @param \DOMDocument $document
     *
     * @return void
     */
    private static function impliedTableBodies(DOMDocument $document): void
    {
        /** @var array<\DOMElement> $tables */
        $tables = iterator_to_array($document->getElementsByTagName('table'));
        foreach ($tables as $table) {
            $group = null;
            $row = null;
            foreach (iterator_to_array($table->childNodes) as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                $name = strtolower($child->localName ?? $child->tagName);
                if (!in_array($name, ['tr', 'td', 'th'], true)) {
                    $group = null;
                    $row = null;

                    continue;
                }
                if ($group === null) {
                    $group = $document->createElement('tbody');
                    $table->insertBefore($group, $child);
                }
                if ($name === 'tr') {
                    $group->appendChild($child);
                    $row = null;

                    continue;
                }
                if ($row === null) {
                    $row = $document->createElement('tr');
                    $group->appendChild($row);
                }
                $row->appendChild($child);
            }
        }
    }

    /**
     * @param \DOMNode $source
     * @param \MarkupCarve\Carve\Import\HtmlNode $target
     * @param bool $fragment
     * @param \DOMComment|null $synthetic The declaration inserted only to select UTF-8 in the libxml fallback.
     *
     * @return void
     */
    private static function copyLegacy(DOMNode $source, HtmlNode $target, bool $fragment = false, ?DOMComment $synthetic = null): void
    {
        $pending = [[$source, $target, '']];
        while ($pending !== []) {
            [$from, $to, $space] = array_pop($pending);
            foreach ($from->childNodes as $child) {
                if ($child === $synthetic) {
                    continue;
                }
                // HTML: in a body fragment an `<html>` or `<body>` start tag is ignored, its content stays in place.
                if ($fragment && $child instanceof DOMElement && in_array(strtolower($child->tagName), ['html', 'body'], true)) {
                    $pending[] = [$child, $to, $space];

                    continue;
                }
                $node = self::legacyNode($child);
                if ($node === null) {
                    continue;
                }
                $childSpace = $node->kind === HtmlNode::ELEMENT && ($space !== '' || $node->name === 'svg' || $node->name === 'math')
                    ? LegacyForeignContent::place($space, $to, $node)
                    : '';
                $to->append($node);
                if ($child instanceof DOMElement && $node->name === 'template') {
                    // libxml has no template contents: the children stand in for them.
                    $holder = new HtmlNode(HtmlNode::ROOT);
                    self::copyLegacy($child, $holder);
                    foreach ($holder->children as $inner) {
                        $inner->parent = null;
                    }
                    $node->content = $holder->children;
                } elseif ($child instanceof DOMElement && $child->firstElementChild === null) {
                    // Only texts and comments below: copied here, in the order the pending list would. A long
                    // paragraph of spans otherwise holds a DOM object and a pair per span (carve-php#5722).
                    foreach ($child->childNodes as $leaf) {
                        $leafNode = self::legacyNode($leaf);
                        if ($leafNode !== null) {
                            $node->append($leafNode);
                        }
                    }
                } elseif ($child instanceof DOMElement) {
                    $pending[] = [$child, $node, $childSpace];
                }
            }
        }
    }

    private static function legacyNode(DOMNode $child): ?HtmlNode
    {
        if ($child instanceof DOMElement) {
            $attrs = [];
            foreach ($child->attributes as $attribute) {
                $attrs[strtolower($attribute->name)] = (string)$attribute->value;
            }

            $name = strtolower($child->localName ?? $child->tagName);

            return new HtmlNode(HtmlNode::ELEMENT, self::$foreignNames[$name] ?? $name, $attrs);
        }
        if ($child instanceof DOMText) {
            return new HtmlNode(HtmlNode::TEXT, str_replace("\0", "\u{FFFD}", $child->data));
        }
        if ($child instanceof DOMComment) {
            return new HtmlNode(HtmlNode::COMMENT, $child->data);
        }

        return $child instanceof DOMDocumentType ? new HtmlNode(HtmlNode::DOCTYPE, $child->name) : null;
    }
}
