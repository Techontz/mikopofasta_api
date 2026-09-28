<?php

namespace App\Services\Messaging;

use App\Models\Branch;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Employee;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates conversations and posts messages, enforcing the hierarchy rules of {@see ChatDirectory}.
 */
class ChatService
{
    public function __construct(private readonly ChatDirectory $directory) {}

    /**
     * Find or open the direct conversation between two employees and post the first message.
     */
    public function direct(Employee $from, Employee $to, string $body): ChatConversation
    {
        if (! $this->directory->canMessage($from, $to)) {
            throw ValidationException::withMessages(['employee_id' => 'You can only message your specific head or the staff under you.']);
        }

        return DB::transaction(function () use ($from, $to, $body): ChatConversation {
            $conversation = ChatConversation::where('company_id', $from->company_id)
                ->where('type', ChatConversation::DIRECT)
                ->whereHas('participants', fn ($query) => $query->where('employee_id', $from->id))
                ->whereHas('participants', fn ($query) => $query->where('employee_id', $to->id))
                ->first();

            if ($conversation === null) {
                $conversation = ChatConversation::create(['company_id' => $from->company_id, 'type' => ChatConversation::DIRECT, 'created_by' => $from->id]);
                $this->addParticipants($conversation, [$from->id, $to->id]);
            }

            $this->post($conversation, $from, $body);

            return $conversation;
        });
    }

    /**
     * @param  list<int>  $memberIds
     */
    public function group(Employee $creator, string $name, array $memberIds, ?string $body): ChatConversation
    {
        if (! $this->directory->isHead($creator)) {
            throw ValidationException::withMessages(['name' => 'Only heads (HQ, zone and branch managers) can create groups.']);
        }

        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        $allowed = $this->directory->contacts($creator)->whereIn('id', $memberIds)->pluck('id')->all();
        if (count($allowed) !== count(array_diff($memberIds, [$creator->id]))) {
            throw ValidationException::withMessages(['employee_ids' => 'Some selected employees are outside your allowed branch or zone.']);
        }

        return DB::transaction(function () use ($creator, $name, $allowed, $body): ChatConversation {
            $conversation = ChatConversation::create([
                'company_id' => $creator->company_id,
                'type' => ChatConversation::GROUP,
                'name' => $name,
                'created_by' => $creator->id,
            ]);
            $this->addParticipants($conversation, [...$allowed, $creator->id]);

            if ($body !== null && trim($body) !== '') {
                $this->post($conversation, $creator, $body);
            }

            return $conversation;
        });
    }

    /**
     * Post to a branch / zone / company channel, creating it and adding any new members first.
     */
    public function broadcast(Employee $sender, string $audience, string $body): ChatConversation
    {
        [$type, $id] = explode(':', $audience);
        $id = $type === ChatConversation::COMPANY ? null : (int) $id;

        if (! $this->directory->audiences($sender)->contains('value', $type === ChatConversation::COMPANY ? 'company:0' : "{$type}:{$id}")) {
            throw ValidationException::withMessages(['audience' => 'You cannot broadcast to this audience.']);
        }

        return DB::transaction(function () use ($sender, $type, $id, $body): ChatConversation {
            $conversation = ChatConversation::firstOrCreate(
                [
                    'company_id' => $sender->company_id,
                    'type' => $type,
                    'branch_id' => $type === ChatConversation::BRANCH ? $id : null,
                    'zone_id' => $type === ChatConversation::ZONE ? $id : null,
                ],
                ['name' => $this->audienceName($type, $id), 'created_by' => $sender->id],
            );

            $this->addParticipants($conversation, $this->directory->audienceMemberIds($sender, $type, $id));
            $this->post($conversation, $sender, $body);

            return $conversation;
        });
    }

    /**
     * Post a message in an existing conversation. Any participant may reply in direct chats and groups;
     * inferred: in broadcast channels only employees allowed to broadcast to that audience may post.
     */
    public function post(ChatConversation $conversation, Employee $sender, string $body): ChatMessage
    {
        $participant = ChatParticipant::where('chat_conversation_id', $conversation->id)->where('employee_id', $sender->id)->first();
        abort_if($participant === null, 403, 'You are not a member of this conversation.');

        if ($conversation->isBroadcast()) {
            $value = $conversation->type === ChatConversation::COMPANY ? 'company:0' : $conversation->type.':'.($conversation->branch_id ?? $conversation->zone_id);
            if (! $this->directory->audiences($sender)->contains('value', $value)) {
                throw ValidationException::withMessages(['body' => 'This is an announcement channel; only its heads can post.']);
            }
        }

        $message = $conversation->messages()->create(['employee_id' => $sender->id, 'body' => trim($body)]);
        $conversation->update(['last_message_at' => $message->created_at]);
        $participant->update(['last_read_message_id' => $message->id]);

        return $message;
    }

    public function markRead(ChatConversation $conversation, Employee $employee): void
    {
        $latest = (int) $conversation->messages()->max('id');

        ChatParticipant::where('chat_conversation_id', $conversation->id)
            ->where('employee_id', $employee->id)
            ->where('last_read_message_id', '<', $latest)
            ->update(['last_read_message_id' => $latest]);
    }

    /**
     * Total unread messages for an employee across all conversations.
     */
    public function unreadCount(Employee $employee): int
    {
        return (int) ChatMessage::query()
            ->join('chat_participants', 'chat_participants.chat_conversation_id', '=', 'chat_messages.chat_conversation_id')
            ->where('chat_participants.employee_id', $employee->id)
            ->whereColumn('chat_messages.id', '>', 'chat_participants.last_read_message_id')
            ->where(fn ($query) => $query->whereNull('chat_messages.employee_id')->orWhere('chat_messages.employee_id', '!=', $employee->id))
            ->count();
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function addParticipants(ChatConversation $conversation, array $employeeIds): void
    {
        $now = now();
        $latest = (int) $conversation->messages()->max('id');

        ChatParticipant::insertOrIgnore(collect($employeeIds)->unique()->map(fn (int $id): array => [
            'chat_conversation_id' => $conversation->id,
            'employee_id' => $id,
            'last_read_message_id' => $latest,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }

    private function audienceName(string $type, ?int $id): string
    {
        return match ($type) {
            ChatConversation::BRANCH => 'BRANCH: '.Branch::whereKey($id)->value('name'),
            ChatConversation::ZONE => 'ZONE: '.Zone::whereKey($id)->value('name'),
            default => 'ALL STAFF',
        };
    }
}
