<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración declarativa de ambos stacks.
 *
 * Estas tablas son la fuente de verdad: se editan en Filament y Laravel las
 * manda al worker Python en cada request, así que los cambios surten efecto sin
 * redeploy (criterio de hot reload de RF-07 A y RF-08 B).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Stack A: clasificación por keywords (RF-05) ──────────────────────
        Schema::create('doc_type_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->json('required_keywords')->nullable()
                ->comment('Ponderan 0.5 del score; basta uno para el máximo');
            $table->json('keywords')->nullable()
                ->comment('Keywords bonus. Ponderan 0.3 del score');
            $table->json('filename_hints')->nullable()
                ->comment('Ponderan 0.2 del score');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        // ── Stack A: extracción por regex (RF-06) ───────────────────────────
        Schema::create('field_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('regex')
                ->comment('Regex Python. Con captura; si no, se usa el match entero');
            $table->string('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        // ── Stack A: reglas de Fase 2 (RF-07) ──────────────────────────────
        Schema::create('validation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('json_rule')
                ->comment('Sintaxis node-json-rules-engine, ejecutada por python-rule-engine');
            $table->string('severity')->default('error')
                ->comment('error | warning. Solo error bloquea la aprobación');
            $table->json('doc_types')->nullable()
                ->comment('Tipos a los que aplica. NULL = todos');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        // ── Stack B: prompts versionados (RF-08) ────────────────────────────
        Schema::create('validation_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()
                ->comment('Ej: classify, extract_factura_v1, validate_poliza_v1');
            $table->text('prompt_text');
            $table->json('expected_schema')->nullable()
                ->comment('Shape JSON que el prompt debe producir');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_prompts');
        Schema::dropIfExists('validation_rules');
        Schema::dropIfExists('field_patterns');
        Schema::dropIfExists('doc_type_rules');
    }
};