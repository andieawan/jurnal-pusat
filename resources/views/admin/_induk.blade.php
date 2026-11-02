{{-- Pemberitahuan: data induk dikelola di Pusat Sekolah. $apa (judul), $path (halaman di Pusat), $ket (opsional) --}}
@php($pusatUrl = rtrim((string) config('pusat.url'), '/'))
<div class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt"><b>{{ $apa }} dikelola di Pusat Sekolah.</b>
    {{ $ket ?? 'Perubahan di sini dinonaktifkan agar semua aplikasi memakai data yang sama; perubahan di Pusat tersalin otomatis ke Absensi.' }}
    @if($pusatUrl !== '')<a class="btn btn-sm" style="margin-left:6px" href="{{ $pusatUrl.$path }}" target="_blank" rel="noopener">Ubah di Pusat ↗</a>@endif</span></div>
