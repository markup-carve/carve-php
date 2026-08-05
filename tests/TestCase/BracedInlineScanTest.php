<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the closer short-circuits in parseBracedInline() and
 * parseEditorialComment(). Each braced construct ({+ins+}, {-del-}, {~sub~},
 * {~a~>b~}, {#comment#}, and the forced-emphasis family {*..*}/{^..^}/{,..,}/
 * {_.._}/{=..=}) scans forward for its own fixed `marker}` / `#}` closer. A run
 * of openers with no closer would otherwise walk to end-of-text at every opener
 * -> O(n^2); a memoized strrpos bails in O(1). Output must be byte-identical.
 */
#[Group('scaling')]
class BracedInlineScanTest extends TestCase
{
    use ScalingGuardTrait;

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testUnclosedInsertStaysLiteral(): void
    {
        $this->assertSame('<p>{+{+</p>', trim($this->converter->convert('{+{+')));
    }

    public function testUnclosedDeleteStaysLiteral(): void
    {
        $this->assertSame('<p>{-{-</p>', trim($this->converter->convert('{-{-')));
    }

    public function testUnclosedForcedStrongFallsThroughToBareStrong(): void
    {
        // No `*}` closer -> parseBracedInline declines (byte-identical to the
        // old to-end-of-text scan); the bare `*` strong path then handles it.
        $this->assertSame('<p>{<strong>{</strong></p>', trim($this->converter->convert('{*{*')));
    }

    public function testClosedInsertStillParses(): void
    {
        $this->assertSame('<p><ins>x</ins></p>', trim($this->converter->convert('{+x+}')));
    }

    public function testClosedSubstitutionStillParses(): void
    {
        $this->assertSame(
            '<p><del>a</del><ins>b</ins></p>',
            trim($this->converter->convert('{~a~>b~}')),
        );
    }

    public function testClosedEditorialCommentStillParses(): void
    {
        $this->assertSame(
            '<p><span class="critic-comment">note</span></p>',
            trim($this->converter->convert('{#note#}')),
        );
    }

    public function testClosedForcedSuperscriptStillParses(): void
    {
        $this->assertSame('<p><sup>x</sup></p>', trim($this->converter->convert('{^x^}')));
    }

    /**
     * @param string $fragment
     */
    #[DataProvider('bracedShapeProvider')]
    public function testBracedScanScalesLinearly(string $fragment): void
    {
        $this->assertScanScalesLinearly($this->converter, $fragment, '', "'{$fragment}'");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bracedShapeProvider(): array
    {
        return [
            'insert' => ['{+'],
            'delete' => ['{-'],
            'strike-sub' => ['{~'],
            'substitution' => ['{~a~>'],
            'editorial-comment' => ['{#'],
            'forced-strong' => ['{*'],
            'forced-super' => ['{^'],
            'forced-sub' => ['{,'],
            'forced-underline' => ['{_'],
            'forced-highlight' => ['{='],
        ];
    }
}
