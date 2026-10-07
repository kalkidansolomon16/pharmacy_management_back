<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterCustomerRequest;
use App\Http\Requests\Auth\RegisterOrganizationRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Auth\RegistrationService;
use App\Support\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private RegistrationService $registration, private ActivityLogger $logger) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', strtolower($request->input('email')))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json([
                'message' => 'The email or password is incorrect.',
                'errors' => ['email' => ['The email or password is incorrect.']],
            ], 422);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => "This account is {$user->status}. Contact your administrator."], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        Auth::setUser($user);
        $this->logger->log('login', $user, [], "{$user->name} signed in");

        return $this->tokenResponse($user, $request->input('device_name', 'web'));
    }

    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $user = $this->registration->registerCustomer($request->validated());

        return $this->tokenResponse($user, 'web', 201, 'Welcome to MedLink Ethiopia!');
    }

    public function registerOrganization(RegisterOrganizationRequest $request): JsonResponse
    {
        $user = $this->registration->registerOrganization($request->validated());

        return $this->tokenResponse(
            $user, 'web', 201,
            'Registration received. Our team will verify your licence within 1-2 working days.'
        );
    }

    public function me(Request $request): JsonResponse
    {
        return $this->respond($this->profile($request->user()));
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['phone'] = ReferenceGenerator::normalizePhone($data['phone'] ?? null);
        $request->user()->update($data);

        return $this->respond($this->profile($request->user()), 'Profile updated.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->input('password')]);
        // Sign out every other device
        $user->tokens()->whereKeyNot($user->currentAccessToken()->id)->delete();
        $this->logger->log('password_changed', $user);

        return $this->respond(null, 'Password changed. Other devices have been signed out.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->respond(null, 'Signed out successfully.');
    }

    private function tokenResponse(User $user, string $device, int $status = 200, ?string $message = null): JsonResponse
    {
        $token = $user->createToken($device)->plainTextToken;

        return response()->json(array_filter([
            'message' => $message,
            'data' => ['token' => $token, 'user' => $this->profile($user)],
        ]), $status);
    }

    /**
     * The user plus everything the SPA needs for role-based rendering.
     */
    private function profile(User $user): array
    {
        $user->loadMissing('tenant', 'roles');

        return (new UserResource($user))->resolve() + [
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
