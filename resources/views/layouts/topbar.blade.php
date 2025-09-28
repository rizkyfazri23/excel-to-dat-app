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
            <a href="#" class="dropdown-item">Status</a>
            <a href="./profile.html" class="dropdown-item">Profile</a>
            <a href="#" class="dropdown-item">Feedback</a>
            <div class="dropdown-divider"></div>
            <a href="./settings.html" class="dropdown-item">Settings</a>
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
            <ul class="navbar-nav d-flex flex-row gap-4">
                <!-- Menu Home -->
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-2" href="#" aria-expanded="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"
                        class="icon icon-tabler icons-tabler-outline icon-tabler-file-type-xls">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                        <path d="M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4" />
                        <path d="M4 15l4 6" />
                        <path d="M4 21l4 -6" />
                        <path d="M17 20.25c0 .414 .336 .75 .75 .75h1.25a1 1 0 0 0 1 -1v-1a1 1 0 0 0 -1 -1h-1a1 1 0 0 1 -1 -1v-1a1 1 0 0 1 1 -1h1.25a.75 .75 0 0 1 .75 .75" />
                        <path d="M11 15v6h3" />
                    </svg>
                    <span class="nav-link-title fw-bold">UPLOAD</span>
                    </a>
                </li>
            </ul>

        </div>
      </div>
    </div>
  </header>