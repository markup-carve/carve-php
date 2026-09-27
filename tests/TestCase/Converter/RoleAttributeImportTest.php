<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoleAttributeImportTest extends TestCase
{
    public function testRoleSurvivesOnBlockContainer(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<div role="search" class="b"><p>x</p></div>');

        self::assertSame("{role=search}\n::: b\nx\n:::", trim($result->value));
        self::assertSame([], $result->diagnostics);
    }

    public function testRoleSurvivesOnInlineSpan(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><span role="img" aria-label="c">x</span></p>');

        self::assertSame('[x]{role=img aria-label=c}', trim($result->value));
        self::assertSame([], $result->diagnostics);
    }

    #[DataProvider('authoredRoles')]
    public function testAuthoredRolesSurviveImportAndRendering(string $html, string $role): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $importer = new HtmlToCarve(importMode: $mode);
            $result = $importer->convertWithReport($html);
            self::assertSame([], $result->diagnostics);
            self::assertStringContainsString('role=' . $role, $result->value);
            self::assertStringContainsString('role="' . $role . '"', (new CarveConverter())->convert($result->value));
            $ast = $importer->convertToAstWithReport($html);
            self::assertSame([], $ast->diagnostics);
            self::assertStringContainsString('"role":"' . $role . '"', json_encode($ast->value, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authoredRoles(): array
    {
        return [
            'core warning' => ['<aside class="admonition warning" role="alert"><p>x</p></aside>', 'alert'],
            'core note' => ['<aside class="admonition note" role="note"><p>x</p></aside>', 'note'],
            'core sidecar' => ['<aside class="admonition warning" data-djot-admonition-type="warning" role="alert"><p>x</p></aside>', 'alert'],
            'paragraph' => ['<p role="note">x</p>', 'note'],
            'heading' => ['<h2 role="status">x</h2>', 'status'],
            'code' => ['<pre role="region"><code>x</code></pre>', 'region'],
            'image code' => ['<pre role="img">x</pre>', 'img'],
            'image container' => ['<div class="diagram" role="img"><p>x</p></div>', 'img'],
            'math override' => ['<span class="math inline" role="img">\\(x\\)</span>', 'img'],
            'tabs override' => ['<div class="tabs" role="region"><p>x</p></div>', 'region'],
            'panel override' => ['<div class="code-group-panel" role="region"><p>x</p></div>', 'region'],
            'admonition override' => ['<div class="admonition warning" role="status"><p>x</p></div>', 'status'],
            'unmarked custom extension' => ['<div class="callout custom" role="note"><p>x</p></div>', 'note'],
            'unrelated classes' => ['<div class="custom admonition warning" role="alert"><p>x</p></div>', 'alert'],
        ];
    }

    #[DataProvider('generatedRoles')]
    public function testGeneratedRolesStayOutOfSource(string $html, string $expected): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        self::assertSame($expected, trim($result->value));
        self::assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function generatedRoles(): array
    {
        return [
            'tabs' => ['<div class="tabs" role="group"><p>x</p></div>', "::: tabs\nx\n:::"],
            'aria tabs' => ['<div class="tabs" role="tablist"><p>x</p></div>', "::: tabs\nx\n:::"],
            'code group' => ['<div class="code-group" role="group"><p>x</p></div>', "::: code-group\nx\n:::"],
            'panel' => ['<div class="tabs-panel" role="tabpanel"><p>x</p></div>', "::: tabs-panel\nx\n:::"],
            'code panel' => ['<div class="code-group-panel" role="group"><p>x</p></div>', "::: code-group-panel\nx\n:::"],
            'extension warning' => ['<div class="admonition warning" role="alert"><p>x</p></div>', "{.warning}\n::: admonition\nx\n:::"],
            'extension custom type' => ['<div class="admonition custom" role="note"><p>x</p></div>', "{.custom}\n::: admonition\nx\n:::"],
            'extension custom sidecar' => ['<div data-djot-admonition-type="custom" role="note"><p>x</p></div>', "::: custom\nx\n:::"],
            'math' => ['<span class="math inline" role="math">\\(x\\)</span>', '$`x`'],
        ];
    }
}
