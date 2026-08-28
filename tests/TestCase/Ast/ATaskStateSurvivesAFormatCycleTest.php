<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 6g / carve#1866. The seven task states render as two, so the wire
 * carried only `checked` and the writer rewrote four of them to `[ ]`. The
 * state is the author's spelling, published like `bulletChar`.
 */
class ATaskStateSurvivesAFormatCycleTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function fmt(string $source): string
    {
        return (new CarveRenderer())->render($this->converter->parse($source));
    }

    /**
     * @return array<array{string}>
     */
    public static function extendedStates(): array
    {
        return [['-'], ['_'], ['>'], ['?']];
    }

    #[DataProvider('extendedStates')]
    public function testTheWriterPutsTheStateBack(string $state): void
    {
        $this->assertSame("- [$state] a\n", $this->fmt("- [$state] a\n"));
    }

    public function testTheStateIsPublishedOnlyWhenItIsNotTheDefault(): void
    {
        $items = function (string $source): array {
            $encoded = (new AstCodec())->encode($this->converter->parse($source));

            return $encoded['children'][0]['items'][0];
        };

        $this->assertArrayNotHasKey('taskState', $items('- [ ] a'));
        $this->assertArrayNotHasKey('taskState', $items('- [x] a'));
        $this->assertSame('-', $items('- [-] a')['taskState']);
        $this->assertFalse($items('- [-] a')['checked']);
    }

    public function testTheCaseIsFoldedBecauseItIsNotAState(): void
    {
        $this->assertSame("- [x] a\n", $this->fmt("- [X] a\n"));
    }

    public function testTheStateRidesTheWire(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode($codec->encode($this->converter->parse("- [>] deferred\n")));

        $this->assertSame("- [>] deferred\n", (new CarveRenderer())->render($document));
    }

    public function testAnItemWithAttributesKeepsItsState(): void
    {
        $this->assertSame("-{.c} [?] a\n", $this->fmt("-{.c} [?] a\n"));
    }

    public function testTheRenderingDoesNotMove(): void
    {
        $html = $this->converter->convert("- [>] a\n");

        $this->assertStringContainsString('<input type="checkbox" disabled', $html);
        $this->assertStringNotContainsString('checked', $html);
    }

    public function testAMarkerTheLanguageDoesNotSpellKeepsTheDefaultBox(): void
    {
        // The constructor takes any character and its docblock names markers
        // outside PART 2's enumeration, so a hand-built item can hold one.
        // Publishing it would put a value on the wire the schema refuses, and
        // writing it would emit `- [/] x`, which reads back as a paragraph.
        $item = new ListItem('/');

        $this->assertNull($item->getAuthoredTaskState());
        $this->assertTrue($item->isTask());
        $this->assertFalse($item->isCompleted());
    }

    public function testAPayloadWhoseFieldsDisagreeIsRefused(): void
    {
        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode([
            'ast' => AstCodec::VERSION,
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'list',
                    'ordered' => false,
                    'tight' => true,
                    'items' => [['type' => 'list_item', 'children' => [], 'checked' => true, 'taskState' => '-']],
                ],
            ],
        ]);
    }
}
