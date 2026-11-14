<x-mail::message>
@if ($approved)
# Your hotel is ready

Hello {{ $name }},

**{{ $hotel }}** is approved. You can sign in now and set up your hotel.

<x-mail::button :url="$signInUrl">
Sign in
</x-mail::button>
@else
# About your sign-up

Hello {{ $name }},

We could not approve **{{ $hotel }}** on {{ $platformName }}.

@if ($reason)
Reason: {{ $reason }}
@endif

If you think this is a mistake, reply to this email.
@endif

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
