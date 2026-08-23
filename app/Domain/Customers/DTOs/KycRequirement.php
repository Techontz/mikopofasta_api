<?php

declare(strict_types=1);

namespace App\Domain\Customers\DTOs;

/**
 * One line of the KYC checklist.
 *
 * A boolean could not carry this. The checklist has to distinguish four states
 * that all used to collapse into "not ticked":
 *
 *   satisfied            — done.
 *   required, not done   — the officer can fix this, and the label says how.
 *   not required         — recorded for completeness; does not block anybody.
 *   required, unavailable— demanded by policy, impossible in this deployment.
 *
 * The last one is the NIDA case and the reason this class exists. A profile
 * that requires a registry check on an installation with no registry produces
 * a customer nobody can complete, and an officer who is told only "NIDA
 * verified ✗" will keep trying. `blocked` says the truth: this is not your
 * fault and not your job.
 */
final readonly class KycRequirement
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $satisfied,
        public bool $required,
        /** Required by policy but impossible here — see the class note. */
        public bool $blocked = false,
        /** What is missing, or why it cannot be done. Shown under the label. */
        public ?string $detail = null,
    ) {}

    /**
     * Whether this line stops the customer being KYC-complete.
     *
     * A blocked item counts as outstanding. Pretending an impossible
     * requirement is met would be the fabrication this whole design refuses;
     * the fix is for the institution to stop requiring it, which is one edit
     * to `account_type_requirements`.
     */
    public function outstanding(): bool
    {
        return $this->required && ! $this->satisfied;
    }

    /**
     * @return array{
     *     key: string, label: string, satisfied: bool, required: bool,
     *     blocked: bool, detail: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'satisfied' => $this->satisfied,
            'required' => $this->required,
            'blocked' => $this->blocked,
            'detail' => $this->detail,
        ];
    }
}
