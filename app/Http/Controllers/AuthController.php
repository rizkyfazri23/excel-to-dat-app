<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Validasi basic
        $validator = Validator::make($request->all(), [
            'username' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $user = User::where('username', $request->username)->first();

        if (!$user) {
            return back()
                ->withErrors(['username' => 'User Not Found'])
                ->withInput();
        }

        if (!Hash::check($request->password, $user->password)) {
            return back()
                ->withErrors(['username' => 'Incorrect Username or Password!'])
                ->withInput();
        }

        $remember = $request->boolean('remember');

        Auth::login($user, $remember);

        $request->session()->regenerate();

        ActivityLog::create([
            'user'    => $user->username,
            'action'  => 'Login',
            'details' => 'User '.$user->username.' Successfully Login.',
        ]);

        return redirect()->intended('/');
    }

    
    public function logout(Request $request)
    {
        $username = Auth::check() ? Auth::user()->username : 'guest';

        ActivityLog::create([
            'user'   => $username,
            'action' => 'Logout',
            'details'=> 'User '.$username.' logout.'
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('success', 'Logout Successfully!');
    }

    public function showChangePasswordForm()
    {
        return view('auth.change-password');
    }

    public function updatePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password'      => 'required',
            'new_password'          => 'required|min:8|confirmed',
        ], [
            'new_password.confirmed' => 'Password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return back()->with('status', 'Password successfully updated!');
    }
}
