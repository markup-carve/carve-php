<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\PlusBulletExtension;
use PHPUnit\Framework\TestCase;

class PlusBulletExtensionTest extends TestCase
{
    public function testPlusIsNotABulletByDefault(): void
    {
        $converter = new CarveConverter();

        $html = $converter->convert("+ Apple\n+ Banana\n");

        $this->assertStringNotContainsString('<li>', $html);
        $this->assertStringContainsString('<p>', $html);
    }

    public function testPlusBecomesBulletWithExtension(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new PlusBulletExtension());

        $html = $converter->convert("+ Apple\n+ Banana\n");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>Apple</li>', $html);
        $this->assertStringContainsString('<li>Banana</li>', $html);
    }

    public function testPlusBulletMixesWithDashAndStar(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new PlusBulletExtension());

        $html = $converter->convert("- Dash\n\n+ Plus\n\n* Star\n");

        $this->assertSame(3, substr_count($html, '<li>'));
        $this->assertStringContainsString('<li>Plus</li>', $html);
    }

    public function testPlusTaskListWithExtension(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new PlusBulletExtension());

        $html = $converter->convert("+ [ ] todo\n+ [x] done\n");

        $this->assertStringContainsString('<input type="checkbox" disabled aria-label="todo"> todo', $html);
        $this->assertStringContainsString('<input type="checkbox" checked disabled aria-label="done"> done', $html);
    }

    public function testLonePlusStaysContinuationMarkerNotBullet(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new PlusBulletExtension());

        // A content-less `+` must NOT become a list item -- it stays the
        // list-continuation marker, the whole reason `+` was dropped as a bullet.
        $html = $converter->convert("+\n");

        $this->assertStringNotContainsString('<li>', $html);
        $this->assertStringContainsString('<p>+</p>', $html);
    }

    public function testPlusContinuationStillWorksInsideList(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new PlusBulletExtension());

        // Lone `+` continues the preceding bullet item; enabling `+` bullets
        // must not break this.
        $html = $converter->convert("- Apple\n\n+\n\n  more text for apple\n");

        $this->assertSame(1, substr_count($html, '<ul>'));
        $this->assertStringContainsString('more text for apple', $html);
        $this->assertStringNotContainsString('<li>+</li>', $html);
    }

    public function testTrailingSpaceOnlyPlusIsNotABullet(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new PlusBulletExtension());

        // `+ ` with only whitespace after must not become a list item either.
        $html = $converter->convert("+   \n");

        $this->assertStringNotContainsString('<li>', $html);
    }

    public function testPlusFirstBlockItemMatchesDashBehavior(): void
    {
        $withDash = (new CarveConverter())->convert("- +\n\n  | table |\n");

        $converter = new CarveConverter();
        $converter->addExtension(new PlusBulletExtension());
        $withPlus = $converter->convert("+ +\n\n  | table |\n");

        // `+ +` is the existing first-block-item syntax (`<bullet> +`) applied
        // to the plus marker -- a plus bullet whose body is the flush-left
        // block that follows. It mirrors `- +` / `* +` exactly; a literal-only
        // `+` item is unrepresentable for any marker by design.
        $this->assertStringContainsString('<table>', $withPlus);
        $this->assertSame($withDash, $withPlus);
    }

    public function testAbuttingAttributesAttachToAPlusBullet(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new PlusBulletExtension());

        $withPlus = $converter->convert("+{.k} x\n");
        $withDash = (new CarveConverter())->convert("-{.k} x\n");

        $this->assertSame($withDash, $withPlus);
        $this->assertStringContainsString('<li class="k">x</li>', $withPlus);
    }
}
