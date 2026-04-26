<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MabarMessage;
use App\Models\MabarSession;
use App\Models\MabarSlot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MabarRoomController extends Controller
{
    private const QUICK_TEMPLATES = [
        'ready' => "🟢 I'm ready! Let's go.",
        'afk' => '⏳ AFK 5 min, jangan start dulu.',
        'need5' => '🕔 Need 5 min, almost there.',
        'gg' => '🎮 GG everyone, great match!',
        'rematch' => '🔁 Rematch? Same squad.',
        'respawn' => '💀 Respawning, stall please.',
        'push' => '⚔ PUSH NOW!',
        'retreat' => '🛡 RETREAT, back off.',
    ];

    // -------------------- Room state --------------------

    public function room(Request $request, int $id): JsonResponse
    {
        $session = $this->findAuthorizedSession($request, $id);
        $this->heartbeatNow($session, $request->user());

        $session->load([
            'host:id,name,username,avatar_url,is_admin',
            'slots.user:id,name,username,avatar_url',
        ]);

        $pinned = null;
        if ($session->pinned_message_id) {
            $pinned = MabarMessage::with('user:id,name,username,avatar_url')
                ->whereNull('deleted_at')
                ->find($session->pinned_message_id);
        }

        $latestMessages = MabarMessage::with(['user:id,name,username,avatar_url', 'replyTo.user:id,name,username,avatar_url'])
            ->where('session_id', $session->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->sortBy('id')
            ->values();

        return response()->json([
            'session' => $this->presentSession($session, $request->user()->id),
            'pinned_message' => $pinned ? $this->presentMessage($pinned, $request->user()->id) : null,
            'messages' => $latestMessages->map(fn ($m) => $this->presentMessage($m, $request->user()->id))->values(),
            'quick_templates' => self::QUICK_TEMPLATES,
            'your_role' => $this->roleOfUser($session, $request->user()->id),
        ]);
    }

    public function listMessages(Request $request, int $id): JsonResponse
    {
        $session = $this->findAuthorizedSession($request, $id);
        $this->heartbeatNow($session, $request->user());

        $since = (int) $request->get('since', 0);

        $query = MabarMessage::with(['user:id,name,username,avatar_url', 'replyTo.user:id,name,username,avatar_url'])
            ->where('session_id', $session->id)
            ->whereNull('deleted_at');

        if ($since > 0) {
            $query->where('id', '>', $since);
        } else {
            $query->orderByDesc('id')->limit(80);
        }

        $messages = $query->orderBy('id')->get();

        $members = $session->slots()->with('user:id,name,username,avatar_url')->get()
            ->filter(fn ($s) => $s->user)
            ->map(fn ($s) => [
                'user_id' => $s->user->id,
                'name' => $s->user->name,
                'username' => $s->user->username,
                'avatar_url' => $s->user->avatar_url,
                'status' => $s->status,
                'role_preference' => $s->role_preference,
                'slot_index' => (int) $s->slot_index,
                'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                'online' => $s->last_seen_at && $s->last_seen_at->gt(now()->subSeconds(60)),
            ])->values();

        return response()->json([
            'messages' => $messages->map(fn ($m) => $this->presentMessage($m, $request->user()->id))->values(),
            'members' => $members,
            'pinned_message_id' => $session->pinned_message_id,
            'session_status' => $session->status,
        ]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:1000',
            'kind' => 'nullable|string|in:text,quick,gif',
            'reply_to_id' => 'nullable|integer|exists:mabar_messages,id',
        ]);

        $session = $this->findAuthorizedSession($request, $id);
        $this->heartbeatNow($session, $request->user());

        $message = MabarMessage::create([
            'session_id' => $session->id,
            'user_id' => $request->user()->id,
            'kind' => $data['kind'] ?? 'text',
            'body' => $data['body'],
            'reply_to_id' => $data['reply_to_id'] ?? null,
        ]);

        $message->load(['user:id,name,username,avatar_url', 'replyTo.user:id,name,username,avatar_url']);

        return response()->json($this->presentMessage($message, $request->user()->id), 201);
    }

    public function react(Request $request, int $id, int $msgId): JsonResponse
    {
        $data = $request->validate([
            'emoji' => 'required|string|max:8',
        ]);

        $session = $this->findAuthorizedSession($request, $id);
        $this->heartbeatNow($session, $request->user());

        $allowed = ['🔥', '👍', '😂', '💯', '🎮', '⚡', '❤️', '😮'];
        if (! in_array($data['emoji'], $allowed, true)) {
            return response()->json(['message' => 'Unsupported emoji'], 422);
        }

        $message = MabarMessage::where('id', $msgId)->where('session_id', $session->id)->firstOrFail();
        $reactions = $message->reactions ?? [];
        $userId = $request->user()->id;
        $emoji = $data['emoji'];

        $list = $reactions[$emoji] ?? [];
        if (in_array($userId, $list, true)) {
            $list = array_values(array_filter($list, fn ($uid) => $uid !== $userId));
        } else {
            $list[] = $userId;
        }

        if (count($list) > 0) {
            $reactions[$emoji] = array_values(array_unique($list));
        } else {
            unset($reactions[$emoji]);
        }

        $message->update(['reactions' => $reactions ?: null]);
        $message->load(['user:id,name,username,avatar_url', 'replyTo.user:id,name,username,avatar_url']);

        return response()->json($this->presentMessage($message, $userId));
    }

    public function togglePin(Request $request, int $id, int $msgId): JsonResponse
    {
        $session = $this->findAuthorizedSession($request, $id);
        if ($session->host_user_id !== $request->user()->id && ! $request->user()->is_admin) {
            abort(403, 'Only host/admin can pin');
        }

        $message = MabarMessage::where('id', $msgId)->where('session_id', $session->id)->firstOrFail();

        if ($session->pinned_message_id === $message->id) {
            $session->update(['pinned_message_id' => null]);
            $message->update(['is_pinned' => false]);
            return response()->json(['pinned' => false]);
        }

        if ($session->pinned_message_id) {
            MabarMessage::where('id', $session->pinned_message_id)->update(['is_pinned' => false]);
        }

        $session->update(['pinned_message_id' => $message->id]);
        $message->update(['is_pinned' => true]);

        self::emitSystem($session, "📌 Host pinned a message.");

        return response()->json(['pinned' => true]);
    }

    public function deleteMessage(Request $request, int $id, int $msgId): JsonResponse
    {
        $session = $this->findAuthorizedSession($request, $id);
        $message = MabarMessage::where('id', $msgId)->where('session_id', $session->id)->firstOrFail();

        $isAuthor = $message->user_id === $request->user()->id;
        $isHost = $session->host_user_id === $request->user()->id;
        $isAdmin = (bool) $request->user()->is_admin;

        if (! $isAuthor && ! $isHost && ! $isAdmin) {
            abort(403, 'Cannot delete this message');
        }

        $message->update(['deleted_at' => now()]);

        if ($session->pinned_message_id === $message->id) {
            $session->update(['pinned_message_id' => null]);
        }

        return response()->json(['message' => 'Deleted']);
    }

    public function heartbeat(Request $request, int $id): JsonResponse
    {
        $session = $this->findAuthorizedSession($request, $id);
        $this->heartbeatNow($session, $request->user());
        return response()->json(['ok' => true, 'ts' => now()->toIso8601String()]);
    }

    // -------------------- Helpers --------------------

    private function findAuthorizedSession(Request $request, int $id): MabarSession
    {
        $session = MabarSession::findOrFail($id);
        $uid = $request->user()->id;

        if ($session->host_user_id === $uid || $request->user()->is_admin) {
            return $session;
        }

        $isMember = MabarSlot::where('session_id', $id)
            ->where('user_id', $uid)
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();

        if (! $isMember) {
            abort(403, 'You are not a member of this room');
        }

        return $session;
    }

    private function heartbeatNow(MabarSession $session, User $user): void
    {
        if ($session->host_user_id === $user->id) {
            // ensure host slot exists and track its last_seen
            MabarSlot::where('session_id', $session->id)
                ->where('user_id', $user->id)
                ->update(['last_seen_at' => now()]);
            return;
        }

        MabarSlot::where('session_id', $session->id)
            ->where('user_id', $user->id)
            ->update(['last_seen_at' => now()]);
    }

    private function roleOfUser(MabarSession $session, int $uid): string
    {
        if ($session->host_user_id === $uid) return 'host';
        $slot = MabarSlot::where('session_id', $session->id)->where('user_id', $uid)->first();
        if (! $slot) return 'none';
        return $slot->status; // pending | confirmed | left
    }

    private function presentSession(MabarSession $session, int $viewerId): array
    {
        $slots = $session->slots ?? collect();
        return [
            'id' => $session->id,
            'title' => $session->title,
            'type' => $session->type,
            'vibe' => $session->vibe,
            'rank_requirement' => $session->rank_requirement,
            'starts_at' => $session->starts_at?->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'status' => $session->status,
            'voice_platform' => $session->voice_platform,
            'discord_link' => $session->discord_link,
            'room_id' => $session->room_id,
            'notes' => $session->notes,
            'max_slots' => (int) $session->max_slots,
            'filled_slots' => $slots->whereIn('status', ['pending', 'confirmed'])->count(),
            'host' => $session->host ? [
                'id' => $session->host->id,
                'name' => $session->host->name,
                'username' => $session->host->username,
                'avatar_url' => $session->host->avatar_url,
                'is_admin' => (bool) $session->host->is_admin,
            ] : null,
            'is_viewer_host' => $session->host_user_id === $viewerId,
            'members' => $slots->map(fn ($slot) => [
                'slot_id' => $slot->id,
                'slot_index' => (int) $slot->slot_index,
                'role_preference' => $slot->role_preference,
                'status' => $slot->status,
                'last_seen_at' => $slot->last_seen_at?->toIso8601String(),
                'online' => $slot->last_seen_at && $slot->last_seen_at->gt(now()->subSeconds(60)),
                'user' => $slot->user ? [
                    'id' => $slot->user->id,
                    'name' => $slot->user->name,
                    'username' => $slot->user->username,
                    'avatar_url' => $slot->user->avatar_url,
                ] : null,
            ])->values(),
        ];
    }

    private function presentMessage(MabarMessage $m, int $viewerId): array
    {
        $reactions = [];
        foreach (($m->reactions ?? []) as $emoji => $uids) {
            $reactions[] = [
                'emoji' => $emoji,
                'count' => count($uids),
                'mine' => in_array($viewerId, $uids, true),
            ];
        }

        return [
            'id' => $m->id,
            'kind' => $m->kind,
            'body' => $m->body,
            'is_pinned' => (bool) $m->is_pinned,
            'reactions' => $reactions,
            'created_at' => $m->created_at?->toIso8601String(),
            'user' => $m->user ? [
                'id' => $m->user->id,
                'name' => $m->user->name,
                'username' => $m->user->username,
                'avatar_url' => $m->user->avatar_url,
            ] : null,
            'reply_to' => $m->replyTo && ! $m->replyTo->deleted_at ? [
                'id' => $m->replyTo->id,
                'body' => $m->replyTo->body,
                'user' => $m->replyTo->user ? [
                    'id' => $m->replyTo->user->id,
                    'name' => $m->replyTo->user->name,
                ] : null,
            ] : null,
        ];
    }

    // -------------------- Shared system message emitter --------------------

    public static function emitSystem(MabarSession $session, string $body, ?int $userId = null): MabarMessage
    {
        return MabarMessage::create([
            'session_id' => $session->id,
            'user_id' => $userId,
            'kind' => 'system',
            'body' => $body,
        ]);
    }
}
