<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\Diagnostics\Diagnostic;

/**
 * C2-N-020, C2-N-054, C2-N-055: the reference findings of a lint run with includes, read from the expanded tree. Each file
 * is linted alone, so a reference that resolves against another file of the expansion, or against the definitions a region
 * selection appends, would read as unresolved, and the definition it uses as unused.
 */
final class References
{
    /**
     * @var array<string, true> The reference occurrences of the expanded tree, by file, start, code and include path.
     */
    private array $observed = [];

    /**
     * @var array<string, true> The occurrences among them that resolve to no definition.
     */
    private array $unresolved = [];

    /**
     * @var array<string, true> The definitions a reference of the expansion resolves to, by file, start and code.
     */
    private array $used = [];

    /**
     * @param array<string, mixed> $node
     * @param string $file
     * @param string $inclusion
     * @param array<array{links: array<string, string>, notes: array<string, string>}> $scopes Innermost last.
     * @param bool $inSnippet
     *
     * @return void
     */
    public function record(array $node, string $file, string $inclusion, array $scopes, bool $inSnippet): void
    {
        $type = $node['type'] ?? null;
        if ($type !== 'link' && $type !== 'image' && $type !== 'footnote_ref') {
            return;
        }
        $pos = is_array($node['pos'] ?? null) ? $node['pos'] : [];
        $start = $pos['startOffset'] ?? null;
        if (!is_int($start)) {
            return;
        }
        $note = $type === 'footnote_ref';
        $occurrence = implode("\0", [$file, $start, $note ? 'unresolved-footnote' : 'unresolved-reference-link', $inclusion]);
        $this->observed[$occurrence] = true;
        $resolved = match ($type) {
            'footnote_ref' => isset($node['number']) || ($inSnippet && $this->target($node['key'] ?? null, true, $scopes) !== null),
            'link' => isset($node['href']),
            default => isset($node['src']),
        };
        if (!$resolved) {
            $this->unresolved[$occurrence] = true;

            return;
        }
        $key = $node['key'] ?? null;
        if (is_string($key) && (!$note || !$inSnippet)) {
            $this->use($key, $note, $scopes);
        }
    }

    /**
     * @param mixed $key
     * @param bool $note
     * @param array<array{links: array<string, string>, notes: array<string, string>}> $scopes
     */
    private function target(mixed $key, bool $note, array $scopes): ?string
    {
        if (!is_string($key)) {
            return null;
        }
        $kind = $note ? 'notes' : 'links';
        for ($i = count($scopes) - 1; $i >= 0; $i--) {
            if (isset($scopes[$i][$kind][$key])) {
                return $scopes[$i][$kind][$key];
            }
        }

        return null;
    }

    /**
     * Marks the definition a resolved reference uses: the innermost one of its key.
     *
     * @param string $key
     * @param bool $note
     * @param array<array{links: array<string, string>, notes: array<string, string>}> $scopes Innermost last.
     *
     * @return void
     */
    private function use(string $key, bool $note, array $scopes): void
    {
        $target = $this->target($key, $note, $scopes);
        if ($target !== null) {
            $this->used[$target . "\0" . ($note ? 'unused-footnote-definition' : 'unused-link-reference-definition')] = true;
        }
    }

    /**
     * @param array<string, true> $targets Definition file and start offset.
     */
    public function useFootnotes(array $targets): void
    {
        foreach ($targets as $target => $_) {
            $this->used[$target . "\0unused-footnote-definition"] = true;
        }
    }

    /**
     * Whether a finding of the top-level document stands once the expansion is read.
     *
     * @param \MarkupCarve\Carve\Diagnostics\Diagnostic $entry
     *
     * @return bool
     */
    public function kept(Diagnostic $entry): bool
    {
        return $this->keeps($entry->code, $entry->file ?? '', $entry->startOffset, '');
    }

    /**
     * Whether a finding at a start in a file, reached through the include at a path, stands once the expansion is read.
     *
     * @param string $code
     * @param string $file
     * @param int $start
     * @param string $inclusion
     *
     * @return bool
     */
    public function keeps(string $code, string $file, int $start, string $inclusion): bool
    {
        if ($code === 'unresolved-reference-link' || $code === 'unresolved-footnote') {
            $occurrence = implode("\0", [$file, $start, $code, $inclusion]);

            return !isset($this->observed[$occurrence]) || isset($this->unresolved[$occurrence]);
        }
        if ($code === 'unused-link-reference-definition' || $code === 'unused-footnote-definition') {
            return !isset($this->used[$file . "\0" . $start . "\0" . $code]);
        }

        return true;
    }
}
