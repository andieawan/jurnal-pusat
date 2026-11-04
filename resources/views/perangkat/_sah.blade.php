@php([$t, $c] = ['' => ['belum diajukan', ''], 'draf' => ['belum diajukan', ''], 'belum' => ['belum ada', 'b-bad'], 'diajukan' => ['menunggu', 'b-warn'], 'disetujui' => ['disahkan', 'b-ok'], 'revisi' => ['perlu perbaikan', 'b-bad']][$s] ?? ['', ''])
<span class="badge {{ $c }}">{{ $t }}</span>
