// Dashboard QA check (P18). Paste into the console on the app, then: await __dashRun(['/', '/cashier'], ['light','dark'])
// For each page and theme it reports: sideways scroll, boxes sticking out, widget errors, charts drawn,
// "no access" screen, money shown (ETB), small tap targets (touch screens only) and text below 4.5:1 contrast.
window.__cv = document.createElement('canvas').getContext('2d', { willReadFrequently: true })
window.__rgb = (c) => {
  const x = window.__cv
  x.clearRect(0, 0, 1, 1)
  x.fillStyle = '#000'
  x.fillStyle = c
  x.fillRect(0, 0, 1, 1)
  const d = x.getImageData(0, 0, 1, 1).data
  return [d[0], d[1], d[2], d[3] / 255]
}
window.__lum = ([r, g, b]) => {
  const f = (v) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)
}
window.__bgOf = (el) => {
  const stack = []
  for (let e = el; e; e = e.parentElement) {
    const c = window.__rgb(getComputedStyle(e).backgroundColor)
    if (c[3] > 0) stack.push(c)
    if (c[3] >= 1) break
  }
  let out = window.__rgb(getComputedStyle(document.body).backgroundColor)
  for (const c of stack.reverse()) out = out.map((v, i) => (i < 3 ? v * (1 - c[3]) + c[i] * c[3] : 1))
  return out
}
window.__vis = (e) => {
  const r = e.getBoundingClientRect()
  if (r.width < 1 || r.height < 1) return false
  const s = getComputedStyle(e)
  return s.visibility !== 'hidden' && s.display !== 'none' && +s.opacity > 0.05 && !e.closest('[aria-hidden=true],.sr-only')
}
window.__name = (e) => (e.getAttribute('aria-label') || e.textContent || e.tagName).trim().replace(/\s+/g, ' ').slice(0, 40)
window.__settle = async (max = 10000) => {
  // Done when nothing is loading and the page text has not changed for 800 ms.
  const t0 = performance.now()
  if (!document.getElementById('__noanim')) {
    const st = document.createElement('style')
    st.id = '__noanim'
    st.textContent = '*,*::before,*::after{animation-duration:0s!important;transition:none!important}'
    document.head.appendChild(st)
  }
  let last = ''
  let stableSince = performance.now()
  while (performance.now() - t0 < max) {
    await new Promise((r) => setTimeout(r, 200))
    const m = document.querySelector('main')
    const txt = m ? m.textContent : ''
    const busy = document.querySelectorAll('main .animate-pulse, main [aria-busy=true], main [data-slot=skeleton]').length
    if (txt !== last || busy || !txt) {
      last = txt
      stableSince = performance.now()
    } else if (performance.now() - stableSince > 800) break
  }
  return Math.round(stableSince - t0)
}
window.__check = () => {
  const vw = document.documentElement.clientWidth
  const main = document.querySelector('main') || document.body
  const res = {}
  res.sideScroll = Math.max(0, document.documentElement.scrollWidth - vw)
  const clipped = (e) => {
    for (let p = e.parentElement; p && p !== document.body; p = p.parentElement) {
      const o = getComputedStyle(p).overflowX
      if (o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') return true
    }
    return false
  }
  res.sticksOut = [...main.querySelectorAll('*')].filter((e) => __vis(e) && e.getBoundingClientRect().right > vw + 1 && !clipped(e)).slice(0, 4).map(__name)
  res.alerts = [...main.querySelectorAll('[role=alert]')].filter(__vis).map(__name)
  res.charts = main.querySelectorAll('.recharts-surface').length
  res.noAccess = /You can.t open this page/.test(main.textContent)
  res.crash = /Something went wrong/i.test(main.textContent)
  res.money = (main.textContent.match(/ETB/g) || []).length
  if (matchMedia('(pointer: coarse)').matches) {
    res.smallTaps = [...document.querySelectorAll('button,a[href],[role=tab],[role=button],input:not([type=hidden]),select,[role=combobox]')]
      .filter(__vis)
      .filter((e) => {
        const r = e.getBoundingClientRect()
        return r.height < 43.5 || (r.width < 43.5 && !/^(A)$/.test(e.tagName))
      })
      .slice(0, 6)
      .map((e) => __name(e) + ' ' + Math.round(e.getBoundingClientRect().width) + 'x' + Math.round(e.getBoundingClientRect().height))
  }
  const low = []
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
  const seen = new Set()
  while (walker.nextNode()) {
    const n = walker.currentNode
    if (!n.textContent.trim()) continue
    const e = n.parentElement
    if (!e || seen.has(e) || !__vis(e) || e.closest('svg,[disabled],[aria-disabled=true]')) continue
    seen.add(e)
    const s = getComputedStyle(e)
    const fg = __rgb(s.color)
    const bg = __bgOf(e)
    const f = fg.map((v, i) => (i < 3 ? v * fg[3] + bg[i] * (1 - fg[3]) : 1))
    const [a, b] = [__lum(f), __lum(bg)].sort((p, q) => q - p)
    const ratio = (a + 0.05) / (b + 0.05)
    const size = parseFloat(s.fontSize)
    const large = size >= 24 || (size >= 18.66 && +s.fontWeight >= 700)
    if (ratio < (large ? 3 : 4.5)) low.push(n.textContent.trim().slice(0, 30) + ' ' + ratio.toFixed(2))
  }
  res.lowContrast = [...new Set(low)].slice(0, 6)
  return res
}
window.__go = async (path) => {
  history.pushState({}, '', path)
  dispatchEvent(new PopStateEvent('popstate'))
  return __settle()
}
window.__theme = async (t) => {
  document.documentElement.classList.toggle('dark', t === 'dark')
  await new Promise((r) => setTimeout(r, 120))
}
window.__dashRun = async (paths, themes = ['light', 'dark']) => {
  const out = {}
  for (const p of paths) {
    const ms = await __go(p)
    for (const t of themes) {
      await __theme(t)
      const r = __check()
      r.loadMs = ms
      // Keep only what is worth reading.
      for (const k of Object.keys(r)) if ((Array.isArray(r[k]) && !r[k].length) || r[k] === false || r[k] === 0) delete r[k]
      out[p + ' ' + t] = r
    }
  }
  return out
}
