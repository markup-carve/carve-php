# Configured conversion fast path

`CarveConverter::convert()` has a conservative source-to-HTML path for documents
that do not require the public owned AST. Configuring extensions no longer
disables that path by itself. Instead, `BorrowedExtensionPlan` compiles the
registered built-in stack for the current source before `BorrowedHtmlLayout`
attempts the document.

The decision is whole-document and fail-closed:

- an unknown extension returns to the authoritative parser;
- an active extension that has no borrowed event returns to the authoritative
  parser;
- unsupported or ambiguous core syntax returns to the authoritative parser;
- `parse()`, alternate renderers, output transformers, warnings, source-line
  capture, strict/safe/profile modes, and explicit parser access continue to use
  the authoritative owned AST;
- no borrowed output is published until the complete document is accepted.

Inactive built-ins are admitted only behind conservative source predicates.
The borrowed renderer currently implements typed events for heading numbering,
heading permalinks, external-link attributes, lowercase heading IDs, display-math
fences, and manual table-of-contents state. Their constructor configuration is
copied through explicit typed accessors, including custom numbering levels,
permalink placement and presentation, internal hosts, link target/`rel`,
`nofollow`, and custom math language tags. TOC auto-insertion has an output
transformer and therefore deliberately remains authoritative; manual TOC data is
committed only after the complete borrowed document succeeds.

The existing facade limits remain deliberate: at most 64 KiB, ASCII input, HTML
output, and the already accepted unambiguous block/inline subset. Ascii heading
IDs are therefore inert on an accepted document. Unsupported syntax falls back;
the fast path does not weaken or approximate it.

## Correctness ratchet

The performance tests compare borrowed output with a caller-supplied
`HtmlRenderer`, which forces the authoritative path. They cover:

- every standalone corpus document accepted by the core facade;
- the reproducible Tier-2 and Tier-3 extension stacks;
- active default and custom event configurations, including math and TOC state;
- heading numbering, case-colliding IDs, reference/direct/table links;
- active unsupported syntax and unknown configuration fallback.

Acceptance counts are pinned so widening or narrowing the route requires an
explicit test review. The authoritative parser remains the oracle.

## Relationship to integrated definition layout

Documents outside the borrowed envelope still benefit from the integrated
definition pass described in `integrated-definition-layout.md`. The two changes
are complementary: configured source-to-HTML avoids materializing an AST where
exact events suffice, while the authoritative fallback avoids its former
duplicate mixed-definition structural scan.
