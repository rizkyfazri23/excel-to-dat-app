<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <title>{{ config('app.name', 'Laravel') }} — Login</title>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

  <!-- Tabler CSS (statis dari public/dist) -->
  <link href="{{ asset('dist/css/tabler.min.css') }}" rel="stylesheet"/>
  <link href="{{ asset('dist/css/tabler-flags.min.css') }}" rel="stylesheet"/>
  <link href="{{ asset('dist/css/tabler-payments.min.css') }}" rel="stylesheet"/>
  <link href="{{ asset('dist/css/tabler-vendors.min.css') }}" rel="stylesheet"/>
  <link href="{{ asset('dist/css/demo.min.css') }}" rel="stylesheet"/>
  <link href="{{ asset('icon/webfont/tabler-icons.min.css') }}" rel="stylesheet"/>

  <style>
    :root { --login-card-width: 420px; }
    body { font-family: 'Figtree', system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, 'Noto Sans', 'Liberation Sans', 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'; }
    .login-wrapper {
      min-height: 100dvh;
      display: grid;
      place-items: center;
      background: linear-gradient(180deg, #e8f1ff 0%, #cfe2ff 100%);
    }
    .login-card { width: min(100%, var(--login-card-width)); }
    .brand img { max-width: 260px; height: auto; }
    .form-label .required::after { content:" *"; color:#dc3545; }
  </style>
</head>
<body>
  <div class="login-wrapper">
    <div class="container-tight py-6">
      <!-- Brand / Logo -->
      <div class="text-center mb-4 brand">
        <a href="{{ url('/') }}" class="navbar-brand">
          <img src="{{ asset('dist/img/logo.png') }}" alt="{{ config('app.name') }}">
        </a>
      </div>

      <!-- Card -->
      <div class="card shadow-sm login-card mx-auto">
        <div class="card-body p-4 p-sm-5">
          <h2 class="card-title text-center mb-4">Sign in</h2>

          {{-- Global session status / flash --}}
          @if (session('status'))
            <div class="alert alert-success" role="alert">
              {{ session('status') }}
            </div>
          @endif

          {{-- Global auth error --}}
          @if ($errors->any() && !$errors->has('username') && !$errors->has('password'))
            <div class="alert alert-danger" role="alert">
              {{ __('Login failed. Please check your credentials.') }}
            </div>
          @endif

          <form action="{{ route('login.post') }}" method="POST" novalidate>
            @csrf

            {{-- Username --}}
            <div class="mb-3">
              <label for="username" class="form-label required">{{ __('Username') }}</label>
              <input
                id="username"
                type="text"
                name="username"
                value="{{ old('username') }}"
                autocomplete="username"
                autofocus
                class="form-control @error('username') is-invalid @enderror"
                placeholder="Enter your username">
              @error('username')
                <div class="invalid-feedback">{{ $message }}</div>
              @enderror
            </div>

            {{-- Password --}}
            <div class="mb-2">
              <label for="password" class="form-label required">{{ __('Password') }}</label>
              <div class="input-group input-group-flat">
                <input
                  id="password"
                  type="password"
                  name="password"
                  autocomplete="current-password"
                  class="form-control @error('password') is-invalid @enderror"
                  placeholder="Enter your password">
                <span class="input-group-text">
                  <a href="#" class="link-secondary" tabindex="-1" id="togglePass" aria-label="Toggle password visibility">
                    <i class="ti ti-eye"></i>
                  </a>
                </span>
                @error('password')
                  <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
              </div>
            </div>

            {{-- Remember me + forgot password --}}
            <div class="d-flex justify-content-between align-items-center mb-4">
              <label class="form-check m-0">
                <input type="checkbox" class="form-check-input" name="remember" {{ old('remember') ? 'checked' : '' }}>
                <span class="form-check-label">{{ __('Remember me') }}</span>
              </label>
              @if (Route::has('password.request'))
                <a class="link-secondary" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
              @endif
            </div>

            {{-- Submit --}}
            <div class="d-grid">
              <button type="submit" class="btn btn-primary">
                <i class="ti ti-login-2 me-1"></i> {{ __('Log in') }}
              </button>
            </div>
          </form>
        </div>
      </div>

      {{-- Footer small note (optional) --}}
      <div class="text-center text-secondary mt-4 small">
        &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
      </div>
    </div>
  </div>

  <!-- JS minimal & tanpa Vite -->
  <script>
    // Toggle password visibility
    document.getElementById('togglePass')?.addEventListener('click', function (e) {
      e.preventDefault();
      const input = document.getElementById('password');
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      this.innerHTML = input.type === 'password' ? '<i class="ti ti-eye"></i>' : '<i class="ti ti-eye-off"></i>';
    });
  </script>
</body>
</html>
