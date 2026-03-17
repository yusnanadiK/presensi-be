<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Controller as ApiController;
use App\Http\Resources\ShiftSubmissionResource;
use App\Models\Attendance;
use App\Models\Shift;
use App\Models\ShiftSubmission;
use App\Models\RequestApproval;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Notifications\SubmissionNotification;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class ShiftSubmissionController extends ApiController
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $userRole = strtolower($user->role);

        $scope = $request->query('scope', 'my');
        $status = $request->query('status', 'all');
        $limit  = $request->query('limit', 10);
        $search = $request->query('search');
        $date   = $request->query('date');

        $query = ShiftSubmission::with(['user.employee', 'currentShift', 'targetShift', 'approvalSteps.approver']);
        $table = 'change_shift_requests';

        if ($scope === 'my') {
            $query->where($table . '.user_id', $user->id);
        } elseif ($scope === 'approval') {
            $query->where($table . '.user_id', '!=', $user->id);
            if ($userRole === 'superadmin') {
                if ($status === 'pending') {
                    $query->where($table . '.status', 'pending');
                }
            } elseif ($userRole === 'admin') {
                $query->whereHas('approvalSteps', function ($q) use ($user, $table, $status) {
                    $q->where('approver_id', $user->id);

                    if ($status === 'pending') {
                        $q->where('status', 'pending')
                            ->whereColumn('step', "$table.current_step");
                    }
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($status && $status !== 'all' && $status !== 'pending') {
            $query->where($table . '.status', $status);
        }

        if ($date) {
            $query->whereDate($table . '.date', $date);
        }

        if ($search) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%");
            });
        }

        $data = $query->latest($table . '.created_at')->paginate($limit);
        return ShiftSubmissionResource::collection($data);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date'             => 'required|date|after_or_equal:today',
            'current_shift_id' => 'required|exists:shifts,id',
            'target_shift_id'  => 'required|exists:shifts,id|different:current_shift_id',
            'reason'           => 'required|string|max:255',
        ], [
            'target_shift_id.different' => 'Shift tujuan tidak boleh sama dengan shift asal.'
        ]);

        if ($validator->fails()) {
            return $this->respondError($validator->errors()->first(), 422);
        }

        $user = Auth::user();

        $exists = ShiftSubmission::where('user_id', $user->id)
            ->where('date', $request->date)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($exists) {
            return $this->respondError('Anda sudah memiliki pengajuan shift pada tanggal tersebut.', 422);
        }

        $approvalLines = $user->approvalLines;
        if ($approvalLines->isEmpty()) {
            return $this->respondError("Aturan persetujuan (Approver) belum diatur oleh HRD. Silakan hubungi admin.", 403);
        }

        try {
            DB::beginTransaction();

            $submission = ShiftSubmission::create([
                'user_id'      => $user->id,
                'status'       => 'pending',
                'date'         => $request->date,
                'shift_old_id' => $request->current_shift_id,
                'shift_new_id' => $request->target_shift_id,
                'reason'       => $request->reason,
                'current_step' => 1,
                'total_steps'  => $approvalLines->count()
            ]);

            foreach ($approvalLines as $line) {
                $submission->approvalSteps()->create([
                    'approver_id' => $line->approver_id,
                    'step'        => $line->step,
                    'status'      => 'pending'
                ]);
            }

            $firstApprover = $submission->approvalSteps()->where('step', 1)->first()->approver;

            if ($firstApprover) {
                $submission->load('user.employee');
                $photoUrl = $submission->user->employee->photo ? Storage::url($submission->user->employee->photo) : null;
                $title   = 'Pengajuan Tukar Shift (Tahap 1)';
                $message = "{$submission->user->name} mengajukan tukar shift untuk tanggal {$submission->date}.";
                $link    = "/shift/approvals/detail/{$submission->id}";

                $firstApprover->notify(new SubmissionNotification($title, $message, $link, 'change_shift', $photoUrl));
            }

            DB::commit();
            return $this->respondSuccess(new ShiftSubmissionResource($submission), 'Pengajuan tukar shift berhasil dikirim.');
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->respondError('Terjadi kesalahan: ' . $th->getMessage(), 500);
        }
    }

    public function show($id)
    {
        $submission = ShiftSubmission::with(['user.employee', 'currentShift', 'targetShift', 'approvalSteps.approver'])->find($id);
        if (!$submission) return $this->respondError('Data pengajuan tidak ditemukan', 404);

        return $this->respondSuccess(new ShiftSubmissionResource($submission));
    }

    public function action(Request $request, $id)
    {
        $request->validate([
            'action'       => 'required|in:approve,reject',
            'reason'       => 'required_if:action,reject|nullable|string',
            'is_represent' => 'boolean'
        ]);

        $submission  = ShiftSubmission::findOrFail($id);
        $user        = auth()->user();
        $action      = $request->action;
        $isRepresent = $request->boolean('is_represent');

        if ($submission->status !== 'pending') {
            return $this->respondError("Pengajuan ini sudah berstatus: {$submission->status}", 400);
        }

        $queryStep = $submission->approvalSteps()
            ->where('step', $submission->current_step)
            ->where('status', 'pending');

        if (!$isRepresent) {
            $queryStep->where('approver_id', $user->id);
        }

        $currentApprovalStep = $queryStep->first();

        if (!$currentApprovalStep) {
            return $this->respondError("Anda tidak memiliki akses atau belum giliran Anda untuk menyetujui dokumen ini.", 403);
        }

        $notifTargetUser = $submission->user;
        $notifLink = "/shift/approvals/detail/{$submission->id}";

        try {
            DB::beginTransaction();

            if ($action === 'reject') {
                $currentApprovalStep->update([
                    'status'      => 'rejected',
                    'note'        => $request->reason,
                    'action_at'   => now(),
                    'approver_id' => $isRepresent ? $user->id : $currentApprovalStep->approver_id
                ]);

                $submission->update([
                    'status'         => 'rejected',
                    'rejection_note' => "Ditolak oleh {$user->name} pada tahap {$submission->current_step}. Alasan: {$request->reason}"
                ]);

                if ($notifTargetUser) {
                    $notifTargetUser->notify(new SubmissionNotification(
                        'Pengajuan Tukar Shift Ditolak',
                        "Pengajuan tukar shift Anda untuk tgl {$submission->date} telah ditolak oleh {$user->name}.",
                        $notifLink,
                        'rejected'
                    ));
                }

                DB::commit();
                return $this->respondSuccess(null, 'Pengajuan Tukar Shift ditolak.');
            }

            if ($action === 'approve') {
                $currentApprovalStep->update([
                    'status'      => 'approved',
                    'action_at'   => now(),
                    'approver_id' => $isRepresent ? $user->id : $currentApprovalStep->approver_id,
                    'note'        => $isRepresent ? "Diambil alih (Bypass) oleh {$user->name}" : null
                ]);

                if ($submission->current_step == $submission->total_steps || $isRepresent) {

                    if ($isRepresent && $submission->current_step < $submission->total_steps) {
                        $submission->approvalSteps()->where('step', '>', $submission->current_step)->delete();
                    }

                    $submission->update(['status' => 'approved', 'current_step' => $submission->total_steps]);

                    $attendance = Attendance::with('logs')
                        ->where('user_id', $submission->user_id)
                        ->whereDate('date', $submission->date)
                        ->first();

                    if ($attendance) {
                        $attendance->shift_id = $submission->shift_new_id;

                        $checkInLog = $attendance->logs->where('attendance_type', 'check_in')->first();
                        if ($checkInLog) {
                            $newShift = Shift::find($submission->shift_new_id);
                            $clockInTime = Carbon::parse($checkInLog->time);
                            $shiftStart  = Carbon::parse($newShift->start_time);
                            $lateThreshold = $shiftStart->copy()->addMinutes($newShift->tolerance_come_too_late);

                            $attendance->status = $clockInTime->gt($lateThreshold) ? Attendance::STATUS_LATE : Attendance::STATUS_PRESENT;
                        }
                        $attendance->save();
                    }

                    // 2. LOGIKA MENGGANTI MATRIX JADWAL BULANAN
                    $dateCarbon = Carbon::parse($submission->date);
                    $scheduleRow = \App\Models\ShiftSchedule::firstOrNew([
                        'user_id' => $submission->user_id,
                        'month'   => $dateCarbon->month,
                        'year'    => $dateCarbon->year
                    ]);

                    $currentData = $scheduleRow->schedule_data ?? [];
                    $currentData[(string) $dateCarbon->day] = [
                        'is_off'   => false,
                        'shift_id' => $submission->shift_new_id
                    ];

                    $scheduleRow->schedule_data = $currentData;
                    if (!$scheduleRow->exists) {
                        $scheduleRow->status = 'approved';
                        $scheduleRow->created_by = $user->id;
                    }
                    $scheduleRow->save();

                    if ($notifTargetUser) {
                        $notifTargetUser->notify(new SubmissionNotification(
                            'Pengajuan Tukar Shift Disetujui',
                            "Pengajuan tukar shift Anda untuk tgl {$submission->date} telah disetujui sepenuhnya.",
                            $notifLink,
                            'approved'
                        ));
                    }

                    DB::commit();
                    return $this->respondSuccess(null, 'Tukar Shift Disetujui Final. Absensi & Matrix Diperbarui.');
                } else {
                    $submission->update(['current_step' => $submission->current_step + 1]);

                    $nextStepApprover = $submission->approvalSteps()->where('step', $submission->current_step)->first()->approver ?? null;
                    if ($nextStepApprover) {
                        $nextStepApprover->notify(new SubmissionNotification(
                            'Butuh Persetujuan: Tukar Shift',
                            "{$user->name} telah menyetujui tahap sebelumnya. Mohon tinjau pengajuan dari {$submission->user->name}.",
                            $notifLink,
                            'change_shift'
                        ));
                    }

                    DB::commit();
                    return $this->respondSuccess(null, "Disetujui. Melanjutkan ke tahap " . $submission->current_step);
                }
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->respondError('Gagal memproses approval tukar shift: ' . $e->getMessage(), 500);
        }
    }
}
