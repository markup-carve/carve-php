<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\Standard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-X-079: only an extension that generates ids suppresses broken-fragment-link.
 */
final class IdExtensionsTest extends TestCase
{
    /**
     * @return array<string, array{0: array<\MarkupCarve\Carve\Extension\ExtensionDefinition|string>, 1: bool}>
     */
    public static function registrations(): array
    {
        return [
            'catalog name marked ids' => [['carve.cite'], false],
            'catalog name not marked ids' => [['carve.colorSwatch'], true],
            'definitions of a row marked ids' => [Standard::definitions('carve.glossary') ?? [], false],
            'a row registered alone that another row also brings' => [Standard::definitions('carve.term') ?? [], true],
            'host extension' => [[new ExtensionDefinition('org.example.x', '1', matcher: ['form' => 'inline', 'name' => 'x'])], false],
            'host extension under a catalog name' => [
                [new ExtensionDefinition('carve.wiki.foo', '1', matcher: ['form' => 'inline', 'name' => 'foo'])],
                false,
            ],
        ];
    }

    /**
     * @param array<\MarkupCarve\Carve\Extension\ExtensionDefinition|string> $extensions
     * @param bool $reported
     */
    #[DataProvider('registrations')]
    public function testOnlyIdExtensionsSuppressBrokenFragmentLink(array $extensions, bool $reported): void
    {
        $codes = array_map(
            static fn (Diagnostic $d): string => $d->code,
            Carve::lint("[x](#missing)\n", ['extensions' => $extensions]),
        );

        $this->assertSame($reported, in_array('broken-fragment-link', $codes, true));
    }
}
