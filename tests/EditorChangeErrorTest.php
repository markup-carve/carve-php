<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use InvalidArgumentException;
use MarkupCarve\Carve\Ast\EditorChangeError;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\CarveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-T-006: every error a public call throws implements CarveException, the editor session's refusals included.
 */
final class EditorChangeErrorTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function refusedChanges(): array
    {
        return [
            'not a list' => [['a' => ['from' => 0, 'to' => 0, 'insert' => 'x']]],
            'range past the end' => [[['from' => 0, 'to' => 9, 'insert' => '']]],
            'out of order' => [[['from' => 2, 'to' => 2, 'insert' => 'x'], ['from' => 0, 'to' => 0, 'insert' => 'y']]],
            'splits a scalar' => [[['from' => 1, 'to' => 1, 'insert' => 'x']]],
            'invalid UTF-8 insert' => [[['from' => 0, 'to' => 0, 'insert' => "\xff"]]],
        ];
    }

    /**
     * @param array<mixed> $changes
     *
     * @return void
     */
    #[DataProvider('refusedChanges')]
    public function testRefusedChangeIsACarveException(array $changes): void
    {
        $session = Carve::createEditorSession("é\n");

        try {
            $session->update($changes);
            $this->fail('The change was accepted.');
        } catch (CarveException $e) {
            $this->assertInstanceOf(EditorChangeError::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
        }
    }

    public function testInvalidSourceIsACarveException(): void
    {
        $this->expectException(EditorChangeError::class);

        Carve::createEditorSession("a\xff\n");
    }
}
