<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The attribute block on the wire is `{id, classes[], keyValues{}, order[]}`
 * with `additionalProperties: false`.
 *
 * This engine stores what the author wrote as a flat `name => value` map, and
 * published it directly - so `{"class": "note"}` went out where the schema wants
 * `{"classes": ["note"]}`, and `title`, `style` and `onclick` appeared as
 * top-level keys the schema rejects outright.
 *
 * PART 12 section 1: an implementation whose internals differ maps on the way
 * out. The parser and the runtime tree are untouched.
 */
class AttrsShapeTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attrsIn(string $source): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (is_array($node)) {
                if (isset($node['attrs']) && is_array($node['attrs'])) {
                    $found[] = $node['attrs'];
                }
                foreach ($node as $value) {
                    $walk($value);
                }
            }
        };
        $walk($this->codec->encode((new CarveConverter())->parse($source)));

        return $found;
    }

    public function testAClassBecomesTheClassesArray(): void
    {
        // An AUTHORED class. `::: note` was the original sample and is the wrong
        // one: the `note` class is STRUCTURAL - the parser derives it from the
        // opener word - and it is published as `kind` rather than as a class, so
        // that document has no `classes` at all (carve-php#552, matching
        // carve-js and carve-rs). The property under test is that a class the
        // author wrote becomes the array, which needs a class the author wrote.
        $this->assertSame(['classes' => ['note'], 'order' => ['.class']], $this->attrsIn("{.note}\nParagraph.\n")[0]);
    }

    public function testSeveralClassesAreSplit(): void
    {
        $attrs = $this->attrsIn("[x]{.a .b}\n")[0];

        $this->assertSame(['a', 'b'], $attrs['classes']);
    }

    public function testKeyValuesAreNestedRatherThanTopLevel(): void
    {
        // `title` as a top-level key is what the schema rejects; it belongs
        // under `keyValues`.
        $attrs = $this->attrsIn("[x]{title=\"t\" k=v}\n")[0];

        $this->assertSame(['title' => 't', 'k' => 'v'], $attrs['keyValues']);
        $this->assertArrayNotHasKey('title', $attrs);
    }

    public function testTheWireCarriesOnlyTheFourStructuredKeys(): void
    {
        foreach ($this->attrsIn("[l]{key=c .a #b}\n") as $attrs) {
            $this->assertSame(
                [],
                array_diff(array_keys($attrs), ['id', 'classes', 'keyValues', 'order']),
            );
        }
    }

    public function testTheAuthorSSlotOrderSurvives(): void
    {
        $attrs = $this->attrsIn("[l]{key=c .a #b}\n")[0];

        $this->assertSame(['key', '.class', '#id'], $attrs['order']);
    }

    public function testARoundTripReproducesTheDocument(): void
    {
        // Storage order is what the renderer emits, so decoding has to restore
        // it - not merely the same set of attributes. `{key=c .a #b}` came back
        // as `{#b .a key=c}` and rendered differently.
        $converter = new CarveConverter();
        foreach (
            [
                "[l]{key=c .a #b}\n",
                "{title=\"attr\"}\n::: note \"opener\"\nBody.\n:::\n",
                "[x]{.a .b}\n",
                "`c`{.hl}\n",
            ] as $source
        ) {
            $decoded = $this->codec->decode($this->codec->encode($converter->parse($source)));

            $this->assertSame(
                $converter->render($converter->parse($source)),
                $converter->render($decoded),
                sprintf('%s must render identically after a round trip', json_encode($source)),
            );
        }
    }

    public function testAnOlderFlatPayloadStillDecodes(): void
    {
        // Trees written before this change are stored: a key that is not one of
        // the four structured ones is taken as an attribute under its own name,
        // which is exactly what it used to mean.
        $decoded = $this->codec->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'paragraph', 'attrs' => ['class' => 'old', 'title' => 't'], 'children' => []],
            ],
        ]);

        $paragraph = $decoded->getChildren()[0];
        $this->assertSame('old', $paragraph->getAttributes()['class']);
        $this->assertSame('t', $paragraph->getAttributes()['title']);
    }
}
