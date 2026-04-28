<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AuthController extends Controller
{   
    // * Login Session
    public function showLogin()
    {   
        return view('pages.auth.signin', [
            'title' => 'Sign In'
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        // Email not found
        if (!$user) {
            return back()->withErrors([
                'email' => 'Email tidak ditemukan'
            ])->onlyInput('email');
        }

        // Password has wrong
        if (!Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'password' => 'Password salah'
            ])->onlyInput('email');
        }

        // Cookie autofill email
        if ($request->has('remember')) {
            Cookie::queue(
                'remembered_email',
                $request->email,
                1440 // 1 day
            );
        } else {
            Cookie::queue(
                Cookie::forget('remembered_email')
            );
        }

        // Login remember me
        Auth::login($user, $request->has('remember'));
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Selamat datang, ' .auth()->user()->name);
    }

    // * Register Session
    public function showRegister()
    {
        return view('pages.auth.signup', [
            'title' => 'Sign Up'
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
            ],
            'terms' => 'required'
        ],[
            'name.required'=>'Nama lengkap wajib diisi',
            'email.unique'=>'Email sudah terdaftar',
            'password.min' => 'Password minimal 8 karakter',
            'password.confirmed'=>'Konfirmasi password tidak cocok',
            'terms.required'=>'Anda harus menyetujui syarat & ketentuan'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password)
        ]);

        Auth::login($user);

        return redirect()
            ->route('dashboard')
            ->with(
                'success',
                'Registrasi berhasil, selamat datang '.$user->name
            );
    }

    // * Forgot Password
    public function showForgotPassword()
    {
        return view('pages.auth.forgot-password', [
            'title' => 'Reset Password'
        ]);
    }

    public function requestOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ],[
            'email.required' => 'Email wajib diisi',
            'email.email' => 'Format email tidak valid',
        ]);

        // 🔥 cek manual email
        $user = \App\Models\User::where('email', $request->email)->first();

        if (!$user) {
            return back()->withErrors([
                'email' => 'Email tidak ditemukan di sistem'
            ]);
        }

        $otp = rand(100000, 999999);

        DB::table('password_resets_otp')->updateOrInsert(
            ['email' => $request->email],
            [
                'otp' => $otp,
                'expired_at' => Carbon::now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        session([
            'reset_email'=>$request->email
        ]);

        return redirect()->route('password.reset')->with('success', "Kode OTP: $otp");
    }

    // * Reset Password OTP
    public function showResetPassword()
    {
        return view('pages.auth.reset-password', [
            'title' => 'Reset Password',
            'email'=>session('reset_email')
        ]);
    }

    public function resetPasswordOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required',
            'password' => 'required|min:8|confirmed',
        ],[
            'password.min' => 'Password minimal 8 karakter',
            'password.confirmed'=>'Konfirmasi password tidak cocok',
        ]);

        $record = DB::table('password_resets_otp')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->first();

        if (!$record) {
            return back()->withErrors(['otp' => 'OTP salah']);
        }

        if (Carbon::now()->isAfter($record->expired_at)) {
            return back()->withErrors(['otp' => 'OTP sudah kadaluarsa']);
        }

        $user = User::where('email', $request->email)->first();

        $user->update([
            'password' => Hash::make($request->password),
            'remember_token' => Str::random(60),
        ]);

        DB::table('password_resets_otp')->where('email', $request->email)->delete();

        session()->forget('reset_email');

        return redirect()->route('login')->with('success', 'Password berhasil diubah');
    }

    // * Logout Session
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
