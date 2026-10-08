<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultados de validación, respuestas enviadas, trazas del LLM y cache por hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('processed_emails')
                ->cascadeOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')
                ->nullOnDelete()
                ->comment('NULL = regla a nivel de correo, p.ej. Fase 1');

            $table->string('rule_name');
            $table->string('status')->index()
                ->comment('pass | fail | warn | error');
            $table->string('severity')->default('error')
                ->comment('error | warning');
            $table->string('code')->nullable();
            $table->text('message')->nullable();
            $table->json('facts_json')->nullable()
                ->comment('Facts evaluados: re-ejecutable sin volver a extraer');
            $table->timestamps();

            $table->index(['email_id', 'rule_name']);
        });

        Schema::create('email_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('processed_emails')
                ->cascadeOnDelete();
            $table->string('template')
                ->comment('missing_documents | validated | issues | clarify');
            $table->string('source')->default('template')
                ->comment('template | llm');
            $table->longText('body');
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('pending')
                ->comment('pending | sent | dry_run | failed');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // ── Stack B: auditoría de llamadas al LLM (RNF-04) ───────────────────
        Schema::create('llm_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->nullable()->constrained('processed_emails')
                ->nullOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')
                ->nullOnDelete();

            $table->string('purpose')
                ->comment('classify | extract_<tipo> | validate | generate_reply');
            $table->string('model');
            $table->string('prompt_name')->nullable()
                ->comment('Prompt de validation_prompts usado, si aplica');

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost_usd', 12, 8)->default(0);

            $table->unsignedInteger('latency_ms')->default(0);
            $table->boolean('cache_hit')->default(false)->index();
            $table->boolean('degraded')->default(false)
                ->comment('true si la llamada falló y se usó el fallback local');

            $table->longText('raw_request')->nullable();
            $table->longText('raw_response')->nullable();
            $table->longText('parsed_response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'purpose']);
        });

        // ── Cache por hash de archivo (F-12) ─────────────────────────────────
        Schema::create('analysis_cache', function (Blueprint $table) {
            $table->id();
            $table->string('sha256', 64);
            $table->string('stack', 1)->default('a');
            $table->json('payload_json');
            $table->timestamps();

            // Único por hash+stack: el mismo archivo puede estar analizado en A y en B.
            $table->unique(['sha256', 'stack']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_cache');
        Schema::dropIfExists('llm_calls');
        Schema::dropIfExists('email_responses');
        Schema::dropIfExists('validation_results');
    }
};