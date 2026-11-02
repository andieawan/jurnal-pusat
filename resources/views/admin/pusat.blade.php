@extends('layouts.app', ['title' => 'Integrasi Pusat'])
@section('content')
@include('admin._tabs')
@if(! $p['siap'])
    <div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">Fitur ini butuh pembaruan database. Buka <a href="{{ route('admin.settings') }}">Pengaturan → Perbarui Database Sekarang</a>.</span></div>
@endif
<div class="head"><div><h1>Integrasi Pusat</h1><small>Sambungan ke Pusat Sekolah: akun (login tunggal) dan data induk (kelas &amp; siswa).</small></div></div>
@include('admin._pusat')
@endsection
