<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ALineCommentDropsTrailingLayoutWhitespaceTest extends TestCase
{
    public function testLineCommentContentUsesOneSeparatorAndNoTrailingAsciiWhitespace(): void
    {
        $cases = [
            ["%%  x \t\n", ' x'],
            ["%% x \r\n", 'x'],
            [":::\n%%. \n:::\n", '.'],
            ["x %%  y \t\n", ' y'],
            ["::: |\na\n%%  z \t\nb\n:::\n", ' z'],
            ["%% \u{00A0}\n", "\u{00A0}"],
            ["%%\vx\n", "\vx"],
            ["%%\v x\n", "\v x"],
        ];
        foreach ($cases as [$source, $expected]) {
            $this->assertSame([$expected], $this->commentContents($source), $source);
            $written = CarveConverter::toCarve($source);
            $this->assertSame([$expected], $this->commentContents($written), $written);
            $converter = CarveConverter::create();
            $this->assertSame($converter->convert($source), $converter->convert($written), $source);
        }
    }

    /**
     * @return list<string>
     */
    private function commentContents(string $source): array
    {
        $tree = (new AstCodec())->encode(CarveConverter::create()->parse($source));
        $contents = [];
        $visit = static function (array $node) use (&$visit, &$contents): void {
            if (($node['type'] ?? null) === 'comment') {
                $contents[] = $node['content'];
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $visit($value);
                }
            }
        };
        $visit($tree);

        return $contents;
    }
}
