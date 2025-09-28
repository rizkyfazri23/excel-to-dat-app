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
        // Validasi input termasuk captcha
        $validator = Validator::make($request->all(), [
            'username' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $user = User::where('username', $request->username)->first();

        if (!$user) {
            return back()->withErrors(['username' => 'User tidak ditemukan'])->withInput();
        }

        if ($user->password === md5($request->password)) {
            $user->password = Hash::make($request->password);
            $user->save();
        }

        if (Hash::check($request->password, $user->password)) {
            Auth::login($user);
            $request->session()->regenerate();

            ActivityLog::create([
                'user'   => $user->username,
                'action' => 'Login',
                'details'=> 'User '.$user->username.' berhasil login.'
            ]);

            // dd(Auth::user());
            return redirect()->intended('/'); 
        }

        return back()->withErrors(['username' => 'Username atau password salah'])->withInput();
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

        return redirect('/login')->with('success', 'Berhasil logout');
    }
}
