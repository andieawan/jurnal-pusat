{{-- Isian rekursif untuk formulir Ubah. $nilai, $acuan (kerangka), $nama (nama input), $label. --}}
@php($id = 'f'.substr(md5($nama), 0, 8))
@if(is_string($acuan))
    <div class="field"><label for="{{ $id }}">{{ $label }}</label><textarea id="{{ $id }}" name="{{ $nama }}" rows="{{ max(2, min(10, (int) ceil(mb_strlen((string) $nilai) / 90))) }}" maxlength="4000">{{ $nilai }}</textarea></div>
@elseif(array_is_list($acuan) && is_string($acuan[0]))
    <div class="field"><label for="{{ $id }}">{{ $label }} <small class="mut">(satu butir per baris)</small></label><textarea id="{{ $id }}" name="{{ $nama }}" rows="{{ max(3, min(14, count($nilai) + 1)) }}">{{ implode("\n", $nilai) }}</textarea></div>
@elseif(array_is_list($acuan))
    <fieldset class="field pilih"><legend>{{ $label }}</legend>
        @foreach(array_merge($nilai, [$acuan[0]]) as $i => $baris)
            <div class="pa-baris">
                <div class="pa-baris-kepala">{{ $loop->last ? 'Tambah baris baru (kosongkan bila tidak perlu)' : 'Butir '.($i + 1).' — kosongkan semua isian untuk menghapus' }}</div>
                @foreach($acuan[0] as $k => $sub)
                    @include('perangkat._isian', ['nilai' => $baris[$k] ?? (is_string($sub) ? '' : []), 'acuan' => $sub, 'nama' => $nama.'['.$i.']['.$k.']', 'label' => ucfirst(str_replace('_', ' ', $k))])
                @endforeach
            </div>
        @endforeach
    </fieldset>
@endif
