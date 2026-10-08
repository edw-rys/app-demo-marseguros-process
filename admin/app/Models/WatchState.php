<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cursor del watcher para un buzón (RF-01).
 *
 * @property string $user_email
 * @property string $history_id
 * @property \Illuminate\Support\Carbon|null $last_poll_at
 */
class WatchState extends Model
{
    use HasFactory;

    protected $table = 'watch_states';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_poll_at' => 'datetime',
        ];
    }

    /** Crea el estado de un buzón la primera vez que se sincroniza. */
    public static function ensureFor(string $mailbox): self
    {
        return static::query()->firstOrCreate(
            ['user_email' => $mailbox],
            ['history_id' => '1'],
        );
    }
}