<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6682: `unattached-caption` reads a line without its line comment, so a caret line holding only a line
 * comment keeps a bare caret (C2-W-146, C2-W-088). Below an image the line attaches and keeps its backslash.
 */
final class WriterCaretCommentLineTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function lines(): array
    {
        return [
            'after a paragraph' => ["x\n\\^ %%\n", "x\n^ %%\n"],
            'comment text' => ["x\n\\^ %% a\n", "x\n^ %% a\n"],
            'a line after it' => ["x\n\\^ %%\ny\n", "x\n^ %%\ny\n"],
            'in a quote' => ["> x\n> \\^ %% c\n", "> x\n> ^ %% c\n"],
            'in a nested item' => ["- - x\n    \\^ %%\n", "- - x\n    ^ %%\n"],
            'apart from an image' => ["![a](u)\n\n\\^ %%\n", "![a](u)\n\n^ %%\n"],
            'below an image' => ["![a](u)\n\\^ %%\n", "![a](u)\n\\^ %%\n"],
            'text before the comment' => ["x\n^ a %%\n", "x\n\\^ a %%\n"],
            'a braced comment' => ["x\n^ {%c%}\n", "x\n\\^ {% c %}\n"],
            'escaped comment marks' => ["x\n^ \\%% a\n", "x\n\\^ \\%% a\n"],
        ];
    }

    #[DataProvider('lines')]
    public function testCaretLine(string $input, string $written): void
    {
        $this->assertSame($written, Carve::format($input));
        $this->assertSame($written, Carve::write(TreeEncoder::decodedForWriter(Carve::parse($input))));
    }
}
