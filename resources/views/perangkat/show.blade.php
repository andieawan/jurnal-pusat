@extends('layouts.app', ['title' => $p->topik])
@section('content')
@php($D = \App\Services\Perangkat::DOKUMEN)
<a class="back-link noprint" href="{{ route('perangkat') }}">‹ Daftar perangkat ajar</a>
<div class="head noprint">
    <div>
        <h1>{{ $p->topik }}</h1>
        <small>{{ $p->isian['mapel'] }} · {{ $p->isian['kelas'] }} · {{ $p->guru?->nama }}</small>
    </div>
    <div class="row">
        <a class="btn" href="{{ route('mengajar', ['subject' => $p->subject_id, 'class' => $p->class_id]) }}">Buka Mengajar</a>
        @can('perangkat-buat')<a class="btn" href="{{ route('perangkat.create', ['dari' => $p->id]) }}">Salin jadi baru</a>@endcan
    </div>
</div>

@if($isiAi && $bolehUbah)
    <div class="card ai-antre noprint" data-ai-antre="{{ collect($ada)->map(fn ($d) => route('perangkat.ai', [$p, $d]))->implode(' ') }}">
        <h2>AI sedang menyusun dokumen</h2>
        <p class="mut">Biasanya 10–40 detik per dokumen. Jangan tutup halaman ini.</p>
        <ol>@foreach($ada as $d)<li>{{ $D[$d] }} — <span>menunggu</span></li>@endforeach</ol>
    </div>
@endif

<nav class="subtab noprint" aria-label="Dokumen">
    @foreach($ada as $d)<a href="{{ route('perangkat.show', ['perangkat' => $p, 'dok' => $d]) }}" @class(['on' => $d === $dok]) @if($d === $dok) aria-current="page" @endif>{{ $D[$d] }}@if($p->dariAi($d)) <span class="badge b-ok">AI</span>@endif</a>@endforeach
</nav>

<div class="card filter-card noprint pa-aksi">
    <div class="row">
        <button type="button" class="btn btn-pri" onclick="window.print()">Cetak / Simpan PDF</button>
        <a class="btn" href="{{ route('perangkat.unduh', [$p, $dok]) }}">Unduh Word</a>
        @if($bolehUbah)
            <a class="btn" href="{{ route('perangkat.edit', [$p, $dok]) }}">Ubah isi</a>
            @if($ai)
                <form method="post" action="{{ route('perangkat.ai', [$p, $dok]) }}" class="inline" data-confirm="Isi {{ $D[$dok] }} akan diganti tulisan AI yang baru. Lanjutkan?">@csrf<button class="btn">{{ $p->dariAi($dok) ? 'Tulis ulang dengan AI' : 'Isi dengan AI' }}</button></form>
            @endif
        @endif
    </div>
    @if(! $p->dariAi($dok))<small class="mut">{{ $bolehUbah ? 'Draf templat: lengkapi bagian bertanda [ … ] lewat tombol Ubah isi.' : 'Draf templat.' }}</small>@else<small class="mut">Ditulis AI — periksa kebenaran isi dan sesuaikan dengan kelas Anda sebelum dipakai.</small>@endif
</div>

<article class="letter pa">@include('perangkat._dokumen')</article>

@if($bolehUbah)
    <form method="post" action="{{ route('perangkat.destroy', $p) }}" class="noprint" style="margin-top:18px" data-confirm="Hapus perangkat ajar “{{ $p->topik }}” beserta semua dokumennya?">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Hapus perangkat ajar ini</button></form>
@endif
@endsection
