<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AuthoredBaseNonDefinitionEdgesTest extends TestCase
{
    public function testFenceShapedPayloadIsNotRebasedAsASecondBlock(): void
    {
        $body = "~~~~\n ```\n~~~~";
        $exact = CarveConverter::toCarve($this->footnote($body, 2));
        $over = CarveConverter::toCarve($this->footnote($body, 3));

        self::assertSame($exact, $over);
        self::assertSame($exact, CarveConverter::toCarve($exact));
        self::assertStringContainsString("\n  ````\n   ```\n  ````\n", $over);
    }

    public function testNestedFootnoteKeepsItsBody(): void
    {
        $source = "[^outer]: intro\n\n  [^inner]: note\n\n  see[^inner]\n\nsee[^outer]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<li id="fn2">', $html);
        self::assertStringContainsString('<p>note<a href="#fnref2"', $html);
        self::assertStringNotContainsString('{empty}', CarveConverter::toCarve($source));
    }

    public function testNestedFootnoteGroupUsesItsOuterAuthoredBase(): void
    {
        $body = "[^inner]: note\n\nsee[^inner]";
        $exact = $this->footnote($body, 2);
        $over = $this->footnote($body, 5);

        self::assertSame(CarveConverter::toCarve($exact), CarveConverter::toCarve($over));
        self::assertSame(
            (new CarveConverter())->convert($exact),
            (new CarveConverter())->convert($over),
        );
    }

    public function testFencedPercentBlockDoesNotLoosenAList(): void
    {
        $blocks = [
            "%%%\nhidden\n%%%",
            "%%% hardbreak\na\n%%%",
            "%%% verse\na\n%%%",
        ];
        foreach ($blocks as $block) {
            $source = "- intro\n\n" . $this->indent($block, 3) . "\n";
            $html = (new CarveConverter())->convert($source);
            self::assertStringContainsString('<li>intro', $html, $block);
            self::assertStringNotContainsString('<li><p>intro</p>', $html, $block);
        }
    }

    private function footnote(string $body, int $width): string
    {
        return "[^n]: intro\n\n" . $this->indent($body, $width) . "\n\nsee[^n]\n";
    }

    private function indent(string $source, int $width): string
    {
        $prefix = str_repeat(' ', $width);

        return implode("\n", array_map(static fn (string $line): string => $prefix . $line, explode("\n", $source)));
    }
}
