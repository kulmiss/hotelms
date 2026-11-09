// Prints the "first download": the JS and CSS the browser fetches before the first screen (index.html's
// script, modulepreload and stylesheet links), gzipped. Screens loaded later (lazy) are not counted.
// Run after `npm run build`:  node scripts/first-download.mjs
import { appendFileSync, readdirSync, readFileSync } from 'node:fs'
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

// Lazy screens over 30 KB gzip (e.g. the dashboard with its charts), so their size is visible too.
const big = readdirSync(join(dist, 'assets'))
  .filter((f) => f.endsWith('.js') && !files.includes(`assets/${f}`))
  .map((f) => ({ file: `assets/${f}`, kb: gzipSync(readFileSync(join(dist, 'assets', f)), { level: 9 }).length / 1024 }))
  .filter((r) => r.kb > 30)
  .sort((a, b) => b.kb - a.kb)
const lazy = ['| Lazy file (loaded only on its screen) | gzip KB |', '|---|---:|', ...big.map((r) => `| ${r.file} | ${r.kb.toFixed(1)} |`)]
console.log(`\n${lazy.join('\n')}`)
if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `\n### Big lazy files\n\n${lazy.join('\n')}\n`)
