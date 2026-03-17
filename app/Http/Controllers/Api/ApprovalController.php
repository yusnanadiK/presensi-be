<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Controller as ApiController;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AttendanceSubmission;
use App\Models\ChangeShiftRequest;
use App\Models\OvertimeSubmission;
use App\Models\RequestApproval;
use App\Models\Shift;
use App\Models\TimeOffRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use App\Notifications\SubmissionNotification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ApprovalController extends ApiController
{
    public function liveApproval(Request $request)
    {
        try {
            $currentUser = auth()->user();

            $statusFilter = $request->query('status', 'pending');
            $limit        = $request->query('limit', 10);
            $search       = $request->query('search');
            $date         = $request->query('date');

            $query = Attendance::with(['user.employee', 'shift', 'logs', 'approvalSteps.approver']);

            $isSuperAdmin = in_array(strtolower($currentUser->role), ['superadmin', 'director']);

            $query->where('user_id', '!=', $currentUser->id);

            if ($statusFilter === 'pending') {
                if ($isSuperAdmin) {
                    $query->where('status', Attendance::STATUS_PENDING);
                } else {
                    $query->whereHas('approvalSteps', function ($q) use ($currentUser) {
                        $q->where('approver_id', $currentUser->id)
                            ->where('status', 'pending')
                            ->whereColumn('step', 'attendances.current_step');
                    });
                }
            } elseif ($statusFilter === 'approved') {
                $query->whereIn('status', [
                    Attendance::STATUS_PRESENT,
                    Attendance::STATUS_LATE,
                    Attendance::STATUS_EARLY_OUT
                ]);
            } elseif ($statusFilter === 'rejected') {
                $query->where('status', Attendance::STATUS_REJECTED);
            }

            if ($date) {
                $query->whereDate('date', $date);
            }

            if ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'ILIKE', '%' . $search . '%');
                });
            }

            $paginatedData = $query->latest()->paginate($limit);

            $formattedCollection = $paginatedData->getCollection()->map(function ($item) {
                $logIn = $item->logs->where('attendance_type', 'check_in')->first();

                $statusLabel = 'Pending';
                if ($item->status == Attendance::STATUS_REJECTED) $statusLabel = 'Rejected';
                elseif ($item->status == Attendance::STATUS_PENDING) $statusLabel = 'Pending';
                else $statusLabel = 'Approved';

                $avatarRaw = $item->user->employee->avatar ?? null;
                $avatarUrl = null;

                if ($avatarRaw) {
                    $avatarUrl = str_starts_with($avatarRaw, 'http') ? $avatarRaw : Storage::url($avatarRaw);
                }

                return [
                    'id'               => $item->id,
                    'source_type'      => 'attendance',
                    'user_name'        => $item->user->name ?? 'Unknown',
                    'user_avatar'      => $avatarUrl,
                    'date'             => $item->date,
                    'shift_name'       => $item->shift->name ?? '-',
                    'time'             => $logIn ? Carbon::parse($logIn->time)->format('H:i') : '-',
                    'reason'           => $logIn ? $logIn->note : 'Gagal Validasi Sistem',
                    'status_label'     => $statusLabel,
                    'status_code'      => (int)$item->status,

                    'current_step'     => $item->current_step,
                    'total_steps'      => $item->total_steps,
                    'approval_history' => $item->approvalSteps->map(function ($step) {
                        return [
                            'step'          => $step->step,
                            'approver_id'   => $step->approver_id,
                            'approver_name' => $step->approver->name ?? 'Unknown',
                            'status'        => $step->status,
                            'note'          => $step->note,
                            'action_at'     => $step->action_at ? Carbon::parse($step->action_at)->isoFormat('dddd, D MMM Y | HH:mm') : null,
                        ];
                    }),
                ];
            });

            $paginatedData->setCollection($formattedCollection);

            return response()->json([
                'success' => true,
                'message' => 'Data Live Approval berhasil diambil',
                'data'    => $paginatedData
            ]);
        } catch (\Throwable $th) {
            return $this->respondError($th->getMessage());
        }
    }

    public function manualRequest(Request $request)
    {
        try {
            $currentUser = auth()->user();

            $isPersonalMode = $request->query('mode') === 'personal';
            $defaultStatus  = $isPersonalMode ? 'all' : 'pending';
            $statusFilter   = $request->query('status', $defaultStatus);
            $limit          = $request->query('limit', 10);
            $search         = $request->query('search');
            $date           = $request->query('date');

            $query = AttendanceSubmission::with(['user.employee', 'shift', 'approvalSteps.approver'])->latest();

            if ($isPersonalMode) {
                $query->where('user_id', $currentUser->id);
            } else {
                $query->where('user_id', '!=', $currentUser->id);
                $isSuperAdmin = in_array(strtolower($currentUser->role), ['superadmin', 'director']);

                if ($statusFilter === 'pending') {
                    if ($isSuperAdmin) {
                        $query->where('status', 'pending');
                    } else {
                        $query->whereHas('approvalSteps', function ($q) use ($currentUser) {
                            $q->where('approver_id', $currentUser->id)
                                ->where('status', 'pending')
                                ->whereColumn('step', 'attendance_requests.current_step');
                        });
                    }
                }
            }

            if ($statusFilter !== 'all' && $statusFilter !== 'pending') {
                $query->where('status', $statusFilter);
            }

            if ($date) {
                $query->whereDate('date', $date);
            }
            if ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'ILIKE', '%' . $search . '%');
                });
            }

            $paginatedData = $query->paginate($limit);

            $formattedCollection = $paginatedData->getCollection()->map(function ($item) {
                $statusLabel = ucfirst($item->status);

                $avatarRaw = $item->user->employee->avatar ?? null;
                $avatarUrl = null;

                if ($avatarRaw) {
                    $avatarUrl = str_starts_with($avatarRaw, 'http') ? $avatarRaw : Storage::url($avatarRaw);
                }

                return [
                    'id'               => $item->id,
                    'source_type'      => 'request',
                    'user_name'        => $item->user->name ?? 'Unknown',
                    'user_avatar'      => $avatarUrl,
                    'date'             => $item->date,
                    'shift_name'       => $item->shift->name ?? '-',
                    'type'             => 'Pengajuan Manual (' . $item->attendance_type . ')',
                    'time'             => Carbon::parse($item->time)->format('H:i'),
                    'reason'           => $item->reason,
                    'attachment'       => $item->file ? Storage::url($item->file) : null,
                    'status_label'     => $statusLabel,
                    'status_code'      => $item->status,

                    'current_step'     => $item->current_step,
                    'total_steps'      => $item->total_steps,
                    'approval_history' => $item->approvalSteps->map(function ($step) {
                        return [
                            'step'          => $step->step,
                            'approver_id'   => $step->approver_id,
                            'approver_name' => $step->approver->name ?? 'Unknown',
                            'status'        => $step->status,
                            'note'          => $step->note,
                            'action_at'     => $step->action_at ? Carbon::parse($step->action_at)->isoFormat('dddd, D MMM Y | HH:mm') : null,
                        ];
                    }),
                ];
            });

            $paginatedData->setCollection($formattedCollection);

            return response()->json([
                'success' => true,
                'message' => 'Data Manual Request berhasil diambil',
                'data'    => $paginatedData
            ]);
        } catch (\Throwable $th) {
            return $this->respondError($th->getMessage());
        }
    }

    public function action(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'           => 'required',
            'action'       => 'required|in:approve,reject',
            'source_type'  => 'required|in:attendance,request',
            'reason'       => 'required_if:action,reject|nullable|string',
            'is_represent' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->respondError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $user = auth()->user();
            $action = $request->action;
            $isRepresent = $request->boolean('is_represent');

            if ($request->source_type === 'attendance') {
                $data = Attendance::with('user')->find($request->id);
                $isRequestData = false;
                $statusPending = Attendance::STATUS_PENDING;
            } else {
                $data = AttendanceSubmission::with('user')->find($request->id);
                $isRequestData = true;
                $statusPending = 'pending';
            }

            if (!$data) throw new \Exception('Data tidak ditemukan');
            if ($data->status != $statusPending) throw new \Exception("Dokumen sudah diproses (Status: {$data->status})");

            $queryStep = $data->approvalSteps()
                ->where('step', $data->current_step)
                ->where('status', 'pending');

            if (!$isRepresent) {
                $queryStep->where('approver_id', $user->id);
            }

            $currentApprovalStep = $queryStep->first();

            if (!$currentApprovalStep && !$isRepresent) {
                return $this->respondError("Anda tidak memiliki akses atau belum giliran Anda.", 403);
            }

            $notifTargetUser = $data->user;
            $notifLink = "/attendance/approvals/detail/{$data->id}?source_type={$request->source_type}";

            if ($action === 'reject') {
                if ($currentApprovalStep) {
                    $currentApprovalStep->update([
                        'status'      => 'rejected',
                        'note'        => $request->reason,
                        'action_at'   => now(),
                        'approver_id' => $isRepresent ? $user->id : $currentApprovalStep->approver_id
                    ]);
                }

                $data->status = $isRequestData ? 'rejected' : Attendance::STATUS_REJECTED;
                $data->rejection_note = "Ditolak oleh {$user->name}. Alasan: {$request->reason}";
                $data->save();


                if (!$isRequestData) {
                    $this->createLog($data, 'check_in', $request->reason, 'Rejected by ' . $user->name);
                }

                if ($notifTargetUser) {
                    $notifTargetUser->notify(new SubmissionNotification(
                        'Pengajuan Absensi Ditolak',
                        "Pengajuan absensi tgl {$data->date} telah ditolak.",
                        $notifLink,
                        'rejected'
                    ));
                }

                DB::commit();
                return $this->respondSuccess(null, 'Pengajuan Absensi berhasil ditolak.');
            }

            if ($action === 'approve') {
                if ($currentApprovalStep) {
                    $currentApprovalStep->update([
                        'status'      => 'approved',
                        'action_at'   => now(),
                        'approver_id' => $isRepresent ? $user->id : $currentApprovalStep->approver_id,
                        'note'        => $isRepresent ? "Diambil alih (Bypass) oleh {$user->name}" : null
                    ]);
                }

                if ($data->current_step == $data->total_steps || $isRepresent) {
                    if ($isRepresent && $data->current_step < $data->total_steps) {
                        $data->approvalSteps()->where('step', '>', $data->current_step)->delete();
                    }

                    $data->current_step = $data->total_steps;
                    $data->status = $isRequestData ? 'approved' : Attendance::STATUS_PRESENT;

                    if (!$isRequestData) {
                        $data->is_location_valid = true;
                        $this->createLog($data, null, null, "Approved Final by {$user->name}");
                    }

                    $data->save();

                    if ($isRequestData) {
                        $this->syncRequestToAttendance($data);
                    }

                    if ($notifTargetUser) {
                        $notifTargetUser->notify(new SubmissionNotification(
                            'Pengajuan Absensi Disetujui',
                            "Pengajuan absensi tgl {$data->date} telah disetujui Final.",
                            $notifLink,
                            'approved'
                        ));
                    }

                    DB::commit();
                    return $this->respondSuccess(null, 'Absensi Disetujui Final & Jadwal Diperbarui.');
                } else {
                    $data->update(['current_step' => $data->current_step + 1]);

                    $nextApprover = $data->approvalSteps()->where('step', $data->current_step)->first()->approver ?? null;
                    if ($nextApprover) {
                        $nextApprover->notify(new SubmissionNotification(
                            'Butuh Persetujuan: Absensi',
                            "{$user->name} telah menyetujui tahap sebelumnya. Mohon tinjau absensi {$data->user->name}.",
                            $notifLink,
                            'attendance'
                        ));
                    }

                    DB::commit();
                    return $this->respondSuccess(null, "Disetujui. Melanjutkan ke tahap " . $data->current_step);
                }
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->respondError('Gagal memproses persetujuan: ' . $th->getMessage());
        }
    }

    private function syncRequestToAttendance($submission)
    {
        $attendance = Attendance::firstOrNew([
            'user_id' => $submission->user_id,
            'date'    => $submission->date
        ]);

        $attendance->shift_id = $submission->shift_id;
        $attendance->status   = Attendance::STATUS_PRESENT;
        $attendance->is_location_valid = true;
        $attendance->save();

        AttendanceLog::create([
            'attendance_id'   => $attendance->id,
            'attendance_type' => $submission->attendance_type,
            'time'            => $submission->time,
            'photo'           => $submission->file,
            'lat'             => null,
            'lng'             => null,
            'device_info'     => 'Manual Request (Approved)',
            'note'            => 'Koreksi Manual: ' . $submission->reason
        ]);
    }

    private function createLog($attendance, $type = null, $note = null, $deviceInfo = 'Admin Action')
    {
        $lastLog = $attendance->logs()->latest()->first();
        $finalNote = $note ?? ($lastLog ? $lastLog->note : '-');

        AttendanceLog::create([
            'attendance_id'   => $attendance->id,
            'attendance_type' => $type ?? ($lastLog ? $lastLog->attendance_type : 'check_in'),
            'time'            => now()->format('H:i:s'),
            'lat'             => $lastLog ? $lastLog->lat : null,
            'lng'             => $lastLog ? $lastLog->lng : null,
            'photo'           => $lastLog ? $lastLog->photo : null,
            'device_info'     => $deviceInfo,
            'note'            => $finalNote
        ]);
    }

    public function show(Request $request, $id)
    {
        $sourceType = $request->query('source_type');
        $data = null;

        if ($sourceType === 'attendance') {
            $attendance = Attendance::with(['user.employee', 'shift', 'logs'])->find($id);

            if ($attendance) {
                $approvalSteps = \App\Models\RequestApproval::where('requestable_id', $id)
                    ->where('requestable_type', 'App\Models\Attendance') // Cukup gunakan where biasa
                    ->with('approver')
                    ->orderBy('step', 'asc')
                    ->get();

                $log = $attendance->logs->sortBy('created_at')->first();
                $statusMap = [
                    Attendance::STATUS_PRESENT => 'Approved',
                    Attendance::STATUS_PENDING => 'Pending',
                    Attendance::STATUS_REJECTED => 'Rejected',
                    Attendance::STATUS_LATE => 'Late',
                    Attendance::STATUS_EARLY_OUT => 'Early Out'
                ];

                $avatarRaw = $attendance->user->employee->avatar ?? null;
                $avatarUrl = null;
                if ($avatarRaw) {
                    $avatarUrl = str_starts_with($avatarRaw, 'http') ? $avatarRaw : Storage::url($avatarRaw);
                }

                $data = [
                    'id'                => $attendance->id,
                    'source_type'       => 'attendance',
                    'user_id'           => $attendance->user->employee->employee_id ?? $attendance->user->employee->nip ?? '-',
                    'user_name'         => $attendance->user->name ?? 'Unknown',
                    'user_avatar'       => $avatarUrl,

                    'date'              => Carbon::parse($attendance->date)->translatedFormat('l, d F Y'),
                    'time'              => $log ? Carbon::parse($log->time)->format('H:i') : '-',
                    'status'            => $statusMap[$attendance->status] ?? 'Unknown',
                    'status_label'      => $statusMap[$attendance->status] ?? 'Unknown',
                    'reason'            => $log ? $log->note : 'Lokasi/Wajah tidak valid',
                    'rejection_note'    => $attendance->rejection_note ?? null,
                    'lat'               => $log ? $log->lat : null,
                    'lng'               => $log ? $log->lng : null,
                    'photo_url'         => ($log && $log->photo) ? Storage::url($log->photo) : null,
                    'attachment'        => null,

                    'createdAt'         => Carbon::parse($attendance->created_at)->translatedFormat('l, d F Y | H:i'),
                    'created_at_human'  => Carbon::parse($attendance->created_at)->diffForHumans(),

                    'current_step'      => $attendance->current_step,
                    'total_steps'       => $attendance->total_steps,
                    'approval_history'  => $approvalSteps->map(function ($step) {
                        return [
                            'step'          => $step->step,
                            'approver_id'   => $step->approver_id,
                            'approver_name' => $step->approver->name ?? 'Unknown',
                            'status'        => $step->status,
                            'note'          => $step->note,
                            'action_at'     => $step->action_at ? Carbon::parse($step->action_at)->isoFormat('dddd, D MMM Y | HH:mm') : null,
                        ];
                    }),
                ];
            }
        } elseif ($sourceType === 'request') {
            $req = AttendanceSubmission::with(['user.employee'])->find($id);

            if ($req) {
                $approvalSteps = \App\Models\RequestApproval::where('requestable_id', $id)
                    ->whereIn('requestable_type', [
                        'App\Models\AttendanceSubmission',
                        'App\Models\AttendanceRequest',
                        'attendance_requests'
                    ])
                    ->with('approver')
                    ->orderBy('step', 'asc')
                    ->get();

                $statusLabel = ucfirst($req->status);
                $avatarRaw = $req->user->employee->avatar ?? null;
                $avatarUrl = null;
                if ($avatarRaw) {
                    $avatarUrl = str_starts_with($avatarRaw, 'http') ? $avatarRaw : Storage::url($avatarRaw);
                }

                $data = [
                    'id'                => $req->id,
                    'source_type'       => 'request',
                    'user_id'           => $req->user->employee->employee_id ?? $req->user->employee->nip ?? '-',
                    'user_name'         => $req->user->name ?? 'Unknown',
                    'user_avatar'       => $avatarUrl,
                    'date'              => Carbon::parse($req->date)->translatedFormat('l, d F Y'),
                    'time'              => Carbon::parse($req->time)->format('H:i'),
                    'status'            => ucfirst($req->status),
                    'status_label'      => $statusLabel,
                    'reason'            => $req->reason,
                    'rejection_note'    => $req->rejection_note ?? null,
                    'lat'               => null,
                    'lng'               => null,
                    'photo_url'         => null,
                    'attachment'        => $req->file ? Storage::url($req->file) : null,

                    'createdAt'         => Carbon::parse($req->created_at)->translatedFormat('l, d F Y | H:i'),
                    'created_at_human'  => Carbon::parse($req->created_at)->diffForHumans(),

                    'current_step'      => $req->current_step,
                    'total_steps'       => $req->total_steps,
                    'approval_history'  => $approvalSteps->map(function ($step) {
                        return [
                            'step'          => $step->step,
                            'approver_id'   => $step->approver_id,
                            'approver_name' => $step->approver->name ?? 'Unknown',
                            'status'        => $step->status,
                            'note'          => $step->note,
                            'action_at'     => $step->action_at ? Carbon::parse($step->action_at)->isoFormat('dddd, D MMM Y | HH:mm') : null,
                        ];
                    }),
                ];
            }
        }

        if (!$data) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function bulkAction(Request $request)
    {
        $request->validate([
            'request_ids'   => 'required|array',
            'request_ids.*' => 'integer',
            'type'          => 'required|string|in:leave,attendance,overtime,change_shift',
            'action'        => 'required|string|in:approve,reject',
            'note'          => 'nullable|string',
            'is_delegation' => 'boolean'
        ]);

        $user = Auth::user();
        $isDelegation = filter_var($request->input('is_delegation', false), FILTER_VALIDATE_BOOLEAN);

        $modelClass = match ($request->type) {
            'leave'        => TimeOffRequest::class,
            'attendance'   => AttendanceSubmission::class,
            'overtime'     => OvertimeSubmission::class,
            'change_shift' => ChangeShiftRequest::class,
        };

        $morphClass = (new $modelClass)->getMorphClass();
        $possibleMorphs = [
            $morphClass,
            str_replace('Submission', 'Request', $morphClass),
            str_replace('Request', 'Submission', $morphClass),
        ];

        DB::beginTransaction();
        try {
            $processedCount = 0;
            $failedReasons = [];

            foreach ($request->request_ids as $id) {
                $requestRecord = $modelClass::find($id);

                if (!$requestRecord) {
                    $failedReasons[] = "ID $id: Data pengajuan tidak ditemukan di database.";
                    continue;
                }

                if ($requestRecord->status !== 'pending') {
                    $failedReasons[] = "ID $id: Status pengajuan saat ini sudah bukan pending (sudah di-ACC/Tolak).";
                    continue;
                }

                $approvalRecord = RequestApproval::whereIn('requestable_type', $possibleMorphs)
                    ->where('requestable_id', $id)
                    ->where('step', $requestRecord->current_step)
                    ->where('status', 'pending')
                    ->with('approver.employee.position')
                    ->first();

                if (!$approvalRecord) {
                    $approvalRecord = RequestApproval::where('requestable_id', $id)
                        ->where('step', $requestRecord->current_step)
                        ->where('status', 'pending')
                        ->with('approver.employee.position')
                        ->first();
                }

                if (!$approvalRecord) {
                    $failedReasons[] = "ID $id: Tidak ditemukan data antrean approval pada tahap {$requestRecord->current_step}.";
                    continue;
                }

                $approverAsli = $approvalRecord->approver;
                $jabatanApproverAsli = strtolower($approverAsli->employee->position->name ?? '');

                if ($isDelegation) {
                    if (!in_array(strtolower($user->role), ['superadmin', 'admin'])) {
                        $failedReasons[] = "ID $id: Hanya pihak SDI atau Admin yang berhak menggunakan fitur Delegasi.";
                        continue;
                    }

                    $isDirector = str_contains(strtolower($approverAsli->role), 'director')
                        || str_contains($jabatanApproverAsli, 'direktur');

                    if (!$isDirector) {
                        $namaPemilikAntrean = $approverAsli->name ?? 'Atasan Terkait';
                        $failedReasons[] = "ID $id: Ditolak! Antrean saat ini masih di {$namaPemilikAntrean} ({$jabatanApproverAsli}). Delegasi SDI HANYA berlaku untuk mengambil alih ACC Direktur.";
                        continue;
                    }
                } else {
                    if ($approvalRecord->approver_id !== $user->id) {
                        $namaPemilikAntrean = $approverAsli->name ?? 'Atasan Terkait';
                        $failedReasons[] = "ID $id: Anda tidak berhak ACC pengajuan ini. (Menunggu ACC dari: {$namaPemilikAntrean})";
                        continue;
                    }
                }
                // ========================================================

                $approvalRecord->status = $request->action === 'approve' ? 'approved' : 'rejected';

                $finalNote = $request->note;
                if ($isDelegation) {
                    $finalNote = ($finalNote ? $finalNote . ' | ' : '') . "[ACC Direktur Diwakilkan oleh SDI: {$user->name}]";
                }
                $approvalRecord->note = $finalNote;
                $approvalRecord->action_at = now();
                $approvalRecord->save();

                if ($request->action === 'reject') {
                    $requestRecord->status = 'rejected';
                    $requestRecord->rejection_note = $finalNote;
                } else {
                    if ($requestRecord->current_step < $requestRecord->total_steps) {
                        $requestRecord->current_step += 1;
                    } else {
                        $requestRecord->status = 'approved'; // Selesai (ACC Final)
                    }
                }
                $requestRecord->save();

                $processedCount++;
            }

            DB::commit();

            if ($processedCount === 0 && count($failedReasons) > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal memproses data. Alasan: ' . $failedReasons[0],
                    'debug_reasons' => $failedReasons
                ], 422);
            }

            $successMsg = "Berhasil memproses $processedCount pengajuan.";
            if (count($failedReasons) > 0) {
                $successMsg .= " (" . count($failedReasons) . " dilewati karena tidak valid).";
            }

            return response()->json([
                'success' => true,
                'message' => $successMsg
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem: ' . $th->getMessage()
            ], 500);
        }
    }
}
