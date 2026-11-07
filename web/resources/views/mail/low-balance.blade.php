<x-mail::message>
# Low balance on account {{ $account->code }}

Hello {{ $account->name }},

The funds available for calls on your account are now **{{ $available }}**, below your alert level of {{ $threshold }}.

@if ($prepaid)
Calls are refused once funds run out, so please top up your account to avoid interruption.
@else
Calls are refused once your credit limit is reached. Please arrange a payment or contact us to review your limit.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
