#!/usr/bin/env bash
# Installs the package the way a downstream project does (git archive, so export-ignore applies, then a Composer
# path repository into an empty project) and calls every public 0.2 entry from there.
set -euo pipefail

repo=$(cd "$(dirname "$0")/../.." && pwd)
ref=${1:-HEAD}
work=$(mktemp -d "${TMPDIR:-/tmp}/carve2-consumer.XXXXXX")
mkdir "$work/pkg" "$work/app"
git -C "$repo" archive --format=tar "$ref" | tar -x -C "$work/pkg"

for path in tests v2/tests v2/oracle v2/spec/conformance v2/bench; do
    if [ -e "$work/pkg/$path" ]; then
        echo "dist ships $path" >&2
        exit 1
    fi
done

cat > "$work/app/composer.json" <<JSON
{
    "name": "example/carve2-consumer",
    "type": "project",
    "minimum-stability": "dev",
    "repositories": [
        {"type": "path", "url": "$work/pkg", "options": {"symlink": false, "versions": {"markup-carve/carve-php": "dev-next2"}}}
    ],
    "require": {"markup-carve/carve-php": "dev-next2"}
}
JSON
cp "$repo/v2/scripts/consumer-smoke.php" "$work/app/smoke.php"
(cd "$work/app" && composer install --no-interaction --no-progress --quiet)
(cd "$work/app" && php smoke.php)
printf '# Hi\n' | (cd "$work/app" && vendor/bin/carve2 render -) | grep -q '<h1' || { echo 'carve2 render failed' >&2; exit 1; }
echo "consumer smoke ok: $work/app ($(du -sh "$work/app/vendor/markup-carve/carve-php" | cut -f1) installed)"
