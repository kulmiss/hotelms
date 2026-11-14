<x-mail::message>
# {{ $title }}

Hello {{ $name }},

{{ $text }}

<x-mail::table>
| | |
|:--|:--|
@foreach ($facts as [$label, $value])
| {{ $label }} | {{ $value }} |
@endforeach
</x-mail::table>

**Was this you?** {{ $advice }}

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
