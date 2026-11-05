<x-mail::message>
# {{ $title }}

**{{ $hotelName }}**

@foreach ($lines as $line)
- {{ $line }}
@endforeach

@if ($fileName)
The full report is attached ({{ $fileName }}).
@endif

@if ($link)
<x-mail::button :url="$link">
Open in {{ $platformName }}
</x-mail::button>
@endif

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
