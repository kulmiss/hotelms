@php
    $lang = app()->getLocale();
    $labels = ['overall' => __('Your stay overall'), 'cleanliness' => __('Cleanliness'), 'staff' => __('Staff'), 'comfort' => __('Comfort'), 'value' => __('Value for money')];
    $err = fn (string $k) => isset($errors[$k]) ? '<span class="err" id="e-'.$k.'">'.e($errors[$k]).'</span>' : '';
@endphp
@extends('landing.layout', ['title' => __('Your stay at :hotel', ['hotel' => $hotel->name]), 'description' => __('Tell :hotel how your stay was.', ['hotel' => $hotel->name]), 'path' => '/f/'.$secret, 'bare' => true, 'noindex' => true])

@section('main')
<style>
.stars{display:inline-flex;flex-direction:row-reverse;gap:2px}
.stars input{position:absolute;opacity:0;width:1px;height:1px}
.stars label{min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;font-size:30px;line-height:1;color:var(--line);cursor:pointer}
.stars input:checked~label,.stars label:hover,.stars label:hover~label{color:#f59e0b}
.stars input:focus-visible+label{outline:2px solid var(--brand);outline-offset:2px;border-radius:8px}
fieldset{border:0;padding:0;margin:0;display:grid;gap:4px}
legend{font-weight:600;font-size:15px;padding:0}
</style>
<div class="wrap" style="padding-top:32px;padding-bottom:48px;max-width:640px">
  <p class="muted" style="font-weight:600">{{ $hotel->name }}</p>
  <h1 style="font-size:clamp(26px,5vw,36px);margin-top:4px">{{ __('How was your stay?') }}</h1>
  @if ($sent)
    <div class="ok" role="status" style="margin-top:24px"><strong>{{ __('Thank you for your feedback!') }}</strong><br>{{ __('The hotel reads every answer. Your words are kept as you wrote them.') }}</div>
  @elseif ($expired)
    <p class="lead">{{ __('This link has expired. Thank you for staying with us.') }}</p>
  @else
    <p class="lead">{{ __('It takes one minute. Only the stars for your stay overall are needed.') }}</p>
    <form class="form card" method="post" action="/f/{{ $secret }}?lang={{ $lang }}" style="margin-top:20px" novalidate>
      @isset($errors['form'])<p class="err" role="alert">{{ $errors['form'] }}</p>@endisset
      @foreach ($labels as $k => $label)
        <fieldset @isset($errors[$k]) aria-describedby="e-{{ $k }}" @endisset>
          <legend>{{ $label }}@if ($k === 'overall') *@endif</legend>
          <div class="stars">
            @for ($n = 5; $n >= 1; $n--)
              <input type="radio" name="{{ $k }}" id="{{ $k }}-{{ $n }}" value="{{ $n }}" @checked((string) ($old[$k] ?? '') === (string) $n) @if ($k === 'overall') required @endif>
              <label for="{{ $k }}-{{ $n }}" title="{{ trans_choice('{1} :n star|[2,*] :n stars', $n, ['n' => $n]) }}"><span aria-hidden="true">★</span><span class="skip">{{ trans_choice('{1} :n star|[2,*] :n stars', $n, ['n' => $n]) }}</span></label>
            @endfor
          </div>
          {!! $err($k) !!}
        </fieldset>
      @endforeach
      <label>{{ __('Anything you want to tell the hotel? (optional)') }} <textarea name="comment" maxlength="2000">{{ $old['comment'] ?? '' }}</textarea>{!! $err('comment') !!}</label>
      <label style="display:flex;gap:10px;align-items:flex-start;font-weight:400;min-height:44px"><input type="checkbox" name="public_ok" value="1" style="width:20px;min-height:20px;margin-top:2px" @checked(($old['public_ok'] ?? null) === '1')> <span>{{ __('The hotel may show my stars and comment on its booking page, with my first name only.') }}</span></label>
      <button class="btn btn-primary" type="submit">{{ __('Send feedback') }}</button>
      <p class="muted" style="font-size:13px">{{ __('Your answer goes only to :hotel. No account and no cookies. See the', ['hotel' => $hotel->name]) }} <a href="{{ \App\Support\PublicLocale::url('/privacy') }}">{{ __('Privacy policy') }}</a>.</p>
    </form>
  @endif
</div>
@endsection
