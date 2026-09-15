<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use RuntimeException;

/**
 * Convenience resolver for trusted local roots.
 */
class FilesystemIncludeResolver implements IncludeResolverInterface
{
    /**
     * @var int
     */
    public const DEFAULT_MAX_FILE_BYTES = 4194304;

    protected string $root;

    /**
     * @param string $root Containment root. Must be an ABSOLUTE path: a
     *   non-absolute spec - blank, whitespace-only or relative alike - is
     *   refused rather than canonicalized against the working directory. A
     *   front end keeps the convenience by expanding its own argument first.
     * @param bool $allowAbsolutePaths
     * @param int|null $maxFileBytes Largest target this resolver will read, or
     *   null for no cap. The expander's byte budget cannot stand in for this:
     *   it charges a target only once the source is in hand, so without a cap
     *   here one oversized file is read into memory in full before expansion
     *   is refused.
     *
     * @throws \RuntimeException
     */
    public function __construct(
        string $root,
        protected bool $allowAbsolutePaths = false,
        protected ?int $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
    ) {
        // PART 9 section 19: the root MUST NOT default to the process working
        // directory. Every canonicalizer resolves a NON-ABSOLUTE spec against
        // exactly that directory - `realpath('')` and `realpath('.')` both
        // answer with it - and `is_dir()` then accepts the result, so the test
        // has to be on the configured value rather than on what it canonicalizes
        // to. Absoluteness subsumes the blank and whitespace-only ends: neither
        // is absolute. A directory genuinely named with spaces stays reachable
        // by its absolute path.
        if (!$this->isAbsolutePath($root)) {
            throw new RuntimeException("The include root must be an absolute path: {$root}");
        }

        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new RuntimeException("Include root does not exist: {$root}");
        }

        $this->root = rtrim($realRoot, DIRECTORY_SEPARATOR);
    }

    public function resolve(string $path, IncludeContext $context): ResolvedInclude
    {
        if (!$this->allowAbsolutePaths && $this->isAbsolutePath($path)) {
            throw new RuntimeException("Absolute include paths are not allowed: {$path}");
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $path) === 1) {
            throw new RuntimeException("Include URI schemes are not allowed: {$path}");
        }

        // The including path is the canonical id this resolver returned for
        // the parent (absolute), or a host-supplied root document path that
        // may be root-relative; either way nested relative includes resolve
        // against the actual parent directory, not the root.
        $base = $this->root;
        $includingPath = $context->getIncludingPath();
        if ($includingPath !== null && $includingPath !== '') {
            $candidateParent = $this->isAbsolutePath($includingPath)
                ? $includingPath
                : $this->root . DIRECTORY_SEPARATOR . ltrim($includingPath, DIRECTORY_SEPARATOR);
            $includingReal = realpath($candidateParent);
            if ($includingReal !== false) {
                $base = dirname($includingReal);
            }
        }

        $candidate = $this->isAbsolutePath($path) ? $path : $base . DIRECTORY_SEPARATOR . $path;

        // Containment is decided on the canonical candidate BEFORE the target
        // is looked for. Reading first answers `not-found` for an absent
        // out-of-root target and `outside-root` for a present one, which makes
        // the refusal class an existence oracle for paths outside the root
        // (markup-carve/carve#1999).
        $real = $this->canonicalCandidate($candidate);
        if ($real !== $this->root && !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Include target escapes configured root: {$path}");
        }

        if (!is_file($real)) {
            throw new RuntimeException("Include target not found: {$path}");
        }

        if ($this->maxFileBytes !== null) {
            $size = filesize($real);
            if ($size === false || $size > $this->maxFileBytes) {
                throw new RuntimeException("Include target exceeds the size cap: {$path}");
            }
        }

        $source = file_get_contents($real);
        if ($source === false) {
            throw new RuntimeException("Include target is unreadable: {$path}");
        }

        return new ResolvedInclude($source, $real);
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    /**
     * The candidate's canonical spelling, constructible whether or not it
     * exists: canonicalize the longest prefix that DOES exist - which resolves
     * every symlink on it - then re-append the remaining segments lexically.
     * A symlink can only live on the existing prefix, so the lexical tail
     * cannot hide one.
     */
    protected function canonicalCandidate(string $candidate): string
    {
        $remainder = [];
        $prefix = $candidate;
        while (true) {
            $real = realpath($prefix);
            if ($real !== false) {
                return $this->reappend($real, $remainder);
            }
            $parent = dirname($prefix);
            if ($parent === $prefix) {
                // Loop terminator: the walk has reached a path that is its own
                // parent and still did not resolve.
                return $this->reappend($prefix, $remainder);
            }
            array_unshift($remainder, basename($prefix));
            $prefix = $parent;
        }
    }

    /**
     * @param string $real
     * @param array<string> $remainder
     *
     * @return string
     */
    protected function reappend(string $real, array $remainder): string
    {
        $segments = explode(DIRECTORY_SEPARATOR, rtrim($real, DIRECTORY_SEPARATOR));
        foreach ($remainder as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                // Never past the resolved prefix's own leading separator.
                if (count($segments) > 1) {
                    array_pop($segments);
                }

                continue;
            }
            $segments[] = $segment;
        }

        return implode(DIRECTORY_SEPARATOR, $segments);
    }
}
