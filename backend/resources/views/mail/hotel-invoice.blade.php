<x-mail::message>
# Invoice {{ $invoice->number }}

**{{ $invoice->hotel->name }}**

| | |
|:--|--:|
| Plan | {{ $invoice->plan?->name }} ({{ $invoice->billing_cycle }}) |
| Period | {{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }} |
| Rooms | {{ $invoice->rooms }} |
| **Amount** | **{{ $invoice->currency }} {{ number_format((float) $invoice->amount, 2) }}** |
| Due | {{ $invoice->due_date->format('j M Y') }} |

Pay before the due date to keep using {{ $platformName }} without a break.

<x-mail::button :url="$link">
Open the invoice
</x-mail::button>

Thanks,<br>
The {{ $platformName }} team

@if ($emailFooter)
<x-mail::subcopy>
{{ $emailFooter }}
</x-mail::subcopy>
@endif
</x-mail::message>
