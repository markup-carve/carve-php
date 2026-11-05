<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A consumed `+` leaves no node (CARVE-P11-040), so the writer parts the blocks
 * around it with an attaching `+` or a blank line, never a `%%` line.
 */
class AConsumedPlusIsWrittenBackAsAPlusTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'between two paragraphs' => ["- intro\n+\n  more\n", "- intro\n+\nmore\n"],
            'after a fence in a quoted item' => ["> - ```\n> +\n> - s\n", "> - ```\n>   ```\n> - s\n"],
            'two in a loose item' => ["- q\n+\n+\n\n- r\n", "- q\n\n- r\n"],
            'between two quotes' => ["- > p\n+\n+\n> z\n", "- > p\n+\n> z\n"],
            'after an authored comment' => ["- intro\n%% x\n+\n  more\n", "- intro\n%% x\n+\nmore\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testTheWrittenSourceReadsBackToTheSameDocument(string $source, string $expected): void
    {
        $written = CarveConverter::toCarve($source);
        $converter = new CarveConverter();

        $this->assertSame($expected, $written);
        $this->assertSame($converter->convert($source), $converter->convert($written));
        $this->assertSame($written, CarveConverter::toCarve($written));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wireSources(): array
    {
        return [
            'between two paragraphs' => ["- intro\n+\n  more\n"],
            'between two quotes' => ["- > p\n+\n+\n> z\n"],
            'in a nested item' => ["- a\n  - intro\n  +\n    more\n"],
        ];
    }

    /**
     * A tree read from the wire no longer knows the comment was a `+`; a
     * comment run still parts the blocks around it.
     */
    #[DataProvider('wireSources')]
    public function testATreeFromTheWireKeepsItsBlocksApart(string $source): void
    {
        $codec = new AstCodec();
        $converter = new CarveConverter();
        $tree = $codec->decodeJson($codec->encodeJson($converter->parse($source)));
        $written = (new CarveRenderer())->render($tree);

        $this->assertSame($converter->convert($source), $converter->convert($written));
    }
}
