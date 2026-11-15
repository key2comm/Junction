<x-mail::message>
# You're invited

{{ $inviter }} has invited you to manage **{{ $host }}** as **{{ $role }}**.

{{ $description }}

<x-mail::button :url="$url">Accept the invitation</x-mail::button>

You'll choose your name and password. The link works once and expires in {{ $days }} days.

If you weren't expecting this, you can ignore this email.

{{ config('app.name') }}
</x-mail::message>
