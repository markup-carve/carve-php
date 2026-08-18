# Rendering untrusted input

Carve source from anyone who is not you - comments, form fields, imported
documents, LLM output - needs configuring before it reaches a browser. This page
is the whole story in one place.

## The default

`new CarveConverter()` has **no safe mode and no profile**. Raw passthrough
renders verbatim, every construct is allowed, and there is no length cap. That is
the right default for content you author yourself and the wrong one for anything
else - nothing below happens unless you ask for it.

| Entry point | Safe mode | Profile |
|-------------|-----------|---------|
| `new CarveConverter()` | off | none |
| `new CarveConverter(safeMode: true)` | `SafeMode::defaults()` | none |
| `markup-carve/laravel-carve`, shipped `default` profile | on | none |

## The short version

```php
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\SafeMode;

$converter = new CarveConverter(safeMode: SafeMode::strict());
$converter->setProfile(Profile::comment());

$html = $converter->convert($userInput);

foreach ($converter->getProfileViolations() as $violation) {
    // Optional: tell the author what was dropped and why.
    $messages[] = $violation->getMessage();
}
```

Two independent layers, and you usually want both:

- **`SafeMode`** decides what happens to raw HTML, dangerous URL schemes and
  event-handler attributes. It is about *output safety*.
- **`Profile`** decides which Carve constructs are allowed at all, with a size
  cap. It is about *appropriateness* - a comment field has no business
  containing headings, tables or footnotes.

On Laravel, `markup-carve/laravel-carve` already ships `safe_mode => true` as the
default (and only) shipped converter profile, so the first layer is on there
unless you define a profile of your own with `safe_mode => false` or reach for the
raw Blade directive. Using carve-php directly, neither layer is on until you set
it.

## What the default does not protect against

Raw passthrough renders **verbatim**, and that includes event handlers:

````
```=html
<b onclick="steal()">x</b>
```
````

| Input | no safe mode | `safeMode: true` | `SafeMode::strict()` |
|-------|--------------|------------------|----------------------|
| raw block ```` ```=html ```` | rendered live | escaped | removed |
| inline raw `` `<b>x</b>`{=html} `` | rendered live | escaped | removed |

Escaped text in the source (`<script>` typed as prose) is escaped in every mode,
and a `javascript:` link destination is emptied in every mode - those two are
handled without opting in. Raw passthrough is not. If you take input from
anywhere untrusted and do not set a safe mode, you have an XSS hole.

## Resource limits

Pathologically nested input is bounded rather than allowed to exhaust the host.
Two bounds are worth knowing about because a caller can observe them:

- **Container nesting in the document** stops at
  `BlockParser::MAX_NESTING_DEPTH` (200). Past it an opener degrades to ordinary
  paragraph text, so the document still parses.
- **Container markers on ONE line** stop at
  `BlockParser::MAX_LINE_PREFIX_DEPTH`. A line spelling more of them than that -
  `> - ` repeated thousands of times - is REFUSED with
  `ContainerPrefixDepthExceededException`, which names the bound it hit. This
  reads the line's markers rather than the containers the document opens, so the
  cap above never bounded it; before the bound existed such a line overflowed the
  stack, and PHP does not turn that into a catchable error.

Catch it wherever you catch a malformed document:

```php
use MarkupCarve\Carve\Exception\ContainerPrefixDepthExceededException;

try {
    $html = $converter->convert($source);
} catch (ContainerPrefixDepthExceededException $exception) {
    // $exception->limit is the bound
}
```

`bin/carve` already does this: it prints the message on stderr and exits 1.

## SafeMode

```php
SafeMode::defaults();  // escape raw HTML, block dangerous schemes and on* attributes
SafeMode::strict();    // strip raw HTML entirely, and also block the style attribute
```

What a `SafeMode` instance enforces **once you attach one**. These are the values
inside `SafeMode::defaults()`, not what the converter does without a safe mode -
without one, none of these rules apply at all:

| Setting | Value in `defaults()` |
|---------|-----------------------|
| raw HTML mode | `RAW_HTML_ESCAPE` (`strict()`: `RAW_HTML_STRIP`, or `RAW_HTML_ALLOW`) |
| dangerous schemes | `javascript`, `vbscript`, `data`, `file` |
| allowed schemes | null, meaning everything not listed as dangerous |
| blocked attribute prefixes | `on` - covers `onclick`, `onload`, every handler |
| blocked attributes | `srcdoc`, `formaction` (`strict()` adds `style`) |

```php
$safe = SafeMode::defaults()
    ->setAllowedSchemes(['https', 'mailto'])   // allowlist instead of denylist
    ->addDangerousScheme('tel');
```

`setSafeMode()` only takes effect on an HTML renderer. Passing `safeMode` to the
constructor is the reliable form.

## Profile

A profile allows or denies node types, caps document length, and carries a
human-readable reason per denied feature. Four presets:

| Preset | For |
|--------|-----|
| `Profile::full()` | trusted authors, everything on |
| `Profile::article()` | CMS-style content |
| `Profile::comment()` | user comments - no headings, tables, footnotes; length capped |
| `Profile::minimal()` | single-line fields, tightest cap |

Denied constructs degrade rather than disappear - a denied heading renders as its
own text - and each one is reported:

```php
$converter->setProfile(Profile::comment());
$converter->convert("# Heading\n\ntext");

$converter->getProfileViolations()[0]->getMessage();
// "'heading' is not allowed: element_not_allowed (Headings are disabled in
//  comments to prevent disrupting page structure.)"
```

Those messages are written to be shown to the author. Build your own:

```php
$profile = Profile::comment()
    ->denyInline(['image'])
    ->allowBlock(['paragraph', 'list', 'blockquote'])
    ->setLinkPolicy($policy);
```

## LinkPolicy

Attach to a profile to control destinations:

```php
use MarkupCarve\Carve\LinkPolicy;

$policy = (new LinkPolicy())
    ->setAllowedSchemes(['https', 'mailto'])
    ->setDeniedDomains(['spam.example'])
    ->setAllowExternal(true)
    ->addRelAttribute('nofollow');       // plus noopener/noreferrer as configured

$profile->setLinkPolicy($policy);
```

`isUrlAllowed($url, $baseHost)` is the same check the renderer runs, so you can
reuse it for validation before storing.

## Images and SVG

`SvgSanitizer` with `SvgSanitizeOptions` neutralizes SVG payloads (scripts,
external references) for cases where images may be user-supplied. Combine with a
`SafeMode` that keeps `data:` in the dangerous-scheme list, which it is by
default.

## Validating before storing

Two cheaper-than-rendering checks:

```php
$converter->convert($source);
$converter->getWarnings();            // parse-level complaints
$converter->getProfileViolations();   // what the profile rejected
```

Rejecting input at submit time with the violation messages attached beats
silently dropping constructs at render time - the author learns the rule once
instead of wondering where their table went.

## Layering with an HTML sanitizer

Safe mode plus a profile is enough for Carve's own output. If the same pipeline
also renders HTML from other sources, keep your existing sanitizer - the two are
not redundant: Carve controls what it emits, a sanitizer controls what survives
regardless of origin.

## Checklist

- [ ] `safeMode` set (constructor, or `safe_mode` in the Laravel config) for any
      input you did not author
- [ ] `SafeMode::strict()` where raw HTML has no legitimate use
- [ ] a `Profile` matching the field's purpose, not `full()` by default
- [ ] a `LinkPolicy` if destinations matter
- [ ] violations surfaced to the author rather than swallowed
