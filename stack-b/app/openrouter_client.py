"""Cliente de OpenRouter con JSON mode, reintentos y traza de auditoría.

Usa el endpoint OpenAI-compatible de OpenRouter
(`POST {base_url}/chat/completions`) con `response_format={"type":"json_object"}`.

Nota: el §8.2 del requerimiento muestra una URL con la forma nativa de Gemini
(`/v1beta/models/...:generateContent`) como si fuera OpenRouter. No lo es:
el endpoint real de OpenRouter es el de abajo.
"""

from __future__ import annotations

import asyncio
import json
import logging
import time
from typing import Any

import httpx

from app.config import settings
from app.models import LlmTrace

log = logging.getLogger(__name__)

# Mensaje de sistema base. El prompt concreto de cada tarea lo sobreescribe;
# esto fija el tono y el idioma, que en v1 es solo español.
_BASE_SYSTEM = (
    "Eres un asistente de análisis documental para un broker de seguros en Ecuador. "
    "Respondes SIEMPRE en español y SIEMPRE con JSON válido, sin texto adicional "
    "alrededor, sin markdown y sin bloques de código."
)


class LlmError(RuntimeError):
    """Error de la llamada al LLM. Lo captura Laravel para activar el fallback."""

    def __init__(self, message: str, *, status: int | None = None, retryable: bool = True):
        super().__init__(message)
        self.status = status
        self.retryable = retryable


class OpenRouterClient:
    """Envoltorio sobre `httpx.AsyncClient` con reintentos y trazas."""

    def __init__(self) -> None:
        self.traces: list[LlmTrace] = []

    # ── Interno ───────────────────────────────────────────────────────────────

    def _headers(self) -> dict[str, str]:
        if not settings.openrouter_api_key:
            raise LlmError(
                "OPENROUTER_API_KEY no está configurada", retryable=False
            )
        return {
            "Authorization": f"Bearer {settings.openrouter_api_key}",
            "Content-Type": "application/json",
            # OpenRouter usa estos headers para atribuir la app en su dashboard.
            # Los headers HTTP son byte-a-byte ASCII: un guion largo (—) o una
            # tilde aquí hacen fallar la petición entera con UnicodeEncodeError.
            "HTTP-Referer": "https://marseguros.com",
            "X-Title": "Gmail Docs Validator - Stack B",
        }

    def _content_for(
        self, text: str, file_b64: str | None, mime: str | None
    ) -> list[dict[str, Any]]:
        """Arma el array `content` de un mensaje multimodal.

        PDF e imagen viajan como `file` inline; el resto ya viene como texto
        porque no tiene sentido (o no se puede) mandarlos binarios (RF-04).
        """
        if not file_b64 or not mime:
            return [{"type": "text", "text": text}]

        return [
            {"type": "text", "text": text},
            {
                "type": "file",
                "file": {
                    "filename": "documento",
                    "file_data": f"data:{mime};base64,{file_b64}",
                },
            },
        ]

    def _estimate_cost(self, prompt_tokens: int, completion_tokens: int) -> float:
        tokens = prompt_tokens + completion_tokens
        return round(tokens / 1_000_000 * settings.llm_price_per_mtok, 8)

    # ── API pública ───────────────────────────────────────────────────────────

    async def complete_json(
        self,
        *,
        purpose: str,
        user_prompt: str,
        system_prompt: str | None = None,
        file_b64: str | None = None,
        mime: str | None = None,
    ) -> tuple[dict[str, Any], LlmTrace]:
        """Hace una llamada con JSON mode y devuelve `(json, trace)`.

        Lanza `LlmError` si la llamada falla tras `LLM_MAX_RETRIES` reintentos
        o si la respuesta no es JSON parseable.
        """
        payload: dict[str, Any] = {
            "model": settings.openrouter_model,
            "temperature": settings.llm_temperature,
            "max_tokens": settings.llm_max_tokens,
            "response_format": {"type": "json_object"},
            "messages": [
                {
                    "role": "system",
                    "content": system_prompt or _BASE_SYSTEM,
                },
                {
                    "role": "user",
                    "content": self._content_for(user_prompt, file_b64, mime),
                },
            ],
        }
        raw_request = json.dumps(payload, ensure_ascii=False)

        last_error: str | None = None
        attempts = settings.llm_max_retries + 1

        async with httpx.AsyncClient(
            timeout=settings.llm_timeout_sec
        ) as client:
            for attempt in range(1, attempts + 1):
                trace = LlmTrace(
                    purpose=purpose,
                    model=settings.openrouter_model,
                    raw_request=raw_request,
                )
                started = time.perf_counter()

                try:
                    response = await client.post(
                        f"{settings.openrouter_base_url.rstrip('/')}/chat/completions",
                        headers=self._headers(),
                        json=payload,
                    )
                    trace.latency_ms = int(
                        (time.perf_counter() - started) * 1000
                    )

                    if response.status_code >= 400:
                        trace.raw_response = response.text
                        retryable = response.status_code in {
                            408, 409, 425, 429, 500, 502, 503, 504,
                        }
                        raise LlmError(
                            f"OpenRouter respondió {response.status_code}: {response.text[:300]}",
                            status=response.status_code,
                            retryable=retryable,
                        )

                    data = response.json()
                    trace.raw_response = json.dumps(data, ensure_ascii=False)
                    usage = data.get("usage") or {}
                    trace.prompt_tokens = int(usage.get("prompt_tokens") or 0)
                    trace.completion_tokens = int(
                        usage.get("completion_tokens") or 0
                    )
                    trace.cost_usd = self._estimate_cost(
                        trace.prompt_tokens, trace.completion_tokens
                    )

                    parsed = self._extract_json(data)
                    trace.parsed_response = json.dumps(parsed, ensure_ascii=False)
                    trace.error = None
                    self.traces.append(trace)
                    return parsed, trace

                except LlmError as exc:
                    last_error = str(exc)
                    trace.error = last_error
                    if not exc.retryable or attempt == attempts:
                        self.traces.append(trace)
                        raise
                except (httpx.TimeoutException, httpx.TransportError) as exc:
                    last_error = f"Error de red hacia OpenRouter: {exc}"
                    trace.error = last_error
                    trace.latency_ms = int((time.perf_counter() - started) * 1000)
                    if attempt == attempts:
                        self.traces.append(trace)
                        raise LlmError(last_error) from exc
                except (json.JSONDecodeError, KeyError, IndexError, TypeError) as exc:
                    last_error = f"Respuesta ilegible de OpenRouter: {exc}"
                    trace.error = last_error
                    if attempt == attempts:
                        self.traces.append(trace)
                        raise LlmError(last_error) from exc

                # Backoff exponencial entre reintentos.
                delay = 2**attempt
                log.warning(
                    "Reintento %d/%d para '%s' en %ds: %s",
                    attempt, attempts - 1, purpose, delay, last_error,
                )
                await asyncio.sleep(delay)

        raise LlmError(last_error or "Fallo desconocido de OpenRouter")

    @staticmethod
    def _extract_json(data: dict[str, Any]) -> dict[str, Any]:
        """Saca el objeto JSON del envelope de chat/completions.

        Los modelos a veces envuelven el JSON en ```json ... ``` o lo preceden
        con texto, así que se recorta antes de parsear.
        """
        try:
            content = data["choices"][0]["message"]["content"]
        except (KeyError, IndexError, TypeError) as exc:
            raise ValueError(f"Envelope sin contenido: {str(data)[:300]}") from exc

        text = (content or "").strip()
        if text.startswith("```"):
            text = text.split("```")[1]
            if text.lower().startswith("json"):
                text = text[4:]
        text = text.strip()

        try:
            parsed = json.loads(text)
        except json.JSONDecodeError:
            # Último recurso: el primer objeto JSON embebido en el texto.
            start, end = text.find("{"), text.rfind("}")
            if start == -1 or end <= start:
                raise
            parsed = json.loads(text[start : end + 1])

        if not isinstance(parsed, dict):
            raise ValueError(f"La respuesta no es un objeto JSON: {type(parsed)}")
        return parsed


# Instancia compartida. `traces` se limpia tras cada request en `main.py`.
client = OpenRouterClient()


def reset_traces() -> None:
    client.traces.clear()