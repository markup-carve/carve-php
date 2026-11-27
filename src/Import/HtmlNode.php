<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * One node of the parsed HTML, the same for both parsers: an element, a text, a comment or a doctype. The importer reads
 * this tree, never the input text (C2-M-003).
 */
final class HtmlNode
{
    /**
     * @var string
     */
    public const ELEMENT = 'element';

    /**
     * @var string
     */
    public const TEXT = 'text';

    /**
     * @var string
     */
    public const COMMENT = 'comment';

    /**
     * @var string
     */
    public const DOCTYPE = 'doctype';

    /**
     * @var string
     */
    public const ROOT = 'root';

    /**
     * @var array<\MarkupCarve\Carve\Import\HtmlNode>
     */
    public array $children = [];

    public ?self $parent = null;

    public int $sourceStart = -1;

    public int $sourceEnd = -1;

    /**
     * Start of the explicit closer, or the boundary where the HTML parser implicitly closes this element.
     */
    public int $sourceCloseStart = -1;

    public int $contentStart = -1;

    /**
     * C2-H-183: the template contents, which the parser keeps apart from the children.
     *
     * @var array<\MarkupCarve\Carve\Import\HtmlNode>|null
     */
    public ?array $content = null;

    /**
     * @param string $kind
     * @param string $name The lower-case local name of an element; the text of a text or comment node.
     * @param array<string, string> $attrs In the order the parser gives.
     * @param bool $foreign An element of the SVG or MathML namespace.
     */
    public function __construct(
        public string $kind,
        public string $name = '',
        public array $attrs = [],
        public bool $foreign = false,
    ) {
    }

    public function append(self $child): void
    {
        $child->parent = $this;
        $this->children[] = $child;
    }

    public function isElement(?string $name = null): bool
    {
        return $this->kind === self::ELEMENT && ($name === null || $this->name === $name);
    }

    public function isText(): bool
    {
        return $this->kind === self::TEXT;
    }

    public function isComment(): bool
    {
        return $this->kind === self::COMMENT;
    }

    /**
     * C2-M-016: text that holds only ASCII whitespace.
     */
    public function isBlank(): bool
    {
        return $this->kind === self::TEXT && strspn($this->name, " \t\n\f\r") === strlen($this->name);
    }

    public function attr(string $name): ?string
    {
        return $this->attrs[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->attrs[$name]);
    }

    /**
     * @return array<\MarkupCarve\Carve\Import\HtmlNode>
     */
    public function elements(): array
    {
        return array_values(array_filter($this->children, static fn (self $child): bool => $child->kind === self::ELEMENT));
    }

    public function firstElement(string $name): ?self
    {
        foreach ($this->children as $child) {
            if ($child->kind === self::ELEMENT && $child->name === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * The text of the node with the markup inside dropped.
     */
    public function plain(): string
    {
        if ($this->kind === self::TEXT) {
            return $this->name;
        }
        if ($this->kind !== self::ELEMENT && $this->kind !== self::ROOT) {
            return '';
        }
        $out = '';
        foreach ($this->children as $child) {
            $out .= $child->plain();
        }

        return $out;
    }

    /**
     * The classes of the element, split at ASCII whitespace.
     *
     * @return array<string>
     */
    public function classes(): array
    {
        $value = $this->attrs['class'] ?? '';

        $names = preg_split('/[ \t\n\f\r]+/', $value) ?: [];

        return array_values(array_filter($names, static fn (string $s): bool => $s !== ''));
    }

    public function tag(): string
    {
        return match ($this->kind) {
            self::ELEMENT => $this->name,
            self::TEXT => '#text',
            self::COMMENT => '#comment',
            default => '#doctype',
        };
    }
}
