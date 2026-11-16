<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowlisted_email_can_access_panel(): void
    {
        config(['auth.panel.admin_emails' => 'owner@magalatna.com, dev@magalatna.com']);

        $user = User::forceCreate([
            'name' => 'Owner',
            'email' => 'OWNER@magalatna.com', // case-insensitive match
            'password' => 'password',
            'email_verified_at' => null,
        ]);

        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_non_allowlisted_email_is_denied_even_if_verified(): void
    {
        config(['auth.panel.admin_emails' => 'owner@magalatna.com']);

        $user = User::forceCreate([
            'name' => 'Random',
            'email' => 'random@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($user->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_verified_email_fallback_when_no_allowlist_configured(): void
    {
        config(['auth.panel.admin_emails' => '']);

        $verified = User::forceCreate([
            'name' => 'Verified',
            'email' => 'v@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        $unverified = User::forceCreate([
            'name' => 'Unverified',
            'email' => 'u@example.com',
            'password' => 'password',
            'email_verified_at' => null,
        ]);

        $this->assertTrue($verified->canAccessPanel(filament()->getPanel('admin')));
        $this->assertFalse($unverified->canAccessPanel(filament()->getPanel('admin')));
    }
}
