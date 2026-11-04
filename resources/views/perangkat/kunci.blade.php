@extends('layouts.app', ['title' => 'Kunci AI'])
@section('content')
<a class="back-link" href="{{ route('perangkat') }}">‹ Perangkat ajar</a>
<div class="head"><div><h1>Kunci AI (Google Gemini)</h1><small>Dipakai untuk menulis draf MGMP dengan AI.</small></div></div>
@if($sekolah)
    <div class="alert alert-success"><span class="alert-ic" aria-hidden="true">✓</span><span class="alert-txt">Sekolah sudah menyediakan kunci AI di server. Anda tidak perlu mengisi kunci sendiri.</span></div>
@endif
<div class="card">
    <h2>Kunci pribadi Anda</h2>
    <p class="mut">Status: @if($punya)<span class="badge b-ok">tersimpan</span> berakhiran <span class="mono">…{{ $akhir }}</span>@else<span class="badge b-warn">belum ada</span>@endif
        @if($sekolah && $punya)<br><small>Kunci sekolah dipakai lebih dulu; kunci Anda hanya cadangan.</small>@endif</p>
    <ol class="steps">
        <li>Buka <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a> dan masuk dengan akun Google.</li>
        <li>Pilih <b>Create API key</b>, lalu salin kuncinya.</li>
        <li>Tempel di bawah dan simpan. Kunci disimpan terenkripsi di server sekolah dan hanya dipakai untuk draf yang Anda susun.</li>
    </ol>
    <form method="post" action="{{ route('perangkat.kunci.simpan') }}" class="fields" style="margin-top:14px">
        @csrf @method('PUT')
        <div style="grid-column:1/-1"><label for="ai_key">Kunci API</label><input id="ai_key" type="password" name="ai_key" autocomplete="off" spellcheck="false" placeholder="AIza…" required></div>
        <div class="row"><button class="btn btn-pri">Simpan kunci</button></div>
    </form>
    @if($punya)
        <form method="post" action="{{ route('perangkat.kunci.simpan') }}" style="margin-top:10px" data-confirm="Hapus kunci API Anda dari server?">@csrf @method('PUT')<input type="hidden" name="hapus" value="1"><button class="btn btn-danger btn-sm">Hapus kunci saya</button></form>
    @endif
</div>
@endsection
