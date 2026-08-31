#!/usr/bin/env bash
# kappstore で配布するzipを作る。設定の実物・データ・鍵・whisperバイナリ/モデルは入れない。
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/check_kaimom.php >/dev/null || { echo "自己テスト失敗→パッケージ中止" >&2; exit 1; }
mkdir -p outputs
stamp=$(date +%Y%m%d)
zip="outputs/kaimom-${stamp}.zip"
rm -f "$zip"
zip -r "$zip" \
  public/kaimom.php public/kaimom_mcp.php public/kaimom_config.php.example public/kaimom_data/.htaccess \
  scripts/kaimom_worker.php scripts/check_kaimom.php \
  README.md LICENSE \
  -x '*.sqlite' -x '*.log' >/dev/null

# 有料の運用ノウハウは公開リポジトリに置かない（GitHubはコードと最小READMEのみ）。
# 実体は ../kaimom-sales/ にあり、配布zipにだけ同梱する。
docs_src="../kaimom-sales"
if [ -d "$docs_src" ]; then
  stage=$(mktemp -d)
  for f in "$docs_src"/*.md; do
    [ -e "$f" ] || continue
    cp "$f" "$stage/"
  done
  if [ -n "$(ls -A "$stage" 2>/dev/null)" ]; then
    (cd "$stage" && zip -r "$OLDPWD/$zip" ./*.md >/dev/null)
    echo "同梱した資料: $(cd "$stage" && ls *.md | tr '\n' ' ')"
  fi
  rm -rf "$stage"
else
  echo "警告: $docs_src が無いので、購入者向け資料は同梱されません" >&2
fi

echo "built: $zip ($(du -h "$zip" | cut -f1))"
