@extends('layouts.app', ['title' => 'Kunci AI'])
@section('content')
<a class="back-link" href="{{ route('perangkat') }}">‹ Perangkat ajar</a>
<div class="head"><div><h1>Kunci AI (Google Gemini)</h1><small>Dipakai untuk menulis draf MGMP dengan AI. Urutan pemakaian: kunci sekolah → kunci bersama MGMP → kunci pribadi.</small></div></div>
@if($sekolah)
    <div class="alert alert-success"><span class="alert-ic" aria-hidden="true">✓</span><span class="alert-txt">Sekolah sudah menyediakan kunci AI di server. Anda tidak perlu mengisi kunci sendiri.</span></div>
@endif

<div class="card">
    <h2>Pemakaian AI semester ini</h2>
    <p class="hint" style="margin:0 0 10px">Dihitung per dokumen yang diisi AI dengan kunci sekolah atau kunci MGMP.
        {{ $kuota ? 'Batas tiap mapel: '.$kuota.' dokumen per semester.' : 'Tanpa batas (AI_KUOTA_MGMP = 0).' }} Kunci pribadi tidak dihitung.</p>
    <div class="scroll"><table class="tbl"><thead><tr><th>Mapel</th><th class="c">Terpakai</th><th>Kunci bersama MGMP</th></tr></thead><tbody>
    @foreach($mapel as $m)
        @php([$n, $k] = $pakai[$m->id])
        <tr><td>{{ $m->name }}</td><td class="c mono">{{ $n }}{{ $k ? ' / '.$k : '' }}</td>
            <td>@if($bersama->has($m->id))<span class="badge b-ok">ada</span> <small class="mut">diisi {{ $bersama[$m->id]->oleh }}</small>@else<span class="mut">—</span>@endif</td></tr>
    @endforeach
    </tbody></table></div>
</div>

<div class="card">
    <h2>Kunci bersama MGMP</h2>
    <p class="hint" style="margin:0 0 10px">Satu kunci untuk satu mapel, dipakai semua anggota MGMP mapel itu (mis. kunci akun Google MGMP). Disimpan terenkripsi di server.</p>
    <form method="post" action="{{ route('perangkat.kunci.simpan') }}" class="fields">
        @csrf @method('PUT')
        <div><label for="mapel">Mapel</label><select id="mapel" name="mapel">@foreach($mapel as $m)<option value="{{ $m->id }}">{{ $m->name }}{{ $bersama->has($m->id) ? ' (ganti)' : '' }}</option>@endforeach</select></div>
        <div><label for="ai_key_mgmp">Kunci API</label><input id="ai_key_mgmp" type="password" name="ai_key" autocomplete="off" spellcheck="false" placeholder="AIza…" required></div>
        <div class="row"><button class="btn btn-pri">Simpan kunci MGMP</button></div>
    </form>
    @foreach($mapel as $m)
        @if($bersama->has($m->id))
            <form method="post" action="{{ route('perangkat.kunci.simpan') }}" class="inline" style="margin:10px 6px 0 0" data-confirm="Hapus kunci bersama MGMP {{ $m->name }}?">@csrf @method('PUT')<input type="hidden" name="mapel" value="{{ $m->id }}"><input type="hidden" name="hapus" value="1"><button class="btn btn-danger btn-sm">Hapus kunci {{ $m->name }}</button></form>
        @endif
    @endforeach
</div>

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
