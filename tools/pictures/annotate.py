"""Draw numbered markers and boxes on app screenshots for the staff guide.

Spec (JSON): {"out": path, "tiles": [{"src": png, "at": [x, y]}], "origin": [x, y], "size": [w, h],
              "marks": [{"n": 1, "box": [x, y, w, h], "side": "left|right|top|bottom"}]}
Coordinates are page CSS pixels (tiles were captured 1:1 at `at`)."""
import json
import sys

from PIL import Image, ImageDraw, ImageFont

ORANGE = (234, 88, 12)
WHITE = (255, 255, 255)
FONT = ImageFont.truetype('/usr/share/fonts/opentype/inter/Inter-Bold.otf', 15)
SCALE = 2  # draw at 2x for smooth edges, then shrink


def main(spec_path: str) -> None:
    spec = json.load(open(spec_path))
    ox, oy = spec['origin']
    w, h = spec['size']
    k = spec.get('scale', 1000 / 1100)  # screenshot pixels per CSS pixel (1100 px wide view in a 1000 px pane)
    canvas = Image.new('RGB', (w, h), (250, 250, 250))
    for tile in spec['tiles']:
        img = Image.open(tile['src']).convert('RGB')
        canvas.paste(img, (round((tile['at'][0] - ox) * k), round((tile['at'][1] - oy) * k)))

    big = canvas.resize((w * SCALE, h * SCALE), Image.LANCZOS)
    overlay = Image.new('RGBA', big.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(overlay)
    font = ImageFont.truetype('/usr/share/fonts/opentype/inter/Inter-Bold.otf', 15 * SCALE)
    for m in spec['marks']:
        x, y, bw, bh = m['box']
        x, y = (x - ox) * k * SCALE, (y - oy) * k * SCALE
        bw, bh = bw * k * SCALE, bh * k * SCALE
        pad = 4 * SCALE
        if m.get('outline', True):
            d.rounded_rectangle([x - pad, y - pad, x + bw + pad, y + bh + pad], radius=10 * SCALE, outline=ORANGE + (255,), width=3 * SCALE)
        r = 13 * SCALE
        side = m.get('side', 'left')
        cy = y + bh / 2
        cx = x + bw / 2
        if side == 'left':
            cx = x - pad - r - 4 * SCALE
        elif side == 'right':
            cx = x + bw + pad + r + 4 * SCALE
        elif side == 'top':
            cy = y - pad - r - 2 * SCALE
        elif side == 'bottom':
            cy = y + bh + pad + r + 2 * SCALE
        elif side == 'corner':  # on the top-left corner of the box
            cx, cy = x - pad, y - pad
        # keep inside the picture
        cx = min(max(cx, r + 2), big.size[0] - r - 2)
        cy = min(max(cy, r + 2), big.size[1] - r - 2)
        d.ellipse([cx - r - 2 * SCALE, cy - r - 2 * SCALE, cx + r + 2 * SCALE, cy + r + 2 * SCALE], fill=WHITE + (255,))
        d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=ORANGE + (255,))
        label = str(m['n'])
        tb = d.textbbox((0, 0), label, font=font)
        d.text((cx - (tb[2] - tb[0]) / 2 - tb[0], cy - (tb[3] - tb[1]) / 2 - tb[1]), label, font=font, fill=WHITE + (255,))
    out = Image.alpha_composite(big.convert('RGBA'), overlay).convert('RGB').resize((w, h), Image.LANCZOS)
    # a fine border so the picture stands out on the page
    ImageDraw.Draw(out).rectangle([0, 0, w - 1, h - 1], outline=(212, 212, 216))
    out.save(spec['out'], 'WEBP', quality=86, method=6)
    print(spec['out'], out.size)


if __name__ == '__main__':
    main(sys.argv[1])
