<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `fmt` must not turn a `{title=...}` attribute into a fence title: the
 * reparsed block would gain a `header` the source never had (CARVE-P11-008).
 */
class ATitleAttributeIsNotAFenceTitleTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'title attribute with a language' => [
                "{.diff title=\"server.js\"}\n```js\nx\n```\n",
                "{.diff title=server.js}\n```js\nx\n```\n",
            ],
            'title attribute without a language' => [
                "{title=\"server.js\"}\n```\nx\n```\n",
                "{title=server.js}\n```\nx\n```\n",
            ],
            'fence title stays a fence title' => [
                "```js \"server.js\"\nx\n```\n",
                "```js \"server.js\"\nx\n```\n",
            ],
            'fence title beside a different title attribute' => [
                "{title=\"a.js\"}\n```js \"b.js\"\nx\n```\n",
                "{title=a.js}\n```js \"b.js\"\nx\n```\n",
            ],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testFmtKeepsTheTitleWhereTheAuthorPutIt(string $source, string $expected): void
    {
        $written = CarveConverter::toCarve($source);

        $this->assertSame($expected, $written);
        $this->assertSame($this->header($source), $this->header($written));
    }

    private function header(string $source): ?string
    {
        $block = (new BlockParser())->parse($source)->getChildren()[0];
        $this->assertInstanceOf(CodeBlock::class, $block);

        return $block->getHeader();
    }
}
