<x-mail::message>
@if ($waiting)
# A device is waiting for approval
@else
# New sign-in
@endif

**{{ $person }}** {{ $waiting ? 'tried to sign in' : 'signed in' }} from a new device.

- Device: {{ $device }}
@if ($ip)
- Address: {{ $ip }}
@endif
- Time: {{ $time }}

@if ($waiting)
Approve it in Team → Devices if this is a hotel device.
@else
If this was not expected, sign the device out in Team → Devices.
@endif

<x-mail::button :url="$link">
Open devices
</x-mail::button>

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
