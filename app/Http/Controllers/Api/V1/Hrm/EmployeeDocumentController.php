<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HRM → Employee Registration documents (enhancement §12.1): CV, certificates and employment contract, PDF only, kept on
 * private storage and served only to HR (hrm.manage / users.manage) for staff in their scope. Uploads never overwrite:
 * every version stays.
 */
class EmployeeDocumentController extends HrmController
{
    public function index(Employee $employee): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'users.manage');
        $this->ensureVisible($employee);

        return response()->json(['data' => $employee->documents()->with('uploader:id,first_name,last_name')->latest('id')->get()
            ->map(fn (EmployeeDocument $document): array => $this->present($document))->all()]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'users.manage');
        $this->ensureVisible($employee);
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(EmployeeDocument::TYPES))],
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $file = $request->file('file');
        $document = $employee->documents()->create([
            'company_id' => $employee->company_id,
            'type' => $validated['type'],
            'file_path' => $file->store("employees/{$employee->company_id}/{$employee->id}/documents", EmployeeDocument::DISK),
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 191),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $this->currentEmployee()->id,
        ]);
        AuditLog::create([
            'company_id' => $employee->company_id,
            'employee_id' => $this->currentEmployee()->id,
            'action' => 'Employee.document_uploaded',
            'auditable_type' => $employee->getMorphClass(),
            'auditable_id' => $employee->id,
            'after' => ['document_id' => $document->id, 'type' => $document->type, 'name' => $document->original_name],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => EmployeeDocument::TYPES[$document->type].' uploaded', 'data' => $this->present($document->load('uploader'))], 201);
    }

    public function download(Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        $this->authorizeAny('hrm.manage', 'users.manage');
        $this->ensureVisible($employee);
        abort_unless((int) $document->employee_id === (int) $employee->id && Storage::disk(EmployeeDocument::DISK)->exists($document->file_path), 404);

        return Storage::disk(EmployeeDocument::DISK)->response($document->file_path, $document->original_name, ['Cache-Control' => 'private, max-age=300'], 'inline');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type,
            'type_label' => EmployeeDocument::TYPES[$document->type] ?? $document->type,
            'name' => $document->original_name,
            'size' => $document->size,
            'uploaded_by' => $document->uploader ? trim($document->uploader->first_name.' '.$document->uploader->last_name) : null,
            'uploaded_at' => $document->created_at?->toDateTimeString(),
        ];
    }
}
