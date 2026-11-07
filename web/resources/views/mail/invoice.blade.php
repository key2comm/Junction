<x-mail::message>
# {{ ucfirst($kind) }} {{ $invoice->number }}

Hello {{ $invoice->account_name }},

Here is your {{ $kind }} for **{{ $month }}** (account {{ $invoice->account_code }}).

<x-mail::table>
| | |
|:--|--:|
| Opening balance | {{ $fmt($invoice->opening_balance) }} |
| Payments received | {{ $fmt($invoice->payments_total) }} |
@if ((float) $invoice->adjustments_total != 0)
| Adjustments | {{ $fmt($invoice->adjustments_total) }} |
@endif
| Usage ({{ number_format($invoice->calls) }} calls, {{ number_format($invoice->billsec / 60, 1) }} min) | −{{ $fmt($invoice->usage_total) }} |
| **Closing balance** | **{{ $fmt($invoice->closing_balance) }}** |
@if ($invoice->billing_type === 'postpaid')
| **Amount due** | **{{ $fmt($invoice->amount_due) }}** |
@endif
</x-mail::table>

@if ($due)
Payment is due by **{{ $due }}**.
@endif

@if ($lines->isNotEmpty())
## Usage by destination

<x-mail::table>
| Destination | Prefix | Calls | Minutes | Amount |
|:--|:--|--:|--:|--:|
@foreach ($lines as $l)
| {{ $l->destination ?? '—' }} | {{ $l->prefix }} | {{ number_format($l->calls) }} | {{ number_format($l->billsec / 60, 1) }} | {{ $fmt($l->amount) }} |
@endforeach
</x-mail::table>

@if ($more > 0)
…and {{ $more }} more destination(s); the attached CSV has the full breakdown.
@else
The attached CSV has the same breakdown.
@endif
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
