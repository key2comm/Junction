<x-mail::message>
# {{ $label }}

**{{ $alert->title }}**

@if ($alert->action_taken)
**Action taken:** {{ $alert->action_taken }}
@endif

@if ($alert->details)
<x-mail::table>
| | |
|:--|:--|
@foreach ($alert->details as $key => $value)
| {{ str_replace('_', ' ', $key) }} | {{ is_array($value) ? implode(', ', $value) : $value }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="$url">Open alerts</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
