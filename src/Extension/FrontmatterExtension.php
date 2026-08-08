<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Document;

/**
 * Parses frontmatter blocks at the start of documents
 *
 * Supports YAML, NEON, TOML, JSON, or any other format. The extension parses
 * the frontmatter syntax but does not interpret the content - applications
 * should use their preferred library (symfony/yaml, etc.) to parse the
 * raw content.
 *
 * Syntax:
 * ```
 * ---yaml
 * title: My Document
 * author: John Doe
 * ---
 *
 * # Document content starts here
 * ```
 *
 * Block attributes are placed above (standard djot style):
 * ```
 * {.meta #frontmatter}
 * ---yaml
 * title: My Document
 * ---
 * ```
 *
 * The format identifier (yaml, toml, json) is required to distinguish
 * from thematic breaks (---).
 *
 * Example usage:
 * ```php
 * $ext = new FrontmatterExtension();
 * $converter = new CarveConverter();
 * $converter->addExtension($ext);
 *
 * $html = $converter->convert($djot);
 *
 * // Get the raw frontmatter
 * $frontmatter = $ext->getFrontmatter();
 * if ($frontmatter) {
 *     echo $frontmatter->getFormat(); // 'yaml'
 *     echo $frontmatter->getContent(); // 'title: My Document...'
 * }
 *
 * // Or use the convenience method with a parser
 * $metadata = $ext->getParsedContent(function($content, $format) {
 *     if ($format === 'yaml') {
 *         return \Symfony\Component\Yaml\Yaml::parse($content);
 *     }
 *     return null;
 * });
 * ```
 *
 * Configuration:
 * ```php
 * // Bare --- opening (no format specified) falls back to 'yaml' by default
 * $ext = new FrontmatterExtension();
 *
 * // Configure a different default format (e.g. for TOML-first projects)
 * $ext = new FrontmatterExtension(defaultFormat: 'toml');
 *
 * // Output frontmatter as HTML comment
 * $ext = new FrontmatterExtension(renderAsComment: true);
 *
 * // Custom render callback
 * $ext = new FrontmatterExtension(
 *     renderCallback: fn(Frontmatter $fm) => '<script type="application/json">' .
 *         htmlspecialchars($fm->getContent()) . '</script>'
 * );
 * ```
 */
class FrontmatterExtension implements ParsedDocumentExtensionInterface
{
    /**
     * The opening delimiter, in ONE spelling.
     *
     * THE SLOT BEFORE THE FORMAT TOKEN IS A SPACE, U+0020 and nothing else.
     * PART 7's MARKER SEPARATORS AND PADDING SLOTS decides the terminal by
     * POSITION rather than by role: a tab is syntax only in a line's leading
     * indentation run, and this slot sits after the `---`. The grammar spells
     * it `frontmatter_open = "---", [space], [frontmatter_format], newline`,
     * and the clause names this slot among the padding ones - "the `---` pair
     * has already decided the block; the token only names the metadata
     * dialect" - padding that takes `space` all the same.
     *
     * The slot read `[ \t]*`, so `---<TAB>yaml` opened frontmatter and its
     * body was swallowed as metadata where the grammar leaves a thematic break
     * followed by ordinary lines. The trailing `\s*$` is not a slot in the
     * grammar and stays as tolerant as it was (carve-php#951).
     *
     * EXACTLY ONE, so ` ?` and not ` *`. PART 7's cardinality paragraph names
     * this among the four slots spelled `space`, and holds the production right
     * against the four artifacts that accepted a run (carve#912). With two
     * spaces `frontmatter_format = (letter | digit)+` cannot match, so the line
     * is not a typed opener; it is not a thematic break either, and the
     * metadata lines fold into an ordinary paragraph.
     *
     * The pattern was spelled out twice, once to register the matcher and once
     * to re-read the captured format. One rule gets one spelling, so a future
     * correction cannot land on one of them and miss the other.
     *
     * @var string
     */
    // LINE PADDING, so PART 7's four characters and not `\s`. A VERTICAL TAB or a
    // FORM FEED after the opener is CONTENT, so `---<VT>` is not a frontmatter
    // opener. Reading it as one is the SEVERE shape of this defect: the opener
    // runs to the next bare three-dash line, so a wide class does not mislabel one
    // line, it swallows the document down to the closer (markup-carve/carve#963).
    /**
     * @var string
     */
    protected const OPEN_PATTERN = '/^--- ?(\w*)[ \t]*$/';

    protected ?Frontmatter $frontmatter = null;

    /**
     * @var \Closure|null
     * @phpstan-var (\Closure(\MarkupCarve\Carve\Extension\Frontmatter): string)|null
     */
    protected ?Closure $renderCallback = null;

    /**
     * @param string $defaultFormat Format to use when the opening delimiter has no format identifier (e.g. bare ---)
     * @param bool $renderAsComment If true, render frontmatter as HTML comment
     * @param (\Closure(\MarkupCarve\Carve\Extension\Frontmatter): string)|null $renderCallback Custom render callback
     */
    public function __construct(
        protected string $defaultFormat = 'yaml',
        protected bool $renderAsComment = false,
        ?Closure $renderCallback = null,
    ) {
        $this->renderCallback = $renderCallback;
    }

    public function register(CarveConverter $converter): void
    {
        $parser = $converter->getParser();

        // Register block pattern for frontmatter
        // Matches --- optionally followed by a format identifier (e.g. ---yaml, ---toml).
        // The space between --- and the identifier is optional (lenient input:
        // both ---yaml and --- yaml are accepted; ---yaml is canonical) - but it
        // is a SPACE when present, see self::OPEN_PATTERN.
        // When no identifier is present, $defaultFormat is used as the fallback
        $parser->addBlockPattern(
            self::OPEN_PATTERN,
            function (array $lines, int $start, $parent, $blockParser) {
                // Frontmatter is the document's FIRST production:
                // grammar.ebnf pins `document = [frontmatter], {block}, EOF`,
                // so nothing may precede the opener - not a blank line, and not
                // a block-attribute line. "First block of Document" is not
                // enough on its own, because neither of those yields a child
                // node, so a `---` pair behind one was still claimed and
                // swallowed the document.
                if (!($parent instanceof Document) || $parent->hasChildren() || $start !== 0) {
                    return null;
                }

                if (!preg_match(self::OPEN_PATTERN, $lines[$start], $matches)) {
                    return null; // @codeCoverageIgnore - pattern already matched
                }
                $format = $matches[1] !== '' ? $matches[1] : $this->defaultFormat;

                // Find closing ---
                $i = $start + 1;
                $count = count($lines);
                $contentLines = [];
                $closed = false;

                while ($i < $count) {
                    $line = $lines[$i];
                    // Closing delimiter is just ---
                    if (preg_match('/^---[ \t]*$/', $line)) {
                        $i++;
                        $closed = true;

                        break;
                    }
                    $contentLines[] = $line;
                    $i++;
                }

                // If no closing delimiter found, don't treat as frontmatter
                if (!$closed) {
                    return null;
                }

                $content = implode("\n", $contentLines);

                $frontmatter = new Frontmatter($content, $format);

                // Apply block attributes from preceding line (standard djot style)
                $attrs = $blockParser->consumePendingAttributes();
                if ($attrs !== []) {
                    $frontmatter->setAttributes($attrs);
                }

                $this->frontmatter = $frontmatter;
                $parent->appendChild($frontmatter);

                return $i - $start;
            },
        );

        // Register render event to control output
        $converter->on('render.frontmatter', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!($node instanceof Frontmatter)) {
                return;
            }

            $event->preventDefault();

            if ($this->renderCallback !== null) {
                $event->setHtml(($this->renderCallback)($node));

                return;
            }

            if ($this->renderAsComment) {
                $content = $node->getContent();
                // Escape -- in content to prevent breaking HTML comments
                $escaped = str_replace('--', '&#45;&#45;', $content);
                $event->setHtml("<!-- frontmatter ({$node->getFormat()})\n{$escaped}\n-->\n");

                return;
            }

            // Default: no output
            $event->setHtml('');
        });
    }

    public function afterParse(Document $document): void
    {
        $this->frontmatter = null;

        foreach ($document->getChildren() as $child) {
            if ($child instanceof Frontmatter) {
                $this->frontmatter = $child;

                break;
            }
        }
    }

    /**
     * Get the parsed frontmatter node
     *
     * Returns null if no frontmatter was found or parsing hasn't occurred yet.
     */
    public function getFrontmatter(): ?Frontmatter
    {
        return $this->frontmatter;
    }

    /**
     * Check if frontmatter was found
     */
    public function hasFrontmatter(): bool
    {
        return $this->frontmatter !== null;
    }

    /**
     * Get the raw frontmatter content
     */
    public function getContent(): ?string
    {
        return $this->frontmatter?->getContent();
    }

    /**
     * Get the frontmatter format (yaml, toml, json, etc.)
     */
    public function getFormat(): ?string
    {
        return $this->frontmatter?->getFormat();
    }

    /**
     * Parse the frontmatter content using a custom parser
     *
     * Example with Symfony YAML:
     * ```php
     * $data = $ext->getParsedContent(function($content, $format) {
     *     return match($format) {
     *         'yaml' => \Symfony\Component\Yaml\Yaml::parse($content),
     *         'json' => json_decode($content, true),
     *         'toml' => \Yosymfony\Toml\Toml::parse($content),
     *         default => null,
     *     };
     * });
     * ```
     *
     * @param callable $parser Callback that receives (string $content, string $format) and returns parsed data
     *
     * @return mixed The parsed content, or null if no frontmatter
     */
    public function getParsedContent(callable $parser): mixed
    {
        if ($this->frontmatter === null) {
            return null;
        }

        return $parser(
            $this->frontmatter->getContent(),
            $this->frontmatter->getFormat(),
        );
    }

    /**
     * Reset the extension state (for reuse with multiple documents)
     */
    public function reset(): void
    {
        $this->frontmatter = null;
    }
}
