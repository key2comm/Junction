<x-mail::message>
# {{ $title }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$url">Open the backup server</x-mail::button>

Sent by {{ $host }}.

{{ config('app.name') }}
</x-mail::message>
