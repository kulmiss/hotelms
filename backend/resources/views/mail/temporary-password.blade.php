<x-mail::message>
# Your new password

Hello {{ $user->name }},

The HotelMS team has set a new temporary password for your account at **{{ $hotel->name }}**.

**Email:** {{ $user->email }}<br>
**Temporary password:** {{ $temporaryPassword }}

You will be asked to choose your own password when you sign in.

<x-mail::button :url="$signInUrl">
Sign in
</x-mail::button>

If you did not ask for this, please contact your Hotel Admin.

Thanks,<br>
The HotelMS team
</x-mail::message>
