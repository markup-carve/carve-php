<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * The two rules about the compact semantic-span names (PART 9 §9 and §10).
 */
class SemanticAttributeLinter
{
    /**
     * @var string
     */
    public const RULE_SEMANTIC_ATTRIBUTE_VALUE_IGNORED = 'semantic-attribute-value-ignored';

    /**
     * @var string
     */
    public const RULE_SEMANTIC_ATTRIBUTE_OUTSIDE_SPAN = 'semantic-attribute-outside-span';

    /**
     * The names whose authored value reaches the output, as `title` or
     * `datetime`. On every other name that becomes an element the value only
     * selects that element and is dropped.
     *
     * Mirrors the mapping in `HtmlRenderer::renderSpan()`, which is the only
     * place that decides it. `SemanticAttributeLinterTest` measures the renderer
     * against this list for every reserved name, so the copy cannot drift
     * unnoticed: a name that starts carrying its value while this list still
     * calls it discarded reports a loss that stopped happening.
     *
     * @var array<string>
     */
    private const NAMES_KEEPING_A_VALUE = ['abbr', 'dfn', 'time'];

    /**
     * Reserved names that ARE valid HTML attributes on a given node type, so
     * finding one there is the author getting what they asked for rather than a
     * silent failure.
     *
     * `cite` on a block quote is the case that matters: it is a URL attribute of
     * `blockquote` and `q` in HTML, and `{cite="https://…"}` on a quote renders
     * `<blockquote cite="https://…">`. Reporting that would be telling an author
     * their correct markup is wrong.
     *
     * @var array<string, array<string>>
     */
    private const VALID_ATTRIBUTE_ON = [
        'block_quote' => ['cite'],
    ];

    /**
     * Longest rendered value quoted back whole, in CODEPOINTS.
     *
     * Past it the diagnostic keeps its head and marks the cut, so a pasted
     * paragraph in an attribute cannot push the sentence explaining the problem
     * off the reader's screen. Counted in codepoints rather than bytes so the
     * cut never lands in the middle of a UTF-8 sequence.
     *
     * @var int
     */
    private const QUOTED_VALUE_LIMIT = 120;

    /**
     * Marks a value the diagnostic cut, inside the quotes it was cut from.
     *
     * @var string
     */
    private const QUOTED_VALUE_ELLIPSIS = "\u{2026}";

    /**
     * @param string $source
     * @param array<string, mixed> $options Supported: `extensions`, a list of
     *   `ExtensionInterface` instances the caller renders with. Anything else in
     *   the list is ignored.
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = []): array
    {
        $converter = new CarveConverter();
        foreach ($this->selectedExtensions($options['extensions'] ?? []) as $extension) {
            $converter->addExtension($extension);
        }
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);

        // Read off the renderer the caller's extensions just configured rather
        // than off a second copy of the tier split. A rule that decides "is this
        // an element?" from its own table reports the wrong thing the moment an
        // extension registers a name the table has not heard of.
        //
        // NO EMPTY-SET SHORTCUT HERE. §9 puts three names in core, so the set is
        // never empty and a guard on it could not fail - the walk below already
        // does nothing for an empty set, which is the same answer without a
        // branch nothing can reach.
        $warnings = [];
        $this->collect(
            $document,
            $converter->getHtmlRenderer()->semanticSpanNames(),
            $converter->getHtmlRenderer(),
            $this->byteOffsets($source),
            strlen($source),
            $warnings,
        );

        return $warnings;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string> $elementNames
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     */
    private function collect(
        Node $node,
        array $elementNames,
        HtmlRenderer $renderer,
        ?array $byteAt,
        int $sourceLength,
        array &$warnings,
    ): void {
        $type = $node->getType();
        $attributes = $node->getAttributes();
        foreach ($elementNames as $name) {
            if (!array_key_exists($name, $attributes)) {
                continue;
            }

            if ($type === 'span') {
                if ($attributes[$name] !== '' && !in_array($name, self::NAMES_KEEPING_A_VALUE, true)) {
                    $warnings[] = $this->warn(
                        $node,
                        $byteAt,
                        $sourceLength,
                        self::RULE_SEMANTIC_ATTRIBUTE_VALUE_IGNORED,
                        sprintf(
                            'Value on the semantic attribute "%s" is discarded: it selects the <%s> element '
                                . 'and reaches no output. Only abbr, dfn and time carry a value '
                                . '(as title or datetime).',
                            $name,
                            $name,
                        ),
                    );
                }

                continue;
            }

            if (in_array($name, self::VALID_ATTRIBUTE_ON[$type] ?? [], true)) {
                continue;
            }

            $warnings[] = $this->warn(
                $node,
                $byteAt,
                $sourceLength,
                self::RULE_SEMANTIC_ATTRIBUTE_OUTSIDE_SPAN,
                sprintf(
                    '"%s" is a semantic span attribute (PART 9 %s10) and only applies to an ordinary '
                        . '[content]{attrs} span; on %s it stays a raw attribute and renders as %s="%s".',
                    $name,
                    "\u{00A7}",
                    $type,
                    $name,
                    // The value the RENDERER emits, cut if it is long, escaped
                    // the way the renderer escapes it. Naming a fixed empty
                    // value here would make the message false as soon as the
                    // author wrote one, and an authored value is the shape most
                    // worth reporting - `` `c`{kbd=…} `` renders the value it
                    // was given.
                    $this->quotedValue($renderer, $name, $attributes[$name]),
                ),
            );
        }

        foreach ($node->getChildren() as $child) {
            $this->collect($child, $elementNames, $renderer, $byteAt, $sourceLength, $warnings);
        }
    }

    /**
     * The rendered value as the diagnostic quotes it: what the renderer writes,
     * cut if it is long, escaped as the renderer escapes it.
     *
     * THE THREE STEPS RUN IN EXACTLY THAT ORDER AND NONE OF THEM COMMUTES:
     *
     * - The sanitizer reads the WHOLE value, so cutting first could quote a long
     *   `javascript:…` payload back as a harmless-looking prefix while the
     *   output holds an empty attribute.
     * - Escaping last is what keeps the cut off the middle of an entity, which
     *   would quote `&qu` at an author who wrote a quote.
     *
     * Each step is pinned by its own case in `SemanticAttributeLinterTest`,
     * because a test that only checks the composed result passes for two of the
     * six possible orders.
     *
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     * @param string $name
     * @param string $value
     *
     * @return string
     */
    private function quotedValue(HtmlRenderer $renderer, string $name, string $value): string
    {
        $rendered = $renderer->renderedAttributeValue($name, $value);
        if (mb_strlen($rendered, 'UTF-8') <= self::QUOTED_VALUE_LIMIT) {
            return $renderer->escapeAttribute($rendered);
        }

        return $renderer->escapeAttribute(mb_substr($rendered, 0, self::QUOTED_VALUE_LIMIT, 'UTF-8'))
            . self::QUOTED_VALUE_ELLIPSIS;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     * @param string $rule
     * @param string $message
     *
     * @return \MarkupCarve\Carve\Lint\LintWarning
     */
    private function warn(
        Node $node,
        ?array $byteAt,
        int $sourceLength,
        string $rule,
        string $message,
    ): LintWarning {
        $pos = $node->getPos();
        // A node the parser could not place carries NO span - PART 12 §4 forbids
        // inventing one - so the finding falls back to the document start rather
        // than to a position it made up.
        if ($pos === null) {
            return new LintWarning(1, 1, $rule, $message, 0, 0);
        }

        return new LintWarning(
            $pos->startLine,
            $pos->startColumn,
            $rule,
            $message,
            $this->toByteOffset($pos->startOffset, $byteAt, $sourceLength),
            $this->toByteOffset($pos->endOffset, $byteAt, $sourceLength),
        );
    }

    /**
     * The byte offset a codepoint offset names. The conversion itself lives in
     * `SourceOffsets`, so a second AST-walking pass cannot convert differently
     * from this one.
     *
     * @param int $codepointOffset
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     */
    private function toByteOffset(int $codepointOffset, ?array $byteAt, int $sourceLength): int
    {
        return SourceOffsets::toByte($codepointOffset, $byteAt, $sourceLength);
    }

    /**
     * Byte offset of each codepoint, or null when the source is pure ASCII.
     *
     * @return array<int, int>|null
     */
    private function byteOffsets(string $source): ?array
    {
        return SourceOffsets::map($source);
    }

    /**
     * @param mixed $extensions
     *
     * @return list<\MarkupCarve\Carve\Extension\ExtensionInterface>
     */
    private function selectedExtensions(mixed $extensions): array
    {
        if (!is_iterable($extensions)) {
            return [];
        }

        $selected = [];
        foreach ($extensions as $extension) {
            if ($extension instanceof ExtensionInterface) {
                $selected[] = $extension;
            }
        }

        return $selected;
    }
}
