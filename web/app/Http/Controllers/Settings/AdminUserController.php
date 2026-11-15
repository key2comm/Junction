<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mail\AdminInviteMail;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Services\MailSettings;
use App\Support\AdminRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Settings → Admin users (owners only): who can manage the server, at what
 * access level, and invitations for new admins.
 */
class AdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        $lastSeen = DB::table('sessions')->whereNotNull('user_id')->groupBy('user_id')
            ->pluck(DB::raw('max(last_activity)'), 'user_id');

        return Inertia::render('settings/AdminUsers', [
            'users' => User::query()->whereNull('account_id')->orderBy('name')->get()
                ->map(fn (User $u) => [
                    ...$u->only(['id', 'name', 'email', 'created_at']),
                    'role' => $u->adminRole()->value,
                    'two_factor' => $u->two_factor_confirmed_at !== null,
                    'last_seen' => isset($lastSeen[$u->id]) ? now()->setTimestamp((int) $lastSeen[$u->id])->toIso8601String() : null,
                    'me' => $u->id === $request->user()->id,
                ]),
            'invitations' => AdminInvitation::query()->with('inviter:id,name')->orderBy('email')->get()
                ->map(fn (AdminInvitation $i) => [
                    ...$i->only(['id', 'email', 'expires_at', 'created_at']),
                    'role' => $i->role->value,
                    'invited_by' => $i->inviter?->name,
                    'expired' => $i->expired(),
                ]),
            'roles' => AdminRole::options(),
            'mailConfigured' => MailSettings::configured(),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(AdminRole::class)],
        ], ['email.unique' => 'Someone with this email can already sign in (as an admin or a portal user).']);

        $invitation = AdminInvitation::query()->firstOrNew(['email' => $data['email']]);
        $invitation->fill(['role' => $data['role'], 'invited_by' => $request->user()->id]);

        return $this->send($invitation, $request->user());
    }

    /** A new link for an invitation (the old one stops working), valid for another week. */
    public function resend(Request $request, AdminInvitation $invitation): RedirectResponse
    {
        return $this->send($invitation, $request->user());
    }

    public function revoke(AdminInvitation $invitation): RedirectResponse
    {
        $invitation->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Cancelled the invitation for {$invitation->email}."]);

        return to_route('admin-users.index');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isAdmin(), 404);
        if ($user->is($request->user())) {
            return $this->refuse("You can't change your own access level. Another owner can.");
        }
        $data = $request->validate(['role' => ['required', Rule::enum(AdminRole::class)]]);

        $user->update(['role' => $data['role']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} is now {$user->adminRole()->label()}."]);

        return to_route('admin-users.index');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isAdmin(), 404);
        if ($user->is($request->user())) {
            return $this->refuse("You can't remove yourself. Another owner can.");
        }

        DB::table('sessions')->where('user_id', $user->id)->delete(); // signed out everywhere, now
        $user->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed {$user->email}. They can no longer sign in."]);

        return to_route('admin-users.index');
    }

    private function refuse(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('admin-users.index');
    }

    /** Email a fresh link, or show it to the owner when email isn't set up (or fails). */
    private function send(AdminInvitation $invitation, User $inviter): RedirectResponse
    {
        $token = $invitation->issueToken();
        $url = route('invitation.show', $token);

        $sent = false;
        if (MailSettings::fresh()) {
            try {
                Mail::to($invitation->email)->send(new AdminInviteMail($invitation, $url, $inviter->name));
                $sent = true;
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($sent) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "Invitation emailed to {$invitation->email}."]);
        } else {
            Inertia::flash('inviteLink', ['email' => $invitation->email, 'url' => $url]);
        }

        return to_route('admin-users.index');
    }
}
