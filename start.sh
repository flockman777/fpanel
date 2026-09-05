#!/usr/bin/env bash
# FPanel - start/stop semua service (VPS)
set -u
ROOT="/opt/fpanel"

stop_all() {
  pkill -9 -f "target/release/fpanel" 2>/dev/null
  pkill -9 -f "target/release/fserver" 2>/dev/null
  pkill -9 -f "vite.*--port 11887" 2>/dev/null
  pkill -9 -f "vite.*--port 11883" 2>/dev/null
}

case "${1:-start}" in
  stop) stop_all; echo "stopped"; exit 0 ;;
  restart) stop_all; sleep 1 ;;
esac

export FPANEL_SECRET="$(cat $ROOT/.fsecret)"
export FPANEL_PUBLIC_IP="157.15.125.2"
export FPANEL_MYSQL_USER="root"
export FPANEL_MYSQL_SOCKET="/tmp/mysql.sock"
export FPANEL_NS1="ns1.fpanel.my.id"
export FPANEL_NS2="ns2.fpanel.my.id"

# Runtime data/vhost paths (cross-compiled binaries use a build-host path by default)
export FPANEL_DATA="$ROOT/panel/data"
export FPANEL_VHOSTS="$ROOT/panel/vhosts"
export FPANEL_LOGS="$ROOT/panel/data/logs"
export FPANEL_HOME="/home"

cd "$ROOT/panel"
nohup ./target/release/fpanel > /var/log/fpanel.log 2>&1 &
echo "panel pid $!"

cd "$ROOT/server"
nohup env RUST_LOG=info ./target/release/fserver > /var/log/fserver.log 2>&1 &
echo "fserver pid $!"

cd "$ROOT/web"
nohup ./node_modules/.bin/vite --host 127.0.0.1 --port 11887 > /var/log/ui-admin.log 2>&1 &
echo "ui-admin pid $!"
nohup ./node_modules/.bin/vite --config client/vite.config.ts --host 127.0.0.1 --port 11883 > /var/log/ui-client.log 2>&1 &
echo "ui-client pid $!"

echo "done. stop: bash $ROOT/start.sh stop"
