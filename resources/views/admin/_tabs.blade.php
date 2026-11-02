@php
    // Menu Admin dikelompokkan: 6 bagian utama, sub-halaman muncul sebagai baris kedua.
    // Bila tersambung ke Pusat, halaman yang sepenuhnya diatur di Pusat (Jurusan, Kenaikan Kelas) tidak ditampilkan.
    $induk = \App\Services\PusatKlien::kelolaSiswa();
    $grup = [
        'Guru & Mengajar' => ['admin.teachers' => 'Akun Guru', 'admin.pairings' => 'Pasangan Mapel'],
        'Siswa' => ['admin.students' => 'Data Siswa'],
        'Kelas & Mapel' => array_filter(['admin.master' => 'Kelas & Mapel', 'admin.majors' => $induk ? null : 'Jurusan', 'admin.promotion' => $induk ? null : 'Kenaikan Kelas']),
        'Impor' => ['admin.import' => 'Impor Data', 'admin.hardcopy' => 'Absensi Hardcopy'],
        'Integrasi' => ['admin.pusat' => 'Pusat Sekolah', 'admin.api' => 'Kunci API'],
        'Sistem' => ['admin.settings' => 'Pengaturan & Backup', 'admin.logs' => 'Log Aktivitas'],
    ];
    $aktif = fn (string $r) => request()->routeIs($r, $r.'.*');
    $grupAktif = collect($grup)->search(fn ($hal) => collect(array_keys($hal))->contains($aktif))
        ?: (request()->routeIs('admin.majors*', 'admin.promotion*') ? 'Kelas & Mapel' : null);
@endphp
<div class="head"><div><h1>Admin Panel</h1><small>Kelola guru, siswa, mapel, impor, integrasi, dan pengaturan.</small></div>
    <div class="seg seg-scroll" role="navigation" aria-label="Bagian Admin Panel">
        @foreach($grup as $judul => $hal)
            <a href="{{ route(array_key_first($hal)) }}" @class(['on' => $grupAktif === $judul]) @if($grupAktif === $judul) aria-current="page" @endif>{{ $judul }}</a>
        @endforeach
    </div>
</div>
@if($grupAktif && count($grup[$grupAktif]) > 1)
    <nav class="subtab" aria-label="Halaman {{ $grupAktif }}">
        @foreach($grup[$grupAktif] as $r => $l)
            <a href="{{ route($r) }}" @class(['on' => $aktif($r)]) @if($aktif($r)) aria-current="page" @endif>{{ $l }}</a>
        @endforeach
    </nav>
@endif
@if(! request()->routeIs('admin.settings') && ($pendingDb = \App\Support\DbUpdate::pending()))
<div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">Ada {{ count($pendingDb) }} pembaruan database yang belum dipasang. <a href="{{ route('admin.settings') }}">Buka Pengaturan → Perbarui Database</a>.</span></div>
@endif
