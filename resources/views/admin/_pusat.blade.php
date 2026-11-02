{{--
    Kartu "Pusat Sekolah" — SAMA di semua aplikasi (Absensi, BK, TU, PKL). Salin berkas ini apa adanya bila diubah.
    $p: aktif, siap, url, callback, webhook, tahun, terakhir (UTC 'Y-m-d H:i:s'), jumlah [label => angka],
        perluSinkron, otomatis, ruteUji, ruteSinkron, catatan (opsional)
    session('hasil'): hasil sinkron (jurusan/kelas/siswa/pegawai: baru/ubah/sama/keluar|nonaktif; peringatan; contoh)
    session('butuh_paksa'): sinkron ditahan karena banyak siswa akan dinonaktifkan → tampilkan tombol "Tetap terapkan"
--}}
@php
    $tz = \App\Support\Dates::tz();
    $terakhirTeks = '-';
    if (! empty($p['terakhir'])) {
        $t = \Carbon\CarbonImmutable::parse($p['terakhir'], 'UTC')->setTimezone($tz);
        $terakhirTeks = $t->format('d-m-Y H:i').' ('.$t->locale('id')->diffForHumans().')';
    }
    $h = session('hasil');
    $label = ['jurusan' => 'Jurusan', 'kelas' => 'Kelas', 'siswa' => 'Siswa', 'pegawai' => 'Guru & staf', 'mapel' => 'Mata pelajaran', 'akun' => 'Akun guru & staf', 'pengampu' => 'Pasangan mapel'];
@endphp
@if(! empty($p['perluSinkron']))<div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">Ada perubahan dari Pusat yang belum lengkap (mis. siswa dipindah ke kelas baru yang belum tersalin). Klik <b>Sinkron sekarang</b>; bila tidak, sinkron berjalan otomatis dalam satu jam.</span></div>@endif
<div class="card">
    <h2>Pusat Sekolah</h2>
    <p style="margin:6px 0 10px">Status:
        @if($p['aktif'] && $p['siap'])<span class="badge b-ok">Aktif</span>
        @elseif($p['aktif'])<span class="badge b-warn">Aktif, database belum diperbarui</span>
        @else<span class="badge">Nonaktif — isi PUSAT_ENABLED, PUSAT_URL, PUSAT_CLIENT_ID, PUSAT_CLIENT_SECRET di .env</span>@endif
    </p>
    <div class="scroll"><table class="tbl" style="font-size:14px">
        <tr><td style="width:190px" class="mut">Alamat Pusat</td><td>{{ $p['url'] ?: '-' }}</td></tr>
        <tr><td class="mut">Redirect URI</td><td><code>{{ $p['callback'] }}</code></td></tr>
        <tr><td class="mut">URL webhook</td><td><code>{{ $p['webhook'] }}</code></td></tr>
        <tr><td class="mut">Tahun ajaran aktif</td><td>{{ $p['tahun'] ?: '-' }}</td></tr>
        <tr><td class="mut">Sinkron terakhir</td><td>{{ $terakhirTeks }}@if(empty($p['terakhir']) && $p['aktif'] && $p['siap']) <span class="badge b-warn">belum pernah</span>@endif</td></tr>
        <tr><td class="mut">Data tersalin</td><td>{{ collect($p['jumlah'])->map(fn ($n, $l) => $n.' '.$l)->implode(' · ') }}</td></tr>
        @if(! empty($p['antreanJumlah']))
        <tr><td class="mut">Menunggu dikirim ke Pusat</td><td><span class="badge b-warn">{{ $p['antreanJumlah'] }} perubahan</span>
            <small class="mut">dikirim otomatis begitu Pusat bisa dihubungi (atau tekan Sinkron sekarang).</small>
            @php($g = collect($p['antrean'])->pluck('galat')->filter()->unique()->first())@if($g)<br><small class="mut">Terakhir: {{ $g }}</small>@endif</td></tr>
        @endif
        <tr><td class="mut">Sinkron otomatis</td><td>{{ ! empty($p['otomatis']) ? 'Tiap jam' : 'Lewat webhook saat ada perubahan di Pusat, ditambah sinkron penuh bila diperlukan' }}</td></tr>
    </table></div>
    @if($p['aktif'] && $p['siap'])
    <div class="row" style="margin-top:12px;gap:8px;flex-wrap:wrap">
        <form method="post" action="{{ $p['ruteUji'] }}">@csrf<button class="btn">Uji koneksi</button></form>
        <form method="post" action="{{ $p['ruteSinkron'] }}">@csrf<button class="btn btn-pri" data-busy="Menyinkron…">Sinkron sekarang</button></form>
        @if(session('butuh_paksa'))
            <form method="post" action="{{ $p['ruteSinkron'] }}" data-confirm="Pusat akan menonaktifkan banyak siswa di aplikasi ini. Yakin data di Pusat sudah benar?">@csrf<input type="hidden" name="paksa" value="1"><button class="btn btn-danger">Tetap terapkan</button></form>
        @endif
    </div>
    @endif
    @if(! empty($p['catatan']))<p class="mut" style="margin-top:10px"><small>{{ $p['catatan'] }}</small></p>@endif
</div>

@if(is_array($h))
<div class="card card-tight">
    <div style="padding:14px 16px;border-bottom:1px solid var(--line2)"><h2>Hasil sinkron terakhir</h2></div>
    @foreach((array) ($h['peringatan'] ?? []) as $w)<div class="alert alert-warn" style="margin:10px 16px 0"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">{{ $w }}</span></div>@endforeach
    <div class="scroll"><table class="tbl">
        <thead><tr><th></th><th class="c">Baru</th><th class="c">Diubah</th><th class="c">Sama</th><th class="c">Keluar / nonaktif</th></tr></thead>
        <tbody>
        @foreach($label as $k => $l)
            @continue(! isset($h[$k]) || ! is_array($h[$k]))
            @php($r = $h[$k])
            <tr><td><b>{{ $l }}</b></td><td class="c">{{ $r['baru'] ?? 0 }}</td><td class="c">{{ $r['ubah'] ?? $r['tertaut'] ?? '-' }}</td><td class="c">{{ $r['sama'] ?? '-' }}</td>
                <td class="c">{{ ($r['keluar'] ?? 0) + ($r['nonaktif'] ?? 0) + ($r['dihapus'] ?? 0) ?: '-' }}</td></tr>
        @endforeach
        </tbody></table></div>
    @if(! empty($h['contoh']))
        <div style="padding:10px 16px"><small class="mut">Contoh perubahan:</small>
            <ul style="margin:4px 0 0;padding-left:18px;font-size:13px">@foreach(array_slice((array) $h['contoh'], 0, 25) as $c)<li>{{ $c }}</li>@endforeach</ul></div>
    @endif
</div>
@endif
