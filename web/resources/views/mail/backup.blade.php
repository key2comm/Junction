<x-mail::message>
@if ($backup->status === 'done')
# Database backup

{{ $backup->filename }} ({{ $mb }} MB{{ $backup->encrypted ? ', encrypted' : '' }}) was made {{ $backup->finished_at?->toDayDateTimeString() }} UTC.

@if ($attached)
The backup is attached.
@else
The backup is too large to attach. Download it from Settings → Backups:
@endif
@if ($backup->error)

Some copies failed: {{ $backup->error }}
@endif
@else
# The database backup failed

{{ $backup->error }}
@endif

<x-mail::button :url="$url">Open Settings → Backups</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
