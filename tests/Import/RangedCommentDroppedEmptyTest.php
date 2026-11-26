<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6834: an empty element counts in a ranged comment's range exactly when the import keeps a node for it.
 */
final class RangedCommentDroppedEmptyTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: array<string>}>
     */
    public static function ranges(): array
    {
        $lost = 'import-structure-repaired';

        return [
            'empty em with a title' => ['<em title="x"></em>', "{#n#}y\n", ['import-structure-repaired']],
            'empty span with only an event handler' => ['<span onclick="x"></span>', "{#n#}y\n", ['import-attribute-dropped']],
            'empty bdi' => ['<bdi></bdi>', "{#n#}y\n", ['import-element-mapped']],
            'empty small' => ['<small></small>', "{#n#}y\n", ['import-element-mapped']],
            'empty q' => ['<q></q>', "{#n#}y\n", ['import-element-mapped']],
            'empty strong with an id' => ['<strong id="u"></strong>', "{#n#}y\n", ['import-structure-repaired']],
            'empty mark with a class' => ['<mark class="c"></mark>', "{#n#}y\n", ['import-structure-repaired']],
            'empty sup with lang' => ['<sup lang="de"></sup>', "{#n#}y\n", []],
            'empty link' => ['<a href="u"></a>', "{#n#}y\n", []],
            'empty em in a kept strong' => [
                '<strong>a<em title="x"></em></strong>',
                "{#n#}{*a*}y\n",
                ['import-structure-repaired', $lost],
            ],
            'empty em in an emptied strong' => [
                '<strong><em title="x"></em></strong>',
                "{#n#}y\n",
                ['import-structure-repaired'],
            ],
            'empty em at the range start' => ['<em title="x"></em>a', "{#n#}ay\n", ['import-structure-repaired', $lost]],
            'empty em at the range end' => ['a<em title="x"></em>', "{#n#}ay\n", ['import-structure-repaired', $lost]],
            'kept anchor' => ['<a id="u"></a>', "{#n#}[]{#u}y\n", [$lost]],
            'kept span with a title' => ['<span title="x"></span>', "{#n#}[]{title=x}y\n", [$lost]],
            'kept span around a dropped em' => ['<span title="t"><em></em></span>', "{#n#}[]{title=t}y\n", [$lost]],
            'kept cite with a title' => [
                '<cite title="x"></cite>',
                "{#n#}[]{title=x}y\n",
                ['import-element-mapped', $lost],
            ],
        ];
    }

    /**
     * @param string $inside
     * @param string $source
     * @param array<string> $codes
     */
    #[DataProvider('ranges')]
    public function testRangeCountsOnlyKeptNodes(string $inside, string $source, array $codes): void
    {
        $html = '<p><span class="comment-start" data-comment-id="1">n</span>' . $inside
            . '<span class="comment-end" data-comment-id="1"></span>y</p>';
        $result = Carve::importHtml($html, ['rawHtml' => false]);

        $this->assertSame($source, $result->source);
        $actual = $result->codes();
        sort($actual);
        sort($codes);
        $this->assertSame($codes, $actual);
    }

    public function testDroppedEmptyElementLeavesNoRangeReport(): void
    {
        $html = '<p><span class="comment-start" data-comment-id="1">n</span><em title="x"></em>'
            . '<span class="comment-end" data-comment-id="1"></span>y</p>';
        $result = Carve::importHtml($html, ['rawHtml' => false]);

        $messages = array_map(fn ($diagnostic) => $diagnostic->message, $result->diagnostics);
        $this->assertSame(['Empty HTML em with attributes dropped'], $messages);
    }
}
