<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * The two rules about the compact semantic-span names (PART 9 §9 and §10).
 *
 * NEITHER REPORTS AN ENGINE DEFECT. All three engines render these
 * byte-identically and exactly as the clause reads. They report the two places
 * where the clause's own scope loses something the author wrote, with nothing
 * else marking it (markup-carve/carve#1131, markup-carve/carve#1132):
 *
 * - `semantic-attribute-value-ignored`: a value on a name that only SELECTS its
 *   wrapper. `[x]{kbd="V"}` renders `<kbd>x</kbd>` and `V` reaches no target.
 *   Only `abbr`, `dfn` and `time` carry a value, as `title` or `datetime`.
 * - `semantic-attribute-outside-span`: a reserved name on anything other than an
 *   ordinary `[content]{attrs}` span, where §10 does not apply and the name
 *   stays a raw attribute. `` `c`{kbd} `` renders `<code kbd="">c</code>`, which
 *   is what it has always rendered - the wart is that one spelling now means two
 *   things depending on what it attaches to.
 *
 * BOTH RULES ARE TIER-AWARE. §9 puts `abbr`, `time` and `kbd` in core and leaves
 * `samp`, `var`, `cite` and `dfn` to the SemanticSpan extension. A name the
 * caller's render does NOT turn into an element is an ordinary attribute
 * everywhere, so its value reaches the output intact and neither rule applies to
 * it. Pass the extensions you render with; omitted means a core render.
 *
 * This is this package's first AST-walking lint pass. `MarkdownHabitLinter`
 * never parses - it is a source scan, named for a different job - so these rules
 * live here rather than bolted onto it.
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
        $elementNames = $converter->getHtmlRenderer()->semanticSpanNames();
        if ($elementNames === []) {
            return [];
        }

        $warnings = [];
        $this->collect(
            $document,
            $elementNames,
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
                    // The value the RENDERER emits, escaped the way it escapes
                    // it. Naming a fixed empty value here would make the message
                    // false as soon as the author wrote one, and an authored
                    // value is the shape most worth reporting - `` `c`{kbd=…} ``
                    // renders the value it was given.
                    $renderer->escapeAttribute($attributes[$name]),
                ),
            );
        }

        foreach ($node->getChildren() as $child) {
            $this->collect($child, $elementNames, $renderer, $byteAt, $sourceLength, $warnings);
        }
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
     * The byte offset a codepoint offset names.
     *
     * A `SourceSpan` counts CODEPOINTS, because PART 12 §4 says so. A
     * `LintWarning` carries BYTE offsets, because that is what a PHP caller
     * slices a string with and what this package's other lint pass has always
     * emitted - two rules in one `carve lint` run reporting in two different
     * units would be a defect of its own.
     *
     * @param int $codepointOffset
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     */
    private function toByteOffset(int $codepointOffset, ?array $byteAt, int $sourceLength): int
    {
        if ($byteAt === null) {
            return min($codepointOffset, $sourceLength);
        }

        return $byteAt[$codepointOffset] ?? $sourceLength;
    }

    /**
     * Byte offset of each codepoint, for codepoints 0..count, or null when the
     * source is pure ASCII and the two units are the same number.
     *
     * @return array<int, int>|null
     */
    private function byteOffsets(string $source): ?array
    {
        if (!preg_match('/[\x80-\xFF]/', $source)) {
            return null;
        }

        $map = [];
        $length = strlen($source);
        for ($i = 0; $i <= $length; $i++) {
            // Continuation bytes (10xxxxxx) do not begin a codepoint, and the
            // one past the end always does - a span may end at the document's
            // last offset.
            if ($i === $length || (ord($source[$i]) & 0xC0) !== 0x80) {
                $map[] = $i;
            }
        }

        return $map;
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
