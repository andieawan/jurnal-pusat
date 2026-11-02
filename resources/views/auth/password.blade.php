@extends('layouts.app', ['title' => 'Ganti Password'])
@section('content')
<div class="card" style="max-width:460px;margin:0 auto">
    <h1>Ganti Password</h1><p class="mut">Akun: <b>{{ auth()->user()->username }}</b></p>
    @if($pusat ?? null)
        <div class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt">Akun ini dari <b>Pusat Sekolah</b>. Ganti password di Pusat; password baru otomatis berlaku juga untuk login di Absensi dan aplikasi lain.</span></div>
        <div class="row"><a class="btn btn-pri" href="{{ $pusat }}" target="_blank" rel="noopener">Ganti password di Pusat ↗</a><a class="btn" href="{{ route('dashboard') }}">Kembali</a></div>
    @else
    <form method="post" action="{{ route('password') }}" autocomplete="off">@csrf
        <div class="field"><label for="old">Password lama</label><div class="pw-wrap"><input id="old" type="password" name="old" autocomplete="current-password" required><button type="button" class="pw-eye" data-toggle-pw="#old" aria-label="Tampilkan password" aria-pressed="false">Lihat</button></div></div>
        <div class="field"><label for="new">Password baru ({{ \App\Support\PasswordPolicy::hint() }})</label><div class="pw-wrap"><input id="new" type="password" name="new" autocomplete="new-password" minlength="{{ \App\Support\PasswordPolicy::current()['min'] }}" required><button type="button" class="pw-eye" data-toggle-pw="#new" aria-label="Tampilkan password" aria-pressed="false">Lihat</button></div></div>
        <div class="field"><label for="new_confirmation">Ulangi password baru</label><div class="pw-wrap"><input id="new_confirmation" type="password" name="new_confirmation" autocomplete="new-password" required><button type="button" class="pw-eye" data-toggle-pw="#new_confirmation" aria-label="Tampilkan password" aria-pressed="false">Lihat</button></div></div>
        <div class="row"><button class="btn btn-pri">Simpan Password</button><a class="btn" href="{{ route('dashboard') }}">Batal</a></div>
    </form>
    @endif
</div>
@endsection
