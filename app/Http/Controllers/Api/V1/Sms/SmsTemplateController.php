<?php

namespace App\Http\Controllers\Api\V1\Sms;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\SmsTemplate;
use App\Services\Sms\SmsTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SMS Centre → Templates: the wording of the automatic SMS (payment receipt, repayment and overdue reminders — edited,
 * switched on or off, never deleted) and the announcement drafts staff reuse when sending by hand.
 */
class SmsTemplateController extends ApiController
{
    public function index(SmsTemplates $templates): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $companyId = (int) $this->currentEmployee()->company_id;
        $templates->ensureDefaults($companyId);
        $order = array_flip(array_keys(SmsTemplates::AUTOMATIC));

        $rows = SmsTemplate::where('company_id', $companyId)->orderBy('name')->get()
            ->sortBy(fn (SmsTemplate $template): int => $template->key === null ? 100 : $order[$template->key] ?? 99)
            ->map(fn (SmsTemplate $template): array => $this->row($template))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:480'],
        ]);

        $template = SmsTemplate::create($data + ['company_id' => $this->currentEmployee()->company_id, 'type' => SmsTemplate::TYPE_ANNOUNCEMENT, 'is_active' => true]);

        return $this->message('Template saved successfully', 201, ['data' => $this->row($template)]);
    }

    public function update(Request $request, SmsTemplate $template): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $this->assertOwned($template);

        $data = $template->type === SmsTemplate::TYPE_AUTOMATIC
            ? $request->validate([
                'body' => ['required', 'string', 'max:480'],
                'is_active' => ['required', 'boolean'],
                'days' => [$template->days === null ? 'prohibited' : 'required', 'integer', 'min:'.($template->key === SmsTemplates::OVERDUE_REMINDER ? 1 : 0), 'max:30'],
            ])
            : $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'body' => ['required', 'string', 'max:480'],
            ]);
        $template->update($data);

        return $this->message('Template updated successfully', 200, ['data' => $this->row($template)]);
    }

    public function destroy(SmsTemplate $template): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $this->assertOwned($template);
        abort_if($template->type === SmsTemplate::TYPE_AUTOMATIC, 422, 'An automatic template cannot be deleted; switch it off instead.');
        $template->delete();

        return $this->message('Template deleted successfully');
    }

    private function assertOwned(SmsTemplate $template): void
    {
        abort_unless((int) $template->company_id === (int) $this->currentEmployee()->company_id, 404);
    }

    /**
     * @return array{id: int, key: string|null, type: string, name: string, body: string, is_active: bool, days: int|null, variables: list<string>}
     */
    private function row(SmsTemplate $template): array
    {
        return [
            'id' => $template->id,
            'key' => $template->key,
            'type' => $template->type,
            'name' => $template->name,
            'body' => $template->body,
            'is_active' => (bool) $template->is_active,
            'days' => $template->days,
            'variables' => SmsTemplates::AUTOMATIC[$template->key]['variables'] ?? SmsTemplates::ANNOUNCEMENT_VARIABLES,
        ];
    }
}
