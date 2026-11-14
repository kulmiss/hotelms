@php
    $open = $c['signup_mode'] !== 'off';
    $contact = $c['whatsapp']['href'] ?? ($c['email'] ? 'mailto:'.$c['email'] : null);
    $hero = $c['pictures']['hero'];
    // Lucide icons (ISC licence), drawn inline: no icon font, no extra download.
    $icons = [
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8H8M16 12H8M13 16H8"/>',
        'sparkles' => '<path d="M12 3l1.9 5.8L20 10l-6.1 1.2L12 17l-1.9-5.8L4 10l6.1-1.2Z"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'phone' => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.7 9a1 1 0 0 1-.6 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1Z"/>',
        'building' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'wifi' => '<path d="M12 20h.01M2 8.8a15 15 0 0 1 20 0M5 12.9a10 10 0 0 1 14 0M8.5 16.4a5 5 0 0 1 7 0"/>',
    ];
    $i = fn (string $n) => '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">'.$icons[$n].'</svg>';
    $features = [
        ['grid', 'Room board and front desk', 'Every room and its status on one screen. Walk-ins, check-in and check-out in a few taps.'],
        ['calendar', 'Bookings your way', 'Nights, day use and hourly stays, deposits, cancellations and no-shows with your own rules.'],
        ['receipt', 'Bills and payments', 'Bills with VAT and your TIN, split bills, cash, bank and mobile money, photos of payment slips.'],
        ['sparkles', 'Housekeeping', 'Dirty rooms become cleaning tasks automatically; staff mark them done from a phone.'],
        ['chart', 'Reports', 'Occupancy, revenue, payments and taxes for any day or month, and a daily summary.'],
        ['users', 'Staff and permissions', 'Each person sees only what their role allows. Every change is in the activity log.'],
        ['phone', 'Phone and tablet ready', 'Big buttons for touch screens. The latest bookings stay viewable when the internet drops.'],
        ['building', 'More than one property', 'Run several branches from one account and compare them (bigger plans).'],
        ['shield', 'Safe by default', 'Two-step sign-in, sign-in alerts, and each hotel\'s data kept apart from all others.'],
    ];
@endphp
@extends('landing.layout', ['title' => $c['platform_name'].' – hotel software for Ethiopia, free to start', 'description' => \Illuminate\Support\Str::limit($c['subline'], 155), 'preload' => $hero])

@section('main')
<section class="hero">
  <div class="wrap">
    <span class="eyebrow">Hotel management for Ethiopia</span>
    <h1>{{ $c['headline'] }}</h1>
    <p class="lead">{{ $c['subline'] }}</p>
    <div class="ctas">
      @if ($open)
        <a class="btn btn-primary" href="/register">Register your hotel free</a>
      @elseif ($contact)
        <a class="btn btn-primary" href="{{ $contact }}" rel="noopener">Contact us{{ $c['whatsapp'] ? ' on WhatsApp' : '' }}</a>
      @endif
      <a class="btn btn-outline" href="{{ $c['demo'] ? '/login' : '#screens' }}">See demo</a>
    </div>
    <p class="note">Free plan for small hotels · No card needed · Upgrade only when you grow</p>
    <div class="device">
      <div class="top" aria-hidden="true"><i></i><i></i><i></i></div>
      <img src="{{ $hero['src'] }}"@if (! empty($hero['srcset'])) srcset="{{ $hero['srcset'] }}" sizes="(max-width: 1160px) calc(100vw - 40px), 1120px"@endif width="{{ $hero['w'] }}" height="{{ $hero['h'] }}" alt="{{ $hero['alt'] }}" fetchpriority="high">
    </div>
  </div>
</section>

@if ($c['sections']['trust'])
<div class="trust">
  <div class="wrap">
    <ul>
      <li>{!! $i('check') !!} Prices in birr, VAT and TIN on bills</li>
      <li>{!! $i('check') !!} Works on phones and weak internet</li>
      <li>{!! $i('check') !!} Encrypted daily backups</li>
      <li>{!! $i('check') !!} Your guest data is yours</li>
    </ul>
  </div>
</div>
@endif

@if ($c['sections']['features'])
<section id="features">
  <div class="wrap">
    <span class="eyebrow">Features</span>
    <h2 class="title">Everything the front desk needs, nothing it doesn't</h2>
    <p class="lead">Made for busy reception desks: clear screens, few clicks, and the same app on the computer and the phone.</p>
    <div class="grid">
      @foreach ($features as [$icon, $title, $text])
        <div class="card"><span class="badge-ico">{!! $i($icon) !!}</span><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
      @endforeach
    </div>
  </div>
</section>
@endif

@if ($c['sections']['how'])
<section id="how" style="background:var(--muted)">
  <div class="wrap">
    <span class="eyebrow">How it works</span>
    <h2 class="title">Ready in an afternoon</h2>
    <ol class="steps">
      <li class="card"><h3>{{ $open ? 'Register free' : 'Contact us' }}</h3><p>{{ $open ? 'Your hotel name, your email and a password. Confirm your email and you are in.' : 'We create your hotel account and send you the sign-in.' }}</p></li>
      <li class="card"><h3>Set up your rooms</h3><p>A short walkthrough: room types, prices, taxes and payment methods. Import rooms from Excel if you like.</p></li>
      <li class="card"><h3>Start working</h3><p>Take bookings, check guests in and out, and add your staff with the right permissions.</p></li>
    </ol>
  </div>
</section>
@endif

@if ($c['sections']['gallery'])
<section id="screens">
  <div class="wrap">
    <span class="eyebrow">Screens</span>
    <h2 class="title">See it before you start</h2>
    <p class="lead">Real screens from our demo hotel.</p>
    <div class="gallery">
      @foreach ($c['pictures']['gallery'] as $n => $pic)
        <figure class="shot{{ empty($pic['phone']) && $n === 0 ? ' wide' : '' }}">
          @if (! empty($pic['phone']))
            <div class="phone"><img src="{{ $pic['src'] }}" width="{{ $pic['w'] }}" height="{{ $pic['h'] }}" alt="{{ $pic['alt'] }}" loading="lazy" decoding="async"></div>
          @else
            <div class="device"><div class="top" aria-hidden="true"><i></i><i></i><i></i></div><img src="{{ $pic['src'] }}"@if (! empty($pic['srcset'])) srcset="{{ $pic['srcset'] }}" sizes="(max-width: 860px) calc(100vw - 40px), 560px"@endif width="{{ $pic['w'] }}" height="{{ $pic['h'] }}" alt="{{ $pic['alt'] }}" loading="lazy" decoding="async"></div>
          @endif
          <figcaption>{{ $pic['caption'] ?? '' }}</figcaption>
        </figure>
      @endforeach
    </div>
  </div>
</section>
@endif

@if ($c['sections']['plans'] && $c['plans'])
<section id="plans" style="background:var(--muted)">
  <div class="wrap center">
    <span class="eyebrow">Plans</span>
    <h2 class="title">Start free. Grow when you are ready.</h2>
    <p class="lead">The daily work is in every plan. Bigger plans add rooms, staff, properties and extras.</p>
    <input class="cy" type="radio" name="cycle" id="cy-m" checked>
    <input class="cy" type="radio" name="cycle" id="cy-y">
    <div class="cycle" role="group" aria-label="Billing period"><label for="cy-m">Monthly</label><label for="cy-y">Yearly · {{ $c['plans'][1]['free_months'] ?? 2 }} months free</label></div>
    <div class="plans">
      @foreach ($c['plans'] as $p)
        <div class="card plan{{ $p['recommended'] ? ' rec' : '' }}">
          @if ($p['recommended'])<span class="tag">Recommended</span>@endif
          <div>
            <h3 style="margin:0;font-size:19px">{{ $p['name'] }}</h3>
            <p class="muted" style="font-size:14px">{{ $p['description'] }}</p>
          </div>
          <div class="price">
            @if ($p['free'])
              Free <small>forever</small>
            @else
              <span class="pm">{{ $p['currency'] }} {{ $p['monthly'] }}</span><span class="py">{{ $p['currency'] }} {{ $p['yearly'] }}</span>
              <small class="mo">/ month</small><small class="yr">/ year</small>
            @endif
          </div>
          <ul>
            @foreach ($p['points'] as $point)<li>{!! $i('check') !!}<span>{{ $point }}</span></li>@endforeach
          </ul>
          @if ($p['free'] && $open)
            <a class="btn {{ $p['recommended'] ? 'btn-primary' : 'btn-outline' }}" href="/register">Register free</a>
          @elseif ($contact)
            <a class="btn {{ $p['recommended'] ? 'btn-primary' : 'btn-outline' }}" href="{{ $contact }}" rel="noopener">{{ $p['free'] ? 'Contact us' : 'Choose '.$p['name'] }}</a>
          @endif
        </div>
      @endforeach
    </div>
    <details class="compare">
      <summary>Compare all plans</summary>
      <div class="table">
        <table>
          <caption class="skip">What each plan includes</caption>
          <thead><tr><th scope="col">Included</th>@foreach ($c['compare']['plans'] as $n)<th scope="col">{{ $n }}</th>@endforeach</tr></thead>
          <tbody>
            @foreach ($c['compare']['rows'] as $row)
              <tr><th scope="row" style="font-weight:500">{{ $row['label'] }}</th>@foreach ($row['values'] as $v)<td>{{ $v }}</td>@endforeach</tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </details>
    <p class="note">Prices in birr. No online payment: we send an invoice. Changing plans never deletes data.</p>
  </div>
</section>
@endif

@if ($c['sections']['security'])
<section id="security">
  <div class="wrap">
    <span class="eyebrow">Security and privacy</span>
    <h2 class="title">Your hotel's data, kept safe</h2>
    <p class="lead">Your hotel decides about its guests' data; we keep it safe for you.</p>
    <div class="sec">
      <div class="card"><span class="badge-ico">{!! $i('lock') !!}</span><h3>Two-step sign-in</h3><p>A code from an app or email, alerts for new devices, and a screen lock for shared computers.</p></div>
      <div class="card"><span class="badge-ico">{!! $i('database') !!}</span><h3>Encrypted backups</h3><p>Backups every night and every hour, encrypted. Guest ID photos are stored encrypted too.</p></div>
      <div class="card"><span class="badge-ico">{!! $i('eye') !!}</span><h3>No ads, no tracking</h3><p>We never sell data or show ads. This website sets no cookies.</p></div>
      <div class="card"><span class="badge-ico">{!! $i('download') !!}</span><h3>Yours to take</h3><p>Download all your hotel's data at any time. Sign-ins are logged and kept for one year.</p></div>
    </div>
  </div>
</section>
@endif

@if ($c['sections']['testimonials'])
<section id="stories" style="background:var(--muted)">
  <div class="wrap">
    <span class="eyebrow">Hotels using {{ $c['platform_name'] }}</span>
    <h2 class="title">What hotels say</h2>
    <div class="grid">
      @foreach ($c['testimonials'] as $t)
        <blockquote class="card quote" style="margin:0"><p>“{{ $t['quote'] }}”</p><footer>{{ $t['name'] }}@if (! empty($t['hotel'])), {{ $t['hotel'] }}@endif</footer></blockquote>
      @endforeach
    </div>
  </div>
</section>
@endif

@if ($c['sections']['faq'] && $c['faq'])
<section id="faq">
  <div class="wrap center">
    <span class="eyebrow">Questions</span>
    <h2 class="title">Good to know</h2>
    <div class="faq">
      @foreach ($c['faq'] as $q)
        @if (filled($q['q'] ?? null))
          <details><summary>{{ $q['q'] }}</summary><div><p>{{ $q['a'] ?? '' }}</p></div></details>
        @endif
      @endforeach
    </div>
  </div>
</section>
@endif

@if ($c['sections']['cta'])
<section>
  <div class="wrap">
    <div class="band">
      <h2 class="title">Try it with your own rooms</h2>
      <p class="lead" style="margin:12px auto 24px">Set up in an afternoon. Free for small hotels.</p>
      @if ($open)
        <a class="btn" href="/register">Register your hotel free</a>
      @elseif ($contact)
        <a class="btn" href="{{ $contact }}" rel="noopener">Contact us{{ $c['whatsapp'] ? ' on WhatsApp' : '' }}</a>
      @endif
    </div>
  </div>
</section>
@endif
@endsection
