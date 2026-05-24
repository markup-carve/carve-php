<?php

declare(strict_types=1);

namespace Carve\Parser;

use Carve\Node\Block\Paragraph;
use Carve\Node\Document;

final class MatcherContext
{
    /**
     * @param \Carve\Parser\BlockParser $blockParser
     * @param \Carve\Parser\InlineParser $inlineParser
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly BlockParser $blockParser,
        private readonly InlineParser $inlineParser,
        private readonly array $config = [],
    ) {
    }

    public function getReference(string $label): ?ReferenceDefinition
    {
        return $this->blockParser->getReference($label);
    }

    public function hasFootnote(string $label): bool
    {
        return $this->blockParser->hasFootnote($label);
    }

    public function getAbbreviation(string $abbr): ?string
    {
        return $this->blockParser->getAbbreviation($abbr);
    }

    /**
     * @return array<\Carve\Node\Node>
     */
    public function parseInlines(string $text): array
    {
        $holder = new Paragraph();
        $this->inlineParser->parse($holder, $text);

        return $holder->getChildren();
    }

    /**
     * @param array<string> $lines
     *
     * @return array<\Carve\Node\Node>
     */
    public function parseBlocks(array $lines): array
    {
        $holder = new Document();
        $this->blockParser->parseBlockContent($holder, $lines);

        return $holder->getChildren();
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
