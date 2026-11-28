<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Inline;

use MarkupCarve\Carve\Node;

/**
 * What the inline pass knows from the block pass (C2-I-001, C2-I-125): the keys of the link
 * definitions, the footnote labels and the abbreviation definitions of the whole document.
 */
final class InlineContext
{
    /**
     * Folded link definition keys (C2-I-079).
     *
     * @var array<string, true>
     */
    public array $linkKeys = [];

    /**
     * Folded footnote definition keys.
     *
     * @var array<string, true>
     */
    public array $footnoteKeys = [];

    /**
     * Abbreviation key to expansion (C2-I-098).
     *
     * @var array<string, string>
     */
    public array $abbreviations = [];

    /**
     * Whether the inline pass read a snippet use (C2-I-128).
     */
    public bool $snippetUse = false;

    /**
     * Collects the definitions of the whole document before the inline pass (C2-B-088, C2-I-125).
     *
     * @param \MarkupCarve\Carve\Node $doc
     * @param string $s
     *
     * @return self
     */
    public static function collect(Node $doc, string $s): self
    {
        $ctx = new self();
        $stack = [$doc];
        while ($stack !== []) {
            $node = array_pop($stack);
            $key = $node->f['key'] ?? null;
            if (is_string($key)) {
                $ctx->define($node, Chars::fold($key));
            }
            foreach ($node->children ?? [] as $child) {
                if ($child->lines === null || $child->children !== null) {
                    $stack[] = $child;
                }
            }
        }

        return $ctx;
    }

    /**
     * The link keys and abbreviations of an outer document in place of those the source defines itself.
     *
     * @param array<string, true> $linkKeys
     * @param array<string, string> $abbreviations
     *
     * @return void
     */
    public function scope(array $linkKeys, array $abbreviations): void
    {
        $this->linkKeys = [];
        foreach (array_keys($linkKeys) as $key) {
            $this->linkKeys[Chars::fold((string)$key)] = true;
        }
        $this->abbreviations = [];
        foreach ($abbreviations as $key => $expansion) {
            $this->abbreviations[Chars::fold((string)$key)] ??= $expansion;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param string $key
     *
     * @return void
     */
    private function define(Node $node, string $key): void
    {
        if ($node->type === 'link_reference_definition') {
            $this->linkKeys[$key] = true;
        } elseif ($node->type === 'footnote') {
            $this->footnoteKeys[$key] = true;
        } elseif ($node->type === 'abbreviation_def') {
            $expansion = $node->f['expansion'] ?? '';
            // C2-B-084: the first definition of a term counts.
            if (!isset($this->abbreviations[$key])) {
                $this->abbreviations[$key] = is_string($expansion) ? $expansion : '';
            }
        }
    }
}
