#!/bin/sh
# bot-wrapper.sh — daemon lifecycle ka SINGLE source of truth.
# Host-side start.sh aur dashboard ka Start button dono YEH use karte hain.
# STOP mechanism: storage/app/BOT_STOP flag — wrapper bhi daemon bhi isko
# respect karte hain, is liye dashboard (nobody user) ko root kill ki
# zaroorat nahi parti.

while true; do
    if [ -f /home/nodesol/storage/app/BOT_STOP ]; then
        sleep 3
        continue
    fi
    php /home/nodesol/artisan bot:daemon >> /tmp/daemon.log 2>&1
    echo "[$(date -u)] daemon exited ($?) — restarting in 5s" >> /tmp/daemon.log
    sleep 5
done
