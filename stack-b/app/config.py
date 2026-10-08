"""Configuración del Stack B (OpenRouter + Gemini Flash Lite)."""

from __future__ import annotations

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # ── Seguridad ──────────────────────────────────────────────────────────────
    analyzer_token: str = ""

    # ── OpenRouter ─────────────────────────────────────────────────────────────
    openrouter_api_key: str = ""
    openrouter_model: str = "google/gemini-2.0-flash-lite"
    openrouter_base_url: str = "https://openrouter.ai/api/v1"

    # temperature=0 es lo que hace el pipeline reproducible (RNF-04).
    llm_temperature: float = 0.0
    llm_max_tokens: int = 1024
    llm_timeout_sec: int = 30
    llm_max_retries: int = 2

    # Precio en USD por millón de tokens (input+output mezclados). Solo se usa
    # para contabilizar el costo en `llm_calls`, no para decidir nada.
    llm_price_per_mtok: float = 0.14

    # ── Comportamiento ─────────────────────────────────────────────────────────
    # RF-05: confidence < 0.5 → "desconocido"
    classify_min_confidence: float = 0.5
    # RF-09: el texto generado por el LLM pasa por filtro de longitud.
    reply_max_chars: int = 800

    cache_enabled: bool = True

    # Límite de caracteres de texto plano que se manda en el prompt para
    # XLSX/DOCX/CSV (RF-04). PDF e imagen van binarios, no pasan por aquí.
    max_text_chars: int = 60_000


settings = Settings()