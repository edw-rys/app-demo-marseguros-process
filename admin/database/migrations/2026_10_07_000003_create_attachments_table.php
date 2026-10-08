<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntos descargados. `sha256` alimenta la cache por hash (F-12) y
 * `extension_mismatch` evidencia el caso de un `.exe` renombrado (CU-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('email_id')->constrained('processed_emails')
                ->cascadeOnDelete();

            $table->string('filename');
            $table->string('local_path');
            $table->string('sha256', 64)->index();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mime')->nullable();

            $table->string('detected_kind')->nullable()
                ->comment('Tipo REAL por magic bytes (filetype)');
            $table->boolean('extension_mismatch')->default(false);
            $table->text('processing_error')->nullable();

            $table->string('doc_type')->nullable()->index();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->text('reasoning')->nullable()
                ->comment('Stack B: justificación del LLM. Se guarda para audit');
            $table->json('key_signals')->nullable();
            $table->json('score_breakdown')->nullable()
                ->comment('Stack A: score por regla. Explica la clasificación');
            $table->json('fields_json')->nullable();

            $table->string('text_path')->nullable();
            $table->longText('extracted_text')->nullable()
                ->comment('Texto completo para el visor del admin (RF-04)');
            $table->boolean('ocr_used')->default(false);
            $table->string('ocr_engine')->nullable()
                ->comment('tesseract | paddleocr | null');
            $table->decimal('ocr_confidence', 5, 2)->nullable();

            $table->timestamps();

            $table->index(['email_id', 'doc_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};