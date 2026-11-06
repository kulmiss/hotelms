<x-mail::message>
# Set a new password

Hello {{ $name }},

Someone (hopefully you) asked to set a new password for {{ $platformName }}.

<x-mail::button :url="$link">
Set a new password
</x-mail::button>

The link works for 60 minutes and only once. If you did not ask for this, ignore this email – your password stays the same.

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
