<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
     * ¿Puede este usuario entrar al panel de Filament?
     *
     * Sin este método Filament solo deja pasar en `APP_ENV=local`; en cualquier
     * otro entorno (incluido `testing`) responde 403. Para el demo basta con
     * que exista una sesión: el control fino por rol/permiso se haría con
     * Spatie (`$user->can('...')`) cuando el panel deje de ser de una sola persona.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}