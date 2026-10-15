<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TokenService;
use App\Support\Passwords;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private TokenService $tokens) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:filter', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            'password2' => ['required', 'string', 'max:128'],
            'first_name' => ['nullable', 'string', 'max:150'],
            'last_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $errors = [];
        $email = self::normalizeEmail($data['email']);
        if (User::query()->where('email', $email)->exists()) {
            $errors['email'][] = 'An account with this email already exists.';
        }
        $phone = isset($data['phone']) && $data['phone'] !== '' ? $data['phone'] : null;
        if ($phone && User::query()->where('phone', $phone)->exists()) {
            $errors['phone'][] = 'An account with this phone number already exists.';
        }
        if ($data['password'] !== $data['password2']) {
            $errors['password2'][] = 'Passwords do not match.';
        }
        if ($pw = Passwords::errors($data['password'], $email)) {
            $errors['password'] = $pw;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $user = new User([
                'email' => $email,
                'password' => Hash::make($data['password']),
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
                'phone' => $phone,
            ]);
            $user->role = Role::USER; // registration can NEVER create ADMIN/REPORTER (role is not mass-assignable)
            $user->is_active = true; // (DB default; set explicitly so the 201 body carries it, like Django)
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent registration: the DB unique indexes are the source of truth.
            throw ValidationException::withMessages(['email' => ['An account with this email already exists.']]);
        }

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:254'], 'password' => ['required', 'string', 'max:1024']]);

        $user = User::query()->where('email', trim($data['email']))->first();
        // Always spend one password hash, even for unknown e-mails, so response time does not reveal
        // which accounts exist.
        $passwordOk = false;
        if ($user) {
            $passwordOk = Hash::check($data['password'], $user->password);
        } else {
            Hash::make($data['password']);
        }
        if (! $user || ! $user->is_active || ! $passwordOk) {
            return response()->json(['detail' => 'Invalid email or password, or this account is inactive.'], 401);
        }

        // Legacy Django PBKDF2 hashes are upgraded to bcrypt on first login.
        if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($data['password']);
        }
        $user->last_login = now();
        $user->save();

        return response()->json($this->tokens->issuePair($user) + ['user' => (new UserResource($user))->resolve()]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $this->tokens->userForRefreshToken($request->input('refresh'));
        if (! $user) {
            return response()->json(['detail' => 'Token is invalid or expired', 'code' => 'token_not_valid'], 401);
        }

        // No rotation: the refresh token stays valid until its own expiry or logout.
        return response()->json(['access' => $this->tokens->issueAccess($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $refresh = $this->tokens->findRefreshToken($request->input('refresh'));
        if (! $refresh || $refresh->tokenable_id !== $user->id) {
            return response()->json(['detail' => 'Invalid or already-blacklisted refresh token.'], 400);
        }
        $refresh->delete();
        $user->currentAccessToken()?->delete();

        return response()->json(null, 205);
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()))->response();
    }

    /**
     * PATCH /auth/me/ - a signed-in user edits THEIR OWN profile: first/last name for everyone, and the public
     * "About the Author" bio for reporters and admins (the only roles that author articles). Email, role,
     * permissions and active state are never accepted here (login identity / privileges stay admin-managed), and a
     * phone number is changed only through the OTP verification flow.
     */
    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $clean = fn ($v) => trim(strip_tags((string) ($v ?? '')));

        if (array_key_exists('bio', $data) && $clean($data['bio']) !== '' && ! in_array($user->role, [Role::ADMIN, Role::REPORTER], true)) {
            throw ValidationException::withMessages(['bio' => ['Only reporters and administrators can add an author bio.']]);
        }

        $first = array_key_exists('first_name', $data) ? $clean($data['first_name']) : (string) $user->first_name;
        $last = array_key_exists('last_name', $data) ? $clean($data['last_name']) : (string) $user->last_name;
        if ($first === '' && $last === '') {
            throw ValidationException::withMessages(['first_name' => ['Please enter your name.']]);
        }

        $user->first_name = $first;
        $user->last_name = $last;
        if (array_key_exists('bio', $data)) {
            $bio = $clean($data['bio']);
            $user->bio = $bio !== '' ? $bio : null;
        }
        $user->save();

        return (new UserResource($user->refresh()))->response();
    }

    public static function normalizeEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', trim($email), 2), 2, '');

        return $local.'@'.mb_strtolower($domain);
    }
}
