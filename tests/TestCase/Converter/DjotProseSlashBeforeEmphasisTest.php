<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotProseSlashBeforeEmphasisTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function slashAndEmphasis(): array
    {
        return [
            'prose before emphasis' => ['Read /folder _em_ x', '<p>Read /folder <em>em</em> x</p>'],
            'code between prose and emphasis' => ['Read /folder `x` _em_ x', '<p>Read /folder <code>x</code> <em>em</em> x</p>'],
            'prose after emphasis' => ['Read /folder _em_ /tail', '<p>Read /folder <em>em</em> /tail</p>'],
            'already escaped prose slash' => ['Read \\/folder _em_ x', '<p>Read /folder <em>em</em> x</p>'],
            'adjacent emphasis spans' => ['Read /folder _a_ _b_ x', '<p>Read /folder <em>a</em> <em>b</em> x</p>'],
            'no converted emphasis' => ['Read /folder x', '<p>Read /folder x</p>'],
            'forced span after prose slash' => ['/_e_ and _f_', '<p>/<em>e</em> and <em>f</em></p>'],
            'forced span after word and slash' => ['p /_e_ q _f_', '<p>p /<em>e</em> q <em>f</em></p>'],
            'prose slash after forced span' => ['_e_/x _f_', '<p><em>e</em>/x <em>f</em></p>'],
            'URL path beside forced spans' => ['https://x.com/_e_/y and _f_', '<p>https://x.com/<em>e</em>/y and <em>f</em></p>'],
            'code between forced spans' => ['/_e_ `x/` _f_/', '<p>/<em>e</em> <code>x/</code> <em>f</em>/</p>'],
            'repeated prose slashes' => ['a/_e_/b/_f_/c/_g_/d', '<p>a/<em>e</em>/b/<em>f</em>/c/<em>g</em>/d</p>'],
        ];
    }

    #[DataProvider('slashAndEmphasis')]
    public function testProseSlashCannotPairWithGeneratedEmphasis(string $djot, string $expected): void
    {
        $carve = (new DjotToCarve())->convert($djot);
        $html = trim((new CarveConverter())->convert($carve));

        self::assertSame($expected, $html);
    }
}
