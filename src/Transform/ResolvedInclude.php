<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

/**
 * Resolver result: the child source plus an optional canonical id for the
 * resolved file. The id feeds cycle detection and becomes the parent entry in
 * the include stack for nested resolves, so resolvers that map paths to files
 * (filesystem, VFS) should supply one; without it two spellings of the same
 * file ('b.crv' vs './b.crv') defeat the cycle guard and only the depth limit
 * stops the recursion.
 */
class ResolvedInclude
{
    public function __construct(
        protected string $source,
        protected ?string $id = null,
    ) {
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getId(): ?string
    {
        return $this->id;
    }
}
