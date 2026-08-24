#!/bin/sh
# ==================================================
#  TRADING BOT - START
#  Usage: bash start.sh
#  Container missing ho to khud create kar leta hai.
#
#  Mode selection (.env):
#    BOT_STREAMING=true   → WebSocket daemon (default, kam REST load)
#    BOT_STREAMING=false  → legacy scheduler + bot:run (REST fallback)
# ==================================================

echo "🤖 Starting Trading Bot..."

# ---------- 1) Container check/create ----------
STATE=$(docker inspect -f '{{.State.Running}}' dev 2>/dev/null)

if [ "$STATE" = "true" ]; then
    echo "✔ Container 'dev' already running"
elif [ "$STATE" = "false" ]; then
    echo "↻ Container 'dev' stopped — starting..."
    docker start dev >/dev/null || { echo "❌ docker start failed"; exit 1; }
else
    echo "✚ Container 'dev' nahi mila — creating fresh..."
    if ! docker image inspect nodesol/devbox:php84-bot >/dev/null 2>&1; then
        echo "❌ Image 'nodesol/devbox:php84-bot' bhi nahi — pehle usay build karo"
        exit 1
    fi
    docker run -d --name dev \
        -v /home/atif/python/trade/bot:/home/nodesol \
        -p 8000:80 -p 5173:5173 -p 5174:5174 \
        nodesol/devbox:php84-bot >/dev/null || { echo "❌ docker run failed"; exit 1; }
fi

sleep 2

# ---------- 2) Extensions safety (fresh base-image case) ----------
docker exec -u root dev apk add --no-cache php84-gmp php84-xml php84-xmlwriter php84-pdo_sqlite php84-sqlite3 >/dev/null 2>&1 || true

# ---------- 3) Purane processes band (double-run se bacho) ----------
# NOTE: pkill -f apni killer-shell ko bhi maar deta hai (pattern khud ki
# cmdline me hota hai) — is liye pgrep + explicit self-exclusion:
docker exec dev sh -c '
MYPID=$$
for pid in $(pgrep -f "artisan schedule:run|artisan bot:daemon|artisan bot:run|while true; do php artisan"); do
    [ "$pid" != "$MYPID" ] && kill -9 "$pid" 2>/dev/null
done
sleep 1
REMAINING=$(pgrep -f "artisan bot:daemon|schedule:run" | grep -v "^$MYPID$" | wc -l | tr -d " ")
echo "remaining after cleanup: $REMAINING"
' || true

# ---------- 4) Vite (dashboard assets) ----------
docker exec dev sh -c 'pkill -f "vite" 2>/dev/null' || true
docker exec dev sh -c 'rm -f /home/nodesol/public/hot'
docker exec -d dev sh -c 'cd /home/nodesol && nohup npm run dev -- --host 0.0.0.0 --port 5174 --strictPort > /tmp/vite.log 2>&1'
sleep 4
# Hot file browser-friendly banao (0.0.0.0 browsers pe kaam nahi karta)
docker exec dev sh -c 'echo -n "http://localhost:5174" > /home/nodesol/public/hot'

# ---------- 5) Bot START (streaming ya legacy scheduler) ----------
STREAMING=$(docker exec dev sh -c 'grep -E "^BOT_STREAMING=" /home/nodesol/.env 2>/dev/null | cut -d= -f2 | tr -d "[:space:]"')
if [ "$STREAMING" = "false" ]; then
    echo "⚙️ Mode: LEGACY REST (scheduler + bot:run)"
    docker exec -d dev sh -c 'cd /home/nodesol && while true; do php artisan schedule:run > /dev/null 2>&1; sleep 60; done'
    RUN_CHECK="schedule:run"
else
    echo "⚡ Mode: WEBSOCKET STREAMING (bot:daemon + auto-restart)"
    # Daemon crash ho jaye to 5s baad wapis start — self-healing loop
    docker exec -d dev sh -c 'cd /home/nodesol && while true; do php artisan bot:daemon >> /tmp/daemon.log 2>&1; echo "[$(date -u)] daemon exited ($?) — restarting in 5s" >> /tmp/daemon.log; sleep 5; done'
    RUN_CHECK="bot:daemon"
fi

# ---------- 6) Verify ----------
sleep 3
OK=1
docker exec dev sh -c "ps aux | grep -v grep | grep -q \"$RUN_CHECK\"" || OK=0
docker exec dev php -m 2>/dev/null | grep -q gmp || OK=0

if [ "$OK" = "1" ]; then
    echo ""
    echo "✅ BOT RUNNING!"
    echo "   • Dashboard:  http://localhost:8000"
    echo "   • Settings:   http://localhost:8000/settings"
    echo "   • Band karo:  bash stop.sh"
    if [ "$RUN_CHECK" = "bot:daemon" ]; then
        echo "   • Logs:       docker exec dev tail -f /tmp/daemon.log"
    fi
else
    echo ""
    echo "⚠️ Bot start hua lekin kuch check karo:"
    docker exec dev sh -c 'ps aux | grep -E "artisan|schedule" | grep -v grep' 2>/dev/null || \
        echo "   Container ke andar processes nahi chal rahe"
fi
