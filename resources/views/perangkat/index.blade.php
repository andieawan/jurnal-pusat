@extends('layouts.app', ['title' => 'Perangkat Ajar'])
@section('content')
<div class="head">
    <div>
        <h1>Perangkat Ajar</h1>
        <small>Modul ajar, LKPD, asesmen, bahan bacaan, dan tindak lanjut — identitas terisi otomatis, siap cetak berkop dan unduh Word.</small>
    </div>
    @if($bisaBuat)<a class="btn btn-pri" href="{{ route('perangkat.create') }}">+ Buat perangkat ajar</a>@endif
</div>

<div class="card card-tight">
    @if($daftar->isEmpty())
        <div class="empty">
            Belum ada perangkat ajar.
            @if($bisaBuat)<br><a class="btn btn-pri" style="margin-top:12px" href="{{ route('perangkat.create') }}">Buat yang pertama</a>@endif
        </div>
    @else
        <table class="tbl tbl-cards">
            <thead><tr><th>Topik</th><th>Mapel · Kelas</th>@if($semua)<th>Guru</th>@endif<th>Dokumen</th><th>Diperbarui</th><th></th></tr></thead>
            <tbody>
            @foreach($daftar as $p)
                <tr>
                    <td class="card-title"><a href="{{ route('perangkat.show', $p) }}"><b>{{ $p->topik }}</b></a></td>
                    <td data-label="Mapel">{{ $p->isian['mapel'] ?? $p->mapel?->name }} · {{ $p->isian['kelas'] ?? $p->kelas?->name }}</td>
                    @if($semua)<td data-label="Guru">{{ $p->guru?->nama ?? '—' }}</td>@endif
                    <td data-label="Dokumen">{{ count($p->hasil) }} dokumen @if($p->sumber)<span class="badge b-ok" title="Sebagian diisi AI">AI</span>@endif</td>
                    <td data-label="Diperbarui">{{ \App\Support\Dates::human(substr((string) $p->updated_at, 0, 10)) }}</td>
                    <td class="r"><a class="btn btn-sm" href="{{ route('perangkat.show', $p) }}">Buka</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @include('partials.pager', ['p' => $daftar])
    @endif
</div>
@endsection
