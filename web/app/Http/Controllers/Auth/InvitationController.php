<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The link in an admin invitation: the new admin picks their name and password. */
class InvitationController extends Controller
{
    use PasswordValidationRules;

    public function show(string $token): Response|RedirectResponse
    {
        $invitation = AdminInvitation::findByToken($token);
        if (! $invitation) {
            return $this->invalid();
        }

        return Inertia::render('auth/AcceptInvite', [
            'token' => $token,
            'email' => $invitation->email,
            'role' => $invitation->role->label(),
            'invitedBy' => $invitation->inviter?->name,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = AdminInvitation::findByToken($token);
        if (! $invitation) {
            return $this->invalid();
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);
        if (User::query()->where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages(['name' => 'An account with this email already exists. Sign in instead.']);
        }

        $user = DB::transaction(function () use ($invitation, $data) {
            $user = User::create(['name' => $data['name'], 'email' => $invitation->email, 'password' => $data['password'], 'role' => $invitation->role]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $invitation->delete();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Welcome, {$user->name}. You're signed in as {$user->adminRole()->label()}."]);

        return redirect()->route('dashboard');
    }

    private function invalid(): RedirectResponse
    {
        return redirect()->route('login')->with('status', 'This invitation link has expired or was already used. Ask for a new one.');
    }
}
