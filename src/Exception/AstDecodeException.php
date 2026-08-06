<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use RuntimeException;

/**
 * An ingested AST payload is not one this decoder can read (PART 12 §9, §11).
 *
 * §9(b) states the requirement, and §11 repeats it for a property the schema
 * does not name: rejection MUST be "a typed, documented failure ... not
 * truncation, not a crash, and not whatever its JSON library happened to
 * raise".
 *
 * The decoder already refused every one of those cases and already named what
 * was wrong. What it did not do was let a caller SAY SO: a bare
 * `RuntimeException` cannot be caught apart from a bug in the caller's own
 * callback or an extension throwing mid-decode, so "this payload is not a
 * Carve AST" and "something went wrong" were the same catch (carve-php#912).
 *
 * EXTENDS `RuntimeException` on purpose. Every existing `catch
 * (RuntimeException)` around a decode keeps working, so gaining the type is
 * additive rather than a break.
 */
class AstDecodeException extends RuntimeException
{
}
