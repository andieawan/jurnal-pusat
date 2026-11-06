<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\RecapController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\LetterController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

// ---- PWA (manifest aplikasi yang bisa dipasang di HP) ----
Route::get('/manifest.webmanifest', [\App\Http\Controllers\PwaController::class, 'manifest'])->name('manifest');

// ---- Publik (tanpa login, divalidasi token) ----
Route::middleware('throttle:publik')->group(function () {
    Route::get('/presensi/{token}', [PublicController::class, 'delegation'])->name('delegation');
    Route::post('/presensi/{token}', [PublicController::class, 'delegationSubmit']);
    Route::get('/wali/{token}', [PublicController::class, 'parent'])->name('parent');
    Route::get('/kelas/{token}', [PublicController::class, 'board'])->name('board');
    Route::post('/kelas/{token}', [PublicController::class, 'boardCheck'])->name('board.check')->middleware('throttle:8,1');
});

// ---- Autentikasi ----
Route::middleware('guest')->group(function () {
    Route::get('/sso/login', [\App\Http\Controllers\PusatSsoController::class, 'mulai'])->middleware('throttle:20,1')->name('sso.login');
    Route::get('/sso/callback', [\App\Http\Controllers\PusatSsoController::class, 'callback'])->middleware('throttle:20,1')->name('sso.callback');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/ganti-akun', [AuthController::class, 'showSwitch'])->name('switch');
    Route::post('/ganti-akun', [AuthController::class, 'switch'])->middleware('throttle:10,1');
    Route::post('/ganti-akun/pusat', [AuthController::class, 'switchPusat'])->name('switch.pusat');
    Route::get('/password', [AuthController::class, 'showPassword'])->name('password');
    Route::post('/password', [AuthController::class, 'password']);

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/mengajar', [\App\Http\Controllers\MengajarController::class, 'index'])->name('mengajar');
    Route::post('/mengajar', [\App\Http\Controllers\MengajarController::class, 'store'])->middleware('throttle:30,1')->name('mengajar.store');
    Route::get('/perangkat', [\App\Http\Controllers\PerangkatAjarController::class, 'index'])->name('perangkat');
    Route::get('/perangkat/baru', [\App\Http\Controllers\PerangkatAjarController::class, 'create'])->name('perangkat.create');
    Route::post('/perangkat', [\App\Http\Controllers\PerangkatAjarController::class, 'store'])->middleware('throttle:20,1')->name('perangkat.store');
    Route::get('/perangkat/kunci', [\App\Http\Controllers\PerangkatAjarController::class, 'kunci'])->name('perangkat.kunci');
    Route::put('/perangkat/kunci', [\App\Http\Controllers\PerangkatAjarController::class, 'kunciSimpan'])->middleware('throttle:10,1')->name('perangkat.kunci.simpan');
    Route::get('/perangkat/rekap', [\App\Http\Controllers\PerangkatAjarController::class, 'rekap'])->name('perangkat.rekap');
    Route::post('/perangkat/{perangkat}/ajukan', [\App\Http\Controllers\PerangkatAjarController::class, 'ajukan'])->whereNumber('perangkat')->name('perangkat.ajukan');
    Route::post('/perangkat/{perangkat}/periksa', [\App\Http\Controllers\PerangkatAjarController::class, 'periksa'])->whereNumber('perangkat')->name('perangkat.periksa');
    Route::get('/perangkat/{perangkat}', [\App\Http\Controllers\PerangkatAjarController::class, 'show'])->whereNumber('perangkat')->name('perangkat.show');
    Route::post('/perangkat/{perangkat}/status', [\App\Http\Controllers\PerangkatAjarController::class, 'status'])->whereNumber('perangkat')->name('perangkat.status');
    Route::post('/perangkat/{perangkat}/ambil', [\App\Http\Controllers\PerangkatAjarController::class, 'ambil'])->whereNumber('perangkat')->name('perangkat.ambil');
    Route::put('/perangkat/{perangkat}/identitas', [\App\Http\Controllers\PerangkatAjarController::class, 'identitas'])->whereNumber('perangkat')->name('perangkat.identitas');
    Route::get('/perangkat/{perangkat}/ubah/{dok}', [\App\Http\Controllers\PerangkatAjarController::class, 'edit'])->whereNumber('perangkat')->name('perangkat.edit');
    Route::put('/perangkat/{perangkat}/ubah/{dok}', [\App\Http\Controllers\PerangkatAjarController::class, 'update'])->whereNumber('perangkat')->name('perangkat.update');
    Route::post('/perangkat/{perangkat}/ai/{dok}', [\App\Http\Controllers\PerangkatAjarController::class, 'ai'])->whereNumber('perangkat')->middleware('throttle:20,1')->name('perangkat.ai');
    Route::post('/perangkat/{perangkat}/bahan', [\App\Http\Controllers\PerangkatAjarController::class, 'bahanTambah'])->whereNumber('perangkat')->middleware('throttle:10,1')->name('perangkat.bahan');
    Route::get('/perangkat/{perangkat}/bahan/{i}', [\App\Http\Controllers\PerangkatAjarController::class, 'bahanUnduh'])->whereNumber(['perangkat', 'i'])->name('perangkat.bahan.unduh');
    Route::delete('/perangkat/{perangkat}/bahan/{i}', [\App\Http\Controllers\PerangkatAjarController::class, 'bahanHapus'])->whereNumber(['perangkat', 'i'])->name('perangkat.bahan.hapus');
    Route::get('/perangkat/{perangkat}/unduh/{dok}', [\App\Http\Controllers\PerangkatAjarController::class, 'unduh'])->whereNumber('perangkat')->name('perangkat.unduh');
    Route::delete('/perangkat/{perangkat}', [\App\Http\Controllers\PerangkatAjarController::class, 'destroy'])->whereNumber('perangkat')->name('perangkat.destroy');
    Route::delete('/mengajar/{jurnal}', [\App\Http\Controllers\MengajarController::class, 'destroy'])->name('mengajar.destroy');
    Route::get('/absensi', [AttendanceController::class, 'index'])->name('attendance');
    Route::post('/absensi', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::post('/absensi/hapus', [AttendanceController::class, 'destroy'])->name('attendance.destroy');
    Route::post('/absensi/delegasi', [AttendanceController::class, 'delegate'])->name('attendance.delegate');
    Route::post('/absensi/info-kelas', [AttendanceController::class, 'boardCreate'])->name('attendance.board');
    Route::post('/absensi/info-kelas/{token}/cabut', [AttendanceController::class, 'boardRevoke'])->name('attendance.board.revoke');
    Route::post('/absensi/delegasi/{token}/cabut', [AttendanceController::class, 'revoke'])->name('attendance.revoke');

    Route::get('/nilai', [GradeController::class, 'index'])->name('grades');
    Route::post('/nilai', [GradeController::class, 'store'])->name('grades.store');
    Route::post('/nilai/{activity}/hapus', [GradeController::class, 'destroy'])->name('grades.destroy');

    Route::get('/siswa', [StudentController::class, 'index'])->name('students');
    Route::post('/siswa/{student}/pengesahan', [StudentController::class, 'clearance'])->name('students.clearance');
    Route::post('/siswa/{student}/akses-wali', [StudentController::class, 'parentCreate'])->name('students.parent.create');
    Route::post('/siswa/akses-wali/{token}/cabut', [StudentController::class, 'parentRevoke'])->name('students.parent.revoke');

    Route::get('/pantau', MonitorController::class)->middleware('can:lihat-sekolah')->name('monitor');
    Route::get('/rekap', [RecapController::class, 'index'])->name('recap');
    Route::get('/rekap/unduh', [RecapController::class, 'export'])->name('recap.export');

    Route::get('/surat/laporan', [LetterController::class, 'report'])->name('letters.report');

    Route::get('/unduh/absensi', [ExportController::class, 'attendance'])->name('export.attendance');
    Route::get('/unduh/nilai', [ExportController::class, 'grades'])->name('export.grades');

    // ---- Admin Panel ----
    Route::prefix('admin')->name('admin.')->middleware('can:admin')->group(function () {
        Route::redirect('/', '/admin/guru');
        Route::get('/guru', [Admin\TeacherController::class, 'index'])->name('teachers');
        Route::post('/guru', [Admin\TeacherController::class, 'store'])->name('teachers.store')->middleware('induk.pusat:Akun_guru');
        Route::put('/guru/{user}', [Admin\TeacherController::class, 'update'])->name('teachers.update');
        Route::post('/guru/{user}/reset', [Admin\TeacherController::class, 'reset'])->name('teachers.reset');
        Route::delete('/guru/{user}', [Admin\TeacherController::class, 'destroy'])->name('teachers.destroy');
        Route::post('/guru/{user}/status', [Admin\TeacherController::class, 'toggle'])->name('teachers.toggle');

        Route::get('/siswa', [Admin\StudentController::class, 'index'])->name('students');
        Route::post('/siswa', [Admin\StudentController::class, 'store'])->name('students.store');
        Route::put('/siswa/{student}', [Admin\StudentController::class, 'update'])->name('students.update');
        Route::post('/siswa/impor', [Admin\StudentController::class, 'import'])->name('students.import');
        Route::post('/siswa/hapus-massal', [Admin\StudentController::class, 'bulkDestroy'])->name('students.bulk');
        Route::delete('/siswa/{student}', [Admin\StudentController::class, 'destroy'])->name('students.destroy');

        Route::get('/kelas', [Admin\MasterController::class, 'index'])->name('master');
        Route::post('/kelas', [Admin\MasterController::class, 'storeClass'])->name('classes.store')->middleware('induk.pusat:Kelas');
        Route::put('/kelas/{class}', [Admin\MasterController::class, 'updateClass'])->name('classes.update')->middleware('induk.pusat:Kelas');
        Route::delete('/kelas/{class}', [Admin\MasterController::class, 'destroyClass'])->name('classes.destroy')->middleware('induk.pusat:Kelas');
        Route::delete('/mapel/{subject}', [Admin\MasterController::class, 'destroySubject'])->name('subjects.destroy')->middleware('induk.pusat:Mapel');
        Route::get('/mapel/csv-pusat', [Admin\MasterController::class, 'subjectsCsv'])->name('subjects.csv');
        Route::post('/mapel', [Admin\MasterController::class, 'storeSubject'])->name('subjects.store')->middleware('induk.pusat:Mapel');
        Route::put('/mapel/{subject}', [Admin\MasterController::class, 'updateSubject'])->name('subjects.update')->middleware('induk.pusat:Mapel');

        Route::get('/jurusan', [Admin\MajorController::class, 'index'])->name('majors');
        Route::post('/jurusan', [Admin\MajorController::class, 'store'])->name('majors.store')->middleware('induk.pusat:Jurusan');
        Route::post('/jurusan/sinkron', [Admin\MajorController::class, 'sync'])->name('majors.sync')->middleware('induk.pusat:Jurusan');
        Route::post('/jurusan/kelas', [Admin\MajorController::class, 'makeClasses'])->name('majors.classes')->middleware('induk.pusat:Kelas');
        Route::put('/jurusan/{jurusan}', [Admin\MajorController::class, 'update'])->name('majors.update')->middleware('induk.pusat:Jurusan');
        Route::delete('/jurusan/{jurusan}', [Admin\MajorController::class, 'destroy'])->name('majors.destroy')->middleware('induk.pusat:Jurusan');

        Route::get('/pasangan', [Admin\PairingController::class, 'index'])->name('pairings');
        Route::post('/pasangan', [Admin\PairingController::class, 'store'])->name('pairings.store')->middleware('induk.pusat:Pasangan');
        Route::get('/pasangan/csv-pusat', [Admin\PairingController::class, 'csv'])->name('pairings.csv');
        Route::delete('/pasangan', [Admin\PairingController::class, 'destroy'])->name('pairings.destroy');

        Route::get('/pusat', [Admin\PusatController::class, 'index'])->name('pusat');
        Route::post('/pusat/uji', [Admin\PusatController::class, 'uji'])->middleware('throttle:10,1')->name('pusat.uji');
        Route::post('/pusat/periksa', [Admin\PusatController::class, 'periksa'])->middleware('throttle:10,1')->name('pusat.periksa');
        Route::post('/pusat/terapkan', [Admin\PusatController::class, 'terapkan'])->middleware('throttle:5,1')->name('pusat.terapkan');

        Route::get('/impor', [Admin\ImportController::class, 'index'])->name('import');
        Route::get('/impor/{type}/template', [Admin\ImportController::class, 'template'])->whereIn('type', ['kelas', 'mapel', 'siswa', 'guru', 'pasangan', 'absensi', 'nilai'])->name('import.template');
        Route::post('/impor/{type}', [Admin\ImportController::class, 'run'])->whereIn('type', ['kelas', 'mapel', 'siswa', 'guru', 'pasangan', 'absensi', 'nilai'])->middleware(['throttle:20,1', 'induk.pusat:Data_ini'])->name('import.run');

        Route::get('/hardcopy', [Admin\HardcopyController::class, 'index'])->name('hardcopy');
        Route::get('/hardcopy/template', [Admin\HardcopyController::class, 'template'])->name('hardcopy.template');
        Route::post('/hardcopy/pratinjau', [Admin\HardcopyController::class, 'preview'])->name('hardcopy.preview');
        Route::post('/hardcopy/simpan', [Admin\HardcopyController::class, 'commit'])->name('hardcopy.commit');

        Route::get('/kenaikan', [Admin\PromotionController::class, 'index'])->name('promotion');
        Route::post('/kenaikan', [Admin\PromotionController::class, 'promote'])->name('promotion.run')->middleware('induk.pusat:Kenaikan_kelas');
        Route::post('/tahun-ajaran', [Admin\PromotionController::class, 'newYear'])->name('promotion.year')->middleware('induk.pusat:Tahun_ajaran');

        Route::get('/log', [Admin\LogController::class, 'index'])->name('logs');

        Route::get('/integrasi', [Admin\ApiKeyController::class, 'index'])->name('api');
        Route::post('/integrasi', [Admin\ApiKeyController::class, 'store'])->name('api.store');
        Route::post('/integrasi/{key}/cabut', [Admin\ApiKeyController::class, 'revoke'])->name('api.revoke');
        Route::delete('/integrasi/{key}', [Admin\ApiKeyController::class, 'destroy'])->name('api.destroy');

        Route::get('/pengaturan', [Admin\SettingController::class, 'index'])->name('settings');
        Route::post('/data-contoh', [Admin\SettingController::class, 'demo'])->name('demo');
        Route::put('/pengaturan', [Admin\SettingController::class, 'update'])->name('settings.update');
        Route::post('/backup', [Admin\SettingController::class, 'backup'])->name('backup');
        Route::post('/perbarui-database', [Admin\SettingController::class, 'migrate'])->name('migrate');
        Route::get('/backup/{file}', [Admin\SettingController::class, 'download'])->name('backup.download');
        Route::get('/ekspor-json', [Admin\SettingController::class, 'json'])->name('json');
    });
});
