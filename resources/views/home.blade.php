@extends('Layouts.app')

@push('styles')
<style>
    .data-table-exist tbody tr.row-grey td { background-color: #d3d3d3 !important; color: #333 !important; }
    .data-table-exist tbody tr.row-green td { background-color: #90ee90 !important; color: #033 !important; }
</style>
@endpush

@section('content')
@include('Layouts.topbar')

<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <div class="page-pretitle">Upload</div>
        <h2 class="page-title">Excel</h2>
      </div>
    </div>
  </div>
</div>

<div class="page-body">
  <div class="container-xl">
    <div class="row row-cards">
      <div class="col-md-12">

        {{-- Alert Error --}}
        @if(session('error'))
          <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        {{-- Validasi form --}}
        @if ($errors->any())
          <div class="alert alert-danger">
            <ul class="mb-0">
              @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
              @endforeach
            </ul>
          </div>
        @endif

        <form id="f_upload_excel" name="f_upload_excel" class="card"
              action="{{ route('generate') }}" method="POST" enctype="multipart/form-data">
          @csrf

          <div class="card-header">
            <h3 class="card-title">Excel Data</h3>
          </div>

          <div class="card-body">
            <div class="mb-3 row">
              <label class="col-3 col-form-label required">Upload Excel File</label>
              <div class="col">
                <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
              </div>
            </div>

            <div class="mb-3 row">
              <label class="col-3 col-form-label required">Format</label>
              <div class="col">
                <select class="form-select" id="format_type" name="format_type" required>
                  <option value="" disabled selected>-- Choose Format --</option>
                  <option value="1" {{ old('format_type')=='1'?'selected':'' }}>Format 1</option>
                  <option value="2" {{ old('format_type')=='2'?'selected':'' }}>Format 2</option>
                  <option value="3" {{ old('format_type')=='3'?'selected':'' }}>Format 3</option>
                  <option value="4" {{ old('format_type')=='4'?'selected':'' }}>Format 4</option>
                  <option value="5" {{ old('format_type')=='5'?'selected':'' }}>Format 5</option>
                  <option value="6" {{ old('format_type')=='6'?'selected':'' }}>Format 6</option>
                </select>
              </div>
            </div>
          </div>

          <div class="card-footer text-end">
            <button type="submit" class="btn btn-primary">
              <i class="ti ti-upload me-2"></i> Submit
            </button>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>
@endsection
