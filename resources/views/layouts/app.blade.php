<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta http-equiv="X-UA-Compatible" content="ie=edge"/>
    <title>Excel 2 Dat</title>
    <!-- CSS files -->
    <link href="{{ asset('dist/css/tabler.min.css?1692870487') }}" rel="stylesheet"/>
    <link href="{{ asset('dist/css/tabler-flags.min.css?1692870487') }}" rel="stylesheet"/>
    <link href="{{ asset('dist/css/tabler-payments.min.css?1692870487') }}" rel="stylesheet"/>
    <link href="{{ asset('dist/css/tabler-vendors.min.css?1692870487') }}" rel="stylesheet"/>
    <link href="{{ asset('dist/css/demo.min.css?1692870487') }}" rel="stylesheet"/>
    <link href="{{ asset('icon/webfont/tabler-icons.min.css') }}" rel="stylesheet"/>
    <link href="{{ asset('dist/css/tabler.min.css') }}" rel="stylesheet"/>
    {{-- <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" /> --}}

    @vite(['resources/js/app.js'])

    <style>
      @import url('https://rsms.me/inter/inter.css');
      :root {
      	--tblr-font-sans-serif: 'Inter Var', -apple-system, BlinkMacSystemFont, San Francisco, Segoe UI, Roboto, Helvetica Neue, sans-serif;
      }
      body {
      	font-feature-settings: "cv03", "cv04", "cv11";
      }
      .submenu-hover {
          transition: background-color 0.3s ease-in-out, color 0.3s ease-in-out;
          padding: 10px;
          border-radius: 6px;
      }

      .submenu-hover:hover {
          background-color: #007bff !important; /* Warna biru saat hover */
          color: white !important;
      }

      /* Tambahan agar efek hover terasa lebih smooth */
      .dropdown-menu {
          border-radius: 8px;
          box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1);
      }

      .btn-custom-size {
        padding: 0.5rem 0.8rem; /* Atur padding vertikal dan horizontal */
        font-size: 0.8rem;   /* Atur ukuran font jika perlu */
        /* Anda juga bisa mengatur line-height jika diperlukan */
        /* line-height: 1.4; */
      }

      input[readonly] {
        background-color: #8e7a7a;
      }

      /* .card-header-blue {
        background-color: #577295;
        color: white;
      } */

    </style>
     {{-- <link
        rel="stylesheet"
        href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css"
      /> --}}

  </head>
  <body>
    <script src="{{ asset('dist/js/demo-theme.min.js?1692870487') }}"></script>
    <div class="page">
      <div class="page-wrapper">
        <!-- Page header -->
        @yield('content')


        
        <footer class="footer footer-transparent d-print-none">
          <div class="container-xl">
            <div class="row text-center align-items-center flex-row-reverse">
              <div class="col-lg-auto ms-lg-auto">
              </div>
              <div class="col-12 col-lg-auto mt-3 mt-lg-0">
                <ul class="list-inline list-inline-dots mb-0">
                  <li class="list-inline-item">
                    Copyright &copy; 2025
                    <a href="." class="link-secondary">Rizky Fazri</a>.
                    All rights reserved.
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </footer>
      </div>
    </div>

     <!-- Libs JS -->
     <script src="{{ asset('dist/libs/apexcharts/dist/apexcharts.min.js?1692870487') }}" defer></script>
     <script src="{{ asset('dist/libs/jsvectormap/dist/js/jsvectormap.min.js?1692870487') }}" defer></script>
     <script src="{{ asset('dist/libs/jsvectormap/dist/maps/world.js?1692870487') }}" defer></script>
     <script src="{{ asset('dist/libs/jsvectormap/dist/maps/world-merc.js?1692870487') }}" defer></script>
     <!-- Tabler Core -->
     <script src="{{ asset('dist/js/tabler.min.js?1692870487') }}" defer></script>
     <script src="{{ asset('dist/js/demo.min.js?1692870487') }}" defer></script>
  
    {{-- <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> --}}
    {{-- <script
      src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"
    ></script>
    <script
      src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"
    ></script>    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script> --}}

    @stack('scripts')

  </body>
</html>