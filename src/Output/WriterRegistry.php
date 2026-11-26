<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Output;

use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;

/**
 * The output targets by name. `Carve::writers()` gives a registry with `html`, `carve` and the exports `markdown`,
 * `plain` and `ansi`. A registry is a plain object: no state is shared between instances.
 */
final class WriterRegistry
{
    /**
     * Targets kept for the exports of `MarkupCarve\Carve\Export`, which only they may register.
     *
     * @var array<string, class-string<\MarkupCarve\Carve\Output\OutputWriter>>
     */
    public const RESERVED_TARGETS = [
        'markdown' => MarkdownExporter::class,
        'plain' => PlainTextExporter::class,
        'ansi' => AnsiExporter::class,
    ];

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
     * Adds a writer; a target that is already registered is replaced. Only the export of a reserved target
     * (`markdown`, `plain`, `ansi`) may take it.
     *
     * @param \MarkupCarve\Carve\Output\OutputWriter $writer
     *
     * @throws \MarkupCarve\Carve\OptionError For another writer of a reserved target.
     *
     * @return $this
     */
    public function register(OutputWriter $writer)
    {
        $target = $writer->target();
        $reserved = self::RESERVED_TARGETS[$target] ?? null;
        if ($reserved !== null && !$writer instanceof $reserved) {
            throw new OptionError('the output target ' . $target . ' is reserved');
        }
        $this->writers[$target] = $writer;

        return $this;
    }

    public function has(string $target): bool
    {
        return isset($this->writers[$target]);
    }

    /**
     * @param string $target
     *
     * @throws \MarkupCarve\Carve\OptionError For a target no writer is registered for; the message lists the known ones.
     *
     * @return \MarkupCarve\Carve\Output\OutputWriter
     */
    public function get(string $target): OutputWriter
    {
        return $this->writers[$target]
            ?? throw new OptionError('unknown output target ' . $target . '; known targets: ' . implode(', ', $this->targets()));
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
