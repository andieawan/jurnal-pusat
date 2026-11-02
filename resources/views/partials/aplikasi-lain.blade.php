{{-- Menu "Aplikasi lain" (sama di semua aplikasi): daftar dari Pusat saat login SSO; tautan lewat Pusat → langsung masuk. --}}
@php
    $pusatUrl = rtrim((string) config('pusat.url'), '/');
    $lain = $pusatUrl === '' ? [] : array_values(array_filter((array) session('aplikasi_lain', []),
        fn ($a) => is_array($a) && isset($a['nama'], $a['url']) && str_starts_with((string) $a['url'], $pusatUrl.'/')));
@endphp
@if($lain)
    <div class="acct-head" style="padding-top:8px;padding-bottom:4px"><small>Pindah ke aplikasi lain</small></div>
    @foreach($lain as $a)<a role="menuitem" href="{{ $a['url'] }}">↗ {{ $a['nama'] }}</a>@endforeach
@endif
@if($pusatUrl !== '')<a role="menuitem" href="{{ $pusatUrl }}/portal">🏫 Pusat Sekolah</a>@endif
