# Imagen del motor ML (TASK-074; SDD §1.11, §4.6). Usuario sin privilegios; el motor no
# accede a la BD de Laravel ni persiste solicitudes (RES-04).
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONPATH=/app/src \
    ML_MODELS_DIR=/app/models

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY src ./src
RUN useradd --create-home --uid 10001 ml \
    && mkdir -p /app/models \
    && chown -R ml /app/models
USER ml

EXPOSE 8000
CMD ["uvicorn", "denticore_ml.main:create_app", "--factory", "--host", "0.0.0.0", "--port", "8000"]
