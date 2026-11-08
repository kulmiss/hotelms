// Prints the "first download": the JS and CSS the browser fetches before the first screen (index.html's
// script, modulepreload and stylesheet links), gzipped. Screens loaded later (lazy) are not counted.
// Run after `npm run build`:  node scripts/first-download.mjs
import { appendFileSync, readFileSync } from 'node:fs'
import { gzipSync } from 'node:zlib'
import { join } from 'node:path'

const dist = new URL('../dist/', import.meta.url).pathname
const html = readFileSync(join(dist, 'index.html'), 'utf8')
const files = [...html.matchAll(/(?:src|href)="\/(assets\/[^"]+\.(?:js|css))"/g)].map((m) => m[1])
const rows = [...new Set(files)].map((f) => {
  const raw = readFileSync(join(dist, f))
  return { file: f, kb: gzipSync(raw, { level: 9 }).length / 1024 }
})
const total = rows.reduce((s, r) => s + r.kb, 0)
const lines = ['| File | gzip KB |', '|---|---:|', ...rows.map((r) => `| ${r.file} | ${r.kb.toFixed(1)} |`), `| **Total first download** | **${total.toFixed(1)}** |`]
console.log(lines.join('\n'))
if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `### First download\n\n${lines.join('\n')}\n`)
