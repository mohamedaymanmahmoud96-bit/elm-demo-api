<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
    use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['sometimes', Rule::in(['student', 'parent'])],
            'grade' => ['nullable', 'string', 'max:80'],
            'governorate' => ['nullable', 'string', 'max:80'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'school_name' => ['nullable', 'string', 'max:180'],
            'parent_name' => ['nullable', 'string', 'max:120'],
            'parent_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $role = $data['role'] ?? 'student';
        if ($role === 'student' && empty($data['parent_phone'])) {
            return response()->json([
                'message' => 'رقم ولي الأمر مطلوب.',
                'errors' => ['parent_phone' => ['أدخل رقم ولي الأمر.']],
            ], 422);
        }

        if (empty($data['email']) && empty($data['phone'])) {
            return response()->json([
                'message' => 'رقم هاتف الطالب مطلوب.',
                'errors' => ['phone' => ['أدخل رقم هاتف الطالب.']],
            ], 422);
        }

        $data['role'] = $role;
        $data['account_status'] = $role === 'student' ? 'pending' : 'active';
        $data['access_code'] = $this->newStudentCode();
        $data['password'] ??= Str::random(32);
        $user = User::create($data);

        return response()->json($this->tokenResponse($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identity' => ['required', 'string'],
            'password' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()
            ->where(function ($query) use ($data) {
                $query->where('email', $data['identity'])
                    ->orWhere('phone', $data['identity'])
                    ->orWhere('access_code', $data['identity']);
            })
            ->first();

        if (!$user) {
            return response()->json(['message' => 'بيانات الدخول غير صحيحة.'], 401);
        }

        if ($user->role === 'student') {
            if (!preg_match('/^\d{8}$/', $data['identity']) || $user->access_code !== $data['identity']) {
                return response()->json(['message' => 'الطلاب يسجلون الدخول بكود من 8 أرقام فقط.'], 401);
            }
        } elseif (empty($data['password']) || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'بيانات الدخول غير صحيحة.'], 401);
        }

        $token = $user->createToken($data['device_name'] ?? 'flutter-app', [$user->role])->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح.']);
    }

    private function tokenResponse(User $user): array
    {
        return [
            'user' => $user,
            'token' => $user->createToken('flutter-app', [$user->role])->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }

    private function newStudentCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        } while (User::where('access_code', $code)->exists());

        return $code;
    }
}