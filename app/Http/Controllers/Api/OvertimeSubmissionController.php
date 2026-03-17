<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Controller as ApiController;
use App\Http\Resources\OvertimeSubmissionResource;
use App\Models\OvertimeSubmission;
use App\Models\RequestApproval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\ImageService;
use App\Notifications\SubmissionNotification;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class OvertimeSubmissionController extends ApiController
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $userRole = strtolower($user->role);

        $scope = $request->query('scope', 'my');
        $status = $request->query('status', 'all');
        $limit = $request->query('limit', 10);
        $search = $request->query('search');
        $date = $request->query('date');

        $query = OvertimeSubmission::with(['user.employee', 'shift', 'approvalSteps.approver']);
        $table = 'overtime_requests';

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
        return OvertimeSubmissionResource::collection($data);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date'                 => 'required|date',
            'shift_id'             => 'required|exists:shifts,id',
            'duration_before'      => 'nullable|numeric|min:0',
            'rest_duration_before' => 'nullable|numeric|min:0',
            'duration_after'       => 'nullable|numeric|min:0',
            'rest_duration_after'  => 'nullable|numeric|min:0',
            'reason'               => 'required|string',
            'file'                 => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->respondError($validator->errors()->first(), 422);
        }

        $user = Auth::user();

        $approvalLines = $user->approvalLines;
        if ($approvalLines->isEmpty()) {
            return $this->respondError("Aturan persetujuan (Approver) belum diatur oleh HRD. Silakan hubungi admin.", 403);
        }

        $totalDuration = ($request->duration_before ?? 0) + ($request->duration_after ?? 0);
        if ($totalDuration <= 0) {
            return $this->respondError('Durasi lembur tidak boleh kosong.', 422);
        }

        try {
            DB::beginTransaction();

            $filePath = null;
            if ($request->hasFile('file')) {
                $imageService = new ImageService();
                $filePath = $imageService->compressAndUpload($request->file('file'), 'overtimes');
            }

            $submission = OvertimeSubmission::create([
                'user_id'              => $user->id,
                'status'               => 'pending',
                'file'                 => $filePath,
                'date'                 => $request->date,
                'shift_id'             => $request->shift_id,
                'duration_before'      => $request->duration_before ?? 0,
                'rest_duration_before' => $request->rest_duration_before ?? 0,
                'duration_after'       => $request->duration_after ?? 0,
                'rest_duration_after'  => $request->rest_duration_after ?? 0,
                'reason'               => $request->reason,
                'current_step'         => 1,
                'total_steps'          => $approvalLines->count()
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
                $title   = 'Pengajuan Lembur Baru (Tahap 1)';
                $message = "{$submission->user->name} mengajukan lembur untuk tanggal {$submission->date}.";
                $link    = "/overtime/approvals/detail/{$submission->id}";

                $firstApprover->notify(new SubmissionNotification($title, $message, $link, 'overtime', $photoUrl));
            }

            DB::commit();
            return $this->respondSuccess(new OvertimeSubmissionResource($submission), 'Pengajuan lembur berhasil dikirim.');
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->respondError('Gagal menyimpan data lembur: ' . $th->getMessage(), 500);
        }
    }

    public function show($id)
    {
        $submission = OvertimeSubmission::with(['user.employee', 'shift', 'approvalSteps.approver'])->find($id);
        if (!$submission) return $this->respondError('Data pengajuan lembur tidak ditemukan', 404);

        return $this->respondSuccess(new OvertimeSubmissionResource($submission));
    }

    public function action(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|nullable|string'
        ]);

        $overtime = OvertimeSubmission::findOrFail($id);
        $user = auth()->user();
        $action = $request->action;

        if ($overtime->status !== 'pending') {
            return $this->respondError("Pengajuan ini sudah berstatus: {$overtime->status}", 400);
        }

        $currentApprovalStep = $overtime->approvalSteps()
            ->where('step', $overtime->current_step)
            ->where('approver_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$currentApprovalStep) {
            return $this->respondError("Anda tidak memiliki akses atau belum giliran Anda untuk menyetujui dokumen ini.", 403);
        }

        $notifTargetUser = $overtime->user;
        $notifLink = "/overtime/approvals/detail/{$overtime->id}";

        try {
            DB::beginTransaction();

            if ($action === 'reject') {
                $currentApprovalStep->update([
                    'status'    => 'rejected',
                    'note'      => $request->reason,
                    'action_at' => now()
                ]);

                $overtime->update([
                    'status'         => 'rejected',
                    'rejection_note' => "Ditolak oleh {$user->name} pada tahap {$overtime->current_step}. Alasan: {$request->reason}"
                ]);

                if ($notifTargetUser) {
                    $notifTargetUser->notify(new SubmissionNotification(
                        'Pengajuan Lembur Ditolak',
                        "Pengajuan lembur Anda untuk tgl {$overtime->date} telah ditolak oleh {$user->name}. Alasan: {$request->reason}",
                        $notifLink,
                        'rejected'
                    ));
                }

                DB::commit();
                return $this->respondSuccess(null, 'Pengajuan Lembur berhasil ditolak.');
            }

            if ($action === 'approve') {
                $currentApprovalStep->update([
                    'status'    => 'approved',
                    'action_at' => now()
                ]);

                if ($overtime->current_step == $overtime->total_steps) {
                    $overtime->update(['status' => 'approved']);

                    if ($notifTargetUser) {
                        $notifTargetUser->notify(new SubmissionNotification(
                            'Pengajuan Lembur Disetujui Final',
                            "Pengajuan lembur Anda untuk tgl {$overtime->date} telah disetujui sepenuhnya.",
                            $notifLink,
                            'approved'
                        ));
                    }

                    DB::commit();
                    return $this->respondSuccess(null, 'Lembur Disetujui Final.');
                } else {
                    $overtime->update(['current_step' => $overtime->current_step + 1]);

                    $nextStepApprover = $overtime->approvalSteps()->where('step', $overtime->current_step)->first()->approver;
                    if ($nextStepApprover) {
                        $nextStepApprover->notify(new SubmissionNotification(
                            'Butuh Persetujuan: Lembur Karyawan',
                            "{$user->name} telah menyetujui tahap sebelumnya. Mohon tinjau lembur dari {$overtime->user->name}.",
                            $notifLink,
                            'overtime'
                        ));
                    }

                    DB::commit();
                    return $this->respondSuccess(null, "Disetujui. Melanjutkan ke tahap " . $overtime->current_step);
                }
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->respondError('Gagal memproses approval lembur: ' . $e->getMessage(), 500);
        }
    }

    public function getReportData(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->respondError($validator->errors()->first());
        }

        $month = $request->month;
        $year  = $request->year;

        $overtimes = OvertimeSubmission::with([
            'user.employee.department',
            'user.employee.position',
            'user.employee.job_level',
            'user.employee.employment_status',
        ])
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->where('status', 'approved')
            ->orderBy('date', 'asc')
            ->get();

        $reportData = $overtimes->map(function ($ovt) {
            $employee = $ovt->user->employee ?? null;

            $totalMinutes = ($ovt->duration_before ?? 0) + ($ovt->duration_after ?? 0);
            $durationInHours = $totalMinutes > 0 ? round($totalMinutes / 60, 9) : 0;
            $overtimePaymentHours = $durationInHours;
            $multiplier = $durationInHours;

            return [
                'employee_id'       => $employee->employee_id ?? '-',
                'full_name'         => $ovt->user->name ?? '-',
                'department'        => $employee->department->name ?? '-',
                'job_position'      => $employee->position->name ?? '-',
                'job_level'         => $employee->job_level->name ?? '-',
                'employment_status' => $employee->employment_status->name ?? '-',
                'grade'             => $employee->grade ?? '-',

                'date'              => $ovt->date,
                'overtime_duration' => $durationInHours,

                'overtime_payment'    => $overtimePaymentHours,
                'overtime_multiplier' => $multiplier,
                'overtime_rate'       => 0,
                'total_payment'       => 0,
            ];
        });

        return $this->respondSuccess($reportData);
    }
}
