<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MabarRating;
use App\Models\MabarSession;
use App\Models\MabarSignal;
use App\Models\MabarSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MabarController extends Controller
{
    /** Valid enum-like values */
    private const TYPES = ['push_rank', 'classic', 'brawl', 'tournament', 'coaching'];
    private const VIBES = ['sweaty', 'chill', 'tryhard', 'learning', 'event'];
    private const RANKS = ['any', 'epic', 'legend', 'mythic', 'mythic_honor', 'mythic_glory', 'mythic_immortal'];
    private const ROLES = ['any', 'tank', 'jungle', 'roam', 'mid', 'exp', 'gold', 'support'];
    private const VOICE = ['discord', 'in_game', 'chat'];
    private const RECUR = ['none', 'weekly'];
    private const STATUS = ['open', 'full', 'live', 'closed', 'expired', 'cancelled'];

    // -------------------- Sessions --------------------

    public function index(Request $request): JsonResponse
    {
        $this->autoUpdateStatuses();

        $query = MabarSession::with(['host:id,name,username,avatar_url,is_admin', 'slots.user:id,name,username,avatar_url'])
            ->orderByDesc('is_featured')
            ->orderBy('starts_at');

        if ($status = $request->get('status')) {
            $statuses = is_array($status) ? $status : explode(',', $status);
            $query->whereIn('status', $statuses);
        } else {
            // default: hide closed/expired/cancelled
            $query->whereIn('status', ['open', 'full', 'live']);
        }

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }
        if ($vibe = $request->get('vibe')) {
            $query->where('vibe', $vibe);
        }
        if ($rank = $request->get('rank_requirement')) {
            $query->where('rank_requirement', $rank);
        }

        if ($request->boolean('mine') && $request->user()) {
            $uid = $request->user()->id;
            $query->where(function ($q) use ($uid) {
                $q->where('host_user_id', $uid)
                    ->orWhereHas('slots', fn ($s) => $s->where('user_id', $uid));
            });
        }

        if ($when = $request->get('when')) {
            $now = Carbon::now();
            match ($when) {
                'today' => $query->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]),
                'tonight' => $query->whereBetween('starts_at', [$now->copy()->setTime(17, 0), $now->copy()->endOfDay()]),
                'tomorrow' => $query->whereBetween('starts_at', [$now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay()]),
                'weekend' => $query->where(function ($q) use ($now) {
                    $sat = $now->copy()->next(Carbon::SATURDAY)->startOfDay();
                    $sun = $now->copy()->next(Carbon::SUNDAY)->endOfDay();
                    $q->whereBetween('starts_at', [$sat, $sun]);
                }),
                'starting_soon' => $query->whereBetween('starts_at', [$now, $now->copy()->addHours(2)]),
                default => null,
            };
        }

        $viewerId = $request->user()?->id;
        $sessions = $query->limit(60)->get()->map(fn ($s) => $this->presentSession($s, false, $viewerId));

        return response()->json(['data' => $sessions]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->autoUpdateStatuses();

        $session = MabarSession::with([
            'host:id,name,username,avatar_url,is_admin',
            'slots.user:id,name,username,avatar_url',
            'ratings.fromUser:id,name,username,avatar_url',
            'ratings.toUser:id,name,username,avatar_url',
        ])->findOrFail($id);

        return response()->json($this->presentSession($session, detailed: true, viewerId: $request->user()?->id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateSessionPayload($request);

        $session = DB::transaction(function () use ($data, $request) {
            $session = MabarSession::create([
                'host_user_id' => $request->user()->id,
                'title' => $data['title'],
                'type' => $data['type'],
                'vibe' => $data['vibe'] ?? null,
                'rank_requirement' => $data['rank_requirement'] ?? 'any',
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'] ?? null,
                'recurrence' => $data['recurrence'] ?? 'none',
                'recurrence_days' => $data['recurrence_days'] ?? null,
                'max_slots' => $data['max_slots'] ?? 5,
                'voice_platform' => $data['voice_platform'] ?? null,
                'discord_link' => $data['discord_link'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $roleSlots = $data['slot_roles'] ?? array_fill(0, (int) ($data['max_slots'] ?? 5), 'any');

            // slot 1 = host
            MabarSlot::create([
                'session_id' => $session->id,
                'slot_index' => 1,
                'role_preference' => $roleSlots[0] ?? 'any',
                'user_id' => $request->user()->id,
                'status' => 'confirmed',
                'joined_at' => now(),
            ]);

            for ($i = 2; $i <= $session->max_slots; $i++) {
                MabarSlot::create([
                    'session_id' => $session->id,
                    'slot_index' => $i,
                    'role_preference' => $roleSlots[$i - 1] ?? 'any',
                    'status' => 'open',
                ]);
            }

            return $session;
        });

        $session->load(['host:id,name,username,avatar_url,is_admin', 'slots.user:id,name,username,avatar_url']);

        return response()->json($this->presentSession($session, detailed: true), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $session = MabarSession::findOrFail($id);
        $this->authorizeHostOrAdmin($request, $session);

        $data = $this->validateSessionPayload($request, updating: true);

        $session->update(array_filter([
            'title' => $data['title'] ?? null,
            'type' => $data['type'] ?? null,
            'vibe' => array_key_exists('vibe', $data) ? $data['vibe'] : null,
            'rank_requirement' => $data['rank_requirement'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => array_key_exists('ends_at', $data) ? $data['ends_at'] : null,
            'recurrence' => $data['recurrence'] ?? null,
            'recurrence_days' => array_key_exists('recurrence_days', $data) ? $data['recurrence_days'] : null,
            'voice_platform' => array_key_exists('voice_platform', $data) ? $data['voice_platform'] : null,
            'discord_link' => array_key_exists('discord_link', $data) ? $data['discord_link'] : null,
            'room_id' => array_key_exists('room_id', $data) ? $data['room_id'] : null,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : null,
        ], fn ($v) => $v !== null));

        if (isset($data['slot_roles']) && is_array($data['slot_roles'])) {
            foreach ($data['slot_roles'] as $idx => $role) {
                MabarSlot::where('session_id', $session->id)
                    ->where('slot_index', $idx + 1)
                    ->update(['role_preference' => $role]);
            }
        }

        $session->load(['host:id,name,username,avatar_url,is_admin', 'slots.user:id,name,username,avatar_url']);
        return response()->json($this->presentSession($session, detailed: true));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $session = MabarSession::findOrFail($id);
        $this->authorizeHostOrAdmin($request, $session);

        $session->delete();
        return response()->json(['message' => 'Session deleted']);
    }

    // -------------------- Slot actions --------------------

    public function join(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'slot_id' => 'nullable|integer|exists:mabar_slots,id',
            'role_preference' => 'nullable|string|in:'.implode(',', self::ROLES),
        ]);

        $session = MabarSession::findOrFail($id);
        if (in_array($session->status, ['closed', 'expired', 'cancelled'])) {
            return response()->json(['message' => 'Session is no longer available'], 422);
        }

        $userId = $request->user()->id;

        // already in session?
        $existing = MabarSlot::where('session_id', $id)
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->first();
        if ($existing) {
            return response()->json(['message' => 'You already joined this session'], 422);
        }

        $slot = null;
        if (! empty($data['slot_id'])) {
            $slot = MabarSlot::where('id', $data['slot_id'])->where('session_id', $id)->first();
            if (! $slot || $slot->status !== 'open' || $slot->user_id) {
                return response()->json(['message' => 'Slot is not available'], 422);
            }
        } else {
            $slot = MabarSlot::where('session_id', $id)
                ->where('status', 'open')
                ->whereNull('user_id')
                ->orderBy('slot_index')
                ->first();
            if (! $slot) {
                return response()->json(['message' => 'No available slots'], 422);
            }
        }

        $slot->update([
            'user_id' => $userId,
            'status' => 'pending',
            'joined_at' => now(),
            'role_preference' => $data['role_preference'] ?? $slot->role_preference,
        ]);

        $this->refreshSessionStatus($session);

        MabarRoomController::emitSystem(
            $session,
            '🚪 '.($request->user()->name ?? 'A player').' requested to join the squad.',
            $userId
        );

        return response()->json(['message' => 'Requested to join', 'slot_id' => $slot->id]);
    }

    public function approve(Request $request, int $id, int $slotId): JsonResponse
    {
        $session = MabarSession::findOrFail($id);
        $this->authorizeHostOrAdmin($request, $session);

        $slot = MabarSlot::where('id', $slotId)->where('session_id', $id)->firstOrFail();
        if ($slot->status !== 'pending') {
            return response()->json(['message' => 'Slot is not pending'], 422);
        }
        $slot->update(['status' => 'confirmed']);
        $this->refreshSessionStatus($session);

        $name = $slot->user?->name ?? 'Player';
        MabarRoomController::emitSystem($session, '✅ '.$name.' joined the squad.', $slot->user_id);

        return response()->json(['message' => 'Slot confirmed']);
    }

    public function leave(Request $request, int $id): JsonResponse
    {
        $session = MabarSession::findOrFail($id);
        $userId = $request->user()->id;

        $slot = MabarSlot::where('session_id', $id)->where('user_id', $userId)->first();
        if (! $slot) {
            return response()->json(['message' => 'You are not in this session'], 422);
        }

        if ($session->host_user_id === $userId) {
            return response()->json(['message' => 'Host cannot leave. Cancel session instead.'], 422);
        }

        $name = $request->user()->name ?? 'A player';
        $slot->update([
            'user_id' => null,
            'status' => 'open',
            'joined_at' => null,
            'last_seen_at' => null,
        ]);

        $this->refreshSessionStatus($session);

        MabarRoomController::emitSystem($session, '👋 '.$name.' left the squad.', $userId);

        return response()->json(['message' => 'Left session']);
    }

    public function kick(Request $request, int $id, int $slotId): JsonResponse
    {
        $session = MabarSession::findOrFail($id);
        $this->authorizeHostOrAdmin($request, $session);

        $slot = MabarSlot::where('id', $slotId)->where('session_id', $id)->firstOrFail();
        if ($slot->slot_index === 1) {
            return response()->json(['message' => 'Cannot kick host slot'], 422);
        }

        $kickedName = $slot->user?->name ?? 'Player';
        $slot->update(['user_id' => null, 'status' => 'open', 'joined_at' => null, 'last_seen_at' => null]);
        $this->refreshSessionStatus($session);

        MabarRoomController::emitSystem($session, '🚫 '.$kickedName.' was removed by the host.');

        return response()->json(['message' => 'Player removed']);
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:live,closed,cancelled,open',
        ]);

        $session = MabarSession::findOrFail($id);
        $this->authorizeHostOrAdmin($request, $session);

        $session->update(['status' => $data['status']]);

        $labels = [
            'live' => '🟢 Session is now LIVE. Let\'s go!',
            'closed' => '🏁 Session has ended. GG!',
            'cancelled' => '❌ Session was cancelled by the host.',
            'open' => '🔓 Session reopened.',
        ];
        if (isset($labels[$data['status']])) {
            MabarRoomController::emitSystem($session, $labels[$data['status']]);
        }

        return response()->json(['message' => 'Status updated', 'status' => $session->status]);
    }

    public function feature(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $session = MabarSession::findOrFail($id);
        $session->update(['is_featured' => ! $session->is_featured]);
        return response()->json(['is_featured' => (bool) $session->is_featured]);
    }

    // -------------------- Ready signal --------------------

    public function readyNow(Request $request): JsonResponse
    {
        $active = MabarSignal::with('user:id,name,username,avatar_url,is_admin')
            ->where('active_until', '>', now())
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(fn ($s) => [
                'user' => [
                    'id' => $s->user->id,
                    'name' => $s->user->name,
                    'username' => $s->user->username,
                    'avatar_url' => $s->user->avatar_url,
                    'is_admin' => (bool) $s->user->is_admin,
                ],
                'active_until' => $s->active_until?->toIso8601String(),
                'mood_tag' => $s->mood_tag,
                'note' => $s->note,
                'minutes_left' => (int) max(0, now()->diffInMinutes($s->active_until, false)),
            ]);

        return response()->json(['data' => $active]);
    }

    public function setSignal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'duration_minutes' => 'nullable|integer|min:5|max:180',
            'mood_tag' => 'nullable|string|in:'.implode(',', self::VIBES),
            'note' => 'nullable|string|max:120',
        ]);

        $minutes = $data['duration_minutes'] ?? 30;

        $signal = MabarSignal::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'active_until' => now()->addMinutes($minutes),
                'mood_tag' => $data['mood_tag'] ?? null,
                'note' => $data['note'] ?? null,
            ]
        );

        return response()->json([
            'active_until' => $signal->active_until?->toIso8601String(),
            'mood_tag' => $signal->mood_tag,
            'note' => $signal->note,
            'minutes_left' => (int) max(0, now()->diffInMinutes($signal->active_until, false)),
        ]);
    }

    public function clearSignal(Request $request): JsonResponse
    {
        MabarSignal::where('user_id', $request->user()->id)->delete();
        return response()->json(['message' => 'Signal cleared']);
    }

    // -------------------- Rating --------------------

    public function rate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'to_user_id' => 'required|integer|exists:users,id',
            'stars' => 'required|integer|min:1|max:5',
            'tags' => 'nullable|array',
            'tags.*' => 'string|in:fun,clutch,chill,toxic,skilled,mvp',
            'comment' => 'nullable|string|max:240',
        ]);

        $session = MabarSession::findOrFail($id);
        $fromId = $request->user()->id;

        if ($fromId == $data['to_user_id']) {
            return response()->json(['message' => 'Cannot rate yourself'], 422);
        }

        // both must have been confirmed in the session
        $fromInSession = MabarSlot::where('session_id', $id)->where('user_id', $fromId)->where('status', 'confirmed')->exists()
            || $session->host_user_id === $fromId;
        $toInSession = MabarSlot::where('session_id', $id)->where('user_id', $data['to_user_id'])->where('status', 'confirmed')->exists()
            || $session->host_user_id === $data['to_user_id'];

        if (! $fromInSession || ! $toInSession) {
            return response()->json(['message' => 'Both users must have been in this session'], 422);
        }

        $rating = MabarRating::updateOrCreate(
            [
                'session_id' => $id,
                'from_user_id' => $fromId,
                'to_user_id' => $data['to_user_id'],
            ],
            [
                'stars' => $data['stars'],
                'tags' => $data['tags'] ?? [],
                'comment' => $data['comment'] ?? null,
            ]
        );

        return response()->json(['message' => 'Rating saved', 'rating' => $rating]);
    }

    // -------------------- My stats / badges --------------------

    public function myStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $hosted = MabarSession::where('host_user_id', $user->id)->count();
        $joined = MabarSlot::where('user_id', $user->id)->where('status', 'confirmed')->distinct('session_id')->count('session_id');
        $upcoming = MabarSession::where('starts_at', '>', now())
            ->where(function ($q) use ($user) {
                $q->where('host_user_id', $user->id)
                    ->orWhereHas('slots', fn ($s) => $s->where('user_id', $user->id)->whereIn('status', ['pending', 'confirmed']));
            })
            ->orderBy('starts_at')
            ->limit(5)
            ->get()
            ->map(fn ($s) => $this->presentSession($s, false, $user->id));

        $avgStars = MabarRating::where('to_user_id', $user->id)->avg('stars');
        $ratingCount = MabarRating::where('to_user_id', $user->id)->count();

        $badges = [];
        if ($hosted >= 10) $badges[] = ['key' => 'squad_leader', 'label' => 'Squad Leader', 'tier' => 'epic', 'description' => 'Hosted 10+ mabar sessions.'];
        elseif ($hosted >= 3) $badges[] = ['key' => 'session_starter', 'label' => 'Session Starter', 'tier' => 'rare', 'description' => 'Hosted 3+ mabar sessions.'];

        if ($joined >= 20) $badges[] = ['key' => 'loyal_squadmate', 'label' => 'Loyal Squadmate', 'tier' => 'legendary', 'description' => 'Joined 20+ mabar sessions.'];
        elseif ($joined >= 5) $badges[] = ['key' => 'team_player', 'label' => 'Team Player', 'tier' => 'rare', 'description' => 'Joined 5+ mabar sessions.'];

        if ($avgStars !== null && $avgStars >= 4.5 && $ratingCount >= 5) {
            $badges[] = ['key' => 'fan_favorite', 'label' => 'Fan Favorite', 'tier' => 'legendary', 'description' => 'Averaging 4.5★+ from teammates.'];
        }

        return response()->json([
            'hosted' => $hosted,
            'joined' => $joined,
            'avg_stars' => $avgStars ? round((float) $avgStars, 2) : null,
            'rating_count' => $ratingCount,
            'upcoming' => $upcoming,
            'badges' => $badges,
        ]);
    }

    // -------------------- Helpers --------------------

    private function validateSessionPayload(Request $request, bool $updating = false): array
    {
        $rules = [
            'title' => ($updating ? 'nullable' : 'required').'|string|max:120',
            'type' => ($updating ? 'nullable' : 'required').'|string|in:'.implode(',', self::TYPES),
            'vibe' => 'nullable|string|in:'.implode(',', self::VIBES),
            'rank_requirement' => 'nullable|string|in:'.implode(',', self::RANKS),
            'starts_at' => ($updating ? 'nullable' : 'required').'|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'recurrence' => 'nullable|string|in:'.implode(',', self::RECUR),
            'recurrence_days' => 'nullable|array',
            'recurrence_days.*' => 'string|in:mon,tue,wed,thu,fri,sat,sun',
            'max_slots' => 'nullable|integer|min:2|max:5',
            'voice_platform' => 'nullable|string|in:'.implode(',', self::VOICE),
            'discord_link' => 'nullable|string|max:255',
            'room_id' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:500',
            'slot_roles' => 'nullable|array|max:5',
            'slot_roles.*' => 'string|in:'.implode(',', self::ROLES),
        ];

        return $request->validate($rules);
    }

    private function authorizeHostOrAdmin(Request $request, MabarSession $session): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        if ($session->host_user_id !== $user->id && ! $user->is_admin) {
            abort(403, 'Only the host or admin can perform this action.');
        }
    }

    private function authorizeAdmin(Request $request): void
    {
        if (! $request->user() || ! $request->user()->is_admin) {
            abort(403, 'Admin only.');
        }
    }

    private function refreshSessionStatus(MabarSession $session): void
    {
        $session->load('slots');
        $filled = $session->slots->whereIn('status', ['pending', 'confirmed'])->count();
        $status = $session->status;

        if (in_array($status, ['live', 'closed', 'cancelled', 'expired'])) {
            return;
        }

        $newStatus = $filled >= $session->max_slots ? 'full' : 'open';
        if ($newStatus !== $session->status) {
            $session->update(['status' => $newStatus]);
        }
    }

    private function autoUpdateStatuses(): void
    {
        // Mark overdue sessions as expired
        MabarSession::whereIn('status', ['open', 'full'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->update(['status' => 'expired']);

        // Without ends_at: expire 3 hours after start
        MabarSession::whereIn('status', ['open', 'full'])
            ->whereNull('ends_at')
            ->where('starts_at', '<', now()->subHours(3))
            ->update(['status' => 'expired']);
    }

    private function presentSession(MabarSession $session, bool $detailed = false, ?int $viewerId = null): array
    {
        $slots = $session->slots ?? collect();
        $filled = $slots->whereIn('status', ['pending', 'confirmed'])->count();
        $pendingRequests = $slots->where('status', 'pending')->count();
        $synergyWithViewer = null;
        if ($viewerId && $session->host_user_id !== $viewerId) {
            $synergyWithViewer = $this->calculateSynergyBetween($viewerId, $session->host_user_id);
        }

        $data = [
            'id' => $session->id,
            'title' => $session->title,
            'type' => $session->type,
            'vibe' => $session->vibe,
            'rank_requirement' => $session->rank_requirement,
            'starts_at' => $session->starts_at?->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'recurrence' => $session->recurrence,
            'recurrence_days' => $session->recurrence_days,
            'max_slots' => (int) $session->max_slots,
            'filled_slots' => $filled,
            'pending_requests' => $pendingRequests,
            'status' => $session->status,
            'voice_platform' => $session->voice_platform,
            'discord_link' => $session->discord_link,
            'room_id' => $session->room_id,
            'notes' => $session->notes,
            'is_featured' => (bool) $session->is_featured,
            'invite_code' => (string) $session->id,
            'synergy_with_viewer' => $synergyWithViewer,
            'host' => $session->host ? [
                'id' => $session->host->id,
                'name' => $session->host->name,
                'username' => $session->host->username,
                'avatar_url' => $session->host->avatar_url,
                'is_admin' => (bool) $session->host->is_admin,
            ] : null,
            'slots' => $slots->map(fn ($slot) => [
                'id' => $slot->id,
                'slot_index' => (int) $slot->slot_index,
                'role_preference' => $slot->role_preference,
                'status' => $slot->status,
                'joined_at' => $slot->joined_at?->toIso8601String(),
                'user' => $slot->user ? [
                    'id' => $slot->user->id,
                    'name' => $slot->user->name,
                    'username' => $slot->user->username,
                    'avatar_url' => $slot->user->avatar_url,
                ] : null,
            ])->values(),
            'created_at' => $session->created_at?->toIso8601String(),
        ];

        if ($detailed && $session->ratings) {
            $data['ratings'] = $session->ratings->map(fn ($r) => [
                'id' => $r->id,
                'stars' => (int) $r->stars,
                'tags' => $r->tags ?? [],
                'comment' => $r->comment,
                'from_user' => $r->fromUser ? [
                    'id' => $r->fromUser->id,
                    'name' => $r->fromUser->name,
                    'username' => $r->fromUser->username,
                    'avatar_url' => $r->fromUser->avatar_url,
                ] : null,
                'to_user' => $r->toUser ? [
                    'id' => $r->toUser->id,
                    'name' => $r->toUser->name,
                    'username' => $r->toUser->username,
                    'avatar_url' => $r->toUser->avatar_url,
                ] : null,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values();
        }

        return $data;
    }

    private function calculateSynergyBetween(int $viewerId, int $hostId): ?array
    {
        if ($viewerId === $hostId) {
            return null;
        }

        $sharedSessionIds = DB::table('mabar_slots as s1')
            ->join('mabar_slots as s2', 's1.session_id', '=', 's2.session_id')
            ->join('mabar_sessions as ms', 'ms.id', '=', 's1.session_id')
            ->where('s1.user_id', $viewerId)
            ->where('s2.user_id', $hostId)
            ->where('s1.status', 'confirmed')
            ->where('s2.status', 'confirmed')
            ->whereIn('ms.status', ['closed', 'live'])
            ->pluck('s1.session_id')
            ->unique()
            ->values();

        $matches = $sharedSessionIds->count();
        if ($matches === 0) {
            return null;
        }

        $wins = DB::table('mabar_ratings')
            ->whereIn('session_id', $sharedSessionIds)
            ->where('to_user_id', $viewerId)
            ->where('stars', '>=', 4)
            ->count();

        $avgStars = DB::table('mabar_ratings')
            ->whereIn('session_id', $sharedSessionIds)
            ->where('to_user_id', $viewerId)
            ->avg('stars');

        return [
            'matches' => $matches,
            'wins_hint' => $wins,
            'avg_rating_hint' => $avgStars ? round((float) $avgStars, 2) : null,
        ];
    }
}
