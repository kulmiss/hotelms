<x-mail::message>
# Welcome to HotelMS

Hello {{ $admin->name }},

Your hotel **{{ $hotel->name }}** is ready on HotelMS. You are its Hotel Admin.

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
The HotelMS team
</x-mail::message>
