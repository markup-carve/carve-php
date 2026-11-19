<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Include;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\TestCase;

final class IncludeCoreTest extends TestCase
{
    public function testC2N003InvalidOptionShapeIsRefused(): void
    {
        foreach (['relative/root', ['resolver' => 'not callable'], ['resolver' => static fn (): array => [], 'depth' => 17]] as $value) {
            try {
                Carve::parse('', ['includes' => $value]);
                $this->fail('The includes option was accepted.');
            } catch (OptionError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testC2N078StrictLossesAndC2N079Roundtrip(): void
    {
        $unresolved = Carve::renderWithDiagnostics('{{ missing }}', ['strictLosses' => true]);
        $this->assertSame('', $unresolved->html);
        $this->assertContains('include-unresolved', $unresolved->codes());
        foreach ($unresolved->diagnostics as $diagnostic) {
            if ($diagnostic->code === 'include-unresolved') {
                $this->assertSame('error', $diagnostic->severity);
            }
        }
        $resolver = static fn (array $request): array => [
            'text' => $request['path'] === 'a' ? '{{ b }}' : 'B',
            'id' => $request['path'],
        ];
        $expanded = Carve::renderWithDiagnostics('{{ a }}', ['includes' => $resolver, 'roundtrip' => true]);
        $this->assertSame('<p>B</p>', $expanded->html);
        $this->assertSame(['include-expanded-roundtrip'], $expanded->codes());
    }

    public function testC2N072IncludeAttributesDoNotReserveAnId(): void
    {
        $resolver = static fn (array $request): array => ['text' => '= Intro', 'id' => 'child'];
        $result = Carve::renderWithDiagnostics("{#Intro}\n{{ child }}", ['includes' => $resolver]);
        $this->assertSame("<section id=\"Intro\">\n  <h1>Intro</h1>\n</section>", $result->html);
    }

    public function testC2N074CaptionOfAnUnexpandedIncludeIsPlainText(): void
    {
        $html = Carve::toHtml("{table}\n{{ a.csv @code:csv }}\n^ Table #: *Plans*\n^ @code more");
        $this->assertSame("<p>{{ a.csv @code:csv }}</p>\n<p>Table 1: Plans ^ @code more</p>", $html);
    }

    public function testC2N020SelectedIncludeDoesNotChangeNestedParse(): void
    {
        $seen = [];
        $extension = new ExtensionDefinition(
            'test.ping',
            '1',
            matcher: ['form' => 'div', 'name' => 'ping'],
            transform: static function () use (&$seen): null {
                $seen[] = Carve::parse("---\nx\n---")->children[0]->type;

                return null;
            },
        );
        $resolver = static fn (): array => ['text' => "one\n\n::: ping\nx\n:::\n", 'id' => 'f'];
        Carve::parse('{{ f @lines:3-5 }}', ['includes' => $resolver, 'extensions' => [$extension]]);
        $this->assertNotSame([], $seen);
        $this->assertSame(array_fill(0, count($seen), 'frontmatter'), $seen);
    }

    public function testC2N048ClampIsReportedAtTheHeadingInItsFile(): void
    {
        $resolver = static fn (array $request): array => ['text' => "# A\n\n###### B", 'id' => 'child'];
        $result = Carve::parseWithDiagnostics('{{ child @shift:1 }}', ['includes' => $resolver]);
        $this->assertCount(1, $result->diagnostics);
        $this->assertSame('include-heading-clamp', $result->diagnostics[0]->code);
        $this->assertSame('child', $result->diagnostics[0]->file);
        $this->assertSame('/children/0/children/1', $result->diagnostics[0]->path);
    }

    public function testC2B085AFileIncludedTwiceRepeatsNoDefinition(): void
    {
        $files = ['a.crv' => "[x][]\n\n[x]: /a\n", 'b.crv' => "[x][]\n\n[x]: /b\n[x]: /c\n"];
        $resolver = static fn (array $request): array => ['text' => $files[$request['path']], 'id' => $request['path']];
        $twice = Carve::renderWithDiagnostics("{{ a.crv }}\n\n{{ a.crv }}\n", ['includes' => $resolver]);
        $this->assertSame("<p><a href=\"/a\">x</a></p>\n<p><a href=\"/a\">x</a></p>", $twice->html);
        $this->assertSame([], $twice->codes());
        $once = Carve::parseWithDiagnostics("{{ b.crv }}\n", ['includes' => $resolver]);
        $this->assertSame(['duplicate-link-reference-definition'], $once->codes());
        $this->assertSame(['b.crv', '/children/0/children/2'], [$once->diagnostics[0]->file, $once->diagnostics[0]->path]);
    }
}
