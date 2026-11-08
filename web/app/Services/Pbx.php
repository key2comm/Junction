<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Endpoint;
use App\Models\PbxExtension;
use App\Models\PbxIvr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * PBX rules shared by the admin pages (and anything else that edits the PBX):
 * the numbers an account has, and destination validation.
 *
 * Inside an account, extensions and IVRs share one number plan of 2-6 digits;
 * anything 7 digits or longer that a phone dials is an outside number.
 */
class Pbx
{
    public const NUMBER_RULE = 'regex:/^[1-9][0-9]{1,5}$/';

    public const DESTINATION_TYPES = ['number', 'voicemail', 'hangup', 'endpoint'];

    /**
     * Every number of the account's PBX, for pickers and validation.
     *
     * @return Collection<int, array{number: string, kind: string, label: string, voicemail: bool}>
     */
    public function numbers(Account $account): Collection
    {
        $extensions = $account->pbxExtensions()->orderBy('extension')->get(['id', 'extension', 'name', 'voicemail_enabled'])
            ->map(fn (PbxExtension $e) => [
                'number' => $e->extension, 'kind' => 'extension',
                'label' => trim("{$e->extension} {$e->name}"), 'voicemail' => $e->voicemail_enabled,
            ]);
        $ivrs = $account->pbxIvrs()->orderBy('extension')->get(['id', 'extension', 'name'])
            ->map(fn (PbxIvr $i) => ['number' => $i->extension, 'kind' => 'ivr', 'label' => "{$i->extension} {$i->name} (IVR)", 'voicemail' => false]);

        return $extensions->concat($ivrs)->sortBy('number')->values();
    }

    /**
     * Where the account's DIDs can be sent (Routing -> PBX/Devices): every PBX
     * (they are shared) and the account's own devices.
     *
     * @return Collection<int, array{id: int, label: string}>
     */
    public function endpoints(Account $account): Collection
    {
        return Endpoint::query()->where(fn ($q) => $q->where('kind', 'pbx')->orWhere(fn ($d) => $d->where('kind', 'device')->where('mode', 'sending')->where('account_id', $account->id)))
            ->orderBy('kind')->orderBy('name')->get(['id', 'kind', 'name'])
            ->map(fn (Endpoint $e) => ['id' => $e->id, 'label' => Endpoint::KINDS[$e->kind].': '.$e->name]);
    }

    /** Whether a number is free in the account (ignoring the record being edited). */
    public function numberTaken(Account $account, string $number, ?PbxExtension $exceptExtension = null, ?PbxIvr $exceptIvr = null): bool
    {
        return $account->pbxExtensions()->where('extension', $number)->when($exceptExtension, fn ($q) => $q->whereKeyNot($exceptExtension->id))->exists()
            || $account->pbxIvrs()->where('extension', $number)->when($exceptIvr, fn ($q) => $q->whereKeyNot($exceptIvr->id))->exists();
    }

    /**
     * Validate a destination ({type, target}) against the account's numbers
     * (and, with $endpoints, its PBX/Devices: {type: endpoint, target: id}).
     *
     * @return array{type: string, target?: string}|null
     */
    public function destination(Account $account, mixed $input, string $field, bool $required = true, bool $endpoints = true): ?array
    {
        $type = is_array($input) ? (string) ($input['type'] ?? '') : '';
        $target = is_array($input) ? trim((string) ($input['target'] ?? '')) : '';
        if ($type === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => 'Choose a destination.']);
            }

            return null;
        }
        if (! in_array($type, self::DESTINATION_TYPES, true) || ($type === 'endpoint' && ! $endpoints)) {
            throw ValidationException::withMessages([$field => 'Unknown destination type.']);
        }
        if ($type === 'hangup') {
            return ['type' => 'hangup'];
        }
        if ($type === 'endpoint') {
            if (! ctype_digit($target) || ! $this->endpoints($account)->contains('id', (int) $target)) {
                throw ValidationException::withMessages([$field => "Choose a PBX, or one of this customer's devices."]);
            }

            return ['type' => 'endpoint', 'target' => $target];
        }

        $match = $this->numbers($account)->firstWhere('number', $target);
        if (! $match) {
            throw ValidationException::withMessages([$field => $target === '' ? 'Choose a number.' : "{$target} is not an extension or IVR of this account."]);
        }
        if ($type === 'voicemail' && ! ($match['kind'] === 'extension' && $match['voicemail'])) {
            throw ValidationException::withMessages([$field => "{$target} has no voicemail."]);
        }

        return ['type' => $type, 'target' => $target];
    }

    /** "1001", "voicemail of 1001", "IVR 5000"... for lists. */
    public function describe(Account $account, ?array $destination): string
    {
        return match ($destination['type'] ?? null) {
            'number' => (string) ($this->numbers($account)->firstWhere('number', $destination['target'] ?? '')['label'] ?? ($destination['target'] ?? '?').' (missing)'),
            'voicemail' => 'Voicemail of '.($destination['target'] ?? '?'),
            'hangup' => 'Hang up',
            'endpoint' => (string) ($this->endpoints($account)->firstWhere('id', (int) ($destination['target'] ?? 0))['label'] ?? 'PBX/Device (missing)'),
            default => '—',
        };
    }
}
