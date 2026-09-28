<?php

namespace App\Http\Resources\Api\V1\Crm;

use App\Models\CrmTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CrmTicket
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'category' => $this->category,
            'category_label' => CrmTicket::CATEGORIES[$this->category] ?? $this->category,
            'channel' => $this->channel,
            'channel_label' => CrmTicket::CHANNELS[$this->channel] ?? $this->channel,
            'priority' => $this->priority,
            'status' => $this->status,
            'status_label' => CrmTicket::STATUSES[$this->status] ?? strtoupper($this->status),
            'subject' => $this->subject,
            'description' => $this->description,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->format('Y-m-d H:i'),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->full_name,
                'code' => $this->customer->customer_code,
                'phone' => $this->customer->phone,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->full_name),
            'resolver' => $this->whenLoaded('resolver', fn () => $this->resolver?->full_name),
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
