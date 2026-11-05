<x-mail::message>
# {{ $isReset ? 'Your new password' : 'Welcome to '.$hotel->name }}

Hello {{ $user->name }},

@if ($isReset)
A manager at **{{ $hotel->name }}** has set a new temporary password for your account.
@else
You now have an account at **{{ $hotel->name }}** on {{ $platformName }}{{ $user->role ? ' as '.$user->role->name : '' }}.
@endif

**Email:** {{ $user->email }}<br>
**Temporary password:** {{ $temporaryPassword }}

You will be asked to choose your own password when you sign in.

<x-mail::button :url="$signInUrl">
Sign in
</x-mail::button>

Thanks,<br>
The {{ $platformName }} team

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
