{{-- resources/views/auth/change-password.blade.php --}}
@extends('layouts.app')

@push('styles')
<style>
    .card-change-pass {
        max-width: 520px;
    }
</style>
@endpush

@section('content')
    @include('layouts.topbar')

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-12 d-flex justify-content-center">
                <div class="card card-change-pass shadow-sm w-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Change Password</h3>
                    </div>
                    <div class="card-body">

                        {{-- flash success --}}
                        @if (session('status'))
                            <div class="alert alert-success mb-3">
                                {{ session('status') }}
                            </div>
                        @endif

                        <form action="{{ route('password.update') }}" method="POST">
                            @csrf

                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input
                                    type="password"
                                    id="current_password"
                                    name="current_password"
                                    class="form-control @error('current_password') is-invalid @enderror"
                                    placeholder="Enter current password">
                                @error('current_password')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    class="form-control @error('new_password') is-invalid @enderror"
                                    placeholder="Enter new password">
                                @error('new_password')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="new_password_confirmation" class="form-label">Confirm New Password</label>
                                <input
                                    type="password"
                                    id="new_password_confirmation"
                                    name="new_password_confirmation"
                                    class="form-control"
                                    placeholder="Confirm new password">
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                         class="me-1">
                                        <path d="M10 13v4" />
                                        <path d="M14 13v4" />
                                        <path d="M5 7h14" />
                                        <path d="M9 7v-2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v2" />
                                        <path d="M4 7v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-11" />
                                    </svg>
                                    Update Password
                                </button>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
