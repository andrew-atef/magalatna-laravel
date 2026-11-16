<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Filament Admin panel access — explicit allowlist authorization.
     *
     * The users table has NO is_admin/role column, so authorization is driven
     * by config('auth.panel.admin_emails') (set FILAMENT_ADMIN_EMAILS in .env,
     * comma-separated). When the allowlist is configured, ONLY those emails
     * pass. When it is not configured, the fallback requires a verified email
     * address — this app has no self-registration, so the users table only
     * contains manually created accounts.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        $email = strtolower(trim((string) $this->email));

        if ($email === '') {
            return false;
        }

        $allowlist = array_values(array_filter(array_map(
            static fn (string $e): string => strtolower(trim($e)),
            explode(',', (string) config('auth.panel.admin_emails', '')),
        )));

        if ($allowlist !== []) {
            return in_array($email, $allowlist, true);
        }

        // Fail closed in production: an UNCONFIGURED allowlist must never
        // grant panel access to any verified account (this app has no
        // self-registration, but a leaked verified credential must not equal
        // admin). Local/dev keeps the verified-email fallback so fixtures
        // and seeded accounts still work. Set FILAMENT_ADMIN_EMAILS.
        return app()->isProduction()
            ? false
            : $this->email_verified_at !== null;
    }
}
