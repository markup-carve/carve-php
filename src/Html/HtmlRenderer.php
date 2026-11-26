<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use Generator;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Block\IncludeShape;
use MarkupCarve\Carve\Extension\Extensions;
use MarkupCarve\Carve\Extension\RenderHost;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Include\Expander;
use MarkupCarve\Carve\Json\TreeDecoder;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Facts;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Resolver;
use MarkupCarve\Carve\Resolve\Walk;
use MarkupCarve\Carve\Snippet\Expander as SnippetExpander;
use MarkupCarve\Carve\Writer\Writer;
use Throwable;
use UnexpectedValueException;
use WeakMap;

/**
 * Renders a resolved tree to an HTML fragment (C2-H). Block elements and their layout live here,
 * inline content in InlineRenderer, tables in TableRenderer, the footnote section in NoteRenderer.
 */
final class HtmlRenderer
{
    /**
     * The writer of each block type, called with the node and the depth.
     *
     * @var array<string, string>
     */
    private const BLOCKS = [
        'paragraph' => 'paragraphBlock',
        'heading' => 'headingBlock',
        'thematic_break' => 'thematicBreak',
        'code_block' => 'codeBlockAt',
        'raw_block' => 'rawBlock',
        'block_quote' => 'quote',
        'list' => 'list',
        'definition_list' => 'descriptionList',
        'div' => 'div',
        'admonition' => 'admonition',
        'directive' => 'directive',
        'line_block' => 'lineBlock',
        'figure_group' => 'figureGroup',
        'table' => 'tableBlock',
        'include' => 'includeBlock',
        'section' => 'sectionBlock',
        'block_extension' => 'blockExtension',
    ];

    /**
     * C2-H-211: the keys of the line options besides the class `line-numbers`.
     *
     * @var array<string>
     */
    private const LINE_OPTION_KEYS = ['data-line-start', 'data-highlight'];

    /**
     * Blocks that never render at their place (C2-H-019, C2-H-070, C2-H-071).
     *
     * @var array<string, true>
     */
    private const SILENT = [
        'comment' => true,
        'frontmatter' => true,
        'link_reference_definition' => true,
        'footnote' => true,
        'abbreviation_def' => true,
        'citation_definition' => true,
        'snippet_definition' => true,
    ];

    public RenderLog $log;

    public RenderState $state;

    public AttrWriter $aw;

    public InlineRenderer $inline;

    public ?Profile $profile = null;

    /**
     * C2-X-044, C2-X-048: one counter for the ids extension renderers take, whatever the prefix.
     */
    private int $extensionIds = 0;

    private TableRenderer $tables;

    /**
     * C2-H-019: the paragraphs a profile left writing nothing, once found by visibleOf().
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node, true>
     */
    private WeakMap $silenced;

    private NoteRenderer $notes;

    private bool $notesPlaced = false;

    /**
     * Line starts of the source, for `sourceLines` (C2-H-016).
     *
     * @var array<int>|null
     */
    private ?array $lineStarts = null;

    private bool $positionsFromTree = false;

    /**
     * @var array<string, array<int>>
     */
    private array $includeLineStarts = [];

    private function __construct(private Node $doc, public Options $o, ?string $source, ?Facts $facts)
    {
        $this->log = new RenderLog();
        foreach (SnippetExpander::losses($doc) as [$code, $node, $message]) {
            $this->log->loss($code, 'warning', [], $node->file, node: $node);
            $this->log->entries[array_key_last($this->log->entries)]['message'] = $message;
        }
        $this->silenced = new WeakMap();
        if ($o->profile !== 'full') {
            $this->profile = new Profile($o->profile, $this->log, $o);
            $this->profile->measure($doc);
        }
        $this->state = new RenderState($doc, $o, $this->profile, $facts);
        if ($this->profile !== null) {
            $this->profile->renderState = $this->state;
        }
        $this->aw = new AttrWriter($o, $this->log);
        $this->inline = new InlineRenderer($o, $this->state, $this->aw, $this->log, $this->host(...));
        $this->inline->profile = $this->profile;
        $this->tables = new TableRenderer($this);
        $this->notes = new NoteRenderer($this);
        if ($o->sourceLines) {
            $this->positionsFromTree = $source === null;
            $this->lineStarts = [0];
            if ($source !== null) {
                $pos = 0;
                while (($nl = strpos($source, "\n", $pos)) !== false) {
                    $this->lineStarts[] = $nl + 1;
                    $pos = $nl + 1;
                }
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node $doc A tree; it is resolved here unless `$resolved` is given.
     * @param \MarkupCarve\Carve\Options $opts
     * @param string|null $source The source, needed only for `sourceLines`.
     * @param \MarkupCarve\Carve\Resolve\Facts|null $resolved Resolver::resolve(..., keep: true)->facts of this
     *   tree, unchanged since, which spares the renderer a walk; without it the output is the same.
     *
     * @return string
     */
    public static function render(Node $doc, Options $opts, ?string $source = null, ?Facts $resolved = null): string
    {
        return self::renderWithLog($doc, $opts, $source, $resolved)[0];
    }

    /**
     * @param \MarkupCarve\Carve\Node $doc
     * @param \MarkupCarve\Carve\Options $opts
     * @param string|null $source
     * @param \MarkupCarve\Carve\Resolve\Facts|null $resolved
     * @param bool $shifted The caller applied `headingShift` before its document transforms (C2-H-208).
     * @param \MarkupCarve\Carve\Node|null $shiftAbove The first heading the shift itself raised above 6.
     * @param bool $shiftFactKnown Whether the caller captured the shift fact before transforms.
     *
     * @return array{0: string, 1: \MarkupCarve\Carve\Html\RenderLog} C2-H-130: no output under `strictLosses` when a loss occurred.
     */
    public static function renderWithLog(
        Node $doc,
        Options $opts,
        ?string $source = null,
        ?Facts $resolved = null,
        bool $shifted = false,
        ?Node $shiftAbove = null,
        bool $shiftFactKnown = false,
    ): array {
        if (!$shifted && $opts->headingShift !== 0) {
            $doc = Extensions::copy($doc);
            HeadingShift::apply($doc, $opts->headingShift, $opts->rendered);
            // Facts are keyed by the nodes of the original tree; the copy is resolved again.
            $resolved = null;
        }
        $max = Profile::maxLength($opts->profile);
        if ($source !== null && $max !== null && strlen($source) > $max) {
            $log = new RenderLog();
            $log->error = 'the input is longer than the ' . $max . ' bytes the profile ' . $opts->profile . ' accepts';

            return ['', $log];
        }
        Walk::$rendered = $opts->rendered;
        $gc = gc_enabled();
        // The tree holds no cycles; with the collector on, each walk fills its root buffer and starts full scans.
        gc_disable();
        $r = null;
        try {
            // C2-A-135: a tree resolved elsewhere is resolved again; no result field is read from it.
            if ($resolved === null) {
                SnippetExpander::expand($doc, $opts->roundtrip);
                $resolved = Resolver::resolve($doc, $source, $opts, true)->facts;
            }
            $r = new self($doc, $opts, $source, $resolved);
            $html = $r->document();
            $above = $shiftFactKnown ? $shiftAbove : ($opts->headingShift !== 0 ? HeadingShift::firstAboveSix($doc) : null);
            if ($above !== null) {
                $r->log->loss(HeadingShift::CODE, 'warning', ['shift' => $opts->headingShift], $above->file, node: $above);
                $r->log->entries[array_key_last($r->log->entries)]['message'] =
                    'The heading shift of ' . $opts->headingShift . ' raises a heading above level 6.';
            }
        } finally {
            if ($r !== null) {
                // The table and note writers and the inline writer's host callback point back at the renderer; left in
                // place, the cycle keeps the tree alive.
                unset($r->tables, $r->notes, $r->inline);
            }
            if ($gc) {
                gc_enable();
            }
        }
        if ($opts->allowLosses !== []) {
            // C2-H-204: an allowed loss is left out of the record and counts for neither strictLosses nor maxLosses.
            $r->log->entries = array_values(array_filter(
                $r->log->entries,
                static fn (array $entry): bool => !isset($opts->allowLosses[$entry['code']]),
            ));
        }
        if ($opts->strictLosses && $r->log->entries !== []) {
            $html = '';
        }

        return [$html, $r->log];
    }

    /**
     * C2-H-001, C2-H-026, C2-H-093.
     *
     * @return string
     */
    private function document(): string
    {
        if ($this->o->roundtrip) {
            $this->roundtripIncludes();
        }
        $out = [];
        $open = [];
        $abbreviations = [];
        $snippets = [];
        $sections = $this->o->sections;
        foreach ($this->documentBlocks($this->doc->children ?? []) as $child) {
            if ($this->snippetRun($child, $snippets, $abbreviations, $out, count($open))) {
                continue;
            }
            if ($child->type === 'abbreviation_def') {
                $this->collectAbbreviation($child, $abbreviations, $out, count($open));

                continue;
            }
            if ($abbreviations !== []) {
                $this->appendAbbreviations($out, $abbreviations, count($open));
                $abbreviations = [];
            }
            if ($sections && $child->type === 'heading' && ($this->profile === null || $this->profile->allowsBlock($child, 0))) {
                $this->openHeadingSection($child, $open, $out);

                continue;
            }
            $depth = count($open);
            if ($child === $this->state->placingDirective && $child->type === 'directive') {
                $this->placeNotes($child, $depth, $out);

                continue;
            }
            $html = $this->block($child, $depth);
            if ($html !== null) {
                $out[] = $html;
            }
        }
        if ($abbreviations !== []) {
            $this->appendAbbreviations($out, $abbreviations, count($open));
        }
        if ($snippets !== []) {
            $out[] = $this->snippetTemplate($snippets, count($open));
        }
        while ($open !== []) {
            array_pop($open);
            $out[] = self::pad(count($open)) . '</section>';
        }
        if (!$this->notesPlaced) {
            $section = $this->notes->section(0, null);
            if ($section !== null) {
                $out[] = $section;
            }
        }

        return implode("\n", $out);
    }

    /**
     * @param \MarkupCarve\Carve\Node $child
     * @param array<\MarkupCarve\Carve\Node> $abbreviations
     * @param array<string> $out
     * @param int $depth
     */
    private function collectAbbreviation(Node $child, array &$abbreviations, array &$out, int $depth): void
    {
        if ($child->file !== null) {
            if ($abbreviations !== []) {
                $this->appendAbbreviations($out, $abbreviations, $depth);
                $abbreviations = [];
            }

            return;
        }
        $abbreviations[] = $child;
    }

    /**
     * @param \MarkupCarve\Carve\Node $child
     * @param int $depth
     * @param array<string> $out
     */
    private function placeNotes(Node $child, int $depth, array &$out): void
    {
        foreach ($this->blocks($child->children ?? [], $depth) as $b) {
            $out[] = $b;
        }
        $section = $this->notes->section($depth, $child);
        if ($section !== null) {
            $out[] = $section;
        }
        $this->notesPlaced = true;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return \Generator<\MarkupCarve\Carve\Node>
     */
    private function documentBlocks(array $nodes): Generator
    {
        foreach ($nodes as $node) {
            if (
                $node->type === 'include' && $node->children !== null
                && ($this->profile === null || $this->profile->allowsBlockType('include'))
            ) {
                yield from $this->documentBlocks($node->children);
            } else {
                yield $node;
            }
        }
    }

    private function roundtripIncludes(): void
    {
        $stack = $this->doc->children ?? [];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->type === 'include' && $node->children !== null) {
                if ($this->profile === null || $this->profile->allowsBlockType('include')) {
                    $this->log->loss(
                        'include-expanded-roundtrip',
                        'warning',
                        ['path' => self::includePathOf($node)],
                        $node->file,
                        node: $node,
                    );
                }

                continue;
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node $child
     * @param array<int> $open
     * @param array<string> $out
     *
     * @return void
     */
    private function openHeadingSection(Node $child, array &$open, array &$out): void
    {
        $level = is_int($child->f['level'] ?? null) ? $child->f['level'] : 1;
        while ($open !== [] && end($open) >= $level) {
            array_pop($open);
            $out[] = self::pad(count($open)) . '</section>';
        }
        $out[] = $this->sectionOpen($child, count($open));
        $open[] = $level;
        $out[] = $this->heading($child, count($open), true);
    }

    /**
     * C2-H-216: under `roundtrip` a run of snippet definitions is one template; every other node ends the run.
     * Returns true for a definition the run took.
     *
     * @param \MarkupCarve\Carve\Node $child
     * @param array<\MarkupCarve\Carve\Node> $snippets
     * @param array<\MarkupCarve\Carve\Node> $abbreviations
     * @param array<string> $out
     * @param int $depth
     *
     * @return bool
     */
    private function snippetRun(Node $child, array &$snippets, array &$abbreviations, array &$out, int $depth): bool
    {
        if (!$this->o->roundtrip) {
            return false;
        }
        if ($child->type === 'snippet_definition') {
            if ($abbreviations !== []) {
                $this->appendAbbreviations($out, $abbreviations, $depth);
                $abbreviations = [];
            }
            $snippets[] = $child;

            return true;
        }
        if ($snippets !== []) {
            $out[] = $this->snippetTemplate($snippets, $depth);
            $snippets = [];
        }

        return false;
    }

    /**
     * C2-H-216: the template of a run of snippet definitions, the run as the canonical writer spells it. A run with a
     * definition of an included file carries the scope.
     *
     * @param array<\MarkupCarve\Carve\Node> $run
     * @param int $depth
     *
     * @return string
     */
    private function snippetTemplate(array $run, int $depth): string
    {
        $children = [];
        $scope = '';
        foreach ($run as $node) {
            $copy = Extensions::copy($node);
            $copy->children = self::plainAbbreviations($copy->children ?? []);
            $children[] = $copy;
            if ($node->file !== null) {
                $scope = ' data-carve-snippet-scope="include"';
            }
        }
        $doc = new Node('document', $children);
        $doc->f['astVersion'] = '2.0';
        $written = Writer::writeWithDiagnostics((new TreeEncoder())->encode($doc, null));
        foreach ($written->diagnostics as $entry) {
            $this->log->loss($entry->code, 'warning', [], $run[0]->file, node: $run[0]);
            $this->log->entries[array_key_last($this->log->entries)]['message'] = $entry->message;
        }
        $source = str_ends_with($written->source, "\n") ? substr($written->source, 0, -1) : $written->source;
        $presence = $this->o->xhtml ? '=""' : '';

        return self::pad($depth) . '<template data-carve-snippets' . $presence . $scope . '>' . Escape::carrierText($source)
            . '</template>';
    }

    /**
     * C2-I-098: an abbreviation in a definition is its term as text in the template, which holds no abbreviation
     * definition; the reader applies the document's abbreviations again.
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function plainAbbreviations(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node->type === 'abbreviation') {
                $key = PlainText::str($node->f['key'] ?? '');
                $node = Node::at('text', -1, -1);
                $node->f['value'] = $key;
            } else {
                SnippetExpander::eachList($node, static function (array &$list): void {
                    $list = self::plainAbbreviations($list);
                });
            }
            $last = $out === [] ? null : $out[count($out) - 1];
            if (
                $last !== null && $last->type === 'text' && $node->type === 'text' && array_keys($last->f) === ['value']
                && array_keys($node->f) === ['value']
            ) {
                $last->f['value'] = PlainText::str($last->f['value']) . PlainText::str($node->f['value']);

                continue;
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * C2-H-178: a run ends at every other top-level node.
     *
     * @param array<string> $out
     * @param array<\MarkupCarve\Carve\Node> $run
     * @param int $depth
     *
     * @return void
     */
    private function appendAbbreviations(array &$out, array $run, int $depth): void
    {
        if ($this->o->roundtrip) {
            $out[] = $this->abbreviationTemplate($run, $depth);
        }
    }

    /**
     * C2-H-178 to C2-H-181: one carrier at the place of each maximal definition run.
     *
     * @param array<\MarkupCarve\Carve\Node> $run
     * @param int $depth
     *
     * @return string
     */
    private function abbreviationTemplate(array $run, int $depth): string
    {
        $source = AbbreviationSource::run($run, $this->log);

        $presence = $this->o->xhtml ? '=""' : '';

        return self::pad($depth) . '<template data-carve-abbreviations' . $presence . '>'
            . Escape::carrierText($source) . '</template>';
    }

    /**
     * C2-H-027: the id of the heading goes on the section.
     *
     * @param \MarkupCarve\Carve\Node $heading
     * @param int $depth
     *
     * @return string
     */
    private function sectionOpen(Node $heading, int $depth): string
    {
        $structural = $this->idCarriers($heading);
        $id = $this->headingId($heading);

        // An authored empty id is written as `id=""` (C2-A-032); build() reads an explicit '' as no id.
        $attr = $this->aw->build($structural, $id === '' ? null : $id ?? '', null) . ($id === '' ? ' id=""' : '');

        return self::pad($depth) . '<section' . $attr . '>';
    }

    /**
     * @param \MarkupCarve\Carve\Node $heading
     *
     * @return array<array{0: string, 1: string|null}>
     */
    private function idCarriers(Node $heading): array
    {
        if (!$this->o->roundtrip) {
            return [];
        }
        $attrs = $heading->f['attrs'] ?? null;
        if ($attrs instanceof Attrs && $attrs->id !== null) {
            return [['data-carve-explicit-id', null]];
        }

        return is_string($heading->f['generatedId'] ?? null) ? [['data-carve-generated-id', null]] : [];
    }

    /**
     * @param \MarkupCarve\Carve\Node $heading
     *
     * @return string|null
     */
    private function headingId(Node $heading): ?string
    {
        return Resolver::idOf($heading);
    }

    /**
     * The id argument of a heading's own element: an authored empty id is left to the attrs, which write it (C2-A-032).
     *
     * @param \MarkupCarve\Carve\Node $heading
     *
     * @return string|null
     */
    private function headingElementId(Node $heading): ?string
    {
        $id = $this->headingId($heading);

        return $id === '' ? null : $id ?? '';
    }

    /**
     * @param int $depth
     *
     * @return string
     */
    public static function pad(int $depth): string
    {
        static $pads = [];

        return $pads[$depth] ??= str_repeat('  ', $depth);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return int|null
     */
    public function line(Node $node): ?int
    {
        if ($this->lineStarts === null) {
            return null;
        }
        if ($this->positionsFromTree) {
            $position = TreeDecoder::position($node);

            return is_array($position) && is_int($position['startLine'] ?? null) ? $position['startLine'] : null;
        }
        if ($node->start < 0) {
            return null;
        }
        $starts = $this->lineStarts;
        if ($node->file !== null && isset($this->doc->includeSources[$node->file])) {
            $starts = $this->includeLineStarts[$node->file] ??= self::starts($this->doc->includeSources[$node->file]);
        }
        $lo = 0;
        $hi = count($starts) - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            if ($starts[$mid] <= $node->start) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo + 1;
    }

    /**
     * @return array<int>
     */
    private static function starts(string $source): array
    {
        $starts = [0];
        $pos = 0;
        while (($nl = strpos($source, "\n", $pos)) !== false) {
            $starts[] = $nl + 1;
            $pos = $nl + 1;
        }

        return $starts;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     * @param int $depth
     *
     * @return array<string>
     */
    public function blocks(array $nodes, int $depth): array
    {
        $out = [];
        foreach ($this->documentBlocks($nodes) as $node) {
            $html = $this->block($node, $depth);
            if ($html !== null) {
                $out[] = $html;
            }
        }

        return $out;
    }

    /**
     * Whether a block writes anything at its place.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    public static function visible(Node $node): bool
    {
        if (isset(self::SILENT[$node->type])) {
            return false;
        }

        return $node->type !== 'paragraph' || !self::onlyComments($node->children ?? []);
    }

    /**
     * C2-H-019: only comments, once the breaks that C2-H-069 does not write are left out.
     *
     * @param array<\MarkupCarve\Carve\Node> $kids
     *
     * @return bool
     */
    private static function onlyComments(array $kids): bool
    {
        $hard = false;
        foreach ($kids as $kid) {
            if ($kid->type === 'hard_break') {
                $hard = true;
            } elseif ($kid->type !== 'comment' && $kid->type !== 'soft_break') {
                return false;
            }
        }
        if (!$hard) {
            return true;
        }
        foreach (InlineRenderer::dropCommentLineBreaks($kids) as $kid) {
            if ($kid->type !== 'comment') {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-H-019: an uncaptioned paragraph whose content writes nothing once the profile has degraded it. A break
     * counts as written unless a comment line takes it (C2-H-069). It is rendered once here so that what it
     * reports is reported, and skipped after that.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    private function silent(Node $node): bool
    {
        if (isset($this->silenced[$node])) {
            return true;
        }
        if ($this->profile === null || $node->type !== 'paragraph' || isset($node->f['caption'])) {
            return false;
        }
        $kids = $node->children ?? [];
        $empty = false;
        foreach (InlineRenderer::dropCommentLineBreaks($kids, false, $this->profile) as $kid) {
            if ($this->inline->writesNothing($kid)) {
                $empty = true;
            } elseif ($kid->type !== 'comment') {
                return false;
            }
        }
        if (!$empty) {
            return false;
        }
        $this->block($node, 0);
        $this->silenced[$node] = true;

        return true;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public function visibleOf(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (self::visible($node) && !$this->silent($node)) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string|null Null when the block renders nothing.
     */
    public function block(Node $node, int $depth): ?string
    {
        $previous = $this->log->currentNode;
        $this->log->currentNode = $node;
        try {
            return $this->blockContent($node, $depth);
        } finally {
            $this->log->currentNode = $previous;
        }
    }

    private function blockContent(Node $node, int $depth): ?string
    {
        if (isset($this->silenced[$node])) {
            return null;
        }
        if ($this->profile !== null && !$this->profile->allowsBlock($node, $depth)) {
            // C2-N-077: a degraded include is reported once, as degraded, and not as unresolved.
            return $this->profile->degradeBlock($node, $depth);
        }
        if (isset(self::SILENT[$node->type])) {
            return null;
        }
        if (self::hasFigureCaption($node)) {
            return $this->captionedBlock($node, $depth, false);
        }
        // An invisible paragraph is caught by paragraph(); the walk of visible() runs once.
        $method = self::BLOCKS[$node->type] ?? 'genericBlock';

        return $this->$method($node, $depth);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string|null
     */
    private function paragraphBlock(Node $node, int $depth): ?string
    {
        $html = $this->paragraph($node, $depth, false);

        return $this->state->longDescriptions ? $this->withLongDescriptions($html, $node, $depth) : $html;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function headingBlock(Node $node, int $depth): string
    {
        return $this->heading($node, $depth, false);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function thematicBreak(Node $node, int $depth): string
    {
        return self::pad($depth) . '<hr' . $this->attrs($node) . ($this->o->xhtml ? ' />' : '>');
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return string
     */
    private function codeBlockAt(Node $node, int $depth): string
    {
        $html = $this->codeBlock($node);
        if ($this->o->codeBlockRenderer !== null) {
            // C2-H-206, C2-H-207: trusted host HTML in place of the default, or null to keep it.
            $host = ($this->o->codeBlockRenderer)($node, $html);
            if ($host !== null && !is_string($host)) {
                throw new OptionError('option codeBlockRenderer: returned a value that is neither text nor nothing');
            }
            $html = $host ?? $html;
        }

        return self::pad($depth) . $html;
    }

    /**
     * C2-H-133: an unexpanded include without a caption is laid out as a paragraph at its place.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    public static function paragraphLike(Node $node): bool
    {
        return $node->type === 'paragraph'
            || ($node->type === 'include' && $node->children === null && !(($node->f['caption'] ?? null) instanceof Node));
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     * @param bool $bare
     *
     * @return string
     */
    private function unexpandedInclude(Node $node, int $depth, bool $bare): string
    {
        $this->log->loss('include-unresolved', 'warning', ['path' => self::includePathOf($node)], $node->file);
        $text = Escape::text(self::includeText($node));
        if ($bare) {
            return self::pad($depth) . $text;
        }
        // C2-H-016: the paragraph of an unexpanded include is the element of a block node.
        $line = $this->line($node);

        return self::pad($depth) . ($line === null ? '<p>' : '<p data-source-line="' . $line . '">') . $text . '</p>';
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    private static function hasFigureCaption(Node $node): bool
    {
        return in_array($node->type, ['paragraph', 'code_block', 'block_quote'], true)
            && ($node->f['caption'] ?? null) instanceof Node;
    }

    /**
     * C2-H-062, C2-H-063: the figure is an HTML element, not a tree node.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     * @param bool $panel
     *
     * @return string
     */
    private function captionedBlock(Node $node, int $depth, bool $panel): string
    {
        $inner = clone $node;
        unset($inner->f['caption'], $inner->f['attrs'], $inner->f['lang'], $inner->f['dir']);
        if ($inner->ownStart >= 0) {
            $inner->start = $inner->ownStart;
        }
        $figure = $node;
        $attrs = $node->f['attrs'] ?? null;
        if ($node->type === 'code_block' && $attrs instanceof Attrs) {
            [$outer, $line] = self::splitLineOptions($attrs);
            if ($line !== null) {
                $inner->f['attrs'] = $line;
                $figure = clone $node;
                $figure->f['attrs'] = $outer;
            }
        }
        $children = $this->blocks([$inner], $depth + 1);
        $caption = $node->f['caption'];
        assert($caption instanceof Node);
        $children[] = $this->caption('figcaption', $caption, $depth + 1);

        return $this->container(
            '<figure' . $this->attrs($figure, $panel ? ['carve-figure-panel'] : []) . '>',
            '</figure>',
            $children,
            $depth,
        );
    }

    /**
     * C2-H-063, C2-H-211: the line options of a captioned code block stay on its `<pre>`, the rest go on the figure.
     *
     * @param \MarkupCarve\Carve\Attrs $attrs
     *
     * @return array{0: \MarkupCarve\Carve\Attrs, 1: \MarkupCarve\Carve\Attrs|null}
     */
    private static function splitLineOptions(Attrs $attrs): array
    {
        $line = null;
        $outer = null;
        foreach ($attrs->classes as $i => $class) {
            if ($class === 'line-numbers') {
                $line ??= new Attrs();
                $outer ??= clone $attrs;
                $line->classes[] = $class;
                unset($outer->classes[$i]);
            }
        }
        foreach ($attrs->pairs as $i => $pair) {
            if (in_array(strtolower($pair[0]), self::LINE_OPTION_KEYS, true)) {
                $line ??= new Attrs();
                $outer ??= clone $attrs;
                $line->pairs[] = $pair;
                unset($outer->pairs[$i]);
            }
        }
        if ($line === null || $outer === null) {
            return [$attrs, null];
        }
        $outer->classes = array_values($outer->classes);
        $outer->pairs = array_values($outer->pairs);

        return [$outer, $line];
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function tableBlock(Node $node, int $depth): string
    {
        return $this->tables->table($node, $depth);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function includeBlock(Node $node, int $depth): string
    {
        if ($node->children !== null) {
            return implode("\n", $this->blocks($node->children, $depth));
        }
        $html = $this->unexpandedInclude($node, $depth, false);
        $caption = $node->f['caption'] ?? null;
        // C2-N-074: the caption is written as C2-H-165 writes the caption of a degraded block.
        foreach ($caption instanceof Node ? $caption->children ?? [] : [] as $block) {
            $html .= "\n" . self::pad($depth) . '<p>' . Escape::text(PlainText::of($block->children ?? [])) . '</p>';
        }

        return $html;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function sectionBlock(Node $node, int $depth): string
    {
        return $this->container(
            '<section' . $this->attrs($node) . '>',
            '</section>',
            $this->blocks($node->children ?? [], $depth + 1),
            $depth,
        );
    }

    /**
     * An unknown block type is written as a div of its blocks.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function genericBlock(Node $node, int $depth): string
    {
        return $this->container('<div' . $this->attrs($node) . '>', '</div>', $this->blocks($node->children ?? [], $depth + 1), $depth);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string> $base
     * @param array<string> $tail
     * @param array<array{0: string, 1: string|null}> $structural
     * @param array<array{0: string, 1: string|null}> $generated
     * @param array{decl?: string, first?: bool, align?: bool} $style
     * @param bool $block The element is a block element and takes `data-source-line` (C2-H-016).
     *
     * @return string
     */
    public function attrs(
        Node $node,
        array $base = [],
        array $tail = [],
        array $structural = [],
        array $generated = [],
        array $style = [],
        bool $block = true,
    ): string {
        $attrs = $node->f['attrs'] ?? null;
        $lang = $node->f['lang'] ?? null;
        if (
            $attrs === null && $lang === null && !isset($node->f['dir']) && $structural === [] && $base === [] && $tail === []
            && $generated === []
            && ($this->lineStarts === null || !$block) && !isset($style['decl'])
        ) {
            return '';
        }

        return $this->aw->build(
            $structural,
            null,
            $attrs instanceof Attrs ? $attrs : null,
            is_string($lang) ? $lang : null,
            $base,
            $tail,
            $generated,
            $style,
            $block ? $this->line($node) : null,
            AttrWriter::dirOf($node),
        );
    }

    /**
     * C2-H-005, C2-H-008: child blocks one level in, an empty body as one empty line.
     *
     * @param string $open
     * @param string $close
     * @param array<string> $children
     * @param int $depth
     *
     * @return string
     */
    public function container(string $open, string $close, array $children, int $depth): string
    {
        $pad = self::pad($depth);
        if ($children === []) {
            return $pad . $open . "\n\n" . $pad . $close;
        }

        return $pad . $open . "\n" . implode("\n", $children) . "\n" . $pad . $close;
    }

    /**
     * The image a block image paragraph writes in place of `<p>`, which stands on its own line in every container.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return \MarkupCarve\Carve\Node|null
     */
    private function blockImage(Node $node): ?Node
    {
        if (
            $node->type !== 'paragraph' || ($node->f['role'] ?? null) !== 'image'
            || ($this->profile !== null && !$this->profile->allowsType('image'))
        ) {
            return null;
        }
        foreach ($node->children ?? [] as $kid) {
            if ($kid->type === 'image' && !(isset($kid->f['key']) && !isset($kid->f['src']))) {
                return $kid;
            }
        }

        return null;
    }

    /**
     * C2-H-007, C2-H-020: a resolved block image writes its `<img>` with no `<p>`.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    private function bareImage(Node $node): bool
    {
        return $this->blockImage($node) !== null;
    }

    /**
     * C2-H-018, C2-M-092: a paragraph a tightness rule writes bare keeps its `<p>` where the bare form would not import as
     * that one paragraph: an image-only run splits into image paragraphs (C2-M-013), and a bare block image
     * after it joins its run (C2-M-012).
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Node|null $next The next visible block of the same item.
     *
     * @return bool
     */
    private function bareReadsBack(Node $node, ?Node $next): bool
    {
        if ($next !== null && $this->bareImage($next)) {
            return false;
        }
        $kids = $node->children ?? [];
        $image = false;
        foreach ($kids as $kid) {
            if ($kid->type === 'image') {
                $image = true;
            } elseif (
                $kid->type !== 'soft_break' && $kid->type !== 'comment'
                && !($kid->type === 'text' && trim(PlainText::str($kid->f['value'] ?? ''), " \t\n") === '')
            ) {
                return true;
            }
        }
        if (!$image) {
            return true;
        }

        return preg_match('/^(?:\s|<img\b[^>]*>|<!--.*?-->)*$/s', $this->inline->inlines($kids, 1)) !== 1;
    }

    /**
     * C2-H-091: whether a block is written as `<p>`, so backlinks can be appended to it.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    public function writesParagraph(Node $node): bool
    {
        if ($node->type !== 'paragraph' || ($node->f['caption'] ?? null) instanceof Node) {
            return false;
        }

        return !$this->bareImage($node);
    }

    /**
     * C2-H-018 to C2-H-020, C2-H-077.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     * @param bool $bare
     * @param string $append HTML written before the closing tag (footnote backlinks).
     *
     * @return string|null
     */
    public function paragraph(Node $node, int $depth, bool $bare, string $append = ''): ?string
    {
        if ($node->type === 'include') {
            return $this->unexpandedInclude($node, $depth, $bare);
        }
        $f = $node->f;
        $carrier = $this->o->roundtrip ? SnippetExpander::carrierText($node) : '';
        if (
            $append === '' && $this->lineStarts === null && !isset($f['attrs']) && !isset($f['lang']) && !isset($f['dir'])
            && !isset($f['role']) && $carrier === ''
        ) {
            // No attributes, no source line and no role: neither a block image nor static math.
            $kids = $node->children ?? [];
            if (self::onlyComments($kids)) {
                return null;
            }
            $content = $this->inline->inlines($kids, $depth + 1);
            if ($content === '' && $kids !== [] && $this->profile !== null) {
                return null;
            }

            return $bare ? self::pad($depth) . $content : self::pad($depth) . '<p>' . $content . '</p>';
        }
        if (!self::visible($node)) {
            return $append === '' ? null : self::pad($depth) . '<p>' . $append . '</p>';
        }
        $kids = $node->children ?? [];
        $image = $append === '' ? $this->blockImage($node) : null;
        if ($image !== null) {
            return $this->imageParagraph($image, $node, $depth);
        }
        $hasAttrs = isset($node->f['attrs']) || isset($node->f['lang']) || isset($node->f['dir']);
        $content = $this->inline->leaf($kids, $depth + 1) . $append;
        if ($content === '' && $kids !== [] && $this->profile !== null) {
            return null;
        }
        if ($bare && !$hasAttrs && $carrier === '') {
            return self::pad($depth) . $content;
        }

        return self::pad($depth) . '<p' . $this->attrs($node, [], [], [], [], ['align' => true]) . $carrier . '>' . $content . '</p>';
    }

    private function imageParagraph(Node $image, Node $node, int $depth): string
    {
        $attrs = $node->f['attrs'] ?? null;
        $own = $image->f['attrs'] ?? null;
        if ($attrs instanceof Attrs) {
            $attrs = clone $attrs;
            if ($own instanceof Attrs) {
                $attrs->merge($own);
            }
        } else {
            $attrs = $own;
        }
        $lang = $image->f['lang'] ?? $node->f['lang'] ?? null;
        $fields = $image->f;
        unset($image->f['attrs'], $image->f['lang']);
        if ($attrs instanceof Attrs) {
            $image->f['attrs'] = $attrs;
        }
        if (is_string($lang)) {
            $image->f['lang'] = $lang;
        }
        if (!isset($fields['dir']) && isset($node->f['dir'])) {
            $image->f['dir'] = $node->f['dir'];
        }
        // The breaks and comments beside the image are written too (C2-H-069). The image itself stays the tree's node,
        // so a loss on it keeps its path.
        try {
            return self::pad($depth) . $this->inline->inlines($node->children ?? [], $depth + 1);
        } finally {
            $image->f = $fields;
        }
    }

    /**
     * C2-H-080: the `longDescription` blocks of the images in a block follow the block.
     *
     * @param string|null $html
     * @param \MarkupCarve\Carve\Node $block
     * @param int $depth
     *
     * @return string|null
     */
    private function withLongDescriptions(?string $html, Node $block, int $depth): ?string
    {
        if (!$this->state->longDescriptions) {
            return $html;
        }
        $extra = [];
        $stack = array_reverse($block->children ?? []);
        while ($stack !== []) {
            $node = array_pop($stack);
            $long = $node->f['longDescription'] ?? null;
            if ($node->type === 'image' && is_array($long)) {
                $extra = array_merge($extra, $this->blocks(PlainText::nodes($long), $depth));
            }
            foreach (array_reverse($node->children ?? []) as $kid) {
                $stack[] = $kid;
            }
        }
        if ($extra === []) {
            return $html;
        }

        return ($html === null ? '' : $html . "\n") . implode("\n", $extra);
    }

    /**
     * C2-H-021, C2-H-022, C2-H-027, C2-H-028.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     * @param bool $inSection
     *
     * @return string
     */
    private function heading(Node $node, int $depth, bool $inSection): string
    {
        $f = $node->f;
        $level = is_int($f['level'] ?? null) ? max(1, $f['level']) : 1;
        $sourceLevel = $this->sourceLevel($level);
        if (
            $level <= 6 && $this->lineStarts === null && !isset($f['attrs']) && !isset($f['lang']) && !isset($f['dir'])
            && ($inSection || !$this->o->roundtrip) && $sourceLevel === null
        ) {
            return $this->plainHeading($node, $level, $depth, $inSection);
        }
        $attrs = $node->f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? $attrs : null;
        $lang = is_string($node->f['lang'] ?? null) ? $node->f['lang'] : null;
        $id = $inSection ? '' : $this->headingElementId($node);
        $structural = $inSection ? [] : $this->idCarriers($node);
        $tag = 'h' . $level;
        $element = $level > 6 ? [['role', 'heading'], ['aria-level', (string)$level]] : [];
        if ($level > 6) {
            $tag = 'div';
        }
        // C2-H-208: the source level follows the id carrier and stands before the role.
        $structural = $sourceLevel === null
            ? array_merge($element, $structural)
            : array_merge($structural, [['data-carve-source-level', (string)$sourceLevel]], $element);
        $line = $this->line($node);
        $dir = AttrWriter::dirOf($node);
        $attr = $this->aw->build($structural, $id, $attrs, $lang, style: ['align' => true], sourceLine: $line, dir: $dir);

        return self::pad($depth) . '<' . $tag . $attr . '>' . $this->inline->leaf($node->children ?? [], $depth + 1) . '</' . $tag . '>';
    }

    /**
     * C2-H-208: under `roundtrip` a heading carries its rendered element level minus N.
     */
    private function sourceLevel(int $level): ?int
    {
        $source = $level - $this->o->headingShift;

        return $this->o->roundtrip && $this->o->headingShift !== 0 ? $source : null;
    }

    /**
     * What AttrWriter::build writes for a heading with no attributes and no carriers: at most the id.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $level
     * @param int $depth
     * @param bool $inSection
     *
     * @return string
     */
    private function plainHeading(Node $node, int $level, int $depth, bool $inSection): string
    {
        $id = $inSection ? null : $node->f['generatedId'] ?? null;
        $open = is_string($id) && $id !== '' ? '<h' . $level . ' id="' . Escape::attr($id) . '">' : '<h' . $level . '>';

        return self::pad($depth) . $open . $this->inline->inlines($node->children ?? [], $depth + 1) . '</h' . $level . '>';
    }

    /**
     * C2-H-047, C2-H-048.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    private function codeBlock(Node $node): string
    {
        $structural = [];
        $attrs = $node->f['attrs'] ?? null;
        $title = $node->f['title'] ?? null;
        if (is_array($title) && !($attrs instanceof Attrs && $attrs->get('title') !== null)) {
            $structural[] = ['title', PlainText::of(PlainText::nodes($title))];
        }
        if ($this->o->roundtrip && is_string($node->f['groupLabel'] ?? null)) {
            $structural[] = ['data-carve-label', $node->f['groupLabel']];
        }
        $syntax = $node->f['syntax'] ?? null;
        $class = is_string($syntax) && $syntax !== '' ? ' class="' . Escape::attr('language-' . $syntax) . '"' : '';

        return '<pre' . $this->attrs($node, [], [], $structural) . '><code' . $class . '>'
            . Escape::text(PlainText::str($node->f['value'] ?? '')) . '</code></pre>';
    }

    /**
     * C2-H-050, C2-H-053, C2-H-054, C2-H-134.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string|null
     */
    private function rawBlock(Node $node, int $depth): ?string
    {
        $format = PlainText::str($node->f['format'] ?? '');
        $value = PlainText::str($node->f['value'] ?? '');
        if ($format !== 'html') {
            $this->log->loss('raw-format-dropped', 'warning', ['kind' => 'block', 'format' => $format, 'target' => 'HTML']);

            return null;
        }
        if ($this->o->rawHtml) {
            return str_ends_with($value, "\n") ? substr($value, 0, -1) : $value;
        }
        $this->log->escapedRaw = true;

        return self::pad($depth) . '<pre' . $this->attrs($node) . '><code class="language-html">' . Escape::text($value) . '</code></pre>';
    }

    /**
     * C2-H-006, C2-H-041.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function quote(Node $node, int $depth): string
    {
        $open = '<blockquote' . $this->attrs($node) . '>';
        $visible = $this->visibleOf(iterator_to_array($this->documentBlocks($node->children ?? []), false));
        if (
            count($visible) === 1 && self::paragraphLike($visible[0]) && !self::hasFigureCaption($visible[0])
            && $this->allowed($visible[0], $depth + 1)
            && !$this->bareImage($visible[0])
        ) {
            $p = $this->paragraph($visible[0], 0, false);
            if ($p !== null) {
                return self::pad($depth) . $open . $p . '</blockquote>';
            }
        }
        $texts = count($visible) === 1 ? $this->degradedLead($visible, $depth + 1) : null;
        if ($texts !== null && count($texts[0]) === 1) {
            return self::pad($depth) . $open . '<p>' . $texts[0][0] . '</p></blockquote>';
        }
        if ($texts !== null) {
            return $this->container($open, '</blockquote>', self::paragraphLines($texts[0], $depth + 1), $depth);
        }

        return $this->container($open, '</blockquote>', $this->blocks($node->children ?? [], $depth + 1), $depth);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return bool
     */
    private function allowed(Node $node, int $depth): bool
    {
        return $this->profile === null || $this->profile->allowsBlock($node, $depth);
    }

    /**
     * C2-H-112: the roundtrip carriers of a list.
     *
     * @param array<mixed> $marker
     * @param bool $ordered
     * @param bool $tight
     *
     * @return array<array{0: string, 1: string|null}>
     */
    private static function listCarriers(array $marker, bool $ordered, bool $tight): array
    {
        $out = [];
        $char = $marker['char'] ?? '-';
        if (!$ordered && is_string($char) && $char !== '-') {
            $out[] = ['data-marker', $char];
        }
        if ($tight) {
            $out[] = ['data-carve-tight', null];
        }

        return $out;
    }

    /**
     * C2-H-030, C2-H-031: `type`, `start` and `data-delim` of an ordered list.
     *
     * @param array<mixed> $marker
     *
     * @return array<array{0: string, 1: string|null}>
     */
    private static function orderedAttributes(array $marker): array
    {
        $structural = [];
        $type = Tables::ORDERED_HTML_TYPE[PlainText::str($marker['style'] ?? '1')] ?? null;
        if ($type !== null) {
            $structural[] = ['type', $type];
        }
        $start = $marker['start'] ?? 1;
        if (is_int($start) && $start !== 1) {
            $structural[] = ['start', (string)$start];
        }
        $delim = Tables::ORDERED_DATA_DELIM[PlainText::str($marker['delimiter'] ?? '.')] ?? null;
        if ($delim !== null) {
            $structural[] = ['data-delim', $delim];
        }

        return $structural;
    }

    /**
     * C2-H-029 to C2-H-037.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function list(Node $node, int $depth): string
    {
        $marker = is_array($node->f['marker'] ?? null) ? $node->f['marker'] : [];
        $ordered = ($marker['kind'] ?? 'bullet') === 'ordered';
        $tight = ($node->f['spacing'] ?? 'tight') !== 'loose';
        $structural = $ordered ? self::orderedAttributes($marker) : [];
        if ($this->o->roundtrip) {
            $structural = array_merge($structural, self::listCarriers($marker, $ordered, $tight));
        }
        $task = false;
        $items = [];
        foreach ($node->children ?? [] as $item) {
            $task = $task || is_array($item->f['task'] ?? null);
            if ($item->type === 'list_item') {
                $items[] = $this->profile === null || $this->profile->allowsBlock($item, $depth + 1)
                    ? $this->item($item, $depth + 1, $tight, $ordered)
                    : (string)$this->block($item, $depth + 1);
            }
        }
        $tag = $ordered ? 'ol' : 'ul';
        $open = '<' . $tag . $this->attrs($node, $task ? ['task-list'] : [], [], $structural) . '>';
        if ($items === []) {
            // C2-H-008 keeps an empty line only in the containers it names; a list with no item is two lines.
            return self::pad($depth) . $open . "\n" . self::pad($depth) . '</' . $tag . '>';
        }

        return $this->container($open, '</' . $tag . '>', $items, $depth);
    }

    /**
     * C2-H-032, C2-H-033, C2-H-174: the checkbox, state, class and style of a task item.
     *
     * @param \MarkupCarve\Carve\Node $item
     * @param array<mixed> $task
     * @param bool $ordered
     *
     * @return array{0: string, 1: array<array{0: string, 1: string|null}>, 2: array<string>, 3: array{decl?: string, first?: bool}}
     */
    private function taskParts(Node $item, array $task, bool $ordered): array
    {
        $mark = PlainText::str($task['mark'] ?? ' ');
        $state = Tables::TASK_STATE_ATTRIBUTE[$mark] ?? null;
        $structural = $state !== null ? [['data-task-state', $state]] : [];
        $style = $ordered ? [] : ['decl' => 'list-style: none', 'first' => false];
        $label = [];
        foreach ($item->children ?? [] as $child) {
            if ($child->type === 'paragraph' || $child->type === 'heading') {
                $label[] = PlainText::of($child->children ?? []);
            }
        }
        $text = implode(' ', $label);
        $box = '<input type="checkbox"' . ((Tables::TASK_CHECKED[$mark] ?? false) ? ' checked' : '') . ' disabled'
            . ($text !== '' ? ' aria-label="' . Escape::attr($text) . '"' : '') . ($this->o->xhtml ? ' /> ' : '> ');

        return [$box, $structural, ['task'], $style];
    }

    /**
     * C2-H-007, C2-H-032 to C2-H-035, C2-H-174.
     *
     * @param \MarkupCarve\Carve\Node $item
     * @param int $depth
     * @param bool $tight
     * @param bool $ordered
     *
     * @return string
     */
    private function item(Node $item, int $depth, bool $tight, bool $ordered): string
    {
        $f = $item->f;
        $task = is_array($f['task'] ?? null) ? $f['task'] : null;
        if ($task === null && $this->lineStarts === null && !isset($f['attrs']) && !isset($f['lang']) && !isset($f['dir'])) {
            $open = self::pad($depth) . '<li>';
        } else {
            [$box, $structural, $base, $style] = $task !== null ? $this->taskParts($item, $task, $ordered) : ['', [], [], []];
            $open = self::pad($depth) . '<li' . $this->attrs($item, $base, [], $structural, [], $style) . '>' . $box;
        }
        $visible = $this->visibleOf(iterator_to_array($this->documentBlocks($item->children ?? []), false));
        if ($visible === []) {
            return ($task === null ? rtrim($open, ' ') : $open) . '</li>';
        }
        [$first, $degradedTail, $rest] = $this->itemLead($visible, $depth, $tight);
        if ($first !== '' && $rest === [] && $degradedTail === []) {
            return $open . $first . '</li>';
        }
        [$lines, $kept] = $this->itemTail($rest, $degradedTail, $depth);
        if ($lines === [] && !$kept) {
            return ($first === '' && $task === null ? rtrim($open, ' ') : $open) . $first . '</li>';
        }
        if ($lines === []) {
            return $open . $first . "\n" . self::pad($depth) . '</li>';
        }

        return $open . $first . "\n" . implode("\n", $lines) . "\n" . self::pad($depth) . '</li>';
    }

    /**
     * The first line of an item, the degraded paragraphs that follow it and the blocks after them.
     *
     * @param array<\MarkupCarve\Carve\Node> $visible
     * @param int $depth
     * @param bool $tight
     *
     * @return array{0: string, 1: array<string>, 2: array<\MarkupCarve\Carve\Node>}
     */
    private function itemLead(array $visible, int $depth, bool $tight): array
    {
        $rest = $visible;
        // C2-H-165: a refused lead is laid out as the paragraphs it becomes; one that leaves no text is dropped.
        while (($texts = $this->degradedLead($rest, $depth + 1)) !== null) {
            [$degraded, $rest] = $texts;
            if ($degraded !== []) {
                $text = (string)array_shift($degraded);

                return [$tight ? $text : '<p>' . $text . '</p>', $degraded, $rest];
            }
        }
        $lead = $rest[0] ?? null;
        if (
            $lead !== null && self::paragraphLike($lead) && !self::hasFigureCaption($lead)
            && $this->allowed($lead, $depth + 1) && !$this->bareImage($lead)
        ) {
            $bare = $tight && $this->bareReadsBack($lead, $rest[1] ?? null);

            return [(string)$this->paragraph($lead, 0, $bare), [], array_slice($rest, 1)];
        }

        return ['', [], $rest];
    }

    /**
     * @param array<string> $texts Escaped paragraph contents.
     * @param int $depth
     *
     * @return array<string>
     */
    private static function paragraphLines(array $texts, int $depth): array
    {
        return array_map(static fn (string $text): string => self::pad($depth) . '<p>' . $text . '</p>', $texts);
    }

    /**
     * The degraded paragraphs of the first block when the profile refuses it, and the blocks after it.
     *
     * @param array<\MarkupCarve\Carve\Node> $blocks
     * @param int $depth
     *
     * @return array{0: array<string>, 1: array<\MarkupCarve\Carve\Node>}|null
     */
    private function degradedLead(array $blocks, int $depth): ?array
    {
        if ($this->profile === null || $blocks === [] || $this->allowed($blocks[0], $depth)) {
            return null;
        }

        return [$this->profile->degradedParagraphs($blocks[0]), array_slice($blocks, 1)];
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $rest
     * @param array<string> $degradedTail
     * @param int $depth
     *
     * @return array{0: array<string>, 1: bool} The lines, and whether a block the profile keeps stands among them.
     */
    private function itemTail(array $rest, array $degradedTail, int $depth): array
    {
        $kept = $degradedTail !== [];
        $lines = self::paragraphLines($degradedTail, $depth + 1);
        foreach ($rest as $child) {
            if (!$this->allowed($child, $depth + 1)) {
                // C2-H-165: a degraded block that leaves no text is dropped, with no line of its own.
                $html = $this->block($child, $depth + 1);
            } else {
                $kept = true;
                $html = self::paragraphLike($child) && !self::hasFigureCaption($child)
                    ? $this->paragraph($child, $depth + 1, false)
                    : $this->block($child, $depth + 1);
            }
            if ($html !== null) {
                $kept = true;
                $lines[] = $html;
            }
        }

        return [$lines, $kept];
    }

    /**
     * C2-H-038 to C2-H-040.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function descriptionList(Node $node, int $depth): string
    {
        $lines = [];
        $pad = self::pad($depth + 1);
        foreach ($node->children ?? [] as $child) {
            if ($child->type === 'definition_term') {
                $lines[] = $pad . '<dt' . $this->attrs($child) . '>' . $this->inline->leaf($child->children ?? [], $depth + 2) . '</dt>';
            } elseif ($child->type === 'definition_description') {
                $lines[] = $this->description($child, $depth + 1);
            }
        }

        return $this->container('<dl' . $this->attrs($node) . '>', '</dl>', $lines, $depth);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function description(Node $node, int $depth): string
    {
        $open = '<dd' . $this->attrs($node) . '>';
        $visible = $this->visibleOf($node->children ?? []);
        if ($visible === []) {
            return self::pad($depth) . $open . '</dd>';
        }
        if (
            count($visible) === 1 && self::paragraphLike($visible[0]) && !self::hasFigureCaption($visible[0])
            && $this->allowed($visible[0], $depth + 1) && !$this->bareImage($visible[0])
        ) {
            return self::pad($depth) . $open . $this->paragraph($visible[0], 0, $this->bareReadsBack($visible[0], null)) . '</dd>';
        }
        $texts = count($visible) === 1 ? $this->degradedLead($visible, $depth + 1) : null;
        if ($texts !== null && count($texts[0]) === 1) {
            return self::pad($depth) . $open . $texts[0][0] . '</dd>';
        }
        if ($texts !== null) {
            return $this->container($open, '</dd>', self::paragraphLines($texts[0], $depth + 1), $depth);
        }

        return $this->container($open, '</dd>', $this->blocks($node->children ?? [], $depth + 1), $depth);
    }

    /**
     * Title and label paragraphs of a div, an admonition and a directive (C2-H-042, C2-H-154).
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     * @param string|null $titleId
     *
     * @return array<string>
     */
    public function titleAndLabel(Node $node, int $depth, ?string $titleId): array
    {
        $out = [];
        $title = $node->f['title'] ?? null;
        if (is_array($title)) {
            $id = $titleId !== null ? ' id="' . Escape::attr($titleId) . '"' : '';
            $out[] = self::pad($depth) . '<p class="admonition-title"' . $id . '>'
                . $this->inline->leaf(PlainText::nodes($title), $depth + 1) . '</p>';
        }
        $label = $node->f['groupLabel'] ?? null;
        if (is_string($label)) {
            $out[] = self::pad($depth) . '<p class="div-label">' . Escape::text($label) . '</p>';
        }

        return $out;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function div(Node $node, int $depth): string
    {
        $structural = [];
        if ($this->o->roundtrip && !isset($node->f['attrs']) && !isset($node->f['lang']) && !isset($node->f['dir'])) {
            $structural[] = ['data-carve-div', null];
        }
        $children = array_merge($this->titleAndLabel($node, $depth + 1, null), $this->blocks($node->children ?? [], $depth + 1));

        return $this->container(
            '<div' . $this->attrs($node, [], [], $structural, [], ['align' => true]) . '>',
            '</div>',
            $children,
            $depth,
        );
    }

    /**
     * C2-H-154 to C2-H-156.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function admonition(Node $node, int $depth): string
    {
        $kind = PlainText::str($node->f['kind'] ?? '');
        $titleId = $this->state->admonitionIds[spl_object_id($node)] ?? null;
        $generated = [];
        if (!RenderState::namedByAuthor($node)) {
            $generated[] = $titleId !== null
                ? ['aria-labelledby', $titleId]
                : ['aria-label', $this->o->label('admonition' . ucfirst($kind))];
        }
        $children = array_merge($this->titleAndLabel($node, $depth + 1, $titleId), $this->blocks($node->children ?? [], $depth + 1));

        return $this->container(
            '<aside' . $this->attrs($node, ['admonition', $kind], [], [], $generated) . '>',
            '</aside>',
            $children,
            $depth,
        );
    }

    /**
     * C2-H-158: a directive that places nothing is a div of its kind.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function directive(Node $node, int $depth): string
    {
        $kind = PlainText::str($node->f['kind'] ?? '');
        $children = array_merge($this->titleAndLabel($node, $depth + 1, null), $this->blocks($node->children ?? [], $depth + 1));

        return $this->container('<div' . $this->attrs($node, [$kind], [], [], [], ['align' => true]) . '>', '</div>', $children, $depth);
    }

    /**
     * C2-H-043, C2-H-136.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function lineBlock(Node $node, int $depth): string
    {
        $break = $this->o->xhtml ? "<br />\n" : "<br>\n";
        $stanzas = [];
        $lines = [];
        $previous = null;
        foreach ($node->children ?? [] as $line) {
            if ($line->type !== 'line') {
                continue;
            }
            if ($line->children === null || $line->children === []) {
                if ($lines !== []) {
                    $stanzas[] = $lines;
                }
                $lines = [];
                $previous = null;

                continue;
            }
            if ($previous instanceof Node && $lines !== [] && self::endsWithHardBreak($previous)) {
                $last = count($lines) - 1;
                if (str_ends_with($lines[$last], $break)) {
                    $lines[$last] = substr($lines[$last], 0, -strlen($break));
                }
            }
            $lines[] = $this->lineHtml($line, $depth);
            $previous = $line;
        }
        if ($lines !== []) {
            $stanzas[] = $lines;
        }
        $children = [];
        foreach ($stanzas as $stanza) {
            $children[] = self::pad($depth + 1) . '<p>' . implode($break, $stanza) . '</p>';
        }
        $structural = $this->o->roundtrip ? [['data-carve-line-block', null]] : [];

        return $this->container(
            '<div' . $this->attrs($node, [], ['line-block'], $structural, [], ['align' => true]) . '>',
            '</div>',
            $children,
            $depth,
        );
    }

    private static function endsWithHardBreak(Node $line): bool
    {
        $children = $line->children ?? [];

        return $children !== [] && $children[count($children) - 1]->type === 'hard_break';
    }

    /**
     * @param \MarkupCarve\Carve\Node $line
     * @param int $depth
     *
     * @return string
     */
    private function lineHtml(Node $line, int $depth): string
    {
        return $this->inline->verseLine($line->children ?? [], $depth + 2);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function figureGroup(Node $node, int $depth): string
    {
        $children = [];
        foreach ($node->children ?? [] as $child) {
            $html = match ($child->type) {
                'paragraph', 'code_block', 'block_quote' => self::hasFigureCaption($child) && $this->allowed($child, $depth + 1)
                    ? $this->captionedBlock($child, $depth + 1, true)
                    : $this->block($child, $depth + 1),
                // D-render-1: C2-H-064 makes every panel a panel figure; the oracle writes a table panel bare.
                'table' => $this->allowed($child, $depth + 1)
                    ? $this->container(
                        '<figure' . $this->panelAttrs($child) . '>',
                        '</figure>',
                        [$this->tables->table($child, $depth + 2)],
                        $depth + 1,
                    )
                    : $this->block($child, $depth + 1),
                default => $this->block($child, $depth + 1),
            };
            if ($html !== null) {
                $children[] = $html;
            }
        }
        $caption = $node->f['caption'] ?? null;
        if ($caption instanceof Node) {
            $children[] = $this->caption('figcaption', $caption, $depth + 1);
        }

        return $this->container('<figure' . $this->attrs($node, ['carve-figure-group']) . '>', '</figure>', $children, $depth);
    }

    /**
     * C2-H-064: the panel wrapper has a class and source line, while the table keeps its own attributes.
     *
     * @param \MarkupCarve\Carve\Node $table
     *
     * @return string
     */
    private function panelAttrs(Node $table): string
    {
        return $this->aw->build([], null, null, null, ['carve-figure-panel'], sourceLine: $this->line($table));
    }

    /**
     * C2-H-065: one paragraph is written bare, several blocks each get their element.
     *
     * @param string $tag `figcaption` or `caption`.
     * @param \MarkupCarve\Carve\Node $caption
     * @param int $depth
     *
     * @return string
     */
    public function caption(string $tag, Node $caption, int $depth): string
    {
        $open = '<' . $tag . $this->attrs($caption, block: false) . '>';
        $visible = $this->visibleOf($caption->children ?? []);
        if (count($visible) === 1 && $visible[0]->type === 'paragraph' && !self::hasFigureCaption($visible[0])) {
            return self::pad($depth) . $open . $this->inline->leaf($visible[0]->children ?? [], $depth + 1) . '</' . $tag . '>';
        }
        $blocks = [];
        foreach ($visible as $block) {
            $html = $block->type === 'paragraph' && !self::hasFigureCaption($block)
                ? $this->paragraph($block, $depth + 1, false)
                : $this->block($block, $depth + 1);
            if ($html !== null && $html !== '') {
                $blocks[] = $html;
            }
        }
        if ($blocks === []) {
            // C2-H-069: a caption whose blocks write nothing holds no empty line.
            return self::pad($depth) . $open . "\n" . self::pad($depth) . '</' . $tag . '>';
        }

        return $this->container($open, '</' . $tag . '>', $blocks, $depth);
    }

    private static function includePathOf(Node $node): string
    {
        return Expander::shownPath($node);
    }

    /**
     * C2-H-133, C2-H-171: the directive as text.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param array<mixed>|null $spelling C2-L-055: an option's source spelling, kept where it decodes to the value.
     *
     * @return string
     */
    public static function includeText(Node $node, ?array $spelling = null): string
    {
        $parts = ['{{', self::includePath(PlainText::str($node->f['path'] ?? ''))];
        $section = $node->f['section'] ?? null;
        if (is_string($section)) {
            $parts[] = '#' . $section;
        }
        $options = is_array($node->f['options'] ?? null) ? $node->f['options'] : [];
        foreach (Tables::INCLUDE_OPTIONS as $spec) {
            $name = (string)$spec['name'];
            if (!array_key_exists($name, $options)) {
                continue;
            }
            $spelled = $spelling[$name] ?? null;
            $value = $options[$name];
            $parts[] = is_string($spelled) && $value !== true && self::spells($name, $spelled, $value)
                ? '@' . $name . ':' . $spelled : self::includeOption($name, $value);
        }
        $invalidOptions = $node->f['invalid'] ?? null;
        foreach (is_array($invalidOptions) ? $invalidOptions : [] as $invalid) {
            if (is_string($invalid)) {
                $parts[] = $invalid;
            }
        }
        $parts[] = '}}';

        return implode(' ', $parts);
    }

    /**
     * C2-W-058: one option as the tree holds it.
     *
     * @param string $name
     * @param mixed $value
     *
     * @return string
     */
    private static function includeOption(string $name, mixed $value): string
    {
        if ($value === true || ($value === '' && ($name === 'rows' || $name === 'cols'))) {
            return '@' . $name;
        }
        if (is_array($value)) {
            // C2-A-181: only a quoted `cols` value becomes a list, so a list is written quoted.
            $names = array_map(static fn (mixed $v): string => addcslashes(PlainText::str($v), '\\",'), $value);

            return '@' . $name . ':"' . implode(',', $names) . '"';
        }

        return '@' . $name . ':' . self::includeValue(PlainText::str($value), $name === 'cols');
    }

    /**
     * C2-L-055: whether `$spelled` reads back as the option value `$value`.
     *
     * @param string $name
     * @param string $spelled
     * @param mixed $value
     *
     * @return bool
     */
    private static function spells(string $name, string $spelled, mixed $value): bool
    {
        $line = '{{ x @' . $name . ':' . $spelled . ' }}';
        $read = IncludeShape::parse($line, 0, strlen($line));

        return is_array($read['options'] ?? null) && array_key_exists($name, $read['options']) && $read['options'][$name] === $value;
    }

    /**
     * C2-B-202, C2-W-058: a tab may stand in a bare include path.
     *
     * @param string $path
     *
     * @return string
     */
    private static function includePath(string $path): string
    {
        if ($path !== '' && strpbrk($path, ' #@}"') === false && !str_contains($path, "\u{201C}")) {
            return $path;
        }

        return '"' . addcslashes($path, '\\"') . '"';
    }

    /**
     * @param string $value
     * @param bool $stored A `cols` value that kept its quotes is written as stored.
     *
     * @return string
     */
    private static function includeValue(string $value, bool $stored): string
    {
        if ($stored && strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return $value;
        }
        if ($value === '' || strpbrk($value, " \t@}") !== false || $value[0] === '"' || $value[0] === "'") {
            return '"' . $value . '"';
        }

        return $value;
    }

    /**
     * C2-X-033: the helpers an extension renderer receives.
     *
     * @return \MarkupCarve\Carve\Extension\RenderHost
     */
    private function host(int $depth = 0): RenderHost
    {
        return new RenderHost(
            function (array $nodes, int $depth = 0): string {
                if ($depth === 0) {
                    return implode("\n", $this->blocks($nodes, 0));
                }

                return implode("\n", array_filter($this->blocks($nodes, $depth), static fn (string $block): bool => $block !== ''));
            },
            fn (array $nodes): string => $this->inline->inlines($nodes, 0),
            static fn (array $nodes): string => PlainText::of($nodes),
            function (string $prefix, bool $reserve = true): string {
                do {
                    $id = $prefix . '-' . ++$this->extensionIds;
                } while ($reserve && !$this->state->reserveExtensionId($id));

                return $id;
            },
            $this->o->profile,
            $this->o->label('tabsGroup'),
            $this->state->isIdTaken(...),
            function (Node $node, bool $block = true, bool $bare = false): string {
                if (!$bare) {
                    return $this->attrs($node, block: $block);
                }
                $line = $block ? $this->line($node) : null;

                return $line === null ? '' : ' data-source-line="' . $line . '"';
            },
            $this->o->mode,
            $this->o->labels,
            $this->o->sourceLines,
            $depth,
            fn (string $url): bool => UrlPolicy::passes($url, $this->o),
        );
    }

    /**
     * C2-H-076, C2-A-127: a block extension renders through its registered renderer (C2-X-034) or its
     * fallback (C2-X-035).
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string|null
     */
    private function blockExtension(Node $node, int $depth): ?string
    {
        $renderer = $this->o->rendered[Walk::key($node)] ?? null;
        if ($renderer !== null) {
            // C2-X-034: trusted host code; its HTML is written unchanged.
            try {
                /** @var mixed $html */
                $html = $renderer($node, $this->host($depth));
                if (!is_string($html)) {
                    throw new UnexpectedValueException('renderer returned a value that is not a string');
                }

                return self::pad($depth) . $html;
            } catch (Throwable $error) {
                $this->log->rendererFailed($node, $error);
            }
        } else {
            $this->log->loss('extension-fallback-rendered', 'warning', ['name' => PlainText::str($node->f['name'] ?? '')]);
        }
        $fallback = PlainText::nodes($node->f['fallback'] ?? []);
        // An attribute that the writer drops (an event handler) leaves no carrier behind.
        if ($this->attrs($node, block: false) !== '') {
            return $this->container('<div' . $this->attrs($node) . '>', '</div>', $this->blocks($fallback, $depth + 1), $depth);
        }
        $blocks = $this->blocks($fallback, $depth);

        return $blocks === [] ? null : implode("\n", $blocks);
    }
}
