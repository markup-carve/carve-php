<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Output;

use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;

/**
 * The output targets by name. `Carve::writers()` gives a registry with `html` and `carve`; the 0.1 Markdown, plain text
 * and ANSI writers join as further targets. A registry is a plain object: no state is shared between instances.
 */
final class WriterRegistry
{
    /**
     * @var array<string, \MarkupCarve\Carve\Output\OutputWriter>
     */
    private array $writers = [];

    /**
     * @param iterable<\MarkupCarve\Carve\Output\OutputWriter> $writers
     */
    public function __construct(iterable $writers = [])
    {
        foreach ($writers as $writer) {
            $this->register($writer);
        }
    }

    /**
     * Adds a writer; a target that is already registered is replaced.
     *
     * @param \MarkupCarve\Carve\Output\OutputWriter $writer
     *
     * @return $this
     */
    public function register(OutputWriter $writer)
    {
        $this->writers[$writer->target()] = $writer;

        return $this;
    }

    public function has(string $target): bool
    {
        return isset($this->writers[$target]);
    }

    /**
     * @param string $target
     *
     * @throws \MarkupCarve\Carve\OptionError For a target no writer is registered for.
     *
     * @return \MarkupCarve\Carve\Output\OutputWriter
     */
    public function get(string $target): OutputWriter
    {
        return $this->writers[$target] ?? throw new OptionError('unknown output target ' . $target);
    }

    /**
     * @return array<string> The registered targets in registration order.
     */
    public function targets(): array
    {
        return array_keys($this->writers);
    }

    /**
     * @param string $target
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(string $target, Node $tree, array $options = []): string
    {
        return $this->get($target)->write($tree, $options);
    }
}
