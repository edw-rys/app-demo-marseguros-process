FROM python:3.13-slim

LABEL maintainer="marseguros" \
      org.opencontainers.image.title="Gmail Docs Validator · stack-a"

# Python 3.13, no 3.14: paddlepaddle no publica wheel cp314 (ver README).

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# ── OCR y rasterizado ────────────────────────────────────────────────────────
# tesseract-ocr   → tier 1 del OCR (el que corre siempre)
# tesseract-ocr-spa → español; sin esto, los documentos en Castilla salen worse
# poppler-utils   → pdftoppm, para rasterizar PDF a imagen
# libGL/glib      → opencv, que paddleocr importa al cargar
#
# Los .traineddata de PaddleOCR se descargan la primera vez que se usa; se
# copian al directorio compartido para no bajarlos en cada contenedor.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        tesseract-ocr \
        tesseract-ocr-spa \
        tesseract-ocr-eng \
        tesseract-ocr-osd \
        poppler-utils \
        curl \
        libgl1 \
        libglib2.0-0 \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/app

# Primero los requirements: mientras no cambien, la capa de dependencias no se
# invalida. Los tests van aparte porque en la imagen no hacen falta.
COPY requirements.txt ./

# PaddleOCR (tier 2) entra en una capa opcional y separada a propósito.
#
# Motivo: son ~1.5 GB, y paddlepaddle no publica wheel para toda plataforma. Si
# su instalación comparte capa con el resto, un fallo ahí tira abajo también
# Tesseract — que sí está y es el OCR principal. Con `|| true`, lo que falla es
# solo el tier 2, que para eso el código ya degrada: `_get_paddle_pipeline()`
# captura la excepción y sigue con Tesseract (RF-04).
#
# El `|| true` es deliberado, no pereza: un fallo aquí NO debe tumbar la imagen.
ARG INSTALL_OCR2=true

RUN pip install -r requirements.txt

# El COPY va FUERA del RUN: `COPY` es una instrucción del Dockerfile, no un
# comando de shell, y dentro de un `RUN` el shell lo toma por un binario que no
# existe. El archivo se copia siempre (es de 20 líneas) y se decide después si
# se instala, que además es lo que permite que la capa base se reutilice cuando
# se cambia INSTALL_OCR2.
COPY requirements-ocr2.txt ./

RUN if [ "$INSTALL_OCR2" = "true" ]; then \
        pip install -r requirements-ocr2.txt \
           || echo "[BUILD] PaddleOCR no se pudo instalar: el servicio quedará solo con Tesseract."; \
    fi \
    && rm -f requirements-ocr2.txt \
    && python -c "import fastapi, pdfplumber, pytesseract; print('[BUILD] dependencias base OK')"

COPY app ./app
COPY pytest.ini ./

# Usuario sin privilegios: un worker que solo lee adjuntos no necesita root, y
# el volumen compartido lo monta `www-data` en el admin con otros permisos.
RUN useradd --create-home --uid 1000 appuser \
    && mkdir -p /var/www/gmail-docs/attachments/text-dump \
    && chown -R appuser:appuser /srv/app /var/www/gmail-docs

USER appuser

EXPOSE 8010

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=40s \
    CMD curl -fsS http://127.0.0.1:8010/healthz || exit 1

# `--workers 1` a propósito: los modelos de PaddleOCR son grandes y cada worker
# carga su propia copia en RAM. Con más de uno el contenedor se muere por OOM
# antes de que rinda. La concurrencia se resuelve con réplicas.
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8010", "--workers", "1"]