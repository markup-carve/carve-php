<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use Closure;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Text;
use ReflectionObject;
use stdClass;

/**
 * Normalizes parsed trees for canonical output comparison.
 *
 * @internal
 */
final class CanonicalAst
{
    /**
     * @param \MarkupCarve\Carve\Renderer\CarveWriterState $state
     * @param (\Closure(object): (array<string, \ReflectionProperty>))|null $canonicalPropertiesOfCallback
     * @param (\Closure(mixed): (?string))|null $canonicalTextContentCallback
     * @param (\Closure(mixed): (mixed))|null $canonicalizeAstCallback
     * @param (\Closure(array<mixed>): (array<mixed>))|null $coalesceTextNodesCallback
     */
    public function __construct(
        private CarveWriterState $state,
        private ?Closure $canonicalPropertiesOfCallback = null,
        private ?Closure $canonicalTextContentCallback = null,
        private ?Closure $canonicalizeAstCallback = null,
        private ?Closure $coalesceTextNodesCallback = null,
    ) {
    }

    /**
     * @return mixed
     */
    public function canonicalizeAst(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $out[$key] = $this->callCanonicalizeAst($child);
            }
            if (array_is_list($out)) {
                $out = $this->callCoalesceTextNodes($out);
            } else {
                ksort($out);
            }

            return $out;
        }

        if (is_object($value)) {
            $name = $value::class;
            // A stdClass carries only dynamic properties, which differ per object.
            $properties = $value instanceof stdClass
                ? $this->callCanonicalPropertiesOf($value)
                : $this->state->canonicalProperties[$name] ??= $this->callCanonicalPropertiesOf($value);
            $out = ['__class' => $value instanceof EscapedText ? Text::class : $name];
            foreach ($properties as $propertyName => $property) {
                $out[$propertyName] = $this->callCanonicalizeAst($property->getValue($value));
            }
            ksort($out);

            return $out;
        }

        return $value;
    }

    /**
     * @return array<string, \ReflectionProperty>
     */
    public function canonicalPropertiesOf(object $value): array
    {
        $properties = [];
        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            $name = $property->getName();
            // `pos` is where a node was READ, never what it is, and the writer's
            // own document carries spans its re-parse does not, so comparing
            // through them answered "different" for every document.
            if ($name === 'parent' || $name === 'pos' || $name === 'sourceLength' || $name === 'ingestPayloadLength') {
                continue;
            }
            $properties[$name] = $property;
        }

        return $properties;
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return array<mixed>
     */
    public function coalesceTextNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $lastIndex = count($out) - 1;
            $content = $this->callCanonicalTextContent($node);
            if ($lastIndex >= 0 && $content !== null) {
                $previousContent = $this->callCanonicalTextContent($out[$lastIndex]);
                if ($previousContent !== null && is_array($out[$lastIndex])) {
                    $out[$lastIndex]['content'] = $previousContent . $content;

                    continue;
                }
            }
            $out[] = $node;
        }

        return $out;
    }

    public function canonicalTextContent(mixed $node): ?string
    {
        if (
            is_array($node)
            && ($node['__class'] ?? null) === Text::class
            && ($node['attributes'] ?? []) === []
            && ($node['attributeOrder'] ?? []) === []
            && ($node['children'] ?? []) === []
            && is_string($node['content'] ?? null)
        ) {
            return $node['content'];
        }

        return null;
    }

    /**
     * @return array<string, \ReflectionProperty>
     */
    private function callCanonicalPropertiesOf(object $value): array
    {
        if ($this->canonicalPropertiesOfCallback !== null) {
            return ($this->canonicalPropertiesOfCallback)($value);
        }

        return $this->canonicalPropertiesOf($value);
    }

    private function callCanonicalTextContent(mixed $node): ?string
    {
        if ($this->canonicalTextContentCallback !== null) {
            return ($this->canonicalTextContentCallback)($node);
        }

        return $this->canonicalTextContent($node);
    }

    /**
     * @return mixed
     */
    private function callCanonicalizeAst(mixed $value): mixed
    {
        if ($this->canonicalizeAstCallback !== null) {
            return ($this->canonicalizeAstCallback)($value);
        }

        return $this->canonicalizeAst($value);
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return array<mixed>
     */
    private function callCoalesceTextNodes(array $nodes): array
    {
        if ($this->coalesceTextNodesCallback !== null) {
            return ($this->coalesceTextNodesCallback)($nodes);
        }

        return $this->coalesceTextNodes($nodes);
    }
}
