@php
    // The brand colour (Super Admin → Branding) and a readable text colour on it.
    $brand = preg_match('/^#[0-9a-fA-F]{6}$/', $c['brand_color'] ?? '') ? $c['brand_color'] : '#0f766e';
    [$r, $g, $b] = sscanf($brand, '#%02x%02x%02x');
    $onBrand = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#0b0f19' : '#ffffff';
    $name = $c['platform_name'] ?: 'HotelMS';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ $url }}{{ $path ?? '/' }}">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b0f19" media="(prefers-color-scheme: dark)">
<meta name="color-scheme" content="light dark">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $name }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $url }}{{ $path ?? '/' }}">
<meta property="og:image" content="{{ $url }}/landing/og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/landing/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/landing/apple-touch-icon.png">
@isset($preload)<link rel="preload" as="image" href="{{ $preload['src'] }}"@if (! empty($preload['srcset'])) imagesrcset="{{ $preload['srcset'] }}" imagesizes="(max-width: 1160px) calc(100vw - 40px), 1120px"@endif fetchpriority="high">@endisset
<style>
:root{--bg:#fff;--card:#fff;--muted:#f1f5f9;--text:#0b1220;--sub:#475569;--line:#e2e8f0;--brand:{{ $brand }};--on-brand:{{ $onBrand }};--ok:#15803d;--shadow:0 1px 2px rgb(15 23 42/.06),0 1px 3px rgb(15 23 42/.08)}
@media (prefers-color-scheme:dark){:root{--bg:#0b0f19;--card:#111827;--muted:#1e293b;--text:#f1f5f9;--sub:#a3b1c6;--line:#283548;--ok:#4ade80;--shadow:0 1px 2px rgb(0 0 0/.4)}}
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
body{margin:0;background:var(--bg);color:var(--text);font:16px/1.6 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Ethiopic","Helvetica Neue",Arial,sans-serif;-webkit-font-smoothing:antialiased}
img{max-width:100%;height:auto;display:block}
a{color:inherit}
h1,h2,h3{line-height:1.2;letter-spacing:-.02em;margin:0}
p{margin:0}
.wrap{max-width:1160px;margin:0 auto;padding:0 20px}
.skip{position:absolute;left:-9999px}.skip:focus{left:12px;top:12px;z-index:9;background:var(--card);padding:8px 12px;border-radius:8px}
:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 20px;border-radius:10px;font-weight:600;font-size:15px;text-decoration:none;border:1px solid transparent;transition:opacity .15s,background-color .15s}
.btn:hover{opacity:.9}
.btn-primary{background:var(--brand);color:var(--on-brand)}
.btn-outline{border-color:var(--line);background:var(--card);color:var(--text)}
.btn-sm{min-height:44px;padding:0 14px;font-size:14px}
header{position:sticky;top:0;z-index:5;background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.bar{display:flex;align-items:center;gap:12px;min-height:64px}
.logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px;text-decoration:none;margin-right:auto}
.mark{width:30px;height:30px;border-radius:8px;background:var(--brand);color:var(--on-brand);display:grid;place-items:center;font-size:15px}
.nav{display:none;gap:4px}.nav a{padding:10px 12px;border-radius:8px;text-decoration:none;color:var(--sub);font-size:14px;font-weight:500}.nav a:hover{background:var(--muted);color:var(--text)}
@media (min-width:860px){.nav{display:flex}}
.signin{display:none}@media (min-width:520px){.signin{display:inline-flex}}
section{padding:64px 0}
.muted{color:var(--sub)}
.eyebrow{display:inline-block;font-size:13px;font-weight:600;color:var(--brand);margin-bottom:10px}
@media (prefers-color-scheme:dark){.eyebrow{color:color-mix(in srgb,var(--brand) 55%,#fff)}}
.title{font-size:clamp(26px,4vw,36px);font-weight:700}
.lead{font-size:17px;color:var(--sub);max-width:640px;margin-top:12px}
.center{text-align:center}.center .lead{margin-left:auto;margin-right:auto}
.hero{padding:48px 0 32px}
.hero h1{font-size:clamp(32px,6vw,56px);font-weight:800;max-width:820px}
.hero .lead{font-size:clamp(17px,2.2vw,20px);margin-top:18px}
.ctas{display:flex;flex-wrap:wrap;gap:12px;margin-top:28px}
.note{font-size:14px;color:var(--sub);margin-top:14px}
.device{margin-top:40px;border:1px solid var(--line);border-radius:16px;background:var(--card);box-shadow:0 20px 50px -20px rgb(15 23 42/.35);overflow:hidden}
.device .top{display:flex;gap:6px;padding:10px 14px;border-bottom:1px solid var(--line);background:var(--muted)}
.device .top i{width:10px;height:10px;border-radius:50%;background:var(--line)}
.trust{border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:22px 0;background:var(--muted)}
.trust ul{list-style:none;margin:0;padding:0;display:grid;gap:12px 24px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
.trust li{display:flex;gap:10px;align-items:center;font-size:15px;font-weight:500}
.ico{width:20px;height:20px;flex:none;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.trust .ico{color:var(--ok)}
.grid{display:grid;gap:16px;margin-top:36px;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px;box-shadow:var(--shadow)}
.card h3{font-size:17px;margin:12px 0 6px}
.card p{color:var(--sub);font-size:15px}
.badge-ico{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;background:var(--muted);color:var(--text)}
.steps{counter-reset:s;display:grid;gap:16px;margin-top:36px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));list-style:none;padding:0}
.steps li{counter-increment:s}
.steps li::before{content:counter(s);display:grid;place-items:center;width:36px;height:36px;border-radius:50%;background:var(--brand);color:var(--on-brand);font-weight:700}
.gallery{display:grid;gap:20px;margin-top:36px;grid-template-columns:1fr}
@media (min-width:860px){.gallery{grid-template-columns:2fr 2fr 1fr}.gallery .wide{grid-column:span 2}}
.shot{margin:0}.shot figcaption{font-size:14px;color:var(--sub);margin-top:8px;text-align:center}
.shot .device{margin-top:0}
.phone{max-width:280px;margin:0 auto;border:8px solid #0f172a;border-radius:34px;overflow:hidden;box-shadow:0 20px 40px -18px rgb(15 23 42/.5)}
.cycle{display:inline-flex;margin:28px auto 0;padding:4px;border:1px solid var(--line);border-radius:12px;background:var(--muted)}
.cy{position:absolute;opacity:0;width:1px;height:1px}
.cycle label{min-height:44px;display:inline-flex;align-items:center;padding:0 16px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;color:var(--sub)}
#cy-m:checked~.cycle [for=cy-m],#cy-y:checked~.cycle [for=cy-y]{background:var(--card);color:var(--text);box-shadow:var(--shadow)}
#cy-m:focus-visible~.cycle [for=cy-m],#cy-y:focus-visible~.cycle [for=cy-y]{outline:2px solid var(--brand);outline-offset:2px}
.py,.yr{display:none}
#cy-y:checked~.plans .py,#cy-y:checked~.plans .yr{display:inline}#cy-y:checked~.plans .pm,#cy-y:checked~.plans .mo{display:none}
.plans{display:grid;gap:16px;margin-top:28px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));text-align:left}
.plan{display:flex;flex-direction:column;gap:14px;position:relative}
.plan.rec{border:2px solid var(--brand);box-shadow:0 12px 30px -16px var(--brand)}
.plan .tag{position:absolute;top:-12px;left:20px;background:var(--brand);color:var(--on-brand);font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px}
.price{font-size:32px;font-weight:800;letter-spacing:-.02em}.price small{font-size:14px;font-weight:500;color:var(--sub)}
.plan ul{list-style:none;padding:0;margin:0;display:grid;gap:8px;font-size:15px}
.plan li{display:flex;gap:8px;align-items:flex-start}.plan li .ico{color:var(--ok);margin-top:3px;width:18px;height:18px}
.plan .btn{margin-top:auto}
details{border:1px solid var(--line);border-radius:12px;background:var(--card)}
summary{cursor:pointer;list-style:none;min-height:52px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;font-weight:600}
summary::-webkit-details-marker{display:none}
summary::after{content:"+";font-size:22px;font-weight:400;color:var(--sub)}details[open]>summary::after{content:"–"}
details>div{padding:0 18px 16px;color:var(--sub)}
.compare{margin-top:24px}
.table{overflow-x:auto;padding:0 0 8px}
table{border-collapse:collapse;width:100%;min-width:640px;font-size:14px}
th,td{padding:10px 12px;border-top:1px solid var(--line);text-align:center}
th:first-child,td:first-child{text-align:left}
thead th{border-top:0;font-size:14px}
.faq{display:grid;gap:10px;margin-top:32px;max-width:820px;margin-left:auto;margin-right:auto;text-align:left}
.sec{display:grid;gap:16px;margin-top:36px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
.quote{font-size:16px}.quote footer{margin-top:12px;font-size:14px;color:var(--sub)}
.band{background:var(--brand);color:var(--on-brand);border-radius:20px;padding:44px 24px;text-align:center}
.band .lead{color:inherit;opacity:.92}
.band .btn{background:var(--on-brand);color:var(--brand)}
.foot{border-top:1px solid var(--line);padding:32px 0 40px;font-size:14px;color:var(--sub)}
.foot .row{display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center}
.foot a{display:inline-flex;align-items:center;min-height:44px;text-decoration:none}.foot a:hover{text-decoration:underline}
.prose{max-width:760px;margin:0 auto;padding:40px 20px 64px}
.prose h1{font-size:32px;margin-bottom:8px}.prose h2{font-size:21px;margin:32px 0 8px}.prose p,.prose li{color:var(--sub)}.prose p{margin:10px 0}
.form{display:grid;gap:14px}.form label{display:grid;gap:6px;font-size:14px;font-weight:600}
.form input,.form select,.form textarea{font:inherit;font-weight:400;min-height:48px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--text);width:100%}
.form textarea{min-height:90px}.two{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr))}
.err{color:#b91c1c;font-size:13px;font-weight:500}@media (prefers-color-scheme:dark){.err{color:#fca5a5}}
.ok{border:1px solid var(--ok);border-radius:12px;padding:16px;background:color-mix(in srgb,var(--ok) 10%,var(--bg))}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.powered{display:inline-flex;align-items:center;gap:6px;min-height:44px;font-size:13px;color:var(--sub);text-decoration:none;border:1px solid var(--line);border-radius:999px;padding:0 12px}
.draft{border:1px solid #d97706;background:color-mix(in srgb,#f59e0b 14%,var(--bg));color:var(--text);padding:10px 14px;border-radius:10px;font-size:14px;margin:12px 0 24px}
</style>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
@unless ($bare ?? false)
<header>
  <div class="wrap bar">
    <a class="logo" href="/"><span class="mark" aria-hidden="true">{{ mb_substr($name, 0, 1) }}</span>{{ $name }}</a>
    <nav class="nav" aria-label="Main">
      <a href="/#features">Features</a><a href="/#plans">Plans</a><a href="/#security">Security</a><a href="/#faq">Questions</a>
    </nav>
    <a class="btn btn-outline btn-sm signin" href="/login">Sign in</a>
    @if ($c['signup_mode'] !== 'off')
      <a class="btn btn-primary btn-sm" href="/register">Register free</a>
    @endif
  </div>
</header>
@endunless
<main id="main">
@yield('main')
</main>
@unless ($bare ?? false)
<footer class="foot">
  <div class="wrap row">
    <span>© {{ date('Y') }} {{ $name }}</span>
    @if ($c['whatsapp'])<a href="{{ $c['whatsapp']['href'] }}" rel="noopener">WhatsApp {{ $c['whatsapp']['label'] }}</a>@endif
    @if ($c['email'])<a href="mailto:{{ $c['email'] }}">{{ $c['email'] }}</a>@endif
    <a href="/terms">Terms</a>
    <a href="/privacy">Privacy</a>
    <a href="/.well-known/security.txt">Security</a>
    <a href="/login">Sign in</a>
  </div>
</footer>
@endunless
</body>
</html>
