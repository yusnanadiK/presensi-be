<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\{User, Attendance, AttendanceLog, Shift, AttendanceSubmission, RequestApproval};
use Carbon\Carbon;
use Illuminate\Support\Facades\{Schema, DB};

class AttendanceSeeder extends Seeder
{
    public function run()
    {
        $users = User::all();
        
        if ($users->isEmpty()) {
            $this->command->error('Tidak ada user ditemukan.');
            return;
        }

        $shift = Shift::first() ?? Shift::create([
            'name' => 'Shift Pagi',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'tolerance_come_too_late' => 15,
            'tolerance_go_home_early' => 0,
        ]);

        $year = Carbon::now()->year; 
        $month = Carbon::now()->month;
        $limitDay = Carbon::now()->day;

        $this->command->info("Cleaning old data...");
        DB::table('attendances')->truncate(); // Gunakan truncate jika ingin bersih total
        DB::table('attendance_logs')->truncate();
        DB::table('attendance_requests')->truncate();

        $this->command->info("Generating ±1000 data absensi...");

        // Array penampung untuk Bulk Insert (Jauh lebih cepat dari ::create)
        $attendancesData = [];
        
        foreach ($users as $user) {
            for ($day = 1; $day <= $limitDay; $day++) {
                $date = Carbon::createFromDate($year, $month, $day);
                if ($date->isSunday()) continue;

                $dateStr = $date->format('Y-m-d');
                
                $dice = rand(1, 100); 
                
                // Default: Hadir Tepat Waktu (70% peluang)
                $status = Attendance::STATUS_PRESENT;
                $inTime = '07:50:00';
                $outTime = '16:05:00';
                $type = 'normal';

                if ($dice <= 10) { // 10% Terlambat
                    $status = Attendance::STATUS_LATE;
                    $inTime = '08:20:00';
                } elseif ($dice <= 15) { // 5% Pulang Awal
                    $status = Attendance::STATUS_EARLY_OUT;
                    $outTime = '15:30:00';
                } elseif ($dice <= 20) { // 5% Lupa Absen (Perlu Submission)
                    $type = 'forgot';
                } elseif ($dice <= 25) { // 5% Alpha (Bolos)
                    continue; 
                }

                // Simpan ke array dulu (Attendance)
                $attendanceId = DB::table('attendances')->insertGetId([
                    'user_id' => $user->id,
                    'shift_id' => $shift->id,
                    'attendance_location_id' => 1,
                    'date' => $dateStr,
                    'status' => $status,
                    'is_location_valid' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($type === 'normal' || $type === 'forgot') {
                    // Log Check In
                    if ($type === 'normal' || rand(1,2) == 1) {
                        DB::table('attendance_logs')->insert([
                            'attendance_id' => $attendanceId,
                            'attendance_type' => 'check_in',
                            'time' => $inTime,
                            'lat' => -7.56, 'lng' => 110.82,
                            'device_info' => 'Seeder Device',
                        ]);
                    }

                    // Log Check Out
                    if ($type === 'normal' || rand(1,2) == 1) {
                        DB::table('attendance_logs')->insert([
                            'attendance_id' => $attendanceId,
                            'attendance_type' => 'check_out',
                            'time' => $outTime,
                            'lat' => -7.56, 'lng' => 110.82,
                            'device_info' => 'Seeder Device',
                        ]);
                    }
                }

                // Jika butuh submission (Lupa absen)
                if ($type === 'forgot') {
                    $subId = DB::table('attendance_requests')->insertGetId([
                        'user_id' => $user->id,
                        'shift_id' => $shift->id,
                        'attendance_type' => rand(1,2) == 1 ? 'check_in' : 'check_out',
                        'date' => $dateStr,
                        'time' => '08:00:00',
                        'reason' => 'Lupa klik tombol absen',
                        'status' => 'approved',
                        'current_step' => 3,
                        'total_steps' => 3,
                        'created_at' => now(),
                    ]);

                    DB::table('request_approvals')->insert([
                        'requestable_id' => $subId,
                        'requestable_type' => AttendanceSubmission::class,
                        'step' => 3,
                        'status' => 'approved',
                        'approver_id' => 1,
                        'note' => 'Auto approved seeder'
                    ]);
                }
            }
        }
        
        $this->command->info('Success: Data dummy masif berhasil dibuat.');
    }
}