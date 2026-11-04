@extends('layouts.app', ['title' => 'Rekap Perangkat Ajar'])
@section('content')
<a class="back-link" href="{{ route('perangkat') }}">‹ Perangkat ajar</a>
<div class="head"><div><h1>Rekap kelengkapan perangkat ajar</h1><small>{{ $set->semester }} {{ $set->tahun_ajaran }} · tiap guru × mapel × kelas yang diampu</small></div></div>

@php($ada = $total - ($hitung['belum'] ?? 0))
<div class="kpis kpis-4">
    <div class="kpi"><small>Punya perangkat</small><div class="n mono">{{ $ada }} / {{ $total }}</div><small>{{ $total ? round($ada * 100 / $total) : 0 }}% pasangan mapel·kelas</small></div>
    <div class="kpi"><small>Disahkan</small><div class="n mono ok">{{ $hitung['disetujui'] ?? 0 }}</div></div>
    <div class="kpi"><small>Menunggu pemeriksaan</small><div class="n mono {{ ($hitung['diajukan'] ?? 0) ? 'warn' : '' }}">{{ $hitung['diajukan'] ?? 0 }}</div></div>
    <div class="kpi"><small>Belum ada</small><div class="n mono {{ ($hitung['belum'] ?? 0) ? 'bad' : 'ok' }}">{{ $hitung['belum'] ?? 0 }}</div></div>
</div>

<nav class="subtab" aria-label="Saring status">
    @foreach(['' => 'Semua', 'belum' => 'Belum ada', 'draf' => 'Belum diajukan', 'diajukan' => 'Menunggu', 'revisi' => 'Perlu perbaikan', 'disetujui' => 'Disahkan'] as $k => $l)
        <a href="{{ route('perangkat.rekap', array_filter(['status' => $k])) }}" @class(['on' => $saring === $k])>{{ $l }}@if($k !== '') <small>({{ $hitung[$k] ?? 0 }})</small>@endif</a>
    @endforeach
</nav>

<div class="card card-tight">
    <div class="list-tools"><input type="search" data-filter="#rekap" placeholder="Cari guru, mapel, atau kelas…" aria-label="Cari"></div>
    @if($baris->isEmpty())
        <div class="empty">Tidak ada data untuk saringan ini.</div>
    @else
    <table class="tbl tbl-cards" id="rekap">
        <thead><tr><th>Guru</th><th>Mapel</th><th>Kelas</th><th>Perangkat ajar</th><th>Status</th></tr></thead>
        <tbody>
        @foreach($baris as $b)
            <tr>
                <td class="card-title"><b>{{ $b['guru'] }}</b></td>
                <td data-label="Mapel">{{ $b['mapel'] }}</td>
                <td data-label="Kelas">{{ $b['kelas'] }}</td>
                <td data-label="Perangkat">@forelse($b['perangkat'] as $pa)<a href="{{ route('perangkat.show', $pa->id) }}">{{ \Illuminate\Support\Str::limit($pa->topik, 40) }}</a>@if(! $loop->last), @endif @empty<span class="mut">—</span>@endforelse</td>
                <td data-label="Status">@include('perangkat._sah', ['s' => $b['status']])</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
@endsection
