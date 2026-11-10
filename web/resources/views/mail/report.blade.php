<x-mail::message>
# {{ $schedule->name }}

Call records from {{ $periodFrom }} to {{ $periodTo }} ({{ $tz }}): {{ number_format($file->rows) }} call(s).

@if ($attached)
The CSV file is attached.
@else
The file is too large to attach ({{ number_format($file->size / 1048576, 1) }} MB). Download it from Settings → Scheduled reports:

<x-mail::button :url="$url">Open Scheduled reports</x-mail::button>
@endif

{{ config('app.name') }}
</x-mail::message>
