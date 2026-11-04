@extends('layouts.app', ['title' => 'Buat Perangkat Ajar'])
@section('content')
@php
    $v = fn (string $k, $def = '') => old($k, $isian[$k] ?? $def);
    $cek = fn (string $k, string $opsi, array $def = []) => in_array($opsi, (array) old($k, $isian[$k] ?? $def), true);
    $kelas = $classes->firstWhere('id', $classId);
@endphp
<a class="back-link" href="{{ route('perangkat') }}">‹ Daftar perangkat ajar</a>
<div class="head">
    <div>
        <h1>Buat Perangkat Ajar</h1>
        <small>Isi yang Anda ketahui; sisanya bisa dilengkapi setelah draf jadi.</small>
    </div>
</div>

@if($subjects->isEmpty())
    <div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">Belum ada mapel yang Anda ampu. Minta admin mengatur Pengampu Mapel di Pusat.</span></div>
@else
<form method="post" action="{{ route('perangkat.store') }}" data-unsaved>
    @csrf
    <div class="card">
        <h2>1. Mapel & kelas</h2>
        <p class="hint" style="margin:0 0 14px">Nama guru, sekolah, kepala sekolah, tahun ajaran ({{ $set->tahun_ajaran }} · {{ $set->semester }}), dan fase diambil otomatis.</p>
        <div class="fields">
            <div><label for="subject">Mata pelajaran</label><select id="subject" name="subject" data-class-map='@json($classMap)'>@foreach($subjects as $s)<option value="{{ $s->id }}" @selected($s->id === $subjectId)>{{ $s->name }}</option>@endforeach</select></div>
            <div><label for="class">Kelas</label><select id="class" name="class">@foreach($classes as $k)<option value="{{ $k->id }}" @selected($k->id === $classId)>{{ $k->name }}</option>@endforeach</select></div>
            <div><label for="konsentrasi">Konsentrasi keahlian</label><input id="konsentrasi" type="text" name="konsentrasi" maxlength="150" value="{{ $v('konsentrasi', $kelas->jurusan ?? '') }}" placeholder="mis. Desain Komunikasi Visual"></div>
            <div><label for="nip">NIP / NUPTK Anda <small class="mut">(opsional)</small></label><input id="nip" type="text" name="nip" maxlength="40" value="{{ old('nip', $nip) }}"></div>
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
        <h2>3. Dokumen yang dibuat</h2>
        <div class="pilih-isi">@foreach(\App\Services\Perangkat::DOKUMEN as $k => $label)<label class="chk"><input type="checkbox" name="dokumen[]" value="{{ $k }}" @checked(in_array($k, (array) old('dokumen', array_keys(\App\Services\Perangkat::DOKUMEN)), true))> {{ $label }}</label>@endforeach</div>
        @if($ai)
            <label class="chk ai-pilih"><input type="checkbox" name="pakai_ai" value="1" @checked(old('pakai_ai', true))> <span><b>Isi dengan AI</b><small class="mut" style="display:block">Hanya data pembelajaran yang dikirim ke AI (tanpa nama guru/siswa). Periksa hasilnya sebelum dipakai.</small></span></label>
            <div class="field" style="margin-top:12px"><label for="catatan">Instruksi tambahan untuk AI <small class="mut">(opsional)</small></label><textarea id="catatan" name="catatan" rows="2" maxlength="1000" placeholder="mis. Gunakan contoh UMKM di Jember; LKPD dikerjakan berpasangan">{{ $v('catatan') }}</textarea></div>
        @else
            <p class="hint" style="margin:12px 0 0">Draf disusun dari templat sesuai model pembelajaran yang dipilih. (Asisten AI belum diaktifkan admin.)</p>
        @endif
    </div>

    <div class="sticky-save">
        <small class="mut hide-sm">Draf bisa diubah, dicetak, dan diunduh setelah dibuat.</small>
        <button class="btn btn-pri">Buat draf perangkat ajar</button>
    </div>
</form>
@endif
@endsection
