// Paste into the browser console on the app (demo hotel, 1100 x 860, light mode). See guide-pictures.md.
// T(selector, /text/)   – first visible element whose text or aria-label matches
// __prep(path)          – open a screen inside the app, light mode, no animations, banners and the dev link hidden
// __marks([[n, el, side], …], scrollToEl?) – boxes in page CSS pixels for make_spec.py (JSON)
window.T = (sel, re, root = document) => [...root.querySelectorAll(sel)].find((e) => re.test((e.getAttribute('aria-label') || e.textContent || '').trim()) && e.getBoundingClientRect().width > 0)
window.__prep = async (path, ms = 2500) => {
  history.pushState({}, '', path)
  dispatchEvent(new PopStateEvent('popstate'))
  await new Promise((r) => setTimeout(r, ms))
  document.documentElement.classList.remove('dark')
  if (!document.getElementById('__noanim')) {
    const st = document.createElement('style')
    st.id = '__noanim'
    st.textContent = '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important} a[href="/design-system"]{display:none!important}'
    document.head.appendChild(st)
  }
  // Support banner ("You are signed in as … for support") and announcements: not in staff pictures.
  const ret = window.T('button,a', /Return to admin/)
  let b = ret
  while (b && b.parentElement && !b.parentElement.querySelector('main')) b = b.parentElement
  if (b && b !== document.body) b.style.display = 'none'
  window.scrollTo(0, 0)
}
window.__marks = (list, scrollToEl) => {
  if (scrollToEl) {
    scrollToEl.scrollIntoView({ block: 'start' })
    window.scrollBy(0, -90)
  }
  const marks = list.map(([n, el, side]) => {
    if (!el) return { n, missing: true }
    const r = el.getBoundingClientRect()
    return { n, box: [Math.round(r.x), Math.round(r.y + scrollY), Math.round(r.width), Math.round(r.height)], side: side || 'left' }
  })
  const main = document.querySelector('main').getBoundingClientRect()
  return JSON.stringify({ iw: innerWidth, ih: innerHeight, sy: Math.round(scrollY), mainX: Math.round(main.x), marks })
}
// Example (room board):
// await __prep('/rooms'); __marks([[1, T('[data-slot=sidebar] a', /^Room board$/), 'right'], [3, T('main button', /^Dirty/), 'bottom'],
//   [4, T('main [role=group]', /Arriving/), 'bottom'], [5, T('main span', /^Owes$/), 'right'], [6, document.querySelector('button[aria-label^="Room "]'), 'left'],
//   [7, T('main label', /Day/)?.closest('div')?.parentElement, 'right']])
