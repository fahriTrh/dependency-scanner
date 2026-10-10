<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_page_renders(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Buat akun baru');
    }

    public function test_register_creates_user_and_logs_in(): void
    {
        $response = $this->post('/register', [
            'name' => 'User A',
            'email' => 'a@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);

        $response->assertRedirect(route('projects.index'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'a@example.com']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $response = $this->from('/register')->post('/register', [
            'name' => 'User B',
            'email' => 'a@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'a@example.com',
            'password' => 'rahasia123',
        ]);

        $response = $this->post('/login', [
            'email' => 'a@example.com',
            'password' => 'rahasia123',
        ]);

        $response->assertRedirect(route('projects.index'));
        $this->assertAuthenticated();
    }

    public function test_login_with_wrong_password_fails(): void
    {
        User::factory()->create([
            'email' => 'a@example.com',
            'password' => 'rahasia123',
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'a@example.com',
            'password' => 'salah',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_ends_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_guest_cannot_access_projects(): void
    {
        $this->get('/projects')->assertRedirect(route('login'));
        $this->get('/projects/create')->assertRedirect(route('login'));
        $this->postJson('/projects')->assertStatus(401);
    }

    public function test_logged_user_redirected_from_login_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/login')
            ->assertRedirect(route('projects.index'));
    }
}
