#!/usr/bin/env bash
# Smoke test: boot the Laravel app against a throwaway, seeded SQLite database and
# check the public site, the admin shell, the public API, API error handling and a
# real login round-trip. Tears everything down afterwards.
#
# Usage: bash .claude/scripts/smoke.sh [port] [timeout-seconds]
#   defaults: 8123 30
# Exit 0 on SMOKE PASS, non-zero on SMOKE FAIL -- so /wrap-up can gate on it.
#
# Uses PHP's built-in server directly (like docker-compose does) rather than
# `php artisan serve`, because `serve` re-reads .env in the child process and
# would drop the DB_* overrides below, pointing the smoke run at your real DB.
set -u

PORT="${1:-8123}"
TIMEOUT="${2:-30}"
BASE_URL="http://127.0.0.1:${PORT}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/../../backend" && pwd)" || { echo "SMOKE FAIL: cannot find backend/"; exit 1; }
cd "${APP_DIR}" || exit 1

[ -f vendor/autoload.php ] || { echo "SMOKE FAIL: run 'composer install' in backend/ first"; exit 1; }
[ -f .env ] || { echo "SMOKE FAIL: backend/.env is missing (cp .env.example .env && php artisan key:generate)"; exit 1; }

# A busy port is fatal: the probes would hit whatever already owns it and report
# a pass that has nothing to do with this branch.
if lsof -ti "tcp:${PORT}" >/dev/null 2>&1; then
  echo "SMOKE FAIL: port ${PORT} is already in use -- stop that server, or pass a free port as arg 1"
  exit 1
fi

# @vite needs either a running dev server (public/hot) or a build manifest.
if [ ! -f public/hot ] && [ ! -f public/build/manifest.json ]; then
  echo "-> no Vite dev server or build found; running npm run build"
  npm run build >/dev/null 2>&1 || { echo "SMOKE FAIL: npm run build failed"; exit 1; }
fi

WORK_DIR="$(mktemp -d -t sms-smoke.XXXXXX)"
DB_FILE="${WORK_DIR}/database.sqlite"
LOG="${WORK_DIR}/server.log"
SERVER_PID=""

cleanup() {
  if [ -n "${SERVER_PID}" ] && kill -0 "${SERVER_PID}" 2>/dev/null; then
    kill "${SERVER_PID}" 2>/dev/null
    wait "${SERVER_PID}" 2>/dev/null
  fi
  rm -rf "${WORK_DIR}"
}
trap cleanup EXIT INT TERM

# Real environment variables win over .env (Dotenv is immutable), so these reach
# both the migrate command and the server process.
export APP_ENV=local APP_DEBUG=false APP_URL="${BASE_URL}"
export DB_CONNECTION=sqlite DB_DATABASE="${DB_FILE}"
export SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync MAIL_MAILER=array

touch "${DB_FILE}"
echo "-> migrating + seeding throwaway database"
php artisan migrate:fresh --seed --force >"${LOG}" 2>&1 || { echo "SMOKE FAIL: migrate/seed failed"; tail -n 20 "${LOG}"; exit 1; }

echo "-> starting server on ${BASE_URL}"
(cd public && exec php -S "127.0.0.1:${PORT}" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) >"${LOG}" 2>&1 &
SERVER_PID=$!

deadline=$(( $(date +%s) + TIMEOUT ))
until curl -s -o /dev/null --max-time 2 "${BASE_URL}/up"; do
  if ! kill -0 "${SERVER_PID}" 2>/dev/null || [ "$(date +%s)" -ge "${deadline}" ]; then
    echo "SMOKE FAIL: server did not come up within ${TIMEOUT}s. Last log lines:"
    tail -n 20 "${LOG}"
    exit 1
  fi
  sleep 1
done

FAILED=0
# check <description> <expected-status> <curl args...>
check() {
  local desc="$1" expected="$2"; shift 2
  local status
  status="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@")"
  if [ "${status}" = "${expected}" ]; then
    echo "  ok   ${desc} -> ${status}"
  else
    echo "  FAIL ${desc} -> ${status} (expected ${expected})"
    FAILED=1
  fi
}

check "GET / (public home)"                 200 "${BASE_URL}/"
check "GET /news"                           200 "${BASE_URL}/news"
check "GET /sitemap.xml"                    200 "${BASE_URL}/sitemap.xml"
check "GET /admin (SPA shell)"              200 "${BASE_URL}/admin"
check "GET /api/public/school"              200 "${BASE_URL}/api/public/school"
check "GET /api/subjects, no token/Accept"  401 "${BASE_URL}/api/subjects"

TOKEN="$(curl -s --max-time 10 -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"admin@sms.com","password":"password"}' "${BASE_URL}/api/login" \
  | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["token"] ?? "";')"
if [ -n "${TOKEN}" ]; then
  echo "  ok   POST /api/login (seeded admin) -> token"
  check "GET /api/subjects as admin"        200 -H "Authorization: Bearer ${TOKEN}" -H 'Accept: application/json' "${BASE_URL}/api/subjects"
  check "GET /api/students as admin"        200 -H "Authorization: Bearer ${TOKEN}" -H 'Accept: application/json' "${BASE_URL}/api/students"
else
  echo "  FAIL POST /api/login (seeded admin) -> no token"
  FAILED=1
fi

if [ "${FAILED}" = "0" ]; then
  echo "SMOKE PASS: ${BASE_URL}"
  exit 0
fi

echo "SMOKE FAIL: see checks above. Last server log lines:"
tail -n 20 "${LOG}"
exit 1
