<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\ListTable;
use MarkupCarve\Carve\Include\FileResolver;
use PHPUnit\Framework\TestCase;

final class Candidate17PortsTest extends TestCase
{
    public function testListTableMergesAuthoredStyleAndAlignment(): void
    {
        $source = "::: list-table\n- -{style=\"color: red;\" align=right} a\n:::";
        $html = Carve::render($source, ['extensions' => [ListTable::bundle()]]);

        self::assertStringContainsString('<td style="color: red; text-align: right;">a</td>', $html);
        self::assertSame(1, substr_count($html, ' style='));
    }

    public function testHtmlImportReadsLabelsForGeneratedAdmonitionName(): void
    {
        $html = '<aside class="admonition note" aria-label="Hinweis"><p>Text.</p></aside>';
        $document = Carve::importHtml($html, ['labels' => ['admonitionNote' => 'Hinweis']])->document;

        self::assertArrayNotHasKey('attrs', $document['children'][0]);
        $different = Carve::importHtml($html, ['labels' => ['admonitionNote' => 'Note']])->document;
        self::assertSame([['aria-label', 'Hinweis']], $different['children'][0]['attrs']['keyValues']);
    }

    public function testFileResolverAllowsContainedAbsolutePathsByDefault(): void
    {
        $path = '/var/tmp/phprul1-candidate17-missing.crv';
        $default = FileResolver::closure('/var/tmp');
        $restricted = FileResolver::closure('/var/tmp', false);

        self::assertSame(['error' => 'not_found'], $default(['path' => $path]));
        self::assertSame(['error' => 'outside_root'], $restricted(['path' => $path]));
    }
}
