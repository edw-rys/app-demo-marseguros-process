<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un correo recibido por el watcher: la unidad de trabajo del pipeline.
 * `gmail_id` es única para que reprocesar el mismo correo no lo duplique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_emails', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('gmail_id')->unique();
            $table->string('thread_id')->nullable();
            $table->string('mailbox')->index()
                ->comment('Buzón de GOOGLE_MAILBOXES donde llegó');

            $table->string('subject')->nullable();
            $table->string('sender')->nullable();
            $table->string('sender_name')->nullable();
            $table->text('snippet')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->string('status')->default('pending')->index()
                ->comment('pending | processing | validated | failed | review');
            $table->string('pipeline_stack')->default('a')->index()
                ->comment('a = OCR local, b = LLM. Cuál procesó este job');
            $table->string('current_stage')->default('received')
                ->comment('Última etapa alcanzada, para el listado del admin');

            $table->boolean('subject_matched')->default(true);
            $table->json('metadata_json')->nullable()
                ->comment('Headers crudos del mensaje, tal como los pedía RF-02');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_emails');
    }
};