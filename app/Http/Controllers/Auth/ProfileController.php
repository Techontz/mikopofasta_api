<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfilePhotoRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A member of staff's own profile.
 *
 * Every route here acts on `$request->user()` and takes no user id. That is
 * the whole access-control story: there is no parameter to tamper with, so
 * "can I edit someone else's profile" is not a question this controller can be
 * asked. Managing *other* people stays where it already is — UserController,
 * behind the admin grants — and nothing there changed.
 */
final class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/auth/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $user->load(['role', 'branch', 'zone', 'region', 'staffProfile']);

        return ApiResponse::data(new ProfileResource($user));
    }

    /**
     * PATCH /api/v1/auth/profile
     *
     * Only the keys UpdateProfileRequest declares survive validation, so the
     * organisational columns are unreachable from here by construction rather
     * than by a check. See that class for why it is written that way.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->actor($request);
        $changes = $request->columns();

        if ($changes === []) {
            return ApiResponse::data(new ProfileResource($user->load(['role', 'branch', 'zone', 'region', 'staffProfile'])));
        }

        DB::transaction(function () use ($user, $changes): void {
            /* Only what actually moved is audited — a save that changed
               nothing should not read as an edit a year later. */
            $before = [];
            foreach (array_keys($changes) as $column) {
                $before[$column] = $user->getAttribute($column);
            }

            $user->fill($changes)->save();
            $dirty = array_intersect_key($before, $user->getChanges()) ?: $before;

            $this->audit->log(
                AuditAction::UserProfileUpdated,
                $user,
                before: $dirty,
                after: array_intersect_key($changes, $dirty),
                actor: $user,
            );
        });

        return ApiResponse::data(
            new ProfileResource($user->fresh(['role', 'branch', 'zone', 'region', 'staffProfile'])),
        );
    }

    /**
     * POST /api/v1/auth/profile/photo
     *
     * Stored on the private disk beside every other photograph this system
     * holds. A staff portrait is personal data on the same terms as a
     * customer's, so it gets the same treatment: never a public path, only a
     * signed and expiring URL.
     */
    public function updatePhoto(UpdateProfilePhotoRequest $request): JsonResponse
    {
        $user = $this->actor($request);
        $photo = $request->file('photo');

        abort_unless($photo instanceof UploadedFile, Response::HTTP_UNPROCESSABLE_ENTITY);

        $previous = $user->photo_path;

        $path = $photo->storeAs(
            'users/'.$user->getKey(),
            sprintf('avatar-%s.%s', Str::random(24), $photo->extension() ?: 'jpg'),
            ['disk' => KycDocumentStorage::DISK],
        );

        DB::transaction(function () use ($user, $path, $previous): void {
            $user->forceFill(['photo_path' => $path])->save();

            $this->audit->log(
                AuditAction::UserProfileUpdated,
                $user,
                before: ['photo' => $previous === null ? null : 'set'],
                after: ['photo' => 'replaced'],
                actor: $user,
            );
        });

        /* The old portrait is genuinely replaced. Unlike a KYC face scan, an
           avatar is not evidence of anything and there is no reason to keep
           every one a person has ever chosen. */
        if ($previous !== null && $previous !== $path) {
            Storage::disk(KycDocumentStorage::DISK)->delete($previous);
        }

        return ApiResponse::data(
            new ProfileResource($user->fresh(['role', 'branch', 'zone', 'region', 'staffProfile'])),
        );
    }

    /**
     * DELETE /api/v1/auth/profile/photo
     *
     * Removes the portrait and the file behind it. The avatar falls back to
     * initials, which is what every screen already renders for a user who
     * never uploaded one — so removal returns the account to a state the UI
     * has always handled rather than a new empty one.
     */
    public function destroyPhoto(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $previous = $user->photo_path;

        if ($previous === null) {
            return ApiResponse::data(
                new ProfileResource($user->load(['role', 'branch', 'zone', 'region', 'staffProfile'])),
            );
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['photo_path' => null])->save();

            $this->audit->log(
                AuditAction::UserProfileUpdated,
                $user,
                before: ['photo' => 'set'],
                after: ['photo' => 'removed'],
                actor: $user,
            );
        });

        Storage::disk(KycDocumentStorage::DISK)->delete($previous);

        return ApiResponse::data(
            new ProfileResource($user->fresh(['role', 'branch', 'zone', 'region', 'staffProfile'])),
        );
    }

    /**
     * GET /api/v1/auth/profile/security
     *
     * What the Security tab needs, assembled from records that already exist:
     * the audit trail for the password and sign-in history, and Sanctum's
     * token table for what is currently signed in. Nothing new is stored — a
     * second copy of "when did you last log in" would only be a second thing
     * to get out of step.
     */
    public function security(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        $lastOf = fn (AuditAction $action) => AuditLog::query()
            ->where('user_id', $user->getKey())
            ->where('action', $action->value)
            ->latest('created_at')
            ->first();

        $passwordChanged = $lastOf(AuditAction::PasswordChanged);
        $lastLogin = $lastOf(AuditAction::UserLoggedIn);

        /*
         * A failed attempt has no user_id — nobody was authenticated — so it
         * is recorded against the identifier that was tried. That is what
         * makes "somebody is guessing at my account" visible at all.
         */
        $lastFailed = AuditLog::query()
            ->where('action', AuditAction::UserLoginFailed->value)
            ->whereJsonContains('after_json->identifier', $user->phone)
            ->latest('created_at')
            ->first();

        $current = $request->user()?->currentAccessToken();

        $sessions = $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token): array => [
                'id' => (string) $token->getKey(),
                'name' => $token->name,
                'createdAt' => $token->created_at?->toIso8601String(),
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                /* Which row is the browser asking the question. */
                'current' => $current !== null && $token->getKey() === $current->getKey(),
            ])
            ->all();

        return ApiResponse::data([
            'passwordChangedAt' => $passwordChanged?->created_at->toIso8601String(),
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
            'lastLoginIp' => $lastLogin?->ip_address,
            'lastFailedLoginAt' => $lastFailed?->created_at->toIso8601String(),
            'lastFailedLoginIp' => $lastFailed?->ip_address,
            'sessions' => $sessions,
            /*
             * Stated rather than implied. A Security tab with no mention of
             * two-factor reads as "this system has no opinion"; saying it is
             * not built is the honest version, and it is not enabled anywhere
             * in this codebase.
             */
            'twoFactor' => ['enabled' => false, 'available' => false],
        ]);
    }

    /**
     * POST /api/v1/auth/sessions/revoke-others
     *
     * Signs every other device out and leaves this one alone — the reason
     * somebody clicks it is that they are worried about a device they are not
     * holding, so logging themselves out too would be the one outcome that
     * does not help.
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $current = $request->user()?->currentAccessToken();

        $revoked = $user->tokens()
            ->when($current !== null, fn ($q) => $q->where('id', '!=', $current->getKey()))
            ->delete();

        $this->audit->log(
            AuditAction::UserSessionsRevoked,
            $user,
            after: ['revoked' => $revoked],
            actor: $user,
        );

        return ApiResponse::data([
            'revoked' => $revoked,
            'message' => $revoked === 0
                ? 'No other sessions were signed in.'
                : "Signed out {$revoked} other session".($revoked === 1 ? '.' : 's.'),
        ]);
    }

    /**
     * GET /api/v1/auth/profile/activity
     *
     * This account's own history, read straight from `audit_logs`. Scoped to
     * `user_id` — a person sees what they did, not what was done to records
     * they can see, which is a different question and a different screen.
     */
    public function activity(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        $limit = min(100, max(1, (int) $request->query('limit', '30')));

        $entries = AuditLog::query()
            ->where('user_id', $user->getKey())
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => (string) $log->getKey(),
                'action' => $log->action,
                'auditableType' => class_basename($log->auditable_type),
                'auditableId' => (string) $log->auditable_id,
                'ipAddress' => $log->ip_address,
                'userAgent' => $log->user_agent,
                'at' => $log->created_at->toIso8601String(),
            ])
            ->all();

        return ApiResponse::data($entries);
    }

    /**
     * GET /api/v1/users/{user}/photo — signed, outside Sanctum.
     *
     * An <img> cannot carry a bearer token, so the signature is the
     * credential. The link expires in minutes and is minted only into a
     * profile response the user was already authorised to read.
     */
    public function photo(User $user): StreamedResponse
    {
        abort_if($user->photo_path === null, Response::HTTP_NOT_FOUND);

        $disk = Storage::disk(KycDocumentStorage::DISK);
        abort_unless($disk->exists($user->photo_path), Response::HTTP_NOT_FOUND);

        $path = $user->photo_path;

        return response()->streamDownload(
            function () use ($disk, $path): void {
                $stream = $disk->readStream($path);

                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            'avatar-'.$user->getKey().'.jpg',
            [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }
}
