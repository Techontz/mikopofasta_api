<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The CSV, and nothing else.
 *
 * `mimes` is deliberately loose — `txt` is included because a CSV exported
 * from a spreadsheet or downloaded from a statistics office frequently arrives
 * with a text MIME type, and refusing it would send an administrator to rename
 * a file for no reason. The importer parses the CONTENT and rejects anything
 * without a `region` header, which is the check that matters.
 */
final class ImportGeographyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file to import.',
            'file.mimes' => 'The register must be a CSV file.',
            'file.max' => 'The file is larger than 20 MB. Split it and import each part — importing is safe to repeat.',
        ];
    }
}
