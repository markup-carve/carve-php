<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Json\TreeReader;
use PHPUnit\Framework\TestCase;

final class Next2TabsOwnershipCaseTest extends TestCase
{
    public function testDeclaredTabOutsideTabsIsRefused(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $case = array_values(array_filter($spec['cases'], static fn (array $entry): bool => $entry['id'] === 'CC-468'))[0];
        try {
            TreeReader::read(json_encode($case['tree'], JSON_THROW_ON_ERROR));
            self::fail('CC-468 must be refused');
        } catch (TreeError $error) {
            self::assertSame('/children/0', $error->pointer);
        }
    }
}
