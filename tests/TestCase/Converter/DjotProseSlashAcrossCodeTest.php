<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotProseSlashAcrossCodeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function prose(): array
    {
        return [
            'code span between path and URL' => [
                "Edit the /trunk folder with this schema:\n`svn:externals svn_url local_folder`\nResult: see http://example.org/x",
                "<p>Edit the /trunk folder with this schema:\n<code>svn:externals svn_url local_folder</code>\nResult: see http://example.org/x</p>",
            ],
            'inline code between slashes' => [
                'Read /folder `code` and other/ x',
                '<p>Read /folder <code>code</code> and other/ x</p>',
            ],
            'slash inside code stays protected' => [
                'Read `/folder` and other/ x',
                '<p>Read <code>/folder</code> and other/ x</p>',
            ],
            'link destination stays protected' => [
                'Read /folder [link](https://example.org/a/b) and other/ x',
                '<p>Read /folder <a href="https://example.org/a/b">link</a> and other/ x</p>',
            ],
            'autolink between slashes' => [
                '/a <http://x/y> c/ d',
                '<p>/a <a href="http://x/y">http://x/y</a> c/ d</p>',
            ],
            'code with attributes between slashes' => [
                'Read /folder `x`{.c} and other/ x',
                '<p>Read /folder <code class="c">x</code> and other/ x</p>',
            ],
            'already escaped slash' => [
                'Read \\/folder `code` and other/ x',
                '<p>Read /folder <code>code</code> and other/ x</p>',
            ],
        ];
    }

    #[DataProvider('prose')]
    public function testPlainSlashesStayLiteralAcrossProtectedSpans(string $djot, string $expected): void
    {
        $carve = (new DjotToCarve())->convert($djot);
        self::assertSame($expected, trim((new CarveConverter())->convert($carve)));
    }
}
