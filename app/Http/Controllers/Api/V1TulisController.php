<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UserError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\DelegationToken;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AccessService;
use App\Services\Audit;
use App\Services\AttendanceService;
use App\Services\Rules;
use App\Support\Dates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Satu-satunya bagian API yang menulis (scope "kehadiran:tulis"), khusus aplikasi BK:
 * input absensi manual per siswa dan tautan delegasi Ketua Kelas. Pelaku dicatat dari kolom "petugas".
 */
class V1TulisController extends Controller
{
    private function petugas(Request $r): string
    {
        $nama = trim((string) $r->input('petugas', ''));
        $app = (string) $r->attributes->get('api_key')?->name;

        return ($nama !== '' ? mb_substr($nama, 0, 100) : 'petugas tak dikenal').' via '.$app;
    }

    private function galat(UserError $e): JsonResponse
    {
        return AuthenticateApiKey::error('invalid_request', $e->getMessage(), 422);
    }

    /** POST /kehadiran/manual {siswa_uuid|siswa_id, tanggal, status H|I|S|A, catatan?, petugas} */
    public function manual(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tanggal' => 'required|date_format:Y-m-d', 'status' => 'required|in:H,I,S,A', 'catatan' => 'nullable|string|max:200',
            'siswa_uuid' => 'nullable|string|max:36', 'siswa_id' => 'nullable|integer', 'petugas' => 'nullable|string|max:100',
        ]);
        $s = ! empty($d['siswa_uuid']) ? Student::where('pusat_uuid', $d['siswa_uuid'])->first() : (! empty($d['siswa_id']) ? Student::find($d['siswa_id']) : null);
        if (! $s) {
            return AuthenticateApiKey::error('not_found', 'Siswa tidak ditemukan di Absensi (sudah sinkron dari Pusat?).', 404);
        }
        try {
            if (! Dates::valid($d['tanggal']) || Dates::isFuture($d['tanggal'])) {
                throw new UserError('Tanggal tidak valid atau berada di masa depan.');
            }
            if ($s->status !== 'aktif') {
                throw new UserError('Siswa tidak aktif.');
            }
            $entries = [['student_id' => $s->id, 'status' => $d['status'], 'notes' => $d['catatan'] ?? '']];
            Rules::validateEntries($entries, (int) $s->class_id);
            $hasil = AttendanceService::write($entries, (int) $s->class_id, null, $d['tanggal'], null, 'bk_manual');
        } catch (UserError $e) {
            return $this->galat($e);
        }
        Audit::log('Absensi Manual BK', 'Absensi', $this->petugas($r), "{$s->nama} ({$s->nis}) {$d['tanggal']} = {$d['status']}");

        return response()->json(['data' => ['siswa_id' => $s->id, 'tanggal' => $d['tanggal'], 'status' => $d['status'], 'dibuat' => $hasil['created'], 'diperbarui' => $hasil['updated']]]);
    }

    /** GET /kehadiran/harian-kelas?kelas_uuid|kelas_id&tanggal → status absensi harian tiap siswa aktif kelas itu (null = belum diisi). */
    public function harianKelas(Request $r): JsonResponse
    {
        $tanggal = (string) $r->query('tanggal', Dates::today());
        if (! Dates::valid($tanggal)) {
            return AuthenticateApiKey::error('invalid_request', 'Parameter "tanggal" harus berformat YYYY-MM-DD.', 422);
        }
        $k = V1Controller::cariKelas($r);
        if (! $k) {
            return AuthenticateApiKey::error('not_found', 'Kelas tidak ditemukan di Absensi (sudah sinkron dari Pusat?).', 404);
        }
        $ada = \App\Models\Attendance::where('class_id', $k->id)->where('tanggal', $tanggal)->whereNull('subject_id')->get()->keyBy('student_id');
        $data = Student::where('class_id', $k->id)->where('status', 'aktif')->orderBy('nama')->get()->map(fn ($s) => [
            'siswa_uuid' => $s->pusat_uuid, 'siswa_id' => $s->id, 'nama' => $s->nama, 'nis' => $s->nis,
            'status' => $ada[$s->id]->status ?? null, 'catatan' => $ada[$s->id]->notes ?? null, 'dicatat_via' => $ada[$s->id]->recorded_via ?? null,
        ])->values();

        return response()->json(['data' => $data, 'meta' => ['kelas_id' => $k->id, 'tanggal' => $tanggal]]);
    }

    /** POST /kehadiran/manual/kelas {kelas_uuid|kelas_id, tanggal, entri:[{siswa_uuid|siswa_id, status, catatan}], petugas} — absensi harian banyak siswa sekaligus. */
    public function manualKelas(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tanggal' => 'required|date_format:Y-m-d', 'petugas' => 'nullable|string|max:100',
            'entri' => 'required|array|min:1|max:200', 'entri.*.status' => 'required|in:H,I,S,A', 'entri.*.catatan' => 'nullable|string|max:200',
            'entri.*.siswa_uuid' => 'nullable|string|max:36', 'entri.*.siswa_id' => 'nullable|integer',
        ]);
        $k = V1Controller::cariKelas($r);
        if (! $k) {
            return AuthenticateApiKey::error('not_found', 'Kelas tidak ditemukan di Absensi (sudah sinkron dari Pusat?).', 404);
        }
        $siswa = Student::where('class_id', $k->id)->where('status', 'aktif')->get();
        $perUuid = $siswa->keyBy('pusat_uuid');
        $perId = $siswa->keyBy('id');
        try {
            if (! Dates::valid($d['tanggal']) || Dates::isFuture($d['tanggal'])) {
                throw new UserError('Tanggal tidak valid atau berada di masa depan.');
            }
            $entries = [];
            foreach ($d['entri'] as $e) {
                $s = ! empty($e['siswa_uuid']) ? ($perUuid[$e['siswa_uuid']] ?? null) : (! empty($e['siswa_id']) ? ($perId[(int) $e['siswa_id']] ?? null) : null);
                if (! $s) {
                    throw new UserError('Ada siswa yang bukan siswa aktif kelas '.$k->name.' di Absensi. Muat ulang halaman lalu coba lagi.');
                }
                $entries[$s->id] = ['student_id' => $s->id, 'status' => $e['status'], 'notes' => $e['catatan'] ?? ''];
            }
            $entries = array_values($entries);
            Rules::validateEntries($entries, (int) $k->id);
            $hasil = AttendanceService::write($entries, (int) $k->id, null, $d['tanggal'], null, 'bk_manual');
        } catch (UserError $e) {
            return $this->galat($e);
        }
        Audit::log('Absensi Manual BK', 'Absensi', $this->petugas($r), "Kelas {$k->name} {$d['tanggal']}: ".count($entries).' siswa');

        return response()->json(['data' => ['kelas_id' => $k->id, 'tanggal' => $d['tanggal'], 'dibuat' => $hasil['created'], 'diperbarui' => $hasil['updated']]]);
    }

    private function tokenOut(DelegationToken $t): array
    {
        return ['token' => $t->token, 'kelas_id' => $t->class_id, 'berlaku_sampai' => $t->expires_at, 'tautan' => route('delegation', $t->token)];
    }

    /** GET /delegasi?kelas_id|kelas_uuid -> tautan aktif */
    public function delegasiIndex(Request $r): JsonResponse
    {
        $k = V1Controller::cariKelas($r);
        if (! $k) {
            return AuthenticateApiKey::error('not_found', 'Kelas tidak ditemukan.', 404);
        }
        $rows = DelegationToken::where('class_id', $k->id)->where('status', 'aktif')->where('expires_at_millis', '>', Dates::nowMillis())->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($t) => $this->tokenOut($t))->values()]);
    }

    /** POST /delegasi {kelas_id|kelas_uuid, jam 1-24, petugas} */
    public function delegasiBuat(Request $r): JsonResponse
    {
        $r->validate(['jam' => 'nullable|integer|min:1|max:24', 'petugas' => 'nullable|string|max:100']);
        $k = V1Controller::cariKelas($r);
        if (! $k) {
            return AuthenticateApiKey::error('not_found', 'Kelas tidak ditemukan.', 404);
        }
        $t = AccessService::issueDelegation($k->id, (int) $r->input('jam', 24), null, $this->petugas($r));

        return response()->json(['data' => $this->tokenOut($t)], 201);
    }

    /** DELETE /delegasi/{token} */
    public function delegasiCabut(Request $r, string $token): JsonResponse
    {
        $t = DelegationToken::find($token);
        if (! $t) {
            return AuthenticateApiKey::error('not_found', 'Tautan tidak ditemukan.', 404);
        }
        if ($t->status === 'aktif') {
            $t->update(['status' => 'dicabut']);
            Audit::log('Cabut Delegasi', 'Absensi', $this->petugas($r), "Kelas #{$t->class_id}");
        }

        return response()->json(['data' => ['token' => $t->token, 'status' => 'dicabut']]);
    }
}
