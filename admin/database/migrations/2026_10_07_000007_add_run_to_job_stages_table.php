<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columna `run`: número de corrida del pipeline sobre un mismo correo.
 *
 * Antes se agrupaban las corridas detectando un reinicio de `sequence`, pero
 * `StageRecorder` continúa la numeración desde `max('sequence')`: al
 * reprocesar, la segunda corrida empieza en un número MÁS alto que el final de
 * la primera, así que `sequence` nunca baja y todas las corridas se leían como
 * una sola. El admin mostraba un timeline infinito sin poder auditar qué pasó
 * en cada intento.
 *
 * Un contador explícito resuelve el caso y además ordena las corridas de forma
 * trivial en el listado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_stages', function (Blueprint $table) {
            $table->unsignedSmallInteger('run')->default(1)
                ->after('email_id')
                ->comment('Corrida del pipeline; 1 = ejecución original');

            // El admin agrupa y ordena por (email_id, run, sequence).
            $table->index(['email_id', 'run', 'sequence'], 'job_stages_email_run_sequence_index');
        });
    }

    public function down(): void
    {
        Schema::table('job_stages', function (Blueprint $table) {
            $table->dropIndex('job_stages_email_run_sequence_index');
            $table->dropColumn('run');
        });
    }
};