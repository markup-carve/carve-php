<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Include;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Diagnostics\Findings;
use MarkupCarve\Carve\Diagnostics\Message;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Resolver;
use Throwable;

final class Expander
{
    private int $bytes = 0;

    private Node $root;

    /**
     * @var array<string, array<string, string>>
     */
    private array $abbreviations = [];

    /**
     * Parse a selected source as a document.
     *
     * @param \MarkupCarve\Carve\Options $options
     * @param \MarkupCarve\Carve\Diagnostics\Findings $findings
     * @param \Closure(string, array<string, string>, bool): array{0: \MarkupCarve\Carve\Node,
     *     1: \MarkupCarve\Carve\Diagnostics\Findings} $parse
     */
    public function __construct(private Options $options, private Findings $findings, private Closure $parse)
    {
    }

    public function expand(Node $root): void
    {
        $this->root = $root;
        $this->abbreviations[$this->options->includeFile ?? ''] = self::ownAbbreviations($root);
        $stack = [];
        if ($this->options->includeFile !== null) {
            $stack[$this->options->includeFile] = true;
        }
        $this->walk($root, $this->options->includeFile, 0, $stack);
        Shift::apply($root, $this->findings);
        if ($this->options->includeResolver !== null) {
            $root->f['stages'] = ['includeExpansion'];
        }
    }

    /**
     * Traverse the block tree in source order.
     *
     * @param \MarkupCarve\Carve\Node $parent
     * @param string|null $from
     * @param int $depth
     * @param array<string, true> $ancestors
     */
    private function walk(Node $parent, ?string $from, int $depth, array $ancestors): void
    {
        foreach ($parent->children ?? [] as $child) {
            if ($child->type !== 'include') {
                $this->walk($child, $from, $depth, $ancestors);

                continue;
            }
            $this->one($child, $from, $depth, $ancestors);
        }
    }

    /**
     * Expand one directive.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param string|null $from
     * @param int $depth
     * @param array<string, true> $ancestors
     */
    private function one(Node $node, ?string $from, int $depth, array $ancestors): void
    {
        $cause = $this->before($node, $depth);
        if ($cause !== null) {
            $this->failure($cause, $node, $from);

            return;
        }
        $request = [
            'path' => is_string($node->f['path'] ?? null) ? $node->f['path'] : '',
            'options' => is_array($node->f['options'] ?? null) ? $node->f['options'] : [],
        ];
        if (isset($node->f['section'])) {
            $request['section'] = $node->f['section'];
        }
        if ($from !== null) {
            $request['from'] = $from;
        }
        // The built-in file resolver refuses a larger file before it reads it whole; a host resolver may ignore this.
        $request['budget'] = $this->options->includeBytes - $this->bytes;
        $answer = $this->resolve($request);
        if (isset($answer['error'])) {
            if (isset($answer['id'])) {
                // C2-N-065 after the cycle test of rank 10: the file was over the budget.
                $node->f['file'] = $answer['id'];
                $this->failure(isset($ancestors[$answer['id']]) ? 'include-cycle' : 'include-budget', $node, $from);

                return;
            }
            $this->failure($answer['error'], $node, $from);

            return;
        }
        $text = $answer['text'];
        $id = $answer['id'];
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            $this->failure('include-non-text', $node, $from);

            return;
        }
        $node->f['file'] = $id;
        if (isset($ancestors[$id])) {
            $this->failure('include-cycle', $node, $from);

            return;
        }
        if (strlen($text) > $this->options->includeBytes - $this->bytes) {
            $this->failure('include-budget', $node, $from);

            return;
        }
        $this->bytes += strlen($text);
        $this->root->includeSources[$id] = $text;
        $this->contents($node, $text, $id, $from, $depth, $ancestors);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{text: string, id: string}|array{error: string, id?: string}
     */
    private function resolve(array $request): array
    {
        try {
            $resolver = $this->options->includeResolver;
            $answer = $resolver === null ? ['error' => 'unreadable'] : $resolver($request);
        } catch (Throwable) {
            return ['error' => 'include-unreadable'];
        }
        if (!is_array($answer)) {
            return ['error' => 'include-unreadable'];
        }
        if (($answer['error'] ?? null) === 'size_exceeded' && is_string($answer['id'] ?? null)) {
            return ['error' => 'include-budget', 'id' => $answer['id']];
        }
        if (isset($answer['error'])) {
            $codes = [
                'outside_root' => 'include-outside-root',
                'not_found' => 'include-not-found',
                'unreadable' => 'include-unreadable',
                'non_text' => 'include-non-text',
            ];

            return [

                'error' => is_string($answer['error'])
                ? ($codes[$answer['error']] ?? 'include-unreadable') : 'include-unreadable',
            ];
        }
        if (!is_string($answer['text'] ?? null) || !is_string($answer['id'] ?? null)) {
            return ['error' => 'include-unreadable'];
        }

        return ['text' => $answer['text'], 'id' => $answer['id']];
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param string $text
     * @param string $id
     * @param string|null $from
     * @param int $depth
@param array<string, true> $ancestors
     */
    private function contents(Node $node, string $text, string $id, ?string $from, int $depth, array $ancestors): void
    {
        $bom = str_starts_with($text, "\u{FEFF}") ? 3 : 0;
        $content = $bom === 0 ? $text : substr($text, 3);
        $options = is_array($node->f['options'] ?? null) ? $node->f['options'] : [];
        [$selected, $offset, $error] = Selection::source($content, $options, $this->tableMode($node, $options));
        if ($error !== null) {
            $this->failure($error, $node, $from);

            return;
        }
        if ($this->tableMode($node, $options)) {
            $ok = $this->fillTable($node, $content, $bom, $options, $offset, strlen($selected), $id, $from);
        } elseif (isset($options['code'])) {
            $node->children = [$this->code($node, $selected, $offset + $bom, $options['code'])];
            $ok = true;
        } else {
            $ok = $this->fillCarve($node, $content, $selected, $offset, $bom, $id, $from);
        }
        if (!$ok) {
            return;
        }
        $ancestors[$id] = true;
        $this->markFile($node, $id, array_reverse(array_keys($ancestors)));
        $this->walk($node, $id, $depth + 1, $ancestors);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param string $content
     * @param int $bom
@param array<string, mixed> $options
     * @param string|null $from
     * @param string $id
     * @param int $length
     * @param int $offset
     */
    private function fillTable(
        Node $node,
        string $content,
        int $bom,
        array $options,
        int $offset,
        int $length,
        string $id,
        ?string $from,
    ): bool {
        [$table, $error] = TableInclude::build($node, $content, $bom, $options, $offset, $offset + $length);
        if ($error !== null || $table === null) {
            $this->failure($error ?? 'include-cols-invalid', $node, $from);

            return false;
        }
        if (TableInclude::malformed($content, ($options['code'] ?? null) === 'tsv' ? "\t" : ',')) {
            $this->findings->add('delimited-table-malformed', $table, $id);
        }
        $node->children = [$table];

        return true;
    }

    private function fillCarve(
        Node $node,
        string $content,
        string $selected,
        int $offset,
        int $bom,
        string $id,
        ?string $from,
    ): bool {
        $inherited = $this->abbreviations[$from ?? ''] ?? [];
        $whole = null;
        if ($selected !== $content) {
            [$whole] = ($this->parse)($content, $inherited, true);
            $inherited = self::ownAbbreviations($whole) + $inherited;
        }
        [$document, $notes] = ($this->parse)($selected, $inherited, $offset === 0);
        $this->abbreviations[$id] = self::ownAbbreviations($document) + $inherited;
        $this->findings->merge($notes, $id);
        if (is_string($node->f['section'] ?? null)) {
            Resolver::resolve($document, $selected, $this->options);
            $children = Section::select($document, $node->f['section']);
            if ($children === null) {
                $this->failure('include-section', $node, $from);

                return false;
            }
            $node->children = $children;
        } else {
            $node->children = $document->children ?? [];
        }
        if ($offset + $bom !== 0) {
            $this->offset($node, $offset + $bom);
        }
        if ($whole !== null) {
            // C2-N-020: selected blocks inherit definitions from outside the selected lines.
            foreach (Section::definitions($whole) as $definition) {
                if ($definition->start >= $offset && $definition->start < $offset + strlen($selected)) {
                    continue;
                }
                if ($bom !== 0) {
                    $holder = new Node('document');
                    $holder->children = [$definition];
                    $this->offset($holder, $bom);
                }
                $node->children[] = $definition;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private static function ownAbbreviations(Node $document): array
    {
        $out = [];
        foreach ($document->children ?? [] as $child) {
            if ($child->type === 'abbreviation_def' && is_string($child->f['key'] ?? null)) {
                $out[Chars::fold($child->f['key'])] ??= is_string($child->f['expansion'] ?? null)
                    ? $child->f['expansion'] : '';
            }
        }

        return $out;
    }

    private function code(Node $include, string $selected, int $start, mixed $syntax): Node
    {
        $end = $selected !== '' && str_ends_with($selected, "\n") ? strlen($selected) - 1 : strlen($selected);
        if ($end > 0 && $selected[$end - 1] === "\r" && str_ends_with($selected, "\r\n")) {
            $end--;
        }
        $code = Node::at('code_block', $start, $start + $end);
        $code->f['value'] = $selected === '' ? '' : str_replace("\r\n", "\n", $selected)
            . (str_ends_with($selected, "\n") ? '' : "\n");
        if (is_string($syntax)) {
            $code->f['syntax'] = $syntax;
        }
        foreach (['attrs', 'caption'] as $key) {
            if (isset($include->f[$key])) {
                $code->f[$key] = $key === 'caption' && $include->f[$key] instanceof Node
                    ? TableInclude::copyCaption($include->f[$key]) : $include->f[$key];
            }
        }

        return $code;
    }

    private function before(Node $node, int $depth): ?string
    {
        if ($this->options->includeResolver === null) {
            return 'include-no-resolver';
        }
        if (!empty($node->f['invalid'])) {
            return 'include-option-invalid';
        }
        $options = is_array($node->f['options'] ?? null) ? $node->f['options'] : [];
        $selections = (int)isset($node->f['section']);
        foreach (['lines', 'region', 'rows'] as $key) {
            $selections += (int)isset($options[$key]);
        }
        $table = $this->tableMode($node, $options);
        if (
            $selections > 1 || isset($node->f['section'], $options['code'])
            || (isset($options['rows']) || isset($options['cols'])) && !$table
        ) {
            return 'include-selection-conflict';
        }
        if ($depth >= $this->options->includeDepth) {
            return 'include-depth';
        }
        if ($this->bytes >= $this->options->includeBytes) {
            return 'include-budget';
        }

        return null;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
@param array<string, mixed> $options
     */
    private function tableMode(Node $node, array $options): bool
    {
        $attrs = $node->f['attrs'] ?? null;

        return isset($options['code']) && in_array($options['code'], ['csv', 'tsv'], true)
            && $attrs instanceof Attrs && $attrs->get('table') !== null;
    }

    private function failure(string $code, Node $node, ?string $from): void
    {
        $message = Message::fill($code, ['path' => self::shownPath($node)] + $this->failureSlots($code, $node));
        $this->findings->add($code, $node, $node->file, message: $message);
    }

    /**
     * The path of an include as a message shows it: never a host path (C2-N-013).
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    public static function shownPath(Node $node): string
    {
        $path = is_string($node->f['path'] ?? null) ? $node->f['path'] : '';

        return str_starts_with($path, '/') ? '[absolute path]'
            : (preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $path) === 1 ? '[URI]' : $path);
    }

    /**
     * The slots of a failure message beside the path.
     *
     * @param string $code
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array<string, string|int>
     */
    private function failureSlots(string $code, Node $node): array
    {
        $options = is_array($node->f['options'] ?? null) ? $node->f['options'] : [];
        if ($code === 'include-option-invalid') {
            $invalid = is_array($node->f['invalid'] ?? null) ? $node->f['invalid'] : [];

            return ['option' => PlainText::str($invalid[0] ?? ''), 'reason' => 'is not a valid option'];
        }
        if ($code === 'include-selection-conflict') {
            return ['reason' => self::conflictReason($node, $options)];
        }
        $limits = ['include-depth' => $this->options->includeDepth, 'include-budget' => $this->options->includeBytes];
        if (isset($limits[$code])) {
            return ['limit' => $limits[$code]];
        }
        $region = PlainText::str($options['region'] ?? '');

        return [
            'include-section' => ['name' => PlainText::str($node->f['section'] ?? '')],
            'include-region-invalid' => ['name' => $region, 'reason' => 'is missing or its markers do not pair'],
            'include-cols-invalid' => ['reason' => 'names a column the table does not have'],
            'include-cols-duplicate' => ['name' => self::duplicateColumn($options['cols'] ?? null)],
        ][$code] ?? [];
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string, mixed> $options
     *
     * @return string
     */
    private static function conflictReason(Node $node, array $options): string
    {
        if (isset($node->f['section'], $options['code'])) {
            return 'a section and @code exclude each other';
        }

        return isset($options['rows']) || isset($options['cols'])
            ? '@rows and @cols need a code include of a csv or tsv table' : 'a directive takes at most one selection';
    }

    private static function duplicateColumn(mixed $cols): string
    {
        $seen = [];
        $list = is_array($cols) ? $cols : (is_string($cols) ? explode(',', $cols) : []);
        foreach ($list as $name) {
            $name = trim(is_string($name) ? $name : '');
            if (isset($seen[$name])) {
                return $name;
            }
            $seen[$name] = true;
        }

        return '';
    }

    private function offset(Node $root, int $delta): void
    {
        $stack = $root->children ?? [];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->start >= 0) {
                $node->start += $delta;
                $node->end += $delta;
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node $root
     * @param string $file
     * @param array<string> $chain
     */
    private function markFile(Node $root, string $file, array $chain): void
    {
        $stack = $root->children ?? [];
        while ($stack !== []) {
            $node = array_pop($stack);
            $node->file = $file;
            $node->includeChain = $chain;
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }
    }
}
