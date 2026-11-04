@extends('layouts.app', ['title' => 'Ubah '.\App\Services\Perangkat::DOKUMEN[$dok]])
@section('content')
<a class="back-link" href="{{ route('perangkat.show', ['perangkat' => $p, 'dok' => $dok]) }}">‹ Kembali ke dokumen</a>
<div class="head"><div><h1>Ubah {{ \App\Services\Perangkat::DOKUMEN[$dok] }}</h1><small>{{ $p->topik }} · {{ $p->isian['mapel'] }} · {{ $p->isian['kelas'] }}</small></div></div>
<form method="post" action="{{ route('perangkat.update', [$p, $dok]) }}" data-unsaved>
    @csrf @method('PUT')
    <div class="card">
        @foreach(\App\Services\Perangkat::kerangka($dok) as $k => $acuan)
            @include('perangkat._isian', ['nilai' => $isi[$k], 'acuan' => $acuan, 'nama' => 'isi['.$k.']', 'label' => ucfirst(str_replace('_', ' ', $k))])
        @endforeach
    </div>
    <div class="sticky-save"><a class="btn" href="{{ route('perangkat.show', ['perangkat' => $p, 'dok' => $dok]) }}">Batal</a><button class="btn btn-pri">Simpan {{ \App\Services\Perangkat::DOKUMEN[$dok] }}</button></div>
</form>
@endsection
