<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L1b/L1c: the blank before an invisible line still separates
 * sibling items in a list nested in a description (carve-php#4279).
 */
class AnInvisibleLineAfterADescriptionSublistKeepsTheListLooseTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invisibleLines(): array
    {
        return [
            'link definition' => ['[x]: /u'],
            'attribute line' => ['{.k}'],
            'comment' => ['%% c'],
        ];
    }

    #[DataProvider('invisibleLines')]
    public function testTheFollowingSiblingStaysInOneLooseList(string $invisible): void
    {
        $source = ":: t\n: x\n\n  - a\n    - b\n\n    {$invisible}\n  - sib\n";
        $expected = <<<'HTML'
<dl>
  <dt>t</dt>
  <dd>
    <p>x</p>
    <ul>
      <li><p>a</p>
        <ul>
          <li>b</li>
        </ul>
      </li>
      <li><p>sib</p></li>
    </ul>
  </dd>
</dl>
HTML;

        self::assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    public function testAParagraphBehindTheInvisibleLineStaysInTheItem(): void
    {
        $source = ":: t\n: x\n\n  - a\n    - b\n\n    [x]: /u\n    tail\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("<li><p>a</p>\n", $html);
        self::assertStringContainsString("<p>tail</p>\n      </li>", $html);
    }

    public function testACommentOneColumnBeforeTheItemContentDoesNotCancelTheBlank(): void
    {
        $source = ":: t\n: x\n\n  - a\n    - b\n\n   %% c\n  - sib\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("<li><p>a</p>\n", $html);
        self::assertStringContainsString('<li><p>sib</p></li>', $html);
    }
}
