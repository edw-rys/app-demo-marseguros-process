<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una fila por etapa de análisis ejecutada. Es la tabla que hace útil el admin:
 * el timeline de un job se lee directo de aquí, sin recalcular nada.
 *
 * Deliberadamente es append-only: reprocesar un job agrega filas nuevas en vez
 * de sobrescribir las viejas, para que la auditoría (RNF-04) muestre qué pasó
 * en cada intento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('processed_emails')
                ->cascadeOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')
                ->nullOnDelete()
                ->comment('NULL = etapa a nivel de correo');

            $table->string('stack', 1)->default('a');
            $table->string('stage_key')->index()
                ->comment('received | subject_filter | download | detect_kind | ...');
            $table->unsignedSmallInteger('sequence')->default(0)
                ->comment('Orden dentro de la ejecución; desempata al reordenar');
            $table->string('status')->index()
                ->comment('running | passed | failed | skipped | degraded');
            $table->string('severity')->default('info')
                ->comment('info | warning | error');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->json('detail_json')->nullable()
                ->comment('Payload de la etapa: inputs, scores, ids de trazabilidad');
            $table->text('message')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['email_id', 'sequence']);
            $table->index(['email_id', 'stage_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_stages');
    }
};