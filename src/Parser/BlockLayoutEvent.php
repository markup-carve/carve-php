<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use InvalidArgumentException;
use LogicException;

/**
 * One immutable answer produced by the block-layout phase.
 *
 * Consumption and definition activation deliberately remain separate. A line
 * can disappear from visible block content without becoming a global
 * definition; conflating those facts was the correctness failure in the first
 * block-layout prototype.
 */
final readonly class BlockLayoutEvent
{
    /**
     * @param int $startLine
     * @param int $linesConsumed
     * @param string $family
     * @param bool $consumed
     * @param string|null $definitionKind
     * @param bool $activeDefinition
     * @param int|null $sourceLine
     * @param list<int> $sourceLines
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public int $startLine,
        public int $linesConsumed,
        public string $family,
        public bool $consumed = true,
        public ?string $definitionKind = null,
        public bool $activeDefinition = false,
        public ?int $sourceLine = null,
        public array $sourceLines = [],
    ) {
        if ($startLine < 0 || $linesConsumed < 1) {
            throw new InvalidArgumentException('A block-layout event must cover at least one source line.');
        }
        if ($definitionKind === null && $activeDefinition) {
            throw new InvalidArgumentException('Only a definition event can be active.');
        }
    }

    public function withDefinitionActivation(bool $active): self
    {
        if ($this->definitionKind === null) {
            throw new LogicException('A non-definition layout event cannot be activated.');
        }

        return new self(
            $this->startLine,
            $this->linesConsumed,
            $this->family,
            $this->consumed,
            $this->definitionKind,
            $active,
            $this->sourceLine,
            $this->sourceLines,
        );
    }

    public function asDefinition(string $kind, bool $active): self
    {
        return new self(
            $this->startLine,
            $this->linesConsumed,
            $this->family,
            $this->consumed,
            $kind,
            $active,
            $this->sourceLine,
            $this->sourceLines,
        );
    }
}
