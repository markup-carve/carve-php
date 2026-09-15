<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * markup-carve/carve#1999: a refusal MUST NOT reveal whether the target exists.
 *
 * Containment is decided on the canonical candidate - the longest existing
 * prefix canonicalized, the remainder re-appended lexically - so an out-of-root
 * target is refused the same way whether or not it is on disk. A resolver that
 * reads first answers `not-found` for the absent one and `outside-root` for the
 * present one, and that difference is an existence oracle for every path
 * outside the root.
 *
 * The PAIR is what observes it: either half alone is green under a
 * reads-first resolver, because each is only ever asked about one of the two
 * states.
 */
class ARefusalDoesNotRevealWhetherATargetExistsTest extends TestCase
{
    protected string $base = '';

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carve-existence-oracle-' . bin2hex(random_bytes(8));
        mkdir($base . DIRECTORY_SEPARATOR . 'root' . DIRECTORY_SEPARATOR . 'book', 0o700, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'root' . DIRECTORY_SEPARATOR . 'shared', 0o700, true);
        file_put_contents($base . '/root/main.crv', '');
        file_put_contents($base . '/root/book/main.crv', '');
        file_put_contents($base . '/root/shared/present.crv', "ok\n");
        file_put_contents($base . '/present.crv', "secret\n");
        $this->base = (string)realpath($base);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);

        parent::tearDown();
    }

    /**
     * The two halves of the pair, spelled identically and differing only in
     * whether the target is on disk.
     *
     * @param string $request
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('outOfRootTargets')]
    public function testAnOutOfRootTargetIsRefusedAsAnEscapeWhetherOrNotItExists(
        string $request,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->refusalFor($request));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function outOfRootTargets(): array
    {
        return [
            // The present half. Green even under a reads-first resolver; it is
            // here so the pair reads as one property rather than two cases.
            'a present target outside the root' => ['../present.crv', 'Include target escapes configured root'],
            // The absent half. This is the only row a reads-first resolver reds.
            'an absent target outside the root' => ['../absent.crv', 'Include target escapes configured root'],
            'an absent target far outside the root' => ['../../../absent.crv', 'Include target escapes configured root'],
            // A missing directory on the way out must not launder the escape
            // into a containment pass: the remainder is re-appended lexically,
            // so `..` still walks out of the prefix that does exist.
            'an escape through a directory that does not exist' => [
                'nowhere/../../present.crv',
                'Include target escapes configured root',
            ],
        ];
    }

    /**
     * The other side of the same rule: a missing target whose canonical result
     * is INSIDE the root stays a miss. Deciding containment first must not turn
     * every absent path into an escape.
     *
     * @param string $from
     * @param string $request
     *
     * @return void
     */
    #[DataProvider('inRootMisses')]
    public function testAMissingTargetInsideTheRootIsStillAMiss(string $from, string $request): void
    {
        $this->assertSame('Include target not found', $this->refusalFor($request, $from));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function inRootMisses(): array
    {
        return [
            'directly under the root' => ['root/main.crv', 'gone.crv'],
            'through a permitted dot-dot' => ['root/book/main.crv', '../shared/gone.crv'],
            'under a directory that does not exist' => ['root/main.crv', 'nowhere/gone.crv'],
        ];
    }

    public function testAPermittedDotDotStillResolvesWhenTheTargetExists(): void
    {
        $resolver = new FilesystemIncludeResolver($this->base . DIRECTORY_SEPARATOR . 'root');
        $context = new IncludeContext($this->base . '/root/book/main.crv');

        $this->assertSame(
            $this->base . '/root/shared/present.crv',
            $resolver->resolve('../shared/present.crv', $context)->getId(),
        );
    }

    /**
     * @return string The refusal's leading clause, which is what the security
     *   corpus reads back as the denial class.
     */
    protected function refusalFor(string $request, string $from = 'root/main.crv'): string
    {
        $resolver = new FilesystemIncludeResolver($this->base . DIRECTORY_SEPARATOR . 'root');
        $context = new IncludeContext($this->base . DIRECTORY_SEPARATOR . $from);

        try {
            $resolver->resolve($request, $context);
        } catch (RuntimeException $exception) {
            return explode(':', $exception->getMessage())[0];
        }

        $this->fail("expected a refusal for {$request}");
    }

    protected function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
