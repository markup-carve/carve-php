<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * For the libxml fallback's pre-parse scan: whether a tag stands in SVG or MathML content, where `title`, `style` and
 * `script` are not raw text. An approximation of the HTML tree builder, linear in the input: font breakout,
 * `annotation-xml` and implied end tags are not modeled.
 *
 * @internal
 */
final class LegacyForeignContent
{
    /**
     * Start tags that end foreign content (the HTML Standard's breakout list, without `font`).
     *
     * @var array<string>
     */
    private const BREAKOUT = [
        'b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl',
        'dt', 'em', 'embed', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu',
        'meta', 'nobr', 'ol', 'p', 'pre', 'ruby', 's',
        'small', 'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tt', 'u', 'ul',
        'var',
    ];

    /**
     * @var array<string>
     */
    private const MATH_TEXT = ['mi', 'mo', 'mn', 'ms', 'mtext'];

    /**
     * @var array<string>
     */
    private const VOIDS = [
        'area', 'base', 'basefont', 'bgsound', 'br', 'col', 'embed', 'frame', 'hr', 'img',
        'input', 'keygen', 'link',
        'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * The open foreign elements and HTML integration points, innermost last: `[mode, name]` with mode `svg`, `math`
     * or `html`.
     *
     * @var array<array{0: string, 1: string}>
     */
    private array $stack = [];

    /**
     * How often each name is on the stack.
     *
     * @var array<string, int>
     */
    private array $onStack = [];

    /**
     * Open HTML elements by name, so an end tag in foreign content can tell an ancestor from a stray tag.
     *
     * @var array<string, int>
     */
    private array $open = [];

    public function foreign(): bool
    {
        $top = $this->top();

        return $top !== null && $top[0] !== 'html';
    }

    /**
     * @param string $name Lowercased tag name.
     * @param bool $end
     * @param bool $selfClosing
     *
     * @return void
     */
    public function tag(string $name, bool $end, bool $selfClosing): void
    {
        if ($end) {
            $this->close($name);

            return;
        }
        $top = $this->top();
        if ($name === 'svg' || $name === 'math') {
            $mode = $name;
        } elseif ($top === null || $top[0] === 'html') {
            $mode = $top !== null && $top[1] === 'mathtext' && in_array($name, ['mglyph', 'malignmark'], true) ? 'math' : null;
            if ($mode === null && !$selfClosing && !in_array($name, self::VOIDS, true)) {
                $this->open[$name] = ($this->open[$name] ?? 0) + 1;
            }
        } elseif (in_array($name, self::BREAKOUT, true)) {
            $this->leave();
            $this->tag($name, false, $selfClosing);

            return;
        } else {
            $mode = $this->childMode($top[0], $name);
        }
        if ($mode !== null && !$selfClosing) {
            $this->push($mode === 'mathtext' ? ['html', 'mathtext'] : [$mode, $name]);
        }
    }

    private function childMode(string $parent, string $name): string
    {
        if ($parent === 'svg' && in_array($name, ['foreignobject', 'desc', 'title'], true)) {
            return 'html';
        }

        return $parent === 'math' && in_array($name, self::MATH_TEXT, true) ? 'mathtext' : $parent;
    }

    private function close(string $name): void
    {
        $key = in_array($name, self::MATH_TEXT, true) ? 'mathtext' : $name;
        if (($this->onStack[$key] ?? 0) > 0) {
            while (($top = $this->pop()) !== null && $top[1] !== $key) {
                // Pops up to and including the element the end tag names.
            }

            return;
        }
        if (($this->open[$name] ?? 0) === 0) {
            return;
        }
        $this->open[$name]--;
        // An end tag for an open HTML ancestor closes the foreign content in between.
        $this->leave();
    }

    private function leave(): void
    {
        while ($this->foreign()) {
            $this->pop();
        }
    }

    /**
     * @param array{0: string, 1: string} $entry
     *
     * @return void
     */
    private function push(array $entry): void
    {
        $this->stack[] = $entry;
        $this->onStack[$entry[1]] = ($this->onStack[$entry[1]] ?? 0) + 1;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function pop(): ?array
    {
        $entry = array_pop($this->stack);
        if ($entry !== null) {
            $this->onStack[$entry[1]]--;
        }

        return $entry;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function top(): ?array
    {
        return $this->stack[count($this->stack) - 1] ?? null;
    }
}
