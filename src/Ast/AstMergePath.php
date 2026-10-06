<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

final class AstMergePath
{
    /**
     * @var list<int|string>
     */
    private array $segments = [];

    public bool $stripMetadata = true;

    public function pointer(): string
    {
        $result = '';
        foreach ($this->segments as $segment) {
            $result .= '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$segment);
        }

        return $result;
    }

    public function push(string|int $key): bool
    {
        $previous = $this->stripMetadata;
        $this->stripMetadata = $previous && $key !== 'keyValues';
        $this->segments[] = $key;

        return $previous;
    }

    public function pop(bool $previous): void
    {
        array_pop($this->segments);
        $this->stripMetadata = $previous;
    }
}
