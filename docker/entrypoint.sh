#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html"

cd "$APP_DIR"

# Asegura .env para Laravel si no se montó/definió en runtime
if [ ! -f "$APP_DIR/.env" ] && [ -f "$APP_DIR/.env.example" ]; then
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

# ─── Sobrescribe variables críticas en .env con valores del entorno Docker ───
# Esto garantiza que las envvars del docker-compose siempre tengan prioridad,
# incluso si el .env copiado tiene esas líneas comentadas o con otros valores.

set_env() {
  local key="$1"
  local val="$2"
  # Si la clave existe (con o sin #), reemplázala; si no, agrégala al final
  if grep -qE "^#?\s*${key}=" "$APP_DIR/.env"; then
    sed -i "s|^#\?[[:space:]]*${key}=.*|${key}=${val}|" "$APP_DIR/.env"
  else
    echo "${key}=${val}" >> "$APP_DIR/.env"
  fi
}

[ -n "${DB_CONNECTION:-}" ]    && set_env "DB_CONNECTION"    "$DB_CONNECTION"
[ -n "${DB_HOST:-}" ]          && set_env "DB_HOST"           "$DB_HOST"
[ -n "${DB_PORT:-}" ]          && set_env "DB_PORT"           "$DB_PORT"
[ -n "${DB_DATABASE:-}" ]      && set_env "DB_DATABASE"       "$DB_DATABASE"
[ -n "${DB_USERNAME:-}" ]      && set_env "DB_USERNAME"       "$DB_USERNAME"
[ -n "${DB_PASSWORD:-}" ]      && set_env "DB_PASSWORD"       "$DB_PASSWORD"
[ -n "${APP_ENV:-}" ]          && set_env "APP_ENV"           "$APP_ENV"
[ -n "${APP_DEBUG:-}" ]        && set_env "APP_DEBUG"         "$APP_DEBUG"
[ -n "${APP_URL:-}" ]          && set_env "APP_URL"           "$APP_URL"
[ -n "${OLLAMA_BASE_URL:-}" ]  && set_env "OLLAMA_BASE_URL"   "$OLLAMA_BASE_URL"
# ─────────────────────────────────────────────────────────────────────────────

# Genera APP_KEY si no está definido
if [ -z "${APP_KEY:-}" ]; then
  php artisan key:generate --force >/dev/null 2>&1 || true
fi

echo "Esperando a MySQL..."
php -r '
$host = getenv("DB_HOST") ?: "127.0.0.1";
$port = getenv("DB_PORT") ?: "3306";
$db   = getenv("DB_DATABASE") ?: "face";
$user = getenv("DB_USERNAME") ?: "faceuser";
$pass = getenv("DB_PASSWORD") ?: "facepassword";
$dsn = "mysql:host=".$host.";port=".$port.";dbname=".$db.";charset=utf8mb4";
$ok = false;
for ($i=0; $i<60; $i++) {
  try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ok = true;
    break;
  } catch (Throwable $e) {
    usleep(500000);
  }
}
exit($ok ? 0 : 1);
' || {
  echo "MySQL no estuvo disponible a tiempo. Revisa DB_* en docker-compose.yml";
  exit 1;
}

echo "Corriendo migraciones..."
# Solo aplicamos migraciones nuevas, sin borrar los datos existentes.
php artisan migrate --force

# Correr seeders de roles si la base de datos está vacía
php artisan db:seed --class=RoleSeeder --force 2>/dev/null || true
# Si quisieras asegurarte de que el super_admin se crea al menos una vez, 
# se puede correr otro seeder específico aquí de forma idempotente.

if [ "${DOWNLOAD_DLIB_MODELS:-0}" = "1" ]; then
  SHAPE_FILE="app/Services/face-recognition/models/shape_predictor_68_face_landmarks.dat"
  FACE_FILE="app/Services/face-recognition/models/dlib_face_recognition_resnet_model_v1.dat"

  if [ ! -f "$SHAPE_FILE" ] || [ ! -f "$FACE_FILE" ]; then
    if [ -f "app/Services/face-recognition/models/download-models.sh" ]; then
      echo "Descargando modelos dlib (puede tardar)..."
      bash app/Services/face-recognition/models/download-models.sh
    else
      echo "download-models.sh no existe. No puedo descargar los modelos."
    fi
  else
    echo "Modelos dlib ya presentes."
  fi
else
  echo "DOWNLOAD_DLIB_MODELS=0; no descargamos modelos dlib."
fi

echo "Iniciando API en :${PORT:-8000}"
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"

