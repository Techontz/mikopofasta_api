<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Messages\BroadcastRequest;
use App\Http\Requests\Api\Messages\GroupRequest;
use App\Http\Requests\Api\Messages\MessageRequest;
use App\Http\Requests\Api\Messages\StartConversationRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Employee;
use App\Services\Messaging\ChatDirectory;
use App\Services\Messaging\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Messages (handwritten notes "COMMUNICATION SYSTEM"): internal chat by position, branch and zone.
 * The frontend polls these endpoints; no websockets.
 */
class MessageController extends ApiController
{
    public function __construct(
        private readonly ChatDirectory $directory,
        private readonly ChatService $chat,
    ) {}

    /**
     * Conversations of the signed-in employee, newest activity first, with unread counts.
     */
    public function conversations(): JsonResponse
    {
        $this->authorizeAny('messages.use');
        $me = $this->currentEmployee();

        $conversations = ChatConversation::query()
            ->join('chat_participants as mine', fn ($join) => $join->on('mine.chat_conversation_id', '=', 'chat_conversations.id')->where('mine.employee_id', $me->id))
            ->where('chat_conversations.company_id', $me->company_id)
            ->select('chat_conversations.*')
            ->selectSub(
                ChatMessage::query()->selectRaw('COUNT(*)')
                    ->whereColumn('chat_messages.chat_conversation_id', 'chat_conversations.id')
                    ->whereColumn('chat_messages.id', '>', 'mine.last_read_message_id')
                    ->where(fn ($query) => $query->whereNull('chat_messages.employee_id')->orWhere('chat_messages.employee_id', '!=', $me->id)),
                'unread_count'
            )
            ->with(['latestMessage.sender', 'employees.role', 'employees.branch'])
            ->withCount('participants')
            ->orderByRaw('COALESCE(chat_conversations.last_message_at, chat_conversations.created_at) DESC')
            ->get();

        return response()->json(['data' => $conversations->map(fn (ChatConversation $conversation): array => $this->conversationData($conversation, $me))]);
    }

    /**
     * Employees the signed-in employee may start a chat with, plus hierarchy info for the UI.
     */
    public function contacts(): JsonResponse
    {
        $this->authorizeAny('messages.use');
        $me = $this->currentEmployee();

        $contacts = $this->directory->contacts($me)->with(['role', 'branch'])->orderBy('first_name')->get();
        $heads = $this->directory->level($me) === ChatDirectory::HQ ? [] : $this->directory->headIdsAbove($me, $this->directory->level($me));

        return response()->json([
            'data' => $contacts->map(fn (Employee $employee): array => [
                'value' => (string) $employee->id,
                'label' => $this->employeeLabel($employee),
                'is_head' => in_array($employee->id, $heads, true),
            ]),
            'level' => $this->directory->level($me),
            'can_create_group' => $this->directory->isHead($me),
            'audiences' => $this->directory->audiences($me)->map(fn (array $audience): array => ['value' => $audience['value'], 'label' => $audience['label']])->values(),
        ]);
    }

    public function unread(): JsonResponse
    {
        $this->authorizeAny('messages.use');

        return response()->json(['data' => ['count' => $this->chat->unreadCount($this->currentEmployee())]]);
    }

    public function start(StartConversationRequest $request): JsonResponse
    {
        $this->authorizeAny('messages.use');

        $recipient = Employee::staff()->where('company_id', $this->currentEmployee()->company_id)->findOrFail($request->integer('employee_id'));
        $conversation = $this->chat->direct($this->currentEmployee(), $recipient, $request->string('body')->toString());

        return $this->message('Message sent successfully', 201, ['data' => ['id' => $conversation->id]]);
    }

    public function group(GroupRequest $request): JsonResponse
    {
        $this->authorizeAny('messages.use');

        $conversation = $this->chat->group(
            $this->currentEmployee(),
            $request->string('name')->toString(),
            $request->input('employee_ids'),
            $request->input('body'),
        );

        return $this->message('Group created successfully', 201, ['data' => ['id' => $conversation->id]]);
    }

    public function broadcast(BroadcastRequest $request): JsonResponse
    {
        $this->authorizeAny('messages.use');

        $conversation = $this->chat->broadcast($this->currentEmployee(), $request->string('audience')->toString(), $request->string('body')->toString());

        return $this->message('Message sent successfully', 201, ['data' => ['id' => $conversation->id]]);
    }

    /**
     * Thread view. Pass after_id to fetch only newer messages when polling. Marks the thread as read.
     */
    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        $me = $this->authorizeConversation($conversation);

        $messages = $conversation->messages()
            ->with('sender')
            ->when($request->filled('after_id'), fn ($query) => $query->where('id', '>', $request->integer('after_id')))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();

        $this->chat->markRead($conversation, $me);
        $conversation->load(['employees.role', 'employees.branch', 'latestMessage.sender'])->loadCount('participants');

        return response()->json([
            'data' => [
                'conversation' => $this->conversationData($conversation, $me) + [
                    'members' => $conversation->employees->map(fn (Employee $employee): array => ['id' => $employee->id, 'label' => $this->employeeLabel($employee)])->values(),
                    'can_post' => $this->canPost($conversation, $me),
                ],
                'messages' => $messages->map(fn (ChatMessage $message): array => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'sender_id' => $message->employee_id,
                    'sender' => $message->sender?->full_name ?? '-',
                    'mine' => $message->employee_id === $me->id,
                    'created_at' => $message->created_at?->format('Y-m-d H:i'),
                ]),
            ],
        ]);
    }

    public function send(MessageRequest $request, ChatConversation $conversation): JsonResponse
    {
        $me = $this->authorizeConversation($conversation);

        $message = $this->chat->post($conversation, $me, $request->string('body')->toString());

        return response()->json(['data' => ['id' => $message->id]], 201);
    }

    private function authorizeConversation(ChatConversation $conversation): Employee
    {
        $this->authorizeAny('messages.use');
        $me = $this->currentEmployee();

        abort_unless(
            $conversation->company_id === $me->company_id
                && ChatParticipant::where('chat_conversation_id', $conversation->id)->where('employee_id', $me->id)->exists(),
            403,
            'You are not a member of this conversation.'
        );

        return $me;
    }

    private function canPost(ChatConversation $conversation, Employee $me): bool
    {
        if (! $conversation->isBroadcast()) {
            return true;
        }

        $value = $conversation->type === ChatConversation::COMPANY ? 'company:0' : $conversation->type.':'.($conversation->branch_id ?? $conversation->zone_id);

        return $this->directory->audiences($me)->contains('value', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationData(ChatConversation $conversation, Employee $me): array
    {
        $other = $conversation->type === ChatConversation::DIRECT
            ? $conversation->employees->firstWhere('id', '!=', $me->id)
            : null;
        $latest = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $other ? $other->full_name : ($conversation->name ?? 'Conversation'),
            'subtitle' => $other ? $this->employeePosition($other) : ($conversation->participants_count ?? $conversation->employees->count()).' members',
            'unread_count' => (int) ($conversation->unread_count ?? 0),
            'last_message' => $latest ? [
                'body' => mb_strimwidth($latest->body, 0, 80, '…'),
                'sender' => $latest->employee_id === $me->id ? 'You' : ($latest->sender?->first_name ?? '-'),
                'created_at' => $latest->created_at?->format('Y-m-d H:i'),
            ] : null,
        ];
    }

    private function employeeLabel(Employee $employee): string
    {
        return $employee->full_name.' ('.$this->employeePosition($employee).')';
    }

    private function employeePosition(Employee $employee): string
    {
        $level = $this->directory->level($employee);

        return trim(($employee->role?->name ?? 'Staff').($level === ChatDirectory::HQ ? ' - HQ' : ($employee->branch ? ' - '.$employee->branch->name : '')));
    }
}
