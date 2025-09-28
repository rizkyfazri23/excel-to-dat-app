<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col justify-center items-center bg-blue-300">
            <!-- Logo -->
            <div class="mb-6">
                <a href="#">
                    <img src="{{ asset('dist/img/logo.png') }}" width="400" height="32" alt="Tracking Tools" class="navbar-brand-image">
                </a>
            </div>

            <!-- Login Form -->
            <div class="w-full sm:max-w-md bg-white px-6 py-8 shadow-lg rounded-lg">
                <!-- Session Status -->
                @if (session('status'))
                    <div class="mb-4 text-sm fon    t-medium text-green-600">
                        {{ session('status') }}
                    </div>
                @endif

                <!-- Login Form -->
                <form action="{{ route('login.post') }}" method="POST">
                    @csrf

                    <!-- Email Address -->
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('username') }}
                        </label>
                        <input id="username" type="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username" 
                            class="block w-full border border-gray-300 focus:ring-indigo-500 focus:border-indigo-500 rounded-md shadow-sm px-3 py-2">
                        @error('username')
                            <span class="text-red-600 text-sm mt-2">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Password -->
                    <div class="mt-4">
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Password') }}
                        </label>
                        <input id="password" type="password" name="password" required autocomplete="current-password" 
                            class="block w-full border border-gray-300 focus:ring-indigo-500 focus:border-indigo-500 rounded-md shadow-sm px-3 py-2">
                        @error('password')
                            <span class="text-red-600 text-sm mt-2">{{ $message }}</span>
                        @enderror
                    </div>

                    {{-- <!-- Captcha -->
                    <div class="mt-4">
                        <img src="{{ captcha_src() }}" 
                            alt="Captcha Image" 
                            class="mt-2 mx-auto cursor-pointer" 
                            id="captcha-img" 
                            onclick="this.src = this.src.split('?')[0] + '?' + Date.now()">
                        <label for="captcha" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Captcha') }}
                        </label>
                        <input id="captcha" type="text" name="captcha" required 
                            class="block w-full border border-gray-300 focus:ring-indigo-500 focus:border-indigo-500 rounded-md shadow-sm px-3 py-2">
                        @error('captcha')
                            <span class="text-red-600 text-sm mt-2">{{ $message }}</span>
                        @enderror
                    </div> --}}

                    <!-- Login Buttons -->
                    <div class="flex items-center justify-end mt-4">
                        @if (Route::has('password.request'))
                            <a class="text-sm text-gray-600 hover:text-gray-900" href="{{ route('password.request') }}">
                                {{ __('Forgot your password?') }}
                            </a>
                        @endif

                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ __('Log in') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </body>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</html>
