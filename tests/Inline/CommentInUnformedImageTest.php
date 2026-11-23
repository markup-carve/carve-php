<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6442, C2-I-023, C2-I-024, C2-I-074: `%%` is alt source only inside an image that forms; an opener that forms
 * no image leaves it a line comment.
 */
final class CommentInUnformedImageTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'comment-only line' => ["![a\n%% c\n", '<p>![a</p>'],
            'no link tail' => ["![a %% b](a b)\n", '<p>![a</p>'],
            'link before the comment' => ["![[](u) x\n%% c\n", '<p>![<a href="u"></a> x</p>'],
            'inside a link frame' => ["![a [b %% c](u)\n", '<p>![a [b</p>'],
            'image that forms keeps it' => ["![a %% b](u)\n", '<img src="u" alt="a %% b">'],
            'outer image forms around a link' => ["![a [b %% c](v)](u)\n", '<img src="u" alt="a [b %% c](v)">'],
        ];
    }

    #[DataProvider('cases')]
    public function testRenders(string $source, string $html): void
    {
        $this->assertSame($html, Carve::toHtml($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unchanged(): array
    {
        return [
            'comment-only line' => ["![a\n%% c\n"],
            'no link tail' => ["![a %% b](a b)\n"],
            'link before the comment' => ["![[](u) x\n%% c\n"],
            'opener before a comment' => ["[a\n%% b\n"],
        ];
    }

    #[DataProvider('unchanged')]
    public function testWritesBackUnchanged(string $source): void
    {
        $sidecar = Carve::formatWithDiagnostics($source);
        $this->assertSame($source, $sidecar->source);
        $this->assertSame([], $sidecar->diagnostics);
    }
}
