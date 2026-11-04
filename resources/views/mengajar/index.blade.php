@extends('layouts.app', ['title' => 'Mengajar'])
@section('content')
@php
    $today = \App\Support\Dates::today();
    $ctx = ['subject' => $subjectId, 'class' => $classId, 'date' => $tanggal, 'jam' => $jam];
    $url = fn (array $o = []) => route('mengajar', array_filter(array_merge($ctx, $o), fn ($v) => $v !== null && $v !== ''));
    $shift = fn (int $d) => gmdate('Y-m-d', strtotime($tanggal.' UTC') + $d * 86400);
@endphp
<div class="head">
    <div>
        <h1>Mengajar</h1>
        <small>{{ $subject->name ?? 'Belum ada mapel' }} · {{ $class->name ?? '—' }} · {{ \App\Support\Dates::human($tanggal) }}@if($tanggal === $today) <span class="badge b-ok">Hari ini</span>@endif</small>
    </div>
</div>

<form method="get" action="{{ route('mengajar') }}" class="card filter-card">
    <div class="fields">
        <div><label for="subject">Mata Pelajaran</label><select id="subject" name="subject" data-auto data-class-map='@json($classMap)'>@forelse($subjects as $s)<option value="{{ $s->id }}" @selected($s->id === $subjectId)>{{ $s->name }}</option>@empty<option value="">— belum ada mapel —</option>@endforelse</select></div>
        <div><label for="class">Kelas</label><select id="class" name="class" data-auto>@forelse($classes as $k)<option value="{{ $k->id }}" @selected($k->id === $classId)>{{ $k->name }}</option>@empty<option value="">— belum ada kelas —</option>@endforelse</select></div>
        <div class="date-pick">
            <label for="date">Tanggal</label>
            <div class="date-row">
                <a class="btn btn-icon" href="{{ $url(['date' => $shift(-1), 'jam' => null]) }}" aria-label="Hari sebelumnya" title="Hari sebelumnya">‹</a>
                <input id="date" type="date" name="date" value="{{ $tanggal }}" max="{{ $today }}" data-auto>
                @if($tanggal < $today)<a class="btn btn-icon" href="{{ $url(['date' => $shift(1), 'jam' => null]) }}" aria-label="Hari berikutnya" title="Hari berikutnya">›</a>@endif
            </div>
        </div>
        <div><label for="jam">Jam ke- <small class="mut">(opsional)</small></label><input id="jam" type="text" name="jam" value="{{ $jam }}" placeholder="mis. 3-4" inputmode="numeric" pattern="\d{1,2}(-\d{1,2})?" maxlength="5" style="max-width:120px" data-auto></div>
        <noscript><div><button class="btn">Tampilkan</button></div></noscript>
    </div>
    @if(($lainHariIni ?? collect())->isNotEmpty())
        <p class="hint" style="margin:8px 0 0">Pertemuan lain hari ini di kelas ini:
            @foreach($lainHariIni as $o)<a class="badge" href="{{ $url(['jam' => $o->jam_ke]) }}">{{ $o->jam_ke !== '' ? 'jam ke-'.$o->jam_ke : 'tanpa jam' }}</a> @endforeach</p>
    @endif
</form>

@if($classId && $subjectId && $auth['allowed'])
    <p class="hint pa-tautan">Perangkat ajar:
        @foreach($perangkat ?? [] as $pa)<a class="badge" href="{{ route('perangkat.show', $pa) }}">{{ \Illuminate\Support\Str::limit($pa->topik, 40) }}</a> @endforeach
        @if(($perangkat ?? collect())->isEmpty())<a href="{{ route('perangkat') }}">ambil dari draf MGMP</a> atau <a href="{{ route('perangkat.create', ['jenis' => 'guru', 'subject' => $subjectId, 'class' => $classId]) }}">buat sendiri</a>@endif</p>
@endif
@unless($auth['allowed'])<div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">{{ $auth['error'] ?? 'Anda tidak berwenang pada kelas/mapel ini.' }}</span></div>@endunless

@if($classId && $subjectId)
<form method="post" action="{{ route('mengajar.store') }}" data-unsaved>
    @csrf
    <input type="hidden" name="subject" value="{{ $subjectId }}"><input type="hidden" name="class" value="{{ $classId }}"><input type="hidden" name="date" value="{{ $tanggal }}"><input type="hidden" name="jam" value="{{ $jam }}">

    <div class="card">
        <h2>Jurnal Pertemuan</h2>
        @if($lalu)
            <div class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt">
                <b>Pertemuan sebelumnya ({{ \App\Support\Dates::human($lalu->tanggal) }}):</b> {{ \Illuminate\Support\Str::limit($lalu->materi, 160) }}
                @if($lalu->rencana)<br><b>Rencana untuk pertemuan ini:</b> {{ $lalu->rencana }}
                    @if(! $jurnal)<br><button type="button" class="btn btn-sm" style="margin-top:6px" data-isi-materi="{{ $lalu->rencana }}">↓ Pakai sebagai materi</button>@endif
                @endif
            </span></div>
        @else
            <p class="mut" style="font-size:13px">Belum ada jurnal sebelumnya untuk kelas & mapel ini.</p>
        @endif
        @if($modul ?? null)
            <div class="alert alert-info isi-modul"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt">
                <b>Dari modul ajar “{{ \Illuminate\Support\Str::limit($modul['perangkat']->topik, 60) }}”:</b>
                <span class="row" style="margin-top:6px">
                    <select aria-label="Pertemuan di modul" data-modul='@json($modul['isi'])' style="width:auto;min-width:0">@foreach($modul['isi'] as $i => $m)<option value="{{ $i }}" @selected($i === $modul['ke'])>{{ $m['judul'] ?: 'Pertemuan '.($i + 1) }}</option>@endforeach</select>
                    <button type="button" class="btn btn-sm" data-isi-modul>↓ Isi materi & kegiatan</button>
                </span>
            </span></div>
        @endif
        @if($jurnal)<p class="hint">Jurnal pertemuan ini sudah diisi{{ $jurnal->guru ? ' oleh '.$jurnal->guru->nama : '' }} — ubah bila perlu lalu simpan lagi.</p>@endif
        <div class="field"><label for="materi">Materi / tujuan pembelajaran <span class="req">*</span></label>
            <textarea id="materi" name="materi" rows="2" maxlength="2000" required placeholder="mis. Prinsip desain: keseimbangan & kontras">{{ old('materi', $jurnal->materi ?? '') }}</textarea></div>
        <div class="field"><label for="kegiatan">Kegiatan & metode</label>
            <textarea id="kegiatan" name="kegiatan" rows="2" maxlength="2000" placeholder="mis. Penjelasan singkat, praktik membuat poster berkelompok, presentasi">{{ old('kegiatan', $jurnal->kegiatan ?? '') }}</textarea></div>
        <div class="field"><label for="catatan">Catatan kejadian</label>
            <textarea id="catatan" name="catatan" rows="2" maxlength="1000" placeholder="mis. 3 siswa belum selesai tugas; proyektor rusak">{{ old('catatan', $jurnal->catatan ?? '') }}</textarea></div>
        <div class="field"><label for="rencana">Rencana pertemuan berikutnya</label>
            <textarea id="rencana" name="rencana" rows="2" maxlength="1000" placeholder="mis. Lanjut evaluasi poster, mulai materi tipografi">{{ old('rencana', $jurnal->rencana ?? '') }}</textarea></div>
    </div>

    <div class="card card-tight">
        <div style="padding:14px 16px;border-bottom:1px solid var(--line2)"><h2>Absensi Pertemuan</h2>
            <small>{{ $existing->isNotEmpty() ? 'Absensi mapel ini untuk tanggal tersebut sudah diisi — ubah bila perlu.' : 'Siswa yang tidak diubah tercatat Hadir.' }}</small></div>
        @if($students->isEmpty())
            <div class="empty">Tidak ada siswa aktif di kelas ini.</div>
        @else
            <div class="list-tools">
                <button type="button" class="btn btn-sm" data-setall="H">✓ Tandai semua Hadir</button>
                <span class="legend" aria-hidden="true"><i class="lg H">H</i>Hadir <i class="lg I">I</i>Izin <i class="lg S">S</i>Sakit <i class="lg A">A</i>Alpa</span>
            </div>
            @foreach($students as $i => $s)
                @include('partials.hisa', ['s' => $s, 'i' => $i, 'cur' => $existing[$s->id] ?? null])
            @endforeach
        @endif
        <div class="sticky-save">
            <div id="recount" class="recount" aria-live="polite"><span class="rc H">H <b data-c="H">0</b></span><span class="rc I">I <b data-c="I">0</b></span><span class="rc S">S <b data-c="S">0</b></span><span class="rc A">A <b data-c="A">0</b></span><small class="hide-sm">dari {{ $students->count() }} siswa</small></div>
            <button class="btn btn-pri" data-busy="Menyimpan…" @disabled(! $auth['allowed'])>Simpan Jurnal & Absensi</button>
        </div>
    </div>
</form>
<p class="hint">Jurnal & absensi bisa diisi/diubah sampai {{ (int) config('absensi.edit_window_days', 7) }} hari ke belakang.</p>

@if($riwayat->isNotEmpty())
<div class="card card-tight">
    <div style="padding:14px 16px;border-bottom:1px solid var(--line2)"><h2>Riwayat Jurnal — {{ $class->name ?? '' }}</h2><small>12 pertemuan terakhir {{ $subject->name ?? '' }}</small></div>
    <div class="scroll"><table class="tbl tbl-cards"><thead><tr><th>Tanggal</th><th>Materi</th><th>Rencana berikutnya</th><th>Guru</th><th class="r">Aksi</th></tr></thead><tbody>
    @foreach($riwayat as $r)
        <tr @if($r->tanggal === $tanggal && $r->jam_ke === $jam) class="row-on" @endif>
            <td class="card-title"><b>{{ \App\Support\Dates::human($r->tanggal) }}</b>@if($r->jam_ke !== '')<br><small>jam ke-{{ $r->jam_ke }}</small>@endif</td>
            <td data-label="Materi">{{ \Illuminate\Support\Str::limit($r->materi, 120) }}</td>
            <td data-label="Rencana">{{ \Illuminate\Support\Str::limit($r->rencana ?? '—', 100) }}</td>
            <td data-label="Guru"><small>{{ $r->guru->nama ?? '-' }}</small></td>
            <td class="r"><a class="btn btn-sm" href="{{ $url(['date' => $r->tanggal, 'jam' => $r->jam_ke]) }}">Buka</a>
                @if(\App\Services\Rules::withinEditWindow($r->tanggal) || auth()->user()->isAdmin())
                <form method="post" action="{{ route('mengajar.destroy', $r) }}" class="inline" data-confirm="Hapus jurnal {{ \App\Support\Dates::human($r->tanggal) }}? Absensi siswa tidak ikut terhapus.">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Hapus</button></form>
                @endif</td>
        </tr>
    @endforeach
    </tbody></table></div>
</div>
@endif
@endif
@endsection
