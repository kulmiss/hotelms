/**
 * Tiny QR code maker (byte mode, error correction level M) for the two-step sign-in key.
 * No library: it follows the QR standard (ISO/IEC 18004) the same way as the well-known
 * public-domain reference implementations. Returns a square grid of dark (true) / light modules.
 */

// Error-correction codewords per block and number of blocks, level M, versions 1–40 (index 0 unused).
const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28]
const NUM_BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49]
const FORMAT_BITS_M = 0 // L=1, M=0, Q=3, H=2

function rawDataModules(ver: number): number {
  let result = (16 * ver + 128) * ver + 64
  if (ver >= 2) {
    const numAlign = Math.floor(ver / 7) + 2
    result -= (25 * numAlign - 10) * numAlign - 55
    if (ver >= 7) result -= 36
  }
  return result
}

const dataCodewords = (ver: number) => Math.floor(rawDataModules(ver) / 8) - ECC_PER_BLOCK[ver] * NUM_BLOCKS[ver]

function gfMultiply(x: number, y: number): number {
  let z = 0
  for (let i = 7; i >= 0; i--) {
    z = (z << 1) ^ ((z >>> 7) * 0x11d)
    z ^= ((y >>> i) & 1) * x
  }
  return z & 0xff
}

function rsDivisor(degree: number): number[] {
  const result = new Array<number>(degree).fill(0)
  result[degree - 1] = 1
  let root = 1
  for (let i = 0; i < degree; i++) {
    for (let j = 0; j < result.length; j++) {
      result[j] = gfMultiply(result[j], root)
      if (j + 1 < result.length) result[j] ^= result[j + 1]
    }
    root = gfMultiply(root, 0x02)
  }
  return result
}

function rsRemainder(data: number[], divisor: number[]): number[] {
  const result = divisor.map(() => 0)
  for (const b of data) {
    const factor = b ^ (result.shift() as number)
    result.push(0)
    divisor.forEach((coef, i) => (result[i] ^= gfMultiply(coef, factor)))
  }
  return result
}

function alignmentPositions(ver: number, size: number): number[] {
  if (ver === 1) return []
  const numAlign = Math.floor(ver / 7) + 2
  const step = ver === 32 ? 26 : Math.ceil((ver * 4 + 4) / (numAlign * 2 - 2)) * 2
  const result = [6]
  for (let pos = size - 7; result.length < numAlign; pos -= step) result.splice(1, 0, pos)
  return result
}

const bit = (x: number, i: number) => ((x >>> i) & 1) !== 0

export function qrMatrix(text: string): boolean[][] {
  const bytes = Array.from(new TextEncoder().encode(text))

  // Smallest version that fits (byte mode).
  let ver = 1
  for (; ver <= 40; ver++) {
    const ccBits = ver < 10 ? 8 : 16
    if (bytes.length < 1 << ccBits && 4 + ccBits + bytes.length * 8 <= dataCodewords(ver) * 8) break
  }
  if (ver > 40) throw new Error('Text too long for a QR code')
  const size = ver * 4 + 17

  // Data bits: mode, length, bytes, terminator, padding.
  const bits: number[] = []
  const push = (val: number, len: number) => {
    for (let i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1)
  }
  push(0x4, 4)
  push(bytes.length, ver < 10 ? 8 : 16)
  bytes.forEach((b) => push(b, 8))
  const capacity = dataCodewords(ver) * 8
  push(0, Math.min(4, capacity - bits.length))
  push(0, (8 - (bits.length % 8)) % 8)
  for (let pad = 0xec; bits.length < capacity; pad ^= 0xec ^ 0x11) push(pad, 8)
  const data: number[] = []
  for (let i = 0; i < bits.length; i += 8) data.push(bits.slice(i, i + 8).reduce((a, b) => (a << 1) | b, 0))

  // Error correction, blocks interleaved.
  const numBlocks = NUM_BLOCKS[ver]
  const eccLen = ECC_PER_BLOCK[ver]
  const raw = Math.floor(rawDataModules(ver) / 8)
  const numShort = numBlocks - (raw % numBlocks)
  const shortLen = Math.floor(raw / numBlocks)
  const divisor = rsDivisor(eccLen)
  const blocks: number[][] = []
  for (let i = 0, k = 0; i < numBlocks; i++) {
    const dat = data.slice(k, k + shortLen - eccLen + (i < numShort ? 0 : 1))
    k += dat.length
    const ecc = rsRemainder(dat, divisor)
    if (i < numShort) dat.push(0)
    blocks.push(dat.concat(ecc))
  }
  const codewords: number[] = []
  for (let i = 0; i < blocks[0].length; i++) {
    blocks.forEach((block, j) => {
      if (i !== shortLen - eccLen || j >= numShort) codewords.push(block[i])
    })
  }

  // Function patterns.
  const modules = Array.from({ length: size }, () => new Array<boolean>(size).fill(false))
  const fixed = Array.from({ length: size }, () => new Array<boolean>(size).fill(false))
  const set = (x: number, y: number, dark: boolean) => {
    modules[y][x] = dark
    fixed[y][x] = true
  }
  for (let i = 0; i < size; i++) {
    set(6, i, i % 2 === 0)
    set(i, 6, i % 2 === 0)
  }
  for (const [cx, cy] of [
    [3, 3],
    [size - 4, 3],
    [3, size - 4],
  ]) {
    for (let dy = -4; dy <= 4; dy++) {
      for (let dx = -4; dx <= 4; dx++) {
        const dist = Math.max(Math.abs(dx), Math.abs(dy))
        const x = cx + dx
        const y = cy + dy
        if (x >= 0 && x < size && y >= 0 && y < size) set(x, y, dist !== 2 && dist !== 4)
      }
    }
  }
  const align = alignmentPositions(ver, size)
  align.forEach((ay, i) =>
    align.forEach((ax, j) => {
      const last = align.length - 1
      if ((i === 0 && j === 0) || (i === 0 && j === last) || (i === last && j === 0)) return
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1)
    }),
  )
  const drawFormat = (mask: number) => {
    const d = (FORMAT_BITS_M << 3) | mask
    let rem = d
    for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537)
    const f = ((d << 10) | rem) ^ 0x5412
    for (let i = 0; i <= 5; i++) set(8, i, bit(f, i))
    set(8, 7, bit(f, 6))
    set(8, 8, bit(f, 7))
    set(7, 8, bit(f, 8))
    for (let i = 9; i < 15; i++) set(14 - i, 8, bit(f, i))
    for (let i = 0; i < 8; i++) set(size - 1 - i, 8, bit(f, i))
    for (let i = 8; i < 15; i++) set(8, size - 15 + i, bit(f, i))
    set(8, size - 8, true)
  }
  drawFormat(0)
  if (ver >= 7) {
    let rem = ver
    for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25)
    const v = (ver << 12) | rem
    for (let i = 0; i < 18; i++) {
      const a = size - 11 + (i % 3)
      const b = Math.floor(i / 3)
      set(a, b, bit(v, i))
      set(b, a, bit(v, i))
    }
  }

  // Codewords in the zig-zag.
  let n = 0
  for (let right = size - 1; right >= 1; right -= 2) {
    if (right === 6) right = 5
    for (let vert = 0; vert < size; vert++) {
      for (let j = 0; j < 2; j++) {
        const x = right - j
        const upward = ((right + 1) & 2) === 0
        const y = upward ? size - 1 - vert : vert
        if (!fixed[y][x] && n < codewords.length * 8) {
          modules[y][x] = bit(codewords[n >>> 3], 7 - (n & 7))
          n++
        }
      }
    }
  }

  // Best of the 8 masks (lowest penalty).
  const masked = (mask: number) => {
    const m = modules.map((row) => row.slice())
    for (let y = 0; y < size; y++) {
      for (let x = 0; x < size; x++) {
        if (fixed[y][x]) continue
        const flip = [
          (x + y) % 2 === 0,
          y % 2 === 0,
          x % 3 === 0,
          (x + y) % 3 === 0,
          (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
          ((x * y) % 2) + ((x * y) % 3) === 0,
          (((x * y) % 2) + ((x * y) % 3)) % 2 === 0,
          (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
        ][mask]
        if (flip) m[y][x] = !m[y][x]
      }
    }
    return m
  }
  let best: boolean[][] = modules
  let bestScore = Infinity
  let bestMask = 0
  for (let mask = 0; mask < 8; mask++) {
    drawFormat(mask)
    const m = masked(mask)
    const score = penalty(m)
    if (score < bestScore) {
      bestScore = score
      best = m
      bestMask = mask
    }
  }
  drawFormat(bestMask)
  // Copy the final format bits into the chosen grid.
  for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (fixed[y][x]) best[y][x] = modules[y][x]
  return best
}

/** Standard penalty rules (runs, 2×2 blocks, finder-like patterns, dark balance). */
function penalty(m: boolean[][]): number {
  const size = m.length
  let score = 0
  const lines: boolean[][] = []
  for (let i = 0; i < size; i++) {
    lines.push(m[i])
    lines.push(m.map((row) => row[i]))
  }
  for (const line of lines) {
    let run = 1
    for (let i = 1; i <= size; i++) {
      if (i < size && line[i] === line[i - 1]) run++
      else {
        if (run >= 5) score += run - 2
        run = 1
      }
    }
    const s = line.map((d) => (d ? '1' : '0')).join('')
    for (const p of ['10111010000', '00001011101']) {
      for (let at = s.indexOf(p); at !== -1; at = s.indexOf(p, at + 1)) score += 40
    }
  }
  for (let y = 0; y < size - 1; y++) {
    for (let x = 0; x < size - 1; x++) {
      const c = m[y][x]
      if (c === m[y][x + 1] && c === m[y + 1][x] && c === m[y + 1][x + 1]) score += 3
    }
  }
  const dark = m.reduce((a, row) => a + row.filter(Boolean).length, 0)
  const total = size * size
  score += Math.floor(Math.abs(dark * 20 - total * 10) / total) * 10
  return score
}

/** SVG path ("M x y h1 v1 h-1 z" per dark module) with a 4-module quiet zone. */
export function qrSvgPath(matrix: boolean[][]): { path: string; size: number } {
  const parts: string[] = []
  matrix.forEach((row, y) => row.forEach((dark, x) => dark && parts.push(`M${x + 4} ${y + 4}h1v1h-1z`)))
  return { path: parts.join(''), size: matrix.length + 8 }
}
