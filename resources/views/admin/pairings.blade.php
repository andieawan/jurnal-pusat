@extends('layouts.app', ['title' => 'Pasangan Mapel'])
@section('content')
@include('admin._tabs')
@php($uMap = $users->pluck('nama', 'id'))
@php($sMap = $subjects->pluck('name', 'id'))
@php($cMap = $classes->pluck('name', 'id'))
@if($dariPusat)
    @include('admin._induk', ['apa' => 'Pasangan mapel', 'path' => '/admin/pengampu', 'ket' => 'Atur di Pusat → Pengampu Mapel lalu Sinkron. Pasangan bertanda “hanya di Absensi” dibuat lokal dan masih bisa dihapus di sini.'])
@else
<form method="post" action="{{ route('admin.pairings.store') }}" class="card">@csrf
    <h2>Otorisasi Pasangan Guru – Mapel – Kelas</h2><p class="mut" style="font-size:12px">Guru hanya bisa mengisi absen mapel/nilai pada kombinasi yang terdaftar di sini (atau kelas + mapel yang diampu di akunnya).</p>
    <div class="fields">
        <div><label>Guru</label>@include('partials.select', ['name' => 'user_id', 'options' => $uMap->all()])</div>
        <div><label>Mata pelajaran</label>@include('partials.select', ['name' => 'subject_id', 'options' => $sMap->all()])</div>
        <div><label>Kelas</label>@include('partials.select', ['name' => 'class_id', 'options' => $cMap->all()])</div>
        <div><button class="btn btn-pri">Tambah Pasangan</button></div>
    </div>
</form>
@if(\App\Services\PusatKlien::kelolaSiswa() && $pairs->isNotEmpty())
<div class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt">Pindahkan pasangan ini ke Pusat:
    <a class="btn btn-sm" href="{{ route('admin.pairings.csv') }}">Unduh CSV untuk Pusat</a> lalu impor di Pusat → Pengampu Mapel → Impor CSV,
    centang izin “Pengampu mapel” untuk Absensi, dan Sinkron. Pindahkan daftar mapel lebih dulu.</span></div>
@endif
@endif
<div class="card card-tight"><table class="tbl tbl-cards"><thead><tr><th>Guru</th><th>Mata pelajaran</th><th>Kelas</th><th class="r">Aksi</th></tr></thead><tbody>
@forelse($pairs as $p)
    <tr><td class="card-title">{{ $uMap[$p->user_id] ?? '#'.$p->user_id }}@if($dariPusat && ! $p->pusat_uuid) <span class="badge" title="Belum ada di Pusat">hanya di Absensi</span>@endif</td><td data-label="Mapel">{{ $sMap[$p->subject_id] ?? '-' }}</td><td data-label="Kelas">{{ $cMap[$p->class_id] ?? '-' }}</td>
        <td class="r">@if($dariPusat && $p->pusat_uuid)<small class="mut">dari Pusat</small>@else<form method="post" action="{{ route('admin.pairings.destroy') }}" class="inline" data-confirm="Hapus pasangan ini?">@csrf @method('DELETE')
            <input type="hidden" name="user_id" value="{{ $p->user_id }}"><input type="hidden" name="subject_id" value="{{ $p->subject_id }}"><input type="hidden" name="class_id" value="{{ $p->class_id }}"><button class="btn btn-sm btn-danger">Hapus</button></form>@endif</td></tr>
@empty
    <tr><td colspan="4" class="empty">Belum ada pasangan.</td></tr>
@endforelse
</tbody></table></div>
@endsection
