<?php

namespace App\Http\Requests\Api\Customers;

use App\Models\FaceScan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * POST /customers/{customer}/face-verify (CUSTOMER_MODULE_IMPLEMENTATION.md §5.7). Multipart; booleans may be
 * sent as true/false, 1/0.
 */
class FaceVerifyRequest extends CustomerScopedRequest
{
    /**
     * @var list<string>
     */
    public const SCORES = ['qualityScore', 'brightnessScore', 'blurScore', 'distanceScore', 'centeringScore', 'eyesOpenScore'];

    private const CHECKS_MESSAGE = 'The scanner did not report every check. The scan cannot be recorded.';

    protected function prepareForValidation(): void
    {
        $boolean = fn (mixed $value): mixed => match (is_string($value) ? strtolower($value) : $value) {
            'true' => true,
            'false' => false,
            default => $value,
        };

        $this->merge([
            'livenessPassed' => $boolean($this->input('livenessPassed')),
            'poseSequenceCompleted' => $boolean($this->input('poseSequenceCompleted')),
        ]);

        if (is_array($this->input('checks'))) {
            $this->merge(['checks' => array_map($boolean, $this->input('checks'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'capture' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'status' => ['required', 'in:passed,failed'],
            'scannerVersion' => ['required', 'string', 'max:64'],
            'livenessPassed' => ['required', 'boolean'],
            'poseSequenceCompleted' => ['required', 'boolean'],
            'checks' => ['nullable', 'array'],
            'captureDevice' => ['nullable', 'string', 'max:191'],
            'captureResolution' => ['nullable', 'string', 'regex:/^\d{2,5}x\d{2,5}$/'],
            'captureDurationMs' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];

        foreach (self::SCORES as $score) {
            $rules[$score] = ['required', 'integer', 'min:0', 'max:100'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'capture.required' => 'A liveness capture is required.',
            'capture.file' => 'The liveness capture must be an image.',
            'capture.image' => 'The liveness capture must be an image.',
            'capture.mimes' => 'The liveness capture must be an image.',
            'capture.max' => 'The liveness capture must not be larger than 5 MB.',
            'captureResolution.regex' => 'The capture resolution must look like 1280x720.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $checks = $this->input('checks');
            $complete = is_array($checks) && collect(FaceScan::CHECKS)->every(
                fn (string $check): bool => array_key_exists($check, $checks) && in_array($checks[$check], [true, false, 1, 0, '1', '0'], true),
            );

            if (! $complete) {
                $validator->errors()->add('checks', self::CHECKS_MESSAGE);
            }
        }];
    }
}
