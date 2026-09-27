<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownGuardsEmailAutolinksTest extends TestCase
{
    public function testBareEmailTextFixture(): void
    {
        $carve = "Mail a@b.co, mailto:c@d.co and xmpp:e@f.co.\n\n"
            . "Not a@b, `g@h.co`, or [i@j.co](/k).\n";
        $markdown = "Mail a<!---->@b.co, mailto:c<!---->@d.co and xmpp:e<!---->@f.co.\n\n"
            . "Not a@b, `g@h.co`, or [i@j.co](/k).\n";

        $this->assertSame($markdown, CarveConverter::markdown()->convert($carve));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'plain' => ['a@b.co', 'a<!---->@b.co'],
            'mailto' => ['mailto:c@d.co', 'mailto:c<!---->@d.co'],
            'xmpp' => ['xmpp:e@f.co', 'xmpp:e<!---->@f.co'],
            'no domain dot' => ['a@b', 'a@b'],
            'code' => ['`g@h.co`', '`g@h.co`'],
            'link text' => ['[i@j.co](/k)', '[i@j.co](/k)'],
            'split spans' => ['[a]{.x}[@]{.y}[b.co]{.z}', 'a<!---->@b.co'],
            'split domain' => ['[a@b]{.x}[.co]{.y}', 'a<!---->@b.co'],
            'line break' => ["a@b\n.co", "a@b\n.co"],
            'no local part' => ['@b.co', '@b.co'],
            'empty label' => ['a@b..co', 'a@b..co'],
            'trailing dot' => ['a@b.co.', 'a<!---->@b.co.'],
            'trailing hyphen' => ['a@b.co-', 'a@b.co-'],
            'trailing underscore' => ['a@b.co_', 'a@b.co_'],
            'last label invalid' => ['a@b.co.d-', 'a@b.co.d-'],
            'domain punctuation' => ['a@b-c_d.e', 'a<!---->@b-c_d.e'],
            'local punctuation' => ['a.b+c-d@e.f', 'a.b+c-d<!---->@e.f'],
        ];
    }

    #[DataProvider('cases')]
    public function testOnlyMatchingTextIsGuarded(string $carve, string $markdown): void
    {
        $this->assertSame($markdown . "\n", CarveConverter::markdown()->convert($carve . "\n"));
    }
}
