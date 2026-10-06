<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use PHPUnit\Framework\TestCase;

/**
 * `scripts/check-spec-pin-ancestry.sh` must not report success when it could
 * not run.
 *
 * The script answers one question: is the pinned spec commit reachable from the
 * spec's default branch. "I could not tell" is not that answer, and an exit
 * code of 0 would claim it is, so a release caller trusting the exit code would
 * take a pass from a check that never ran (carve-php#2920). The cannot-check
 * paths exit 2, distinct from the 1 that reports a real dangling pin.
 *
 * The blind spot is what this test covers. A test over the forward-move case
 * would pass against a script that answered 0 unconditionally, which is the
 * shape being guarded against.
 */
class ThePinCheckFailsWhenItCannotCheckTest extends TestCase
{
    public function testAMissingGitlinkExitsNonZeroAndNamesWhatWasMissing(): void
    {
        $root = dirname(__DIR__);
        $script = $root . '/scripts/check-spec-pin-ancestry.sh';
        $this->assertFileExists($script);

        // A path with no gitlink recorded against it. The script answers from
        // the index alone here, so the case needs no network.
        $command = sprintf(
            'cd %s && bash %s %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($script),
            escapeshellarg('tests/no-such-submodule'),
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);
        $text = implode("\n", $output);

        $this->assertNotSame(0, $status, 'unable to check must not report success: ' . $text);
        $this->assertSame(2, $status, 'cannot-check is 2, kept distinct from the 1 of a real finding');
        $this->assertStringContainsString('CANNOT CHECK', $text);
        $this->assertStringContainsString('tests/no-such-submodule', $text);
    }
}
