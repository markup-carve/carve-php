<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Diagnostics\Message;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Options;

/**
 * The state of one lint run over one source. Offsets are bytes of the source.
 */
final class Context
{
    /**
     * @var array<\MarkupCarve\Carve\Lint\Line>
     */
    public array $lines = [];

    /**
     * @var array<int> The start offset of each line, by line index.
     */
    public array $lineStarts = [];

    /**
     * @var array<string, string> The node type at each path.
     */
    public array $nodeTypes = [];

    /**
     * The parsed nodes in source walk order for the shared data rule model.
     *
     * @var list<array{node: array<string, mixed>, path: string, parent: string, index: int, pos: array<string, int>|null, type: string}>
     */
    public array $walked = [];

    public bool $dataRules;

    /**
     * @var array<string, true>
     */
    public array $dataNodeTypes = [];

    /**
     * @var array<string, array<string, mixed>> The source layout entry of each node, by path (data rules only).
     */
    public array $layoutEntries = [];

    /**
     * @var array<string, true>
     */
    private array $dataIds = [];

    /**
     * @var array<string, int> The first line of the node at each path.
     */
    public array $firstLines = [];

    /**
     * @var array<int, string> Type of the top-level block before a block starting on this line.
     */
    public array $previousTopLevelBlocks = [];

    /**
     * @var array<int, string> Path of each parsed pipe table row by source line.
     */
    public array $tableRowPaths = [];

    /**
     * @var array<int, true> First lines of parsed description entries.
     */
    public array $descriptionEntryLines = [];

    /**
     * @var array<int, int> First line of the parsed block comment covering each physical line.
     */
    public array $blockCommentStartLines = [];

    /**
     * First source byte of each paragraph's content.
     *
     * @var array<string, int>
     */
    public array $paragraphStarts = [];

    /**
     * @var array<int, true> Start bytes of parsed braced underline spans.
     */
    public array $underlineStarts = [];

    /**
     * Frontmatter lines whose text is opaque to all other line rules.
     *
     * @var array<int, true>
     */
    public array $frontmatterLines = [];

    /**
     * @var array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public array $diagnostics = [];

    /**
     * @var array<int> The line each finding counts at, by finding index.
     */
    public array $findingLines = [];

    /**
     * @var array<array{0: int, 1: int, 2: string, 3: string}> Byte ranges of the opaque leaves, with path and type.
     */
    public array $leafMask = [];

    public int $headingLevel = 0;

    /**
     * @var array<string, true>
     */
    public array $headingKinds = [];

    /**
     * @var array{0: int, 1: int, 2: string}|null
     */
    public ?array $firstHeading = null;

    public bool $mixedReported = false;

    /**
     * @var array<string, true>
     */
    public array $referenceUrls = [];

    /**
     * @var array<string, true>
     */
    public array $ids = [];

    /**
     * C2-A-218: ids of top-level frontmatter, lookup keys and no cross-reference target.
     *
     * @var array<string, true>
     */
    public array $frontmatterIds = [];

    /**
     * @var array<array{0: array<string, mixed>, 1: string}>
     */
    public array $fragmentLinks = [];

    /**
     * @var array<string, string>
     */
    public array $footnoteLabels = [];

    /**
     * @var array<int, true>
     */
    public array $attachedLines = [];

    /**
     * @var array<string, true>
     */
    public array $usedReferences = [];

    /**
     * @var array<string, true>
     */
    public array $usedFootnotes = [];

    /**
     * @var array<array{0: array<string, mixed>, 1: string}>
     */
    public array $referenceDefinitions = [];

    /**
     * @var array<array{0: array<string, mixed>, 1: string}>
     */
    public array $unresolvedReferences = [];

    /**
     * @var array<array{0: array<string, mixed>, 1: string}>
     */
    public array $footnoteDefinitions = [];

    public Layout $layout;

    /**
     * @var array<string, bool> The answer of `active()` by rule id; the options do not change during a run.
     */
    private array $activeRules = [];

    /**
     * @var array<string, array<array{0: string, 1: \Closure}>> The active node rules by node type.
     */
    public array $nodeRules = [];

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<string, array{severity: string, default: string|null, status: string|null}> $catalog
     */
    public function __construct(public string $source, public array $options, private array $catalog)
    {
        $this->dataRules = DataRules::enabled();
        if ($this->dataRules) {
            $this->dataIds = DataRules::ids();
            $this->dataNodeTypes = DataRules::nodeTypes($this);
        }
        $start = 0;
        $number = 1;
        while (($feed = strpos($source, "\n", $start)) !== false) {
            $end = $feed > $start && $source[$feed - 1] === "\r" ? $feed - 1 : $feed;
            $this->lines[] = new Line(substr($source, $start, $end - $start), $start, $end, $number++);
            $this->lineStarts[] = $start;
            $start = $feed + 1;
        }
        $this->lines[] = new Line(substr($source, $start), $start, strlen($source), $number);
        $this->lineStarts[] = $start;
        $this->layout = new Layout($source);
    }

    /**
     * The 1-based line of a byte offset.
     *
     * @param int $offset
     *
     * @return int
     */

    /**
     * C2-H-075 keys with those a registered extension sets (carve-js#5060).
     *
     * @return array<array{0: string, 1: string|null}>
     */
    public function semanticKeys(): array
    {
        $keys = Options::SEMANTIC_KEYS;
        foreach (is_array($this->options['extensions'] ?? null) ? $this->options['extensions'] : [] as $item) {
            if ($item instanceof ExtensionDefinition && $item->semanticKeys !== null) {
                $keys = $item->semanticKeys;
            }
        }

        return $keys;
    }

    public function lineOf(int $offset): int
    {
        $low = 0;
        $high = count($this->lineStarts) - 1;
        while ($low < $high) {
            $mid = ($low + $high + 1) >> 1;
            if ($this->lineStarts[$mid] <= $offset) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low + 1;
    }

    /**
     * C2-W-109: whether a rule runs, by the `rules` and `platforms` options and the catalog default.
     *
     * @param string $id
     *
     * @return bool
     */
    public function active(string $id): bool
    {
        return $this->activeRules[$id] ??= !isset($this->dataIds[$id]) && $this->activeRule($id);
    }

    public function activeRule(string $id): bool
    {
        if (($this->catalog[$id]['status'] ?? null) === 'void') {
            return false;
        }
        $rules = is_array($this->options['rules'] ?? null) ? $this->options['rules'] : [];
        if (str_starts_with($id, 'platform-')) {
            $platforms = is_array($this->options['platforms'] ?? null) ? $this->options['platforms'] : [];

            return in_array('github', $platforms, true) && ($rules[$id] ?? null) !== 'off';
        }

        return ($rules[$id] ?? $this->catalog[$id]['default'] ?? null) === 'on';
    }

    public function off(string $id): bool
    {
        $rules = is_array($this->options['rules'] ?? null) ? $this->options['rules'] : [];

        return ($rules[$id] ?? null) === 'off';
    }

    /**
     * @param string $id
     * @param int $start
     * @param int $end
     * @param string $path
     * @param array<string, string|int> $values
     * @param int $index The message of the rule.
     *
     * @return void
     */
    public function report(string $id, int $start, int $end, string $path, array $values = [], int $index = 0): void
    {
        $length = strlen($this->source);
        $start = max(0, min($start, $length));
        $end = max($start, min($end, $length));
        $severity = $this->catalog[$id]['severity'] ?? 'warning';
        $this->diagnostics[] = new Diagnostic($id, $severity, 'lint', Message::fill($id, $values, $index), $path, $start, $end);
        $this->findingLines[] = $this->lineOf($start);
    }
}
