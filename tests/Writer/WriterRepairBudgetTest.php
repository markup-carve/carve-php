<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\RepairRefused;
use MarkupCarve\Carve\Writer\State;
use PHPUnit\Framework\TestCase;

final class WriterRepairBudgetTest extends TestCase
{
    public function testRepeatedRepairStateRefusesItsGroup(): void
    {
        $state = new State([], false, false);
        $state->beginRepairGroup(['type' => 'paragraph']);
        $state->chargeRepair('/children/0/children/0', 'first-line', '# a' . "\n");

        try {
            $state->chargeRepair('/children/0/children/0', 'first-line', '# a' . "\n");
            self::fail('A repeated repair state should stop the group');
        } catch (RepairRefused $e) {
            $state->refuseRepair('/children/0');
        }

        self::assertSame('writer-refused', $state->losses[0]->code);
        self::assertSame('render-loss', $state->losses[0]->channel);
        self::assertSame('/children/0', $state->losses[0]->path);
    }

    public function testCharacterLimitRefusesOnlyAfterItsFloor(): void
    {
        $state = new State([], false, false);
        $state->beginRepairGroup(['type' => 'paragraph']);
        $state->chargeRepair('/children/0/children/0', 'first-line', str_repeat('a', 24_000_000));

        $this->expectException(RepairRefused::class);
        $state->chargeRepair('/children/0/children/1', 'first-line', 'b');
    }

    public function testTheGroupSizeRaisesTheFloor(): void
    {
        $state = new State([], false, false);
        $state->beginRepairGroup(['type' => 'paragraph', 'value' => str_repeat('a', 10_000)]);
        $state->chargeRepair('/children/0/children/0', 'first-line', str_repeat('b', 24_000_001));

        self::assertSame([], $state->losses);
    }

    public function testLaterGroupsDrawOnTheSpentDocumentBudget(): void
    {
        $state = new State([], false, false);
        $state->beginRepairDocument([['type' => 'paragraph'], ['type' => 'paragraph'], ['type' => 'paragraph']]);
        $state->beginRepairGroup(['type' => 'paragraph']);
        $state->chargeRepair('/children/0/children/0', 'first-line', str_repeat('a', 12_000_000));
        $state->endRepairGroup();
        $state->beginRepairGroup(['type' => 'paragraph']);
        $state->chargeRepair('/children/1/children/0', 'first-line', str_repeat('b', 12_000_000));
        $state->endRepairGroup();
        $state->beginRepairGroup(['type' => 'paragraph']);

        $this->expectException(RepairRefused::class);
        $state->chargeRepair('/children/2/children/0', 'first-line', 'c');
    }

    public function testTheDocumentSizeRaisesTheBudgetOfEveryGroup(): void
    {
        $state = new State([], false, false);
        $state->beginRepairDocument([['type' => 'paragraph', 'value' => str_repeat('a', 10_000)], ['type' => 'paragraph']]);
        $state->beginRepairGroup(['type' => 'paragraph']);
        $state->chargeRepair('/children/1/children/0', 'first-line', str_repeat('b', 24_000_001));

        self::assertSame([], $state->losses);
    }

    public function testManyRepairedParagraphsStayUnderTheDocumentBudget(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $case = array_values(array_filter($spec['cases'], static fn (array $entry): bool => $entry['id'] === 'CC-1492'))[0];
        $result = Carve::formatWithDiagnostics($case['input'], $case['options']);

        self::assertSame($case['expected']['carve'], $result->source);
        self::assertNotContains('writer-refused', array_map(static fn ($d): string => $d->code, $result->diagnostics));
    }
}
