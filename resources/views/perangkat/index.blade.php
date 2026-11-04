@extends('layouts.app', ['title' => 'Perangkat Ajar'])
@section('content')
<div class="head">
    <div>
        <h1>Perangkat Ajar</h1>
        <small>MGMP menyusun draf tiap semester; guru mengambilnya untuk kelas masing-masing, lalu menyesuaikan, mencetak berkop, dan mengunduh Word.</small>
    </div>
    @if($mgmp)<div class="row"><a class="btn" href="{{ route('perangkat.kunci') }}">Kunci AI</a><a class="btn btn-pri" href="{{ route('perangkat.create') }}">+ Draf MGMP baru</a></div>@endif
</div>

<div class="card card-tight">
    <div class="list-tools"><b>Draf MGMP siap dipakai — {{ $set->semester }} {{ $set->tahun_ajaran }}</b><small class="mut">untuk mapel yang Anda ampu</small></div>
    @forelse($siap as $p)
        <div class="bk-item">
            <div class="bk-top">
                <div class="nm"><a href="{{ route('perangkat.show', $p) }}"><b>{{ $p->topik }}</b></a><small>{{ $p->mapel?->name }} · kelas {{ $p->tingkat }} · disusun {{ $p->guru?->nama }}</small></div>
                <div class="row">@if(in_array($p->id, $diambil, true))<span class="badge b-ok">sudah diambil</span>@endif<a class="btn btn-sm btn-pri" href="{{ route('perangkat.show', $p) }}">Lihat & ambil</a></div>
            </div>
        </div>
    @empty
        <div class="empty">Belum ada draf MGMP yang siap untuk mapel Anda semester ini.</div>
    @endforelse
</div>

@if($mgmp)
<div class="card card-tight">
    <div class="list-tools"><b>Draf yang disusun MGMP</b><small class="mut">mapel tim MGMP Anda · semua semester</small></div>
    @forelse($draf as $p)
        <div class="bk-item">
            <div class="bk-top">
                <div class="nm"><a href="{{ route('perangkat.show', $p) }}"><b>{{ $p->topik }}</b></a><small>{{ $p->mapel?->name }} · kelas {{ $p->tingkat }} · {{ $p->semester }} {{ $p->tahun }} · {{ $p->guru?->nama }}</small></div>
                <div class="row">
                    <span @class(['badge', 'b-ok' => $p->status === 'siap', 'b-warn' => $p->status !== 'siap'])>{{ $p->status === 'siap' ? 'siap dipakai' : 'masih draf' }}</span>
                    @if($p->sumber)<span class="badge" title="Sebagian diisi AI">AI</span>@endif
                </div>
            </div>
        </div>
    @empty
        <div class="empty">Belum ada draf. Mulai dengan <a href="{{ route('perangkat.create') }}">Draf MGMP baru</a>.</div>
    @endforelse
</div>
@endif

<div class="card card-tight">
    <div class="list-tools"><b>{{ $semua ? 'Perangkat ajar guru' : 'Perangkat ajar saya' }}</b></div>
    @if($milik->isEmpty())
        <div class="empty">Belum ada. Ambil draf MGMP di atas untuk kelas Anda.</div>
    @else
        <table class="tbl tbl-cards">
            <thead><tr><th>Topik</th><th>Mapel · Kelas</th>@if($semua)<th>Guru</th>@endif<th>Diperbarui</th><th></th></tr></thead>
            <tbody>
            @foreach($milik as $p)
                <tr>
                    <td class="card-title"><a href="{{ route('perangkat.show', $p) }}"><b>{{ $p->topik }}</b></a></td>
                    <td data-label="Mapel">{{ $p->mapel?->name }} · {{ $p->kelas?->name }}</td>
                    @if($semua)<td data-label="Guru">{{ $p->guru?->nama ?? '—' }}</td>@endif
                    <td data-label="Diperbarui">{{ \App\Support\Dates::human(substr((string) $p->updated_at, 0, 10)) }}</td>
                    <td class="r"><a class="btn btn-sm" href="{{ route('perangkat.show', $p) }}">Buka</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @include('partials.pager', ['p' => $milik])
    @endif
</div>
@endsection
