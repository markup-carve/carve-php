<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1743. A local hard-break block nested in another one used to close
 * its parent early, leaving the parent's own closer to open a container of its
 * own - which, never closed, ran to end of input and rendered as a stray empty
 * div.
 *
 * The assertions are on RENDERED HTML rather than on the tree, because the
 * leftover is only visible as output: the nesting itself was always right.
 */
class ANestedHardBreakFenceLeavesNothingBehindTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    public function testNestsInItselfLeavingNothingBehind(): void
    {
        $expected = <<<'HTML'
        <div class="hardbreaks">
          <p>outer</p>
          <div class="hardbreaks">
            <p>inner</p>
          </div>
        </div>
        HTML;

        $this->assertSame($expected, $this->html("::: \\\n outer\n\n::: \\\n inner\n:::\n:::\n"));
    }

    /**
     * THREE deep, because an opener the collector cannot count is off by one
     * per level: two levels and three levels fail differently, and only the
     * deeper one shows that the fix counts rather than special-cases.
     */
    public function testNestsThreeDeepLeavingNothingBehind(): void
    {
        $expected = <<<'HTML'
        <div class="hardbreaks">
          <p>outer</p>
          <div class="hardbreaks">
            <p>middle</p>
            <div class="hardbreaks">
              <p>inner</p>
            </div>
          </div>
        </div>
        HTML;

        $source = "::: \\\nouter\n\n::: \\\nmiddle\n\n::: \\\ninner\n:::\n:::\n:::\n";

        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The defect was in the GENERIC opener, which is what the nesting-aware
     * body collector counts nested openers with. Every other member of the
     * colon-fence family was already admitted there; the backslash was the one
     * the collector could not see.
     */
    public function testTheGenericOpenerAdmitsEveryColonFenceSpelling(): void
    {
        $parser = new FencedBlockParser();

        $spellings = [
            'bare div' => ':::',
            'labeled div' => ':::[First]',
            'typed div' => '::: warning',
            'admonition with a title' => '::: note "Title"',
            'figure group' => '::: figure',
            'line block' => '::: |',
            'fenced block quote' => '::: >',
            'local hard-break block' => '::: \\',
        ];

        foreach ($spellings as $what => $line) {
            $this->assertNotNull($parser->parseDivFenceOpener($line), $what . ' must be a countable opener');
        }
    }

    /**
     * A nested fence one colon wider than its parent is the form the canonical
     * writer emits, and it has to survive the same way a constant-width nest
     * does.
     */
    public function testNestsAtAWiderInnerFenceLeavingNothingBehind(): void
    {
        $expected = <<<'HTML'
        <div class="hardbreaks">
          <p>outer</p>
          <div class="hardbreaks">
            <p>inner</p>
          </div>
        </div>
        HTML;

        $this->assertSame($expected, $this->html("::: \\\nouter\n\n:::: \\\ninner\n::::\n:::\n"));
    }
}
