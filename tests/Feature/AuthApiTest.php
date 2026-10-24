<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_login(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Student Test',
            'email' => 'student@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
        ])->assertCreated()->assertJsonStructure(['user', 'token', 'token_type']);

        $this->assertDatabaseHas('users', ['email' => 'student@example.com']);

        $login = $this->postJson('/api/v1/auth/login', [
            'identity' => 'student@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['user', 'token', 'token_type']);

        $this->actingAs(User::where('email', 'student@example.com')->first())
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('email', 'student@example.com');
    }
}