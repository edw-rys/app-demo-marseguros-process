FROM python:3.13-slim

LABEL maintainer="marseguros" \
      org.opencontainers.image.title="Gmail Docs Validator · stack-b"

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# Este servicio no hace OCR: delega en OpenRouter. Solo necesita `curl` para el
# healthcheck y los certificados raíz para llamar a la API.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/app

COPY requirements.txt ./

# Sin capa opcional: las dependencias son todas de Python puro o wheels
# multiplataforma, así que no hay nada que pueda romper el build por plataforma.
RUN pip install -r requirements.txt

COPY app ./app
COPY pytest.ini ./

RUN useradd --create-home --uid 1000 appuser \
    && mkdir -p /var/www/gmail-docs/attachments/text-dump \
    && chown -R appuser:appuser /srv/app /var/www/gmail-docs

USER appuser

EXPOSE 8011

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=30s \
    CMD curl -fsS http://127.0.0.1:8011/healthz || exit 1

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8011", "--workers", "2"]