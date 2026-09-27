# HTML parser compatibility probe

Run `php tools/html-parser-compatibility/compare.php --json` after `composer install`. To compare Masterminds without changing Carve's dependencies, install `masterminds/html5` in a separate Composer project and pass `--autoload=/absolute/path/to/that/vendor/autoload.php`.

The eight cases compare element names, text, and child structure against parse5 7.3.0 default-adapter trees, folding `template.content` into the template's child list. They cover paragraph closure, table foster parenting, formatting reconstruction, implicit cells, templates, SVG, and MathML. Attributes, namespace identities, import policy, diagnostics, and performance need separate checks. This is an informational report; differences do not change the exit status. Missing backends are listed explicitly.

On PHP 8.5, legacy DOM matched 3/8 cases, Masterminds 2.11 matched 4/8, and MensBeam 1.4.5 matched 8/8. Native HTML5 matched the seven comparable cases. Its template case is marked `ADAPTER-LIMIT` with a null match result because this DOM API does not expose the content fragment through `childNodes`. The legacy SVG difference is tag-name casing, not child placement.

Production import uses MensBeam on every supported PHP version. The `carve` entry exercises the production loader; `legacy` retains libxml's HTML parser for comparison. Importer tests also cover footnote relocation, template contents, boolean attributes, foreign-element serialization, and HTML5 changes to diagnostic paths.

Regenerate with `node tools/html-parser-compatibility/generate.mjs /absolute/path/to/node_modules/parse5 --write`. Omit `--write` to verify the stored trees and version metadata without changing them. `source.json` records the parser version and normalization.
