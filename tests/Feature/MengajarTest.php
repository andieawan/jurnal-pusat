<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Jurnal;
use App\Support\Dates;
use Tests\TestCase;

/** Tahap 1 Jurnal Guru: satu pertemuan = jurnal + absensi mapel. */
class MengajarTest extends TestCase
{
    private function isi(array $o = []): array
    {
        return array_merge(['subject' => 2, 'class' => 1, 'date' => Dates::today(), 'jam' => '3-4',
            'materi' => 'Persamaan linear', 'kegiatan' => 'Latihan soal', 'catatan' => '', 'rencana' => 'Sistem persamaan',
            'status' => [1 => 'H', 2 => 'S', 3 => 'H', 4 => 'A'], 'notes' => [2 => 'Demam']], $o);
    }

    public function test_guru_mengisi_jurnal_dan_absensi_sekaligus(): void
    {
        $this->actingAs($this->guru)->get('/mengajar?subject=2&class=1')->assertOk()->assertSee('Jurnal Pertemuan')->assertSee('Siswa 1');
        $this->post('/mengajar', $this->isi())->assertRedirect()->assertSessionMissing('error')
            ->assertSessionHas('warning', fn ($m) => str_starts_with($m, 'Jurnal tersimpan bersama absensi 4 siswa')); // + peringatan kehadiran 85%

        $j = Jurnal::firstOrFail();
        $this->assertSame(['3-4', 'Persamaan linear', 'Sistem persamaan', $this->guru->id], [$j->jam_ke, $j->materi, $j->rencana, $j->user_id]);
        $this->assertSame('S', Attendance::where('student_id', 2)->where('subject_id', 2)->where('tanggal', Dates::today())->value('status'));
        $this->assertSame(4, Attendance::where('subject_id', 2)->count());

        // simpan lagi = perbarui, tidak ganda
        $this->post('/mengajar', $this->isi(['materi' => 'Persamaan linear satu variabel']))->assertSessionMissing('error');
        $this->assertSame(1, Jurnal::count());
        $this->assertSame('Persamaan linear satu variabel', Jurnal::first()->materi);

        // pertemuan berikutnya menampilkan materi & rencana sebelumnya
        $besok = gmdate('Y-m-d', strtotime(Dates::today().' UTC') + 86400);
        \Illuminate\Support\Carbon::setTestNow($besok.' 08:00:00');
        $this->get('/mengajar?subject=2&class=1')->assertOk()->assertSee('Rencana untuk pertemuan ini:')->assertSee('Sistem persamaan');
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_aturan_kewenangan_batas_waktu_dan_isian_wajib(): void
    {
        // wali tidak mengampu Matematika di kelas 1
        $this->actingAs($this->wali)->post('/mengajar', $this->isi())->assertSessionHas('error');
        $this->assertSame(0, Jurnal::count());

        $this->actingAs($this->guru)->post('/mengajar', $this->isi(['materi' => '']))->assertSessionHasErrors('materi');
        $this->post('/mengajar', $this->isi(['date' => gmdate('Y-m-d', strtotime(Dates::today().' UTC') - 10 * 86400)]))->assertSessionHas('error');
        $this->post('/mengajar', $this->isi(['jam' => 'pagi']))->assertSessionHas('error');
        $this->assertSame(0, Jurnal::count());
        $this->assertSame(0, Attendance::count()); // gagal → absensi juga tidak tersimpan

        // tanpa jam ke- tetap boleh; hapus jurnal tidak menghapus absensi
        $this->post('/mengajar', $this->isi(['jam' => '', 'status' => [1 => 'H', 2 => 'H', 3 => 'H', 4 => 'H']]))->assertSessionHas('success');
        $j = Jurnal::firstOrFail();
        $this->delete('/mengajar/'.$j->id)->assertSessionHas('success');
        $this->assertSame(0, Jurnal::count());
        $this->assertSame(4, Attendance::where('subject_id', 2)->count());
    }

    public function test_menu_mengajar_tampil(): void
    {
        $this->actingAs($this->guru)->get('/')->assertSee(route('mengajar'));
    }
}
