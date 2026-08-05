<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\FaceScanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /customers/{customer}/face-verify — the liveness capture and its report.
 *
 * The image used to be the whole request. That made the endpoint indifferent
 * to whether a liveness check had happened at all: any JPEG would set
 * `face_verified_at`, and "verified" meant nothing more than "a file arrived".
 * Every measurement below is therefore required, not optional — a capture that
 * cannot say how bright it was, how sharp, or which head positions the
 * customer completed is not a verification and must not be recorded as one.
 *
 * The scores are the scanner's own measurements, normalised to 0–100 in the
 * browser where the pixels are. Nothing here re-derives them: the server never
 * sees the video, only the one frame that was kept.
 *
 * Note what this endpoint deliberately does NOT accept: `faceVerifiedAt`. The
 * timestamp is the server's, stamped when a passing scan is recorded. A client
 * that could name its own verification time could date one to before the
 * customer walked in.
 */
final class FaceVerifyRequest extends FormRequest
{
    /** Every check the scanner reports on, by name. */
    public const array CHECKS = [
        'oneFaceDetected',
        'eyesOpen',
        'centered',
        'correctDistance',
        'goodLighting',
        'sharpImage',
        'poseStraight',
        'poseLeft',
        'poseRight',
        'poseUp',
        'poseDown',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $score = ['required', 'integer', 'between:0,100'];

        $rules = [
            /* Images only, and capped: this is biometric data on a private
               disk, and an unbounded upload is both a storage and a
               denial-of-service concern. */
            'capture' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],

            'status' => ['required', Rule::enum(FaceScanStatus::class)],

            'qualityScore' => $score,
            'brightnessScore' => $score,
            'blurScore' => $score,
            'distanceScore' => $score,
            'centeringScore' => $score,
            'eyesOpenScore' => $score,

            'scannerVersion' => ['required', 'string', 'max:64'],
            'livenessPassed' => ['required', 'boolean'],
            'poseSequenceCompleted' => ['required', 'boolean'],

            /* The camera's own label and the track's real settings, read off
               the MediaStream. Nullable because a browser may withhold the
               device label until permission is granted, and inventing one
               would defeat the point of recording it. */
            'captureDevice' => ['nullable', 'string', 'max:191'],
            'captureResolution' => ['nullable', 'string', 'regex:/^\d{2,5}x\d{2,5}$/'],
            /* An hour is far beyond any real scan; the bound is here so a
               nonsense figure cannot be stored as a measurement. */
            'captureDurationMs' => ['nullable', 'integer', 'between:0,3600000'],

            'reason' => ['nullable', 'string', 'max:500'],
        ];

        foreach (self::CHECKS as $check) {
            $rules["checks.{$check}"] = ['required', 'boolean'];
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
            'capture.image' => 'The liveness capture must be an image.',
            'capture.max' => 'The liveness capture must not be larger than 5 MB.',
            'captureResolution.regex' => 'The capture resolution must look like 1280x720.',
            'checks.*.required' => 'The scanner did not report every check. The scan cannot be recorded.',
        ];
    }

    /**
     * The validated payload in the shape the action stores — snake_case
     * columns, with the checks collected into the JSON block.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        /** @var array<string, mixed> $checks */
        $checks = (array) $this->validated('checks');

        return [
            'status' => FaceScanStatus::from((string) $this->validated('status')),
            'quality_score' => (int) $this->validated('qualityScore'),
            'brightness_score' => (int) $this->validated('brightnessScore'),
            'blur_score' => (int) $this->validated('blurScore'),
            'distance_score' => (int) $this->validated('distanceScore'),
            'centering_score' => (int) $this->validated('centeringScore'),
            'eyes_open_score' => (int) $this->validated('eyesOpenScore'),
            'scanner_version' => (string) $this->validated('scannerVersion'),
            'liveness_passed' => $this->boolean('livenessPassed'),
            'pose_sequence_completed' => $this->boolean('poseSequenceCompleted'),
            'checks' => array_map(
                static fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_BOOL),
                array_intersect_key($checks, array_flip(self::CHECKS)),
            ),
            'capture_device' => $this->validated('captureDevice'),
            'capture_resolution' => $this->validated('captureResolution'),
            'capture_duration_ms' => $this->validated('captureDurationMs') === null
                ? null
                : (int) $this->validated('captureDurationMs'),
            'reason' => $this->validated('reason'),
        ];
    }
}
