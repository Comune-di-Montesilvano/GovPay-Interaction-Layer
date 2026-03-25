FROM python:3.12-alpine

# mariadb-client per mysqldump nei backup; docker-cli come fallback diagnostico
RUN apk add --no-cache mariadb-client docker-cli

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 8099

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8099", "--workers", "1"]
