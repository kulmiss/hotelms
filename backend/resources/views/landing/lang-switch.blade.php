{{-- English / Amharic for the public pages: plain links, no cookie (the choice is in the link). --}}
<nav class="lang" aria-label="{{ __('Language') }}">
  <a href="{{ \App\Support\PublicLocale::switchTo($path, 'en') }}" hreflang="en" lang="en"@if (app()->getLocale() === 'en') aria-current="true"@endif>EN</a>
  <a href="{{ \App\Support\PublicLocale::switchTo($path, 'am') }}" hreflang="am" lang="am"@if (app()->getLocale() === 'am') aria-current="true"@endif>አማ</a>
</nav>
