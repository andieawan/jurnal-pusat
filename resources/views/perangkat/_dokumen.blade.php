{{-- Satu dokumen perangkat ajar. Dipakai di layar, cetak, dan unduhan Word. Butuh $p (PerangkatAjar) dan $dok. --}}
@php
    $in = $p->isian;
    $isi = \App\Services\Perangkat::rapikan($p->hasil[$dok] ?? [], \App\Services\Perangkat::kerangka($dok));
    $judul = ['modul' => 'MODUL AJAR', 'lkpd' => 'LEMBAR KERJA PESERTA DIDIK (LKPD)', 'asesmen' => 'INSTRUMEN ASESMEN & RUBRIK PENILAIAN',
              'bacaan' => 'BAHAN BACAAN GURU & PESERTA DIDIK', 'tindak' => 'PROGRAM TINDAK LANJUT PEMBELAJARAN'][$dok];
    $disahkan = $p->jenis === 'guru' && $p->pengesahan === 'disetujui' ? \App\Support\Dates::human(substr((string) $p->pengesahan_pada, 0, 10)) : '';
    $daftar = fn (array $xs) => $xs ? '<ol>'.collect($xs)->map(fn ($x) => '<li>'.e($x).'</li>')->implode('').'</ol>' : '<p>-</p>';
@endphp
@include('letters._kop')
<h1 class="pa-judul">{{ $judul }}</h1>
<p class="pa-sub">{{ $in['mapel'] }} · {{ $in['topik'] }}</p>

<table class="pa-tbl pa-id">
    <tr><th>Penyusun</th><td>{{ $in['guru'] }}@if($in['nip']) — NIP/NUPTK {{ $in['nip'] }}@endif</td><th>Satuan pendidikan</th><td>{{ $in['sekolah'] }}</td></tr>
    <tr><th>Mata pelajaran</th><td>{{ $in['mapel'] }}</td><th>Fase / kelas</th><td>{{ $in['fase'] ? 'Fase '.$in['fase'].' / ' : '' }}{{ $in['kelas'] }}</td></tr>
    <tr><th>Konsentrasi keahlian</th><td>{{ $in['konsentrasi'] ?: '-' }}</td><th>Semester / tahun</th><td>{{ $in['semester'] }} / {{ $in['tahun'] }}</td></tr>
    <tr><th>Alokasi waktu</th><td>{{ $in['alokasi'] }} × {{ $in['pertemuan'] }} pertemuan</td><th>Model</th><td>{{ $in['model'] }}</td></tr>
</table>

@if($dok === 'modul')
    <h2 class="pa-h">A. Identifikasi</h2>
    <table class="pa-tbl">
        <tr><th>Elemen CP</th><td>{{ $in['elemen'] ?: '-' }}</td></tr>
        <tr><th>Dimensi profil lulusan</th><td>{{ implode(', ', $in['dimensi']) ?: '-' }}</td></tr>
        <tr><th>Kesiapan peserta didik</th><td>{{ $in['kesiapan'] ?: '-' }}</td></tr>
        <tr><th>Sarana & prasarana</th><td>{{ $in['sarana'] ?: '-' }}</td></tr>
    </table>
    <h2 class="pa-h">B. Desain pembelajaran</h2>
    <table class="pa-tbl">
        <tr><th>Tujuan pembelajaran</th><td>{!! $daftar($isi['tujuan']) !!}</td></tr>
        <tr><th>Pemahaman bermakna</th><td>{{ $isi['pemahaman_bermakna'] ?: '-' }}</td></tr>
        <tr><th>Pertanyaan pemantik</th><td>{!! $daftar($isi['pertanyaan_pemantik']) !!}</td></tr>
        <tr><th>Metode</th><td>{{ implode(', ', $in['metode']) ?: '-' }}</td></tr>
        <tr><th>Lingkungan belajar</th><td>{{ implode(', ', $in['lingkungan']) ?: '-' }}</td></tr>
        <tr><th>Media & alat</th><td>{{ implode(', ', $in['media']) ?: '-' }}</td></tr>
    </table>
    <h2 class="pa-h">C. Langkah pembelajaran</h2>
    @foreach($isi['pertemuan'] as $pt)
        <table class="pa-tbl pa-langkah">
            <tr><th colspan="2" class="pa-sel">{{ $pt['judul'] ?: 'Pertemuan '.$loop->iteration }}</th></tr>
            @foreach(['pendahuluan' => 'Pendahuluan', 'memahami' => 'Inti — memahami', 'mengaplikasi' => 'Inti — mengaplikasi', 'merefleksi' => 'Inti — merefleksi', 'penutup' => 'Penutup'] as $k => $label)
                <tr><th>{{ $label }}</th><td>{!! $daftar($pt[$k]) !!}</td></tr>
            @endforeach
        </table>
    @endforeach
    <h2 class="pa-h">D. Asesmen</h2>
    <p>{{ $isi['asesmen'] ?: '-' }}</p>
    <table class="pa-ttd"><tr>
        <td>Mengetahui,<br>Kepala Sekolah<div class="pa-sp">@if($disahkan)<span class="pa-cap">✓ DISAHKAN<br>{{ $disahkan }}</span>@endif</div><b>{{ $in['kepsek'] }}</b>@if($in['kepsek_nip'])<br>NIP/NUPTK {{ $in['kepsek_nip'] }}@endif</td>
        <td>{{ config('absensi.surat.kota') }}, {{ \App\Support\Dates::human(substr((string) $p->updated_at, 0, 10)) }}<br>Guru Mata Pelajaran<div class="pa-sp"></div><b>{{ $in['guru'] }}</b>@if($in['nip'])<br>NIP/NUPTK {{ $in['nip'] }}@endif</td>
    </tr></table>
@elseif($dok === 'lkpd')
    <h2 class="pa-h">{{ $isi['judul'] ?: 'LKPD' }}</h2>
    <table class="pa-tbl"><tr><th>Nama kelompok</th><td></td></tr><tr><th>Anggota</th><td class="pa-kosong"></td></tr></table>
    <h2 class="pa-h">Petunjuk</h2>{!! $daftar($isi['petunjuk']) !!}
    @foreach($isi['aktivitas'] as $a)
        <table class="pa-tbl"><tr><th class="pa-sel">{{ $a['judul'] }}</th></tr><tr><td>{!! $daftar($a['langkah']) !!}<div class="pa-jawab">Jawaban / hasil kerja:</div></td></tr></table>
    @endforeach
    <h2 class="pa-h">Refleksi</h2>
    <table class="pa-tbl"><tr><th style="width:55%">Pertanyaan</th><th>Tanggapan</th></tr>@foreach($isi['refleksi'] as $q)<tr><td>{{ $q }}</td><td class="pa-kosong"></td></tr>@endforeach</table>
@elseif($dok === 'asesmen')
    <h2 class="pa-h">1. Asesmen diagnostik (awal pembelajaran)</h2>
    <table class="pa-tbl"><tr><th style="width:40px">No</th><th>Pertanyaan</th><th>Tindak lanjut</th></tr>@foreach($isi['diagnostik'] as $r)<tr><td>{{ $loop->iteration }}</td><td>{{ $r['pertanyaan'] }}</td><td>{{ $r['tindak_lanjut'] }}</td></tr>@endforeach</table>
    <h2 class="pa-h">2. Asesmen formatif (observasi proses)</h2>
    <div class="scroll"><table class="pa-tbl"><tr><th style="width:40px">No</th><th>Indikator</th><th>Dimensi profil</th><th style="width:90px">Ya / Tidak</th><th>Catatan</th></tr>@foreach($isi['formatif'] as $r)<tr><td>{{ $loop->iteration }}</td><td>{{ $r['indikator'] }}</td><td>{{ $r['dimensi'] }}</td><td></td><td></td></tr>@endforeach</table></div>
    <h2 class="pa-h">3. Rubrik penilaian sumatif</h2>
    <div class="scroll"><table class="pa-tbl pa-rubrik"><tr><th>Kriteria</th><th>Baru berkembang (1)</th><th>Layak (2)</th><th>Cakap (3)</th><th>Mahir (4)</th></tr>@foreach($isi['rubrik'] as $r)<tr><th>{{ $r['kriteria'] }}</th><td>{{ $r['baru_berkembang'] }}</td><td>{{ $r['layak'] }}</td><td>{{ $r['cakap'] }}</td><td>{{ $r['mahir'] }}</td></tr>@endforeach</table></div>
@elseif($dok === 'bacaan')
    <p class="pa-lead">{{ $isi['ringkasan'] }}</p>
    @foreach($isi['bagian'] as $b)<h2 class="pa-h">{{ $b['judul'] }}</h2><p class="pa-isi">{{ $b['isi'] }}</p>@endforeach
    <h2 class="pa-h">Glosarium</h2>
    <table class="pa-tbl"><tr><th style="width:30%">Istilah</th><th>Arti</th></tr>@foreach($isi['glosarium'] as $g)<tr><td><b>{{ $g['istilah'] }}</b></td><td>{{ $g['arti'] }}</td></tr>@endforeach</table>
    <h2 class="pa-h">Daftar pustaka</h2>{!! $daftar($isi['pustaka']) !!}
@else
    <h2 class="pa-h">A. Remedial</h2>
    <table class="pa-tbl"><tr><th style="width:40%">Sasaran</th><th>Kegiatan</th></tr>@foreach($isi['remedial'] as $r)<tr><td>{{ $r['sasaran'] }}</td><td>{{ $r['kegiatan'] }}</td></tr>@endforeach</table>
    <h2 class="pa-h">B. Pengayaan</h2>
    <table class="pa-tbl"><tr><th style="width:40%">Fokus</th><th>Kegiatan</th></tr>@foreach($isi['pengayaan'] as $r)<tr><td>{{ $r['fokus'] }}</td><td>{{ $r['kegiatan'] }}</td></tr>@endforeach</table>
@endif
@if($disahkan)
    <p class="pa-disahkan">Perangkat ajar ini telah diperiksa dan disahkan oleh Kepala Sekolah ({{ $p->pengesahan_oleh }}) pada {{ $disahkan }}.</p>
@endif
