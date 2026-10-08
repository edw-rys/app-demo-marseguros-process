<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado del watch de cada buzón. `history_id` se persiste para que el
 * watcher sobreviva a reinicios (RF-01, criterio CA-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_states', function (Blueprint $table) {
            $table->id();
            $table->string('user_email')->unique();
            $table->string('history_id')->default('1')
                ->comment('Cursor de users.history.list de Gmail');
            $table->timestamp('last_poll_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_states');
    }
};