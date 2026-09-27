# HTML parser compatibility probe

Run `php tools/html-parser-compatibility/compare.php --json` after `composer install`. To compare optional libraries without changing Carve's dependencies, install `masterminds/html5` and `mensbeam/html-parser` in a separate Composer project and pass `--autoload=/absolute/path/to/that/vendor/autoload.php`.

The eight cases compare element names, text, and child structure against parse5 7.3.0 default-adapter trees, folding `template.content` into the template's child list. They cover paragraph closure, table foster parenting, formatting reconstruction, implicit cells, templates, SVG, and MathML. Attributes, namespace identities, import policy, diagnostics, and performance need separate checks. This is an informational report; differences do not change the exit status. Missing backends are listed explicitly.

On PHP 8.5, legacy DOM matched 3/8 cases, Masterminds 2.11 matched 4/8, and MensBeam 1.4.5 matched 8/8. Native HTML5 matched the seven comparable cases. Its template case is marked `ADAPTER-LIMIT` with a null match result because this DOM API does not expose the content fragment through `childNodes`. The legacy SVG difference is tag-name casing, not child placement.

Keep the current backend for now. At commit `5980afa9`, a direct MensBeam prototype produced 68 failures in 6,189 converter tests. Those include output and report differences that require investigation before adoption. Native HTML5 also requires PHP 8.4 and different DOM types; Carve supports PHP 8.2. The probe establishes compatibility cases, not a replacement decision based on eight examples.

Regenerate with `node tools/html-parser-compatibility/generate.mjs /absolute/path/to/node_modules/parse5 --write`. Omit `--write` to verify the stored trees and version metadata without changing them. `source.json` records the parser version and normalization.
