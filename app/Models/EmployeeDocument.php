<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An HR document of an employee (enhancement §12.1): CV, certificate or employment contract, a PDF on private storage.
 * Every upload is kept, so earlier versions stay on record.
 */
class EmployeeDocument extends Model
{
    public const DISK = 'local';

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'cv' => 'CV',
        'certificate' => 'Certificates',
        'contract' => 'Employment Contract',
    ];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by');
    }
}
