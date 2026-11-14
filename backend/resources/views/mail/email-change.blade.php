<x-mail::message>
# Confirm your new sign-in email

Hello {{ $name }},

@if ($by)
{{ $by }} changed the sign-in email of your {{ $platformName }} account to **{{ $email }}**.
@else
You asked to sign in to {{ $platformName }} with **{{ $email }}** from now on.
@endif
It changes only when you open this link (it works for {{ $hours }} hours):

<x-mail::button :url="$link">
Confirm this email
</x-mail::button>

Not you? Do nothing: your sign-in email stays as it is.

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
