<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6827: C2-M-113 reports a lost range only when an inline the import keeps stands in it.
 */
final class RangedCommentKeptInlineTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: array<string>}>
     */
    public static function ranges(): array
    {
        $start = '<p><span class="comment-start" data-comment-id="1">n</span>';
        $end = '<span class="comment-end" data-comment-id="1"></span>y</p>';

        return [
            'script' => [$start . '<script>x()</script>' . $end, [], "{#n#}y\n", ['import-element-dropped']],
            'empty nested comment' => [
                $start . '<span class="comment-start" data-comment-id="2"></span>'
                . '<span class="comment-end" data-comment-id="2"></span>' . $end,
                [],
                "{#n#}y\n",
                ['import-element-dropped'],
            ],
            'empty em' => [$start . '<em></em>' . $end, [], "{#n#}y\n", []],
            'raw element with rawHtml off' => [
                $start . '<textarea>t</textarea>' . $end,
                ['rawHtml' => false],
                "{#n#}y\n",
                ['import-element-dropped'],
            ],
            'nested comment with text' => [
                $start . '<span class="comment-start" data-comment-id="2">m</span>'
                . '<span class="comment-end" data-comment-id="2"></span>' . $end,
                [],
                "{#n#}{#m#}y\n",
                ['import-structure-repaired'],
            ],
            'kept text' => [$start . 'a' . $end, [], "{#n#}ay\n", ['import-structure-repaired']],
            'kept text beside a script' => [
                $start . '<script>x()</script>a' . $end,
                [],
                "{#n#}ay\n",
                ['import-element-dropped', 'import-structure-repaired'],
            ],
        ];
    }

    /**
     * @param string $html
     * @param array<string, mixed> $options
     * @param string $source
     * @param array<string> $codes
     */
    #[DataProvider('ranges')]
    public function testRangeCountsOnlyKeptInlines(string $html, array $options, string $source, array $codes): void
    {
        $result = Carve::importHtml($html, $options);

        $this->assertSame($source, $result->source);
        $actual = $result->codes();
        sort($actual);
        $this->assertSame($codes, $actual);
    }
}
