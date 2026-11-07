<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use App\Services\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

/**
 * Customer portal logins, managed from the customer's account page. New users
 * get a password-reset link by email when email is set up; otherwise the admin
 * is shown a one-time password to pass on.
 */
class PortalUserController extends Controller
{
    public function store(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->is_customer, 404);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);

        $password = Str::password(16, symbols: false);
        $user = User::create([...$data, 'password' => Hash::make($password), 'account_id' => $account->id]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $sent = false;
        if (MailSettings::fresh()) {
            try {
                $sent = Password::broker()->sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
            } catch (Throwable) {
                $sent = false;
            }
        }

        if ($sent) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "Portal login created. {$user->email} has been emailed a link to set their password."]);
        } else {
            Inertia::flash('portalPassword', ['email' => $user->email, 'password' => $password]);
        }

        return to_route('accounts.show', $account);
    }

    public function destroy(Account $account, User $user): RedirectResponse
    {
        abort_unless($user->account_id === $account->id, 404);
        $user->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed portal login {$user->email}."]);

        return to_route('accounts.show', $account);
    }
}
