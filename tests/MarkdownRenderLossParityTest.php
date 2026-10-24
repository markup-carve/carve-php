<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownRenderLossParityTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function sources(): array
    {
        return [
            'container' => ["::: box\nhi\n:::\n", ['markdown-attributes-dropped', 'markdown-container-flattened']],
            'caption' => ["![x](a)\n^ caption\n", ['markdown-caption-flattened']],
            'attributes' => ["{#x}\nhello\n", ['markdown-attributes-dropped']],
            'unattached' => ["{#x}\n", ['markdown-attributes-dropped']],
            'raw block' => ["```=html\n<b>x</b>\n```\n", ['raw-html-escaped']],
        ];
    }

    #[DataProvider('sources')]
    public function testLossesHaveSourcePositionsAndLeaveMarkdownUntouched(string $source, array $codes): void
    {
        $converter = new CarveConverter(renderer: new MarkdownRenderer());
        $report = $converter->convertWithReport($source);
        self::assertSame($converter->convert($source), $report->value);
        self::assertSame($codes, array_column($report->losses, 'code'));
        self::assertSame(count($codes), $report->totalLosses);
        foreach ($report->losses as $loss) {
            self::assertSame('markdown', $loss['target']);
            self::assertArrayHasKey('startOffset', $loss['pos']);
        }
    }

    public function testRoundtripCarriesAttributesAndCaptions(): void
    {
        $renderer = (new MarkdownRenderer())->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport("{#x}\nhello\n\n![x](a)\n^ caption\n");
        self::assertSame(0, $report->totalLosses);
    }

    public function testRawInlineUsesInlineNodeType(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => [['type' => 'raw_inline', 'format' => 'html', 'content' => '<b>x</b>']]]],
        ]);
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->renderWithReport($document);
        self::assertSame('raw-html-escaped', $report->losses[0]['code']);
        self::assertSame('inline', $report->losses[0]['nodeType']);
    }
}
