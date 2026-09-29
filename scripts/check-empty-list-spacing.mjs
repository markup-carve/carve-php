import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

// Pass a spec checkout to remeasure against another revision.
const spec = process.argv[2]
  ? pathToFileURL(resolve(process.argv[2]) + '/')
  : new URL('../tests/spec/', import.meta.url);
const { parse } = await import(new URL('scripts/spec/layout.mjs', spec));
const { renderDoc } = await import(new URL('scripts/spec/html.mjs', spec));
const fixture = JSON.parse(readFileSync(new URL('../tests/fixtures/empty-list-block-spacing.json', import.meta.url)));
const differences = [];
for (const row of fixture.cases) {
  const oracle = renderDoc(parse(row.source));
  assert.equal(oracle, row.oracleHtml, row.name);
  assert.equal(row.phpHtml.replace(/\s/g, ''), oracle.replace(/\s/g, ''), row.name);
  if (row.phpHtml.trimEnd() !== oracle.trimEnd()) differences.push(row.name);
}
// Zero since carve-php#2714. The 24 absent-payload shapes used to differ from
// the oracle in whitespace inside the item.
assert.deepEqual(differences, []);
console.log(`${fixture.cases.length} cases: 0 whitespace differences against the oracle`);
