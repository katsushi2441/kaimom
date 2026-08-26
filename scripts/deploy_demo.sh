#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/kaimom/
# デモの文字起こしは0.3のkaimom-whisper-relay(:18344, ddns経由)→whisper.cpp large-v3-turbo。
# 要約も同relayの/summarize→ローカルgemma4。トークンは .env から注入(リポジトリに置かない)。
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/check_kaimom.php >/dev/null || { echo "自己テスト失敗→デプロイ中止" >&2; exit 1; }
set -a; . /home/kojima/work/aixec/.env; set +a
RELAY_TOKEN=$(grep -m1 '^KAIMOM_RELAY_TOKEN=' .env | cut -d= -f2)
DEMO_PW=$(grep -m1 '^KAIMOM_DEMO_PASSWORD=' .env | cut -d= -f2)
[ -n "$RELAY_TOKEN" ] && [ -n "$DEMO_PW" ] || { echo ".envにKAIMOM_RELAY_TOKEN/KAIMOM_DEMO_PASSWORDがない" >&2; exit 1; }
remote="/web/proto_exbridge_jp/kaimom"
up() { curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"; echo "up: $2"; }
tmp=$(mktemp)
sed -e "s/__KAIMOM_RELAY_TOKEN__/${RELAY_TOKEN}/" -e "s/__KAIMOM_DEMO_PASSWORD__/${DEMO_PW}/" demo/kaimom_config.php > "$tmp"
up public/kaimom.php kaimom.php
up "$tmp" kaimom_config.php
rm -f "$tmp"
up demo/index.php index.php
up demo/.htaccess .htaccess
up public/kaimom_data/.htaccess kaimom_data/.htaccess
echo "published: https://proto.exbridge.jp/kaimom/"
