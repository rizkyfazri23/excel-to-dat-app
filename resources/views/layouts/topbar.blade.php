<!-- Navbar -->
<header class="navbar navbar-expand-md d-print-none" style="background-image: url('{{ asset('dist/img/headert.jpg') }}');background-size:100%;background-repeat: no-repeat;" >
    <div class="container-xl" >
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu" aria-controls="navbar-menu" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <h1 class="navbar-brand navbar-brand-autodark d-none-navbar-horizontal pe-0 pe-md-3">
        <a href=".">
          <img src="{{ asset('dist/img/logo.png') }}" width="110" height="32" alt="Tracking Tools" class="navbar-brand-image">
        </a>
      </h1>
      <div class="navbar-nav flex-row order-md-last">
        <div class="nav-item dropdown">
          <a 
            href="#" 
            class="nav-link d-flex align-items-center lh-1 text-white p-0" 
            data-bs-toggle="dropdown" 
            aria-label="Open user menu"
            aria-expanded="false"
          >
            <!-- Ikon -->
            <svg  
              xmlns="http://www.w3.org/2000/svg"
              width="30"  
              height="30"  
              viewBox="0 0 24 24"  
              fill="none"  
              stroke="currentColor"  
              stroke-width="2"
              stroke-linecap="round"  
              stroke-linejoin="round"  
              class="icon icon-tabler icons-tabler-outline icon-tabler-user-square-rounded text-white me-2"
            >
              <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
              <path d="M12 13a3 3 0 1 0 0 -6a3 3 0 0 0 0 6z" />
              <path d="M12 3c7.2 0 9 1.8 9 9s-1.8 9 -9 9s-9 -1.8 -9 -9s1.8 -9 9 -9z" />
              <path d="M6 20.05v-.05a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v.05" />
            </svg>
            <!-- Teks -->
            <div>{{ Auth::user()->USER_FULLNAME }}</div>
          </a>
          <!-- Dropdown Menu -->
          <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="dropdown-item">Logout</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </header>
  <header class="navbar-expand-md">
    <div class="collapse navbar-collapse" id="navbar-menu">
      <div class="navbar">
        <div class="container-xl">
            <ul class="navbar-nav d-flex flex-row align-items-center gap-4">
              <!-- Menu Upload -->
              <li class="nav-item">
                <a class="nav-link d-flex align-items-center gap-2 fw-semibold" href="{{ url('/') }}">
                  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
                      fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                      stroke-linejoin="round" class="icon icon-tabler icon-tabler-file-upload">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                    <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" />
                    <path d="M12 11v6" />
                    <path d="M9 14l3 -3l3 3" />
                  </svg>
                  <span>Upload</span>
                </a>
              </li>

              <!-- Menu Change Password -->
              <li class="nav-item">
                <a class="nav-link d-flex align-items-center gap-1 fw-semibold" href="{{ route('password.change') }}">
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
                  <span>Change Password</span>
                </a>
              </li>

            </ul>
        </div>
      </div>
    </div>
  </header>