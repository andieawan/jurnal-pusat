@extends('layouts.app', ['title' => $switching ? 'Ganti Akun' : 'Masuk', 'bare' => ! $switching, 'width' => $switching ? '520px' : null])
@section('content')
@php($viaPusat = \App\Services\PusatKlien::aktif())
<div class="card login">
    <img class="logo-login" src="{{ asset('icons/icon-192.png') }}?v={{ @filemtime(public_path('icons/icon-192.png')) }}" alt="" width="56" height="56">
    <h1>{{ $switching ? 'Ganti Akun' : 'Absensi Siswa' }}</h1>
    <p class="mut">{{ $settings->school_name }}<br>{{ $switching ? 'Pindah ke akun lain.' : 'Masuk dengan akun Pusat Sekolah.' }}</p>
    @if($needsInstall ?? false)<div class="alert alert-info">Aplikasi belum dipasang. <a href="{{ route('install') }}">Buka halaman instalasi →</a></div>@endif

    @if($viaPusat)
        @if($switching)
            <form method="post" action="{{ route('switch.pusat') }}">@csrf<button class="btn btn-pri btn-blok">Ganti akun lewat Pusat</button></form>
        @else
            <a class="btn btn-pri btn-blok" href="{{ route('sso.login') }}">Masuk lewat Pusat</a>
        @endif
        <div class="login-atau"><span>atau dengan username &amp; password</span></div>
    @endif

    <form method="post" action="{{ $switching ? route('switch') : route('login') }}" autocomplete="off">
        @csrf
        <div class="field"><label for="u">Username</label><input id="u" type="text" name="username" value="{{ old('username') }}" autocapitalize="none" autocomplete="username" required @unless($viaPusat) autofocus @endunless></div>
        <div class="field"><label for="pw">Password</label><div class="pw-wrap"><input id="pw" type="password" name="password" autocomplete="current-password" required><button type="button" class="pw-eye" data-toggle-pw="#pw" aria-label="Tampilkan password" aria-pressed="false">Lihat</button></div></div>
        <button @class(['btn', 'btn-blok', 'btn-pri' => ! $viaPusat]) data-busy="Memeriksa…">{{ $switching ? 'Pindah Akun' : 'Masuk' }}</button>
    </form>
    @if($viaPusat)<p class="login-catatan">Akun Pusat memakai password Pusat, jadi tetap bisa masuk saat Pusat sedang tidak bisa dihubungi. Akun baru: masuk lewat Pusat dulu untuk mengganti password awal.</p>@endif

    @unless($switching)<button type="button" class="btn btn-sm btn-blok hide" id="pwa-install" style="margin-top:12px">📲 Pasang Aplikasi di Perangkat Ini</button>@endunless
    @if($switching)<p style="margin-top:14px"><a href="{{ route('dashboard') }}">&larr; Batal, kembali</a></p>@endif
</div>
@endsection
