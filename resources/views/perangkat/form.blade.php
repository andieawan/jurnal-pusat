@extends('layouts.app', ['title' => 'Draf MGMP Baru'])
@section('content')
@php
    $v = fn (string $k, $def = '') => old($k, $isian[$k] ?? $def);
    $cek = fn (string $k, string $opsi, array $def = []) => in_array($opsi, (array) old($k, $isian[$k] ?? $def), true);
@endphp
<a class="back-link" href="{{ route('perangkat') }}">‹ Daftar perangkat ajar</a>
<div class="head">
    <div>
        <h1>Draf MGMP Baru</h1>
        <small>Draf ini dipakai bersama guru mapel {{ $set->semester }} {{ $set->tahun_ajaran }}. Isi yang Anda ketahui; sisanya bisa dilengkapi setelah draf jadi.</small>
    </div>
</div>

<form method="post" action="{{ route('perangkat.store') }}" enctype="multipart/form-data" data-unsaved>
    @csrf
    <div class="card">
        <h2>1. Mapel & tingkat</h2>
        <p class="hint" style="margin:0 0 14px">Sekolah, kepala sekolah, dan fase diisi otomatis. Nama guru, NIP, dan kelas diganti otomatis saat guru mengambil draf.</p>
        <div class="fields">
            <div><label for="subject">Mata pelajaran</label><select id="subject" name="subject">@foreach($subjects as $s)<option value="{{ $s->id }}" @selected($s->id === $subjectId)>{{ $s->name }}</option>@endforeach</select></div>
            <div><label for="tingkat">Tingkat</label><select id="tingkat" name="tingkat">@foreach(['X' => 'Kelas X (Fase E)', 'XI' => 'Kelas XI (Fase F)', 'XII' => 'Kelas XII (Fase F)'] as $t => $label)<option value="{{ $t }}" @selected(old('tingkat', $tingkat) === $t)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="konsentrasi">Konsentrasi keahlian <small class="mut">(kosong = ikut kelas guru)</small></label><input id="konsentrasi" type="text" name="konsentrasi" maxlength="150" value="{{ $v('konsentrasi') }}" placeholder="mis. Desain Komunikasi Visual"></div>
            <div><label for="alokasi">Alokasi waktu per pertemuan</label><input id="alokasi" type="text" name="alokasi" maxlength="60" required value="{{ $v('alokasi', '2 x 45 menit') }}"></div>
            <div><label for="pertemuan">Jumlah pertemuan</label><input id="pertemuan" type="number" name="pertemuan" min="1" max="6" required value="{{ $v('pertemuan', 1) }}"></div>
        </div>
    </div>

    <div class="card">
        <h2>2. Desain pembelajaran</h2>
        <div class="field"><label for="topik">Topik / materi pokok <span class="req">*</span></label><input id="topik" type="text" name="topik" maxlength="200" required value="{{ $v('topik') }}" placeholder="mis. Prinsip desain: keseimbangan dan kontras"></div>
        <div class="field"><label for="tp">Tujuan pembelajaran <span class="req">*</span> <small class="mut">(satu tujuan per baris)</small></label><textarea id="tp" name="tp" rows="3" maxlength="2000" required placeholder="Peserta didik mampu menerapkan prinsip keseimbangan pada desain poster.">{{ $v('tp') }}</textarea></div>
        <div class="field"><label for="elemen">Elemen CP</label><input id="elemen" type="text" name="elemen" maxlength="300" value="{{ $v('elemen') }}" placeholder="mis. Prinsip dasar desain dan komunikasi"></div>
        <div class="field"><label for="model">Model pembelajaran</label>
            <select id="model" name="model">@foreach(\App\Services\Perangkat::MODEL as $m => $tahap)<option value="{{ $m }}" @selected($v('model', 'Project Based Learning (PjBL)') === $m)>{{ $m }}</option>@endforeach</select></div>
        @foreach(['metode' => ['Metode', \App\Services\Perangkat::METODE, ['Diskusi kelompok', 'Tanya jawab', 'Presentasi']],
                  'dimensi' => ['Dimensi profil lulusan', \App\Services\Perangkat::DIMENSI, ['Penalaran kritis', 'Kreativitas', 'Kolaborasi']],
                  'lingkungan' => ['Lingkungan belajar', \App\Services\Perangkat::LINGKUNGAN, ['Ruang kelas']],
                  'media' => ['Media & alat', \App\Services\Perangkat::MEDIA, ['Slide presentasi']]] as $nama => [$label, $opsi, $def])
            <fieldset class="field pilih">
                <legend>{{ $label }}</legend>
                <div class="pilih-isi">@foreach($opsi as $o)<label class="chk"><input type="checkbox" name="{{ $nama }}[]" value="{{ $o }}" @checked($cek($nama, $o, $def))> {{ $o }}</label>@endforeach</div>
            </fieldset>
        @endforeach
        <details class="more">
            <summary>Kesiapan peserta didik & sarana sekolah (opsional)</summary>
            <div class="field"><label for="kesiapan">Kesiapan & karakteristik peserta didik</label><textarea id="kesiapan" name="kesiapan" rows="2" maxlength="600" placeholder="mis. Sebagian sudah terbiasa memakai Canva, minat tinggi pada media sosial">{{ $v('kesiapan') }}</textarea></div>
            <div class="field"><label for="sarana">Sarana & prasarana</label><textarea id="sarana" name="sarana" rows="2" maxlength="600" placeholder="mis. Lab komputer 30 unit, proyektor, internet terbatas">{{ $v('sarana') }}</textarea></div>
        </details>
    </div>

    <div class="card">
        <h2>3. Bahan ajar rujukan <small class="mut" style="font-weight:500">(opsional, sangat disarankan bila memakai AI)</small></h2>
        <p class="hint" style="margin:0 0 10px">Buku siswa, modul, silabus, atau ringkasan materi. PDF, DOCX, atau TXT; maksimal 3 berkas, 8 MB per berkas. AI menyusun isi dokumen dari bahan ini.</p>
        <input type="file" name="bahan[]" accept=".pdf,.docx,.txt" multiple>
    </div>

    <div class="card">
        <h2>4. Dokumen yang dibuat</h2>
        <div class="pilih-isi">@foreach(\App\Services\Perangkat::DOKUMEN as $k => $label)<label class="chk"><input type="checkbox" name="dokumen[]" value="{{ $k }}" @checked(in_array($k, (array) old('dokumen', array_keys(\App\Services\Perangkat::DOKUMEN)), true))> {{ $label }}</label>@endforeach</div>
        @if($ai)
            <label class="chk ai-pilih"><input type="checkbox" name="pakai_ai" value="1" @checked(old('pakai_ai', true))> <span><b>Isi dengan AI</b><small class="mut" style="display:block">Hanya data pembelajaran dan bahan rujukan yang dikirim ke AI (tanpa nama guru/siswa). Periksa hasilnya sebelum ditandai siap.</small></span></label>
            <div class="field" style="margin-top:12px"><label for="catatan">Instruksi tambahan untuk AI <small class="mut">(opsional)</small></label><textarea id="catatan" name="catatan" rows="2" maxlength="1000" placeholder="mis. Gunakan contoh UMKM di Jember; LKPD dikerjakan berpasangan">{{ $v('catatan') }}</textarea></div>
        @else
            <p class="hint" style="margin:12px 0 0">Draf disusun dari templat sesuai model pembelajaran yang dipilih. Agar AI ikut menulis, isi <a href="{{ route('perangkat.kunci') }}">kunci AI</a> Anda dulu.</p>
        @endif
    </div>

    <div class="sticky-save">
        <small class="mut hide-sm">Draf bisa diubah, dicetak, dan diunduh setelah dibuat.</small>
        <button class="btn btn-pri">Buat draf MGMP</button>
    </div>
</form>
@endsection
