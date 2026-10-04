<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * A container frame and its rendered leaves, before cumulative indentation.
 *
 * @internal
 */
final class ContainerLayout
{
    /**
     * @param string $open
     * @param string $prefix
     * @param string $close
     * @param bool $compact
     * @param list<self|string> $children
     * @param bool $trimBody
     */
    public function __construct(
        public string $open,
        public string $prefix,
        public string $close,
        public bool $compact,
        public array $children,
        public bool $trimBody = true,
    ) {
        if ($trimBody) {
            while ($this->children !== []) {
                $last = $this->children[array_key_last($this->children)];
                if (!is_string($last)) {
                    break;
                }
                array_pop($this->children);
                $last = rtrim($last, "\n");
                if ($last !== '') {
                    $this->children[] = $last;

                    break;
                }
            }
        }
    }
}
