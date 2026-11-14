@php
    $h = $p['hotel'];
    $types = collect($p['branches'])->flatMap(fn ($b) => collect($b['types'])->map(fn ($t) => $t + ['branch' => $b['name'], 'branch_id' => $b['id']]));
    $multi = count($p['branches']) > 1;
    $val = fn (string $k, $d = '') => e((string) ($old[$k] ?? $d));
    $err = fn (string $k) => isset($errors[$k]) ? '<span class="err" id="e-'.$k.'">'.e($errors[$k]).'</span>' : '';
    $desc = 'Ask '.$h['name'].($h['city'] ? ' in '.$h['city'] : '').' for a room: choose your dates and send a booking request.';
@endphp
@extends('landing.layout', ['title' => 'Book a room – '.$h['name'], 'description' => $desc, 'path' => '/h/'.$slug, 'bare' => true])

@section('main')
<header style="border-bottom:1px solid var(--line)">
  <div class="wrap bar">
    <span class="logo">
      @if ($h['logo'])<img src="{{ $h['logo'] }}" alt="" width="32" height="32" style="width:32px;height:32px;object-fit:contain;border-radius:8px">@endif
      {{ $h['name'] }}
    </span>
    @if ($h['phone'])<a class="btn btn-outline btn-sm" href="tel:{{ preg_replace('/[^0-9+]/', '', $h['phone']) }}">Call {{ $h['phone'] }}</a>@endif
  </div>
</header>
<div class="wrap" style="padding-top:32px;padding-bottom:48px;max-width:860px">
  <h1 style="font-size:clamp(28px,5vw,40px)">Book a room</h1>
  <p class="lead">{{ $h['city'] ? $h['name'].', '.$h['city'].'.' : $h['name'].'.' }} Send your dates – the hotel confirms by phone.</p>
  @if ($p['note'])<p class="muted" style="margin-top:8px">{{ $p['note'] }}</p>@endif

  @if ($sent)
    <div class="ok" role="status" style="margin-top:24px"><strong>Thank you! Your request was sent.</strong><br>The hotel will call you to confirm. Nothing is booked or paid until they do.</div>
  @endif

  @if ($types->isNotEmpty())
    <h2 class="title" style="font-size:22px;margin-top:36px">Rooms</h2>
    <div class="grid" style="margin-top:16px">
      @foreach ($types as $t)
        <div class="card">
          <h3 style="margin-top:0">{{ $t['name'] }}</h3>
          @if ($multi)<p style="font-size:13px">{{ $t['branch'] }}</p>@endif
          @if ($t['price'])<p style="color:var(--text);font-weight:700;font-size:18px;margin-top:6px">{{ $t['currency'] }} {{ $t['price'] }} <small class="muted" style="font-weight:500;font-size:13px">/ night</small></p>@endif
          <p style="margin-top:6px">Up to {{ $t['max'] }} {{ $t['max'] == 1 ? 'guest' : 'guests' }}@if ($t['amenities']) · {{ implode(', ', $t['amenities']) }}@endif</p>
        </div>
      @endforeach
    </div>
  @endif

  <h2 class="title" style="font-size:22px;margin-top:40px">Your request</h2>
  <form class="form card" method="post" action="/h/{{ $slug }}" style="margin-top:16px" novalidate>
    @isset($errors['form'])<p class="err" role="alert">{{ $errors['form'] }}</p>@endisset
    <input type="hidden" name="form_token" value="{{ $token }}">
    <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
    @if ($multi)
      <label>Hotel / branch
        <select name="branch_id" required>
          @foreach ($p['branches'] as $b)<option value="{{ $b['id'] }}" @selected(($old['branch_id'] ?? null) == $b['id'])>{{ $b['name'] }}{{ $b['city'] ? ' – '.$b['city'] : '' }}</option>@endforeach
        </select>{!! $err('branch_id') !!}
      </label>
    @else
      <input type="hidden" name="branch_id" value="{{ $p['branches'][0]['id'] ?? '' }}">
    @endif
    <label>Room
      <select name="room_type_id">
        <option value="">Any room</option>
        @foreach ($types as $t)<option value="{{ $t['id'] }}" @selected(($old['room_type_id'] ?? null) == $t['id'])>{{ $t['name'] }}{{ $multi ? ' ('.$t['branch'].')' : '' }}</option>@endforeach
      </select>{!! $err('room_type_id') !!}
    </label>
    <div class="two">
      <label>Arrival <input type="date" name="check_in" required min="{{ now()->toDateString() }}" value="{{ $val('check_in') }}" @isset($errors['check_in']) aria-describedby="e-check_in" aria-invalid="true" @endisset>{!! $err('check_in') !!}</label>
      <label>Departure <input type="date" name="check_out" required min="{{ now()->addDay()->toDateString() }}" value="{{ $val('check_out') }}" @isset($errors['check_out']) aria-describedby="e-check_out" aria-invalid="true" @endisset>{!! $err('check_out') !!}</label>
    </div>
    <div class="two">
      <label>Adults <input type="number" name="adults" min="1" max="20" required value="{{ $val('adults', '1') }}">{!! $err('adults') !!}</label>
      <label>Children <input type="number" name="children" min="0" max="20" value="{{ $val('children', '0') }}">{!! $err('children') !!}</label>
    </div>
    <label>Your name <input name="name" autocomplete="name" required value="{{ $val('name') }}">{!! $err('name') !!}</label>
    <div class="two">
      <label>Phone <input type="tel" name="phone" autocomplete="tel" inputmode="tel" required placeholder="+251 9…" value="{{ $val('phone') }}">{!! $err('phone') !!}</label>
      <label>Email (optional) <input type="email" name="email" autocomplete="email" value="{{ $val('email') }}">{!! $err('email') !!}</label>
    </div>
    <label>Message (optional) <textarea name="note" maxlength="500">{{ $val('note') }}</textarea>{!! $err('note') !!}</label>
    <button class="btn btn-primary" type="submit">Send request</button>
    <p class="muted" style="font-size:13px">Your details go only to {{ $h['name'] }}, to answer your request. See the <a href="/privacy">privacy policy</a>.</p>
  </form>
</div>
<footer class="foot">
  <div class="wrap row">
    <span>© {{ date('Y') }} {{ $h['name'] }}</span>
    @if ($h['email'])<a href="mailto:{{ $h['email'] }}">{{ $h['email'] }}</a>@endif
    @if ($p['powered_by'])<a class="powered" href="/">Powered by {{ $c['platform_name'] ?: 'HotelMS' }}</a>@endif
  </div>
</footer>
@endsection
