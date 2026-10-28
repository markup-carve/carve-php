<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\TestCase;

final class ResolvedSourceFindingsTest extends TestCase
{
    public function testHeadingJumpSuppressionUsesReportedSourceLine(): void
    {
        $sameLine = "# One\n\n### Three %% carve-lint-disable-line heading-level-skip\n";
        self::assertNotContains('heading-level-skip', array_column((new DocumentLinter())->lint($sameLine), 'rule'));

        $nextLine = "# One\n%% carve-lint-disable-next-line heading-level-skip\n### Three\n";
        self::assertNotContains('heading-level-skip', array_column((new DocumentLinter())->lint($nextLine), 'rule'));

        $otherLine = "# One %% carve-lint-disable-line heading-level-skip\n\n### Three\n";
        self::assertContains('heading-level-skip', array_column((new DocumentLinter())->lint($otherLine), 'rule'));
    }

    public function testDirectiveInsideCodeIsInert(): void
    {
        $source = "# One\n```carve\n%% carve-lint-disable heading-level-skip\n```\n### Three\n";
        self::assertContains('heading-level-skip', array_column((new DocumentLinter())->lint($source), 'rule'));
    }

    public function testDirectiveRequiresSpaceAfterCommentMarker(): void
    {
        $source = "# One\n%%carve-lint-disable heading-level-skip\n### Three\n";
        self::assertContains('heading-level-skip', array_column((new DocumentLinter())->lint($source), 'rule'));
    }
}
