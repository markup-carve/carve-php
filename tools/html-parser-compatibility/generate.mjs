import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const [directory, flag] = process.argv.slice(2)
if (!directory || (flag !== undefined && flag !== '--write')) {
  throw new Error('Usage: node generate.mjs /path/to/node_modules/parse5 [--write]')
}
const packageDirectory = resolve(directory)
const manifest = JSON.parse(readFileSync(resolve(packageDirectory, 'package.json'), 'utf8'))
const entry = manifest.module ?? manifest.exports?.import
if (manifest.name !== 'parse5' || typeof entry !== 'string') {
  throw new Error('Expected a parse5 package with an ESM entry point')
}
const { parse } = await import(pathToFileURL(resolve(packageDirectory, entry)).href)
const casesPath = fileURLToPath(new URL('cases.json', import.meta.url))
const sourcePath = fileURLToPath(new URL('source.json', import.meta.url))
const cases = JSON.parse(readFileSync(casesPath, 'utf8'))
if (!Array.isArray(cases) || cases.length === 0) throw new Error('Expected a nonempty case set')
const findRoot = node => node.nodeName === 'carve-import-root'
  ? node : (node.childNodes ?? []).map(findRoot).find(Boolean)
const tree = node => node.nodeName === '#text' ? node.value
  : [node.nodeName, (node.content?.childNodes ?? node.childNodes ?? []).map(tree)]
const generated = cases.map(({ name, html }) => {
  if (typeof name !== 'string' || typeof html !== 'string') throw new Error('Invalid comparison case')
  const document = parse('<!DOCTYPE html><html><head></head><body><carve-import-root>' + html + '</carve-import-root></body></html>')
  const root = findRoot(document)
  if (!root) throw new Error('Missing comparison root: ' + name)
  return { name, html, expected: tree(root) }
})
const source = { package: 'parse5', version: manifest.version, adapter: 'default', templates: 'content folded into children' }
if (flag === '--write') {
  writeFileSync(casesPath, JSON.stringify(generated, null, 2) + '\n')
  writeFileSync(sourcePath, JSON.stringify(source, null, 2) + '\n')
} else {
  if (JSON.stringify(generated) !== JSON.stringify(cases)
    || JSON.stringify(source) !== JSON.stringify(JSON.parse(readFileSync(sourcePath, 'utf8')))) {
    throw new Error('Parser expectations or source metadata differ; inspect before regenerating with --write')
  }
}
console.log(`${generated.length} cases checked with parse5 ${manifest.version}`)
