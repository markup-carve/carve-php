<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * carve#6788: revision keys on insert, delete and editorial comment (C2-H-210, C2-M-044, C2-M-113).
 */
final class RevisionMetadataTest extends TestCase
{
    public function testTreeWriterEscapesABraceBehindAnEditorialComment(): void
    {
        $tree = [

            'type' => 'document',
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'critic_comment', 'value' => 'note'],
                        ['type' => 'text', 'value' => '{.k} c'],
                    ],
                ],
            ],
        ];

        $this->assertSame("{#note#}\\{.k} c\n", Carve::write($tree));
    }

    public function testAuthoredTargetKeyIsKeptOnImport(): void
    {
        $result = Carve::importHtml('<ins author="Ann" data-author="Bob">x</ins>');

        $this->assertSame("{+x+}{author=Ann data-author=Bob}\n", $result->source);
    }

    public function testRangedCommentWithoutEndLosesNothing(): void
    {
        $result = Carve::importHtml('<p>a <span class="comment-start" data-author="Ann">n <b>x</b></span>b</p>');

        $this->assertSame("a {#n x#}{author=Ann}b\n", $result->source);
        $this->assertSame([], $result->codes());
    }

    public function testRangedCommentEndInAnotherElementIsFound(): void
    {
        $html = '<p><span class="comment-start" data-comment-id="1">n</span>'
            . '<em>a<span class="comment-end" data-comment-id="1"></span></em></p>';
        $result = Carve::importHtml($html);

        $this->assertSame("{#n#}/a/\n", $result->source);
        $this->assertSame(['import-structure-repaired'], $result->codes());
    }
}
