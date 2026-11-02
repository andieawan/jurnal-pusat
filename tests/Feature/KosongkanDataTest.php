<?php

namespace Tests\Feature;

use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\PusatKlien;
use Tests\TestCase;

class KosongkanDataTest extends TestCase
{
    public function test_kosongkan_data_absensi(): void
    {
        PusatKlien::simpan('mapel_pusat', 1);
        Subject::find(5)->update(['pusat_uuid' => 'm-bk']);
        $this->artisan('absensi:kosongkan-data')->expectsQuestion('Ketik HAPUS SEMUA untuk melanjutkan', '')->assertExitCode(1);
        $this->assertGreaterThan(0, Student::count());

        $this->artisan('absensi:kosongkan-data', ['--yakin' => true, '--tanpa-cadangan' => true])->assertExitCode(0);
        $this->assertSame(0, Student::count());
        $this->assertSame(0, SchoolClass::count());
        $this->assertSame(0, Pairing::count());
        $this->assertSame([5], Subject::pluck('id')->all());     // mapel BK tetap (ID dipakai fitur BK)
        $this->assertNull(Subject::find(5)->pusat_uuid);
        $this->assertNull(PusatKlien::baca('mapel_pusat'));
        $this->assertNotNull(User::find($this->admin->id));
        $this->assertNull(User::find($this->guru->id));
        $this->assertNull(User::find($this->wali->id));
        $this->actingAs($this->admin)->get(route('admin.master'))->assertOk();
    }
}
