<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5700, C2-W-088: a bracket, a parenthesis or a sigil beside a node takes a backslash only where the bare
 * text forms a construct the tree lacks. Each input is the form carve-js writes, so each is a fixed point.
 */
final class BracketReadBackTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function bare(): array
    {
        return [
            'bracket pair before a footnote reference' => ["a [x][^n]\n\n[^n]: f\n"],
            'pair before a braced comment' => ["a [x]{% c %}\n"],
            'pair before a critic node' => ["a [x]{=ins=}\n"],
            'unclosed bracket before code' => ["a [x`c`\n"],
            'unclosed bracket before strong' => ["a [x *s*\n"],
            'parenthesis behind a footnote reference' => ["a [^x](p)\n"],
            'parenthesis behind a full reference' => ["a [x][l](u)\n"],
            'empty pair before a braced comment' => ["a []{% c %}\n"],
            'nested pair in a quote' => ["> a [[x]] [^n]\n\n[^n]: f\n"],
            'pair in a task item' => ["- [ ] a [x] *s*\n"],
            'pair before braces that open nothing' => ["a [x]{>>c<<}\n"],
            'footnote-shaped brackets that pair with nothing' => ["a ^[x[^n]\n"],
            'sigil before a span' => ["a ![s]{.c}\n"],
            'dollar before display math' => ["a \$\$\$`m`\n"],
            'parenthesis behind a collapsed reference' => ["a [l][](p)\n\n[l]: /u\n"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escaped(): array
    {
        return [
            'link shape' => ["\\[x](u)\n"],
            'image shape before a link' => ["a \\![l](u)\n"],
            'inline footnote shape' => ["^\\[x]\n"],
            'tail behind an inline footnote with a bracket' => ["a ^[x \\[l]\\(u)\n"],
            'task box reports its kind' => ["- \\[ ] a \\[x]{% c %}\n"],
            'footnote definition keeps its line kind' => ["\\[^n]: f [y]\n"],
            'task box before a bracket' => ["- \\[ ] [y] b [z]\n"],
            'reference before a span' => ["a \\[x][b]{.c}\n"],
            'lint pair behind a footnote shape' => ["a \\[^x]\\_u_\n"],
            'image shape under a definition' => ["a !\\[x] %% c\n\n[x]: /x\n"],
        ];
    }

    #[DataProvider('bare')]
    #[DataProvider('escaped')]
    public function testWritesBackUnchanged(string $input): void
    {
        $sidecar = Carve::formatWithDiagnostics($input);
        $this->assertSame($input, $sidecar->source);
        $this->assertSame([], $sidecar->diagnostics);
        $this->assertSame($input, Carve::format($input, ['normalize' => true]));
        $this->assertSame($input, Carve::write(Carve::parse($input)));
    }
}
