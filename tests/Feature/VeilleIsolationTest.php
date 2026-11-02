<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class VeilleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_update_and_delete_his_veille(): void
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->for($user)->create();

        $this->assertTrue($user->can('view', $veille));
        $this->assertTrue($user->can('update', $veille));
        $this->assertTrue($user->can('delete', $veille));
    }

    public function test_user_cannot_view_another_users_veille(): void
    {
        $other = User::factory()->create();
        $veille = Veille::factory()->create();

        $this->assertFalse($other->can('view', $veille));
        $this->assertTrue(Gate::forUser($other)->denies('view', $veille));
    }

    public function test_user_cannot_update_another_users_veille(): void
    {
        $other = User::factory()->create();
        $veille = Veille::factory()->create();

        $this->assertFalse($other->can('update', $veille));
        $this->assertTrue(Gate::forUser($other)->denies('update', $veille));
    }

    public function test_user_cannot_delete_another_users_veille(): void
    {
        $other = User::factory()->create();
        $veille = Veille::factory()->create();

        $this->assertFalse($other->can('delete', $veille));
        $this->assertTrue(Gate::forUser($other)->denies('delete', $veille));
    }

    public function test_policy_checks_ownership_not_route_input(): void
    {
        // L'identifiant résolu depuis l'URL ne doit jamais suffire :
        // la Policy compare user_id du modèle à l'utilisateur authentifié.
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $veille = Veille::factory()->for($owner)->create();

        $this->assertTrue(Gate::forUser($owner)->allows('view', $veille));
        $this->assertTrue(Gate::forUser($other)->denies('view', $veille));
        $this->assertTrue(Gate::forUser($other)->denies('update', $veille));
        $this->assertTrue(Gate::forUser($other)->denies('delete', $veille));
    }
}
