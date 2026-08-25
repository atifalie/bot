#!/bin/sh
# ============================================
#  TRADING BOT - STOP
#  Usage: bash stop.sh
#  Note: daemon/scheduler dono modes band karta hai.
# ============================================

echo "🛑 Stopping Trading Bot..."

# pgrep + self-exclusion (pkill -f apni shell ko bhi maar deta hai)
docker exec dev sh -c '
MYPID=$$
for pid in $(pgrep -f "artisan schedule:run|artisan bot:daemon|artisan bot:run|while true; do php artisan|bot-wrapper.sh"); do
    [ "$pid" != "$MYPID" ] && kill -9 "$pid" 2>/dev/null
done
' || true

sleep 1
if docker exec dev sh -c 'ps aux | grep -v grep | grep -qE "bot:daemon|schedule:run|bot:run"'; then
    echo "❌ Abhi bhi chal raha hai — dobara try karo"
else
    echo ""
    echo "✅ BOT STOPPED — ab koi naya cycle nahi chalega."
    echo "   • Open positions waise ki waise rahengi"
    echo "   • Sab kuch manually close karna ho to dashboard ka 🚨 Close All use karo:"
    echo "     http://localhost:8010"
fi
