<x-mail::message>
# Your sign-in code

Hello {{ $name }},

Use this code to finish signing in to {{ $platformName }}:

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

It works for {{ $minutes }} minutes. If you did not try to sign in, change your password.

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
