@extends('layouts.app', ['title' => $p->topik])
@section('content')
@php($D = \App\Services\Perangkat::DOKUMEN)
@php($draf = $p->jenis === 'mgmp')
@php($sah = ['' => ['belum diajukan', ''], 'diajukan' => ['menunggu pemeriksaan', 'b-warn'], 'disetujui' => ['disahkan kepala sekolah', 'b-ok'], 'revisi' => ['perlu perbaikan', 'b-bad']][$p->pengesahan] ?? ['', ''])
<a class="back-link noprint" href="{{ route('perangkat') }}">‹ Perangkat ajar</a>
<div class="head noprint">
    <div>
        <h1>{{ $p->topik }}</h1>
        <small>
            @if($draf)
                Draf MGMP · {{ $p->isian['mapel'] }} · kelas {{ $p->tingkat }} · {{ $p->semester }} {{ $p->tahun }} · disusun {{ $p->guru?->nama }}
                <span @class(['badge', 'b-ok' => $p->status === 'siap', 'b-warn' => $p->status !== 'siap'])>{{ $p->status === 'siap' ? 'siap dipakai guru' : 'masih draf' }}</span>
            @else
                {{ $p->isian['mapel'] }} · {{ $p->kelas?->name ?? $p->isian['kelas'] }} · {{ $p->guru?->nama }}
                <span class="badge {{ $sah[1] }}">{{ $sah[0] }}</span>
                @if($asal) · dari draf MGMP @if($asal->updated_at > $p->created_at)<span class="badge b-warn" title="Draf MGMP diubah setelah Anda mengambilnya">draf MGMP diperbarui</span>@endif @endif
            @endif
        </small>
    </div>
    <div class="row">
        @if($draf && $bisaUbah)
            <form method="post" action="{{ route('perangkat.status', $p) }}" class="inline">@csrf<button @class(['btn', 'btn-pri' => $p->status !== 'siap'])>{{ $p->status === 'siap' ? 'Kembalikan ke draf' : 'Tandai siap dipakai guru' }}</button></form>
            <a class="btn" href="{{ route('perangkat.create', ['dari' => $p->id]) }}">Salin jadi draf baru</a>
        @elseif(! $draf)
            <a class="btn" href="{{ route('mengajar', ['subject' => $p->subject_id, 'class' => $p->class_id]) }}">Buka Mengajar</a>
            @if($nilai)<a class="btn" href="{{ route('grades', ['subject' => $p->subject_id, 'class' => $p->class_id, 'perangkat' => $p->id]) }}">Buat kegiatan nilai</a>@endif
        @endif
    </div>
</div>

@if($draf && $kelasAmbil->isNotEmpty() && ($p->status === 'siap' || $bisaUbah))
    <form method="post" action="{{ route('perangkat.ambil', $p) }}" class="card filter-card noprint ambil">
        @csrf
        <div class="fields">
            <div><label for="ambil-kelas">Ambil draf ini untuk kelas</label><select id="ambil-kelas" name="class">@foreach($kelasAmbil as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach</select></div>
            <div class="row"><button class="btn btn-pri">Ambil jadi perangkat ajar saya</button></div>
        </div>
        <small class="mut">Salinan memakai nama, NIP, dan kelas Anda. Draf MGMP tetap utuh.</small>
    </form>
@endif

@if($isiAi && $ai)
    <div class="card ai-antre noprint" data-ai-antre="{{ collect($ada)->map(fn ($d) => route('perangkat.ai', [$p, $d]))->implode(' ') }}">
        <h2>AI sedang menyusun dokumen</h2>
        <p class="mut">Biasanya 10–40 detik per dokumen. Jangan tutup halaman ini.</p>
        <ol>@foreach($ada as $d)<li>{{ $D[$d] }} — <span>menunggu</span></li>@endforeach</ol>
    </div>
@endif

@if(! $draf && ($pemilik || $periksa || $p->pengesahan !== ''))
    <div @class(['card', 'noprint', 'pa-sah-kartu', 'is-'.($p->pengesahan ?: 'draf')])>
        <h2>Pemeriksaan kepala sekolah <span class="badge {{ $sah[1] }}">{{ $sah[0] }}</span></h2>
        @if($p->pengesahan === 'disetujui')
            <p class="mut">Disahkan {{ $p->pengesahan_oleh }}, {{ \App\Support\Dates::human(substr((string) $p->pengesahan_pada, 0, 10)) }}. Tanda pengesahan ikut tercetak. Mengubah isi akan membatalkan pengesahan.</p>
        @elseif($p->pengesahan === 'diajukan')
            <p class="mut">Sudah diajukan; menunggu kepala sekolah memeriksa.</p>
        @elseif($p->pengesahan === 'revisi')
            <p><b>Catatan perbaikan dari {{ $p->pengesahan_oleh }}:</b> {{ $p->pengesahan_catatan }}</p>
        @else
            <p class="mut">Bila semua dokumen sudah lengkap, ajukan ke kepala sekolah untuk diperiksa dan disahkan.</p>
        @endif
        @if($p->pengesahan_catatan && $p->pengesahan === 'disetujui')<p><b>Catatan:</b> {{ $p->pengesahan_catatan }}</p>@endif
        @if($pemilik && in_array($p->pengesahan, ['', 'revisi'], true))
            <form method="post" action="{{ route('perangkat.ajukan', $p) }}" class="inline">@csrf<button class="btn btn-pri">{{ $p->pengesahan === 'revisi' ? 'Ajukan ulang' : 'Ajukan ke kepala sekolah' }}</button></form>
        @endif
        @if($periksa && $p->pengesahan === 'diajukan')
            <form method="post" action="{{ route('perangkat.periksa', $p) }}" style="margin-top:6px">@csrf
                <div class="field"><label for="catatan">Catatan untuk guru <small class="mut">(wajib bila perlu perbaikan)</small></label><textarea id="catatan" name="catatan" rows="2" maxlength="1000">{{ old('catatan') }}</textarea></div>
                <div class="row"><button class="btn btn-pri" name="keputusan" value="setujui">Setujui & sahkan</button><button class="btn" name="keputusan" value="revisi">Perlu perbaikan</button></div>
            </form>
        @endif
    </div>
@endif

<nav class="subtab noprint" aria-label="Dokumen">
    @foreach($ada as $d)<a href="{{ route('perangkat.show', ['perangkat' => $p, 'dok' => $d]) }}" @class(['on' => $d === $dok]) @if($d === $dok) aria-current="page" @endif>{{ $D[$d] }}@if($p->dariAi($d)) <span class="badge b-ok">AI</span>@endif</a>@endforeach
</nav>

<div class="card filter-card noprint pa-aksi">
    <div class="row">
        <button type="button" class="btn btn-pri" onclick="window.print()">Cetak / Simpan PDF</button>
        <a class="btn" href="{{ route('perangkat.unduh', [$p, $dok]) }}">Unduh Word</a>
        @if($bisaUbah)
            <a class="btn" href="{{ route('perangkat.edit', [$p, $dok]) }}">Ubah isi</a>
            @if($ai)
                <form method="post" action="{{ route('perangkat.ai', [$p, $dok]) }}" class="inline" data-confirm="Isi {{ $D[$dok] }} akan diganti tulisan AI yang baru. Lanjutkan?">@csrf<button class="btn">{{ $p->dariAi($dok) ? 'Tulis ulang dengan AI' : 'Isi dengan AI' }}</button></form>
            @endif
        @endif
    </div>
    <small class="mut">{{ $p->dariAi($dok) ? 'Ditulis AI — periksa kebenaran isi dan sesuaikan dengan kelas sebelum dipakai.' : ($bisaUbah ? ($p->mulai === 'kosong' ? 'Diisi manual: tulis isinya lewat tombol Ubah isi.' : 'Lengkapi bagian bertanda [ … ] lewat tombol Ubah isi.') : '') }}
        @if($ai) AI terpakai {{ $pakaiAi[0] }}{{ $pakaiAi[1] ? ' dari '.$pakaiAi[1] : '' }} dokumen semester ini untuk mapel ini{{ $pakaiGuru && $pakaiGuru[1] ? '; kuota Anda '.$pakaiGuru[0].' dari '.$pakaiGuru[1] : '' }}.@endif</small>
</div>

@if($bisaUbah || ($p->isian['bahan'] ?? []))
    <div class="card noprint">
        <h2>Bahan ajar rujukan</h2>
        @forelse($p->isian['bahan'] ?? [] as $i => $b)
            <div class="row pa-bahan">
                <a href="{{ route('perangkat.bahan.unduh', [$p, $i]) }}">{{ $b['nama'] }}</a> <small class="mut">{{ number_format($b['ukuran'] / 1048576, 1, ',', '.') }} MB</small>
                @if($bisaUbah)<form method="post" action="{{ route('perangkat.bahan.hapus', [$p, $i]) }}" class="inline" data-confirm="Hapus bahan “{{ $b['nama'] }}”?">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Hapus</button></form>@endif
            </div>
        @empty
            <p class="mut">{{ $draf ? 'Belum ada. Bahan rujukan membuat tulisan AI sesuai buku/modul yang dipakai sekolah.' : 'Belum ada. Lampirkan buku, modul, atau ringkasan materi yang Anda pakai (PDF, DOCX, TXT).' }}</p>
        @endforelse
        @if($bisaUbah && count($p->isian['bahan'] ?? []) < \App\Services\BahanAjar::MAKS_BERKAS)
            <form method="post" action="{{ route('perangkat.bahan', $p) }}" enctype="multipart/form-data" class="row" style="margin-top:10px">@csrf
                <input type="file" name="bahan[]" accept=".pdf,.docx,.txt" multiple required style="flex:1;min-width:200px"><button class="btn">Unggah</button></form>
        @endif
    </div>
@endif

@if(! $draf && $bisaUbah)
    <details class="card noprint">
        <summary class="link-summary">Ubah identitas (NIP, konsentrasi, alokasi waktu)</summary>
        <form method="post" action="{{ route('perangkat.identitas', $p) }}" class="fields" style="margin-top:10px">@csrf @method('PUT')
            <div><label for="id-nip">NIP / NUPTK</label><input id="id-nip" type="text" name="nip" maxlength="40" value="{{ $p->isian['nip'] }}"></div>
            <div><label for="id-kons">Konsentrasi keahlian</label><input id="id-kons" type="text" name="konsentrasi" maxlength="150" value="{{ $p->isian['konsentrasi'] }}"></div>
            <div><label for="id-alok">Alokasi waktu</label><input id="id-alok" type="text" name="alokasi" maxlength="60" required value="{{ $p->isian['alokasi'] }}"></div>
            <div class="row"><button class="btn btn-pri">Simpan identitas</button></div>
        </form>
    </details>
@endif

<article class="letter pa">@include('perangkat._dokumen')</article>

@if($bisaUbah)
    <form method="post" action="{{ route('perangkat.destroy', $p) }}" class="noprint" style="margin-top:18px" data-confirm="Hapus “{{ $p->topik }}” beserta semua dokumennya?">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Hapus {{ $draf ? 'draf MGMP' : 'perangkat ajar' }} ini</button></form>
@endif
@endsection
