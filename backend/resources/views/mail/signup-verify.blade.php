<x-mail::message>
# Confirm your email

Hello {{ $name }},

Thank you for registering **{{ $hotel }}** on {{ $platformName }}.
Please confirm this email address (the link works for {{ $days }} days):

<x-mail::button :url="$link">
Confirm my email
</x-mail::button>

Not you? Do nothing. The sign-up is removed after {{ $days }} days.

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
