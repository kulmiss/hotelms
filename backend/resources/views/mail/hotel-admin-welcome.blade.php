<x-mail::message>
# Welcome to {{ $platformName }}

Hello {{ $admin->name }},

Your hotel **{{ $hotel->name }}** is ready on {{ $platformName }}. You are its Hotel Admin.

**Email:** {{ $admin->email }}<br>
**Temporary password:** {{ $temporaryPassword }}

You will be asked to choose your own password when you sign in for the first time.

<x-mail::button :url="$signInUrl">
Sign in
</x-mail::button>

@if ($hotel->trial_ends_at)
Your free trial runs until {{ $hotel->trial_ends_at->format('j F Y') }}.
@endif

Thanks,<br>
The {{ $platformName }} team

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
