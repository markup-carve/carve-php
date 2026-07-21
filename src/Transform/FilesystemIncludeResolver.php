<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use RuntimeException;

/**
 * Convenience resolver for trusted local roots.
 */
class FilesystemIncludeResolver implements IncludeResolverInterface
{
    protected string $root;

    public function __construct(
        string $root,
        protected bool $allowAbsolutePaths = false,
    ) {
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
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException("Include target not found: {$path}");
        }

        if ($real !== $this->root && !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Include target escapes configured root: {$path}");
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
}
