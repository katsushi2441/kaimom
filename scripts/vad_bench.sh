#!/usr/bin/env bash
# silero-VAD を入れると kaimom の文字起こしがどう変わるかを実測する。
#
# なぜ要るか(2026-08-31):
#   relay/kaimom_whisper_relay.py は whisper-cli を VAD なしで呼んでいる。
#   会議録音は無音が長く、(1)無音もデコードするので遅い (2)無音区間で
#   Whisper が幻覚テキストを出す（議事録では実害が大きい）。
#   同梱の whisper.cpp は --vad に対応済みなので、モデルを置くだけで試せる。
#
# 使い方: bash scripts/vad_bench.sh <音声ファイル>
set -euo pipefail
BIN=/home/kojima/work/kaimom/vendor/whisper.cpp/build/bin/whisper-cli
MODEL=/mnt/data/kaimom/models/ggml-large-v3-turbo.bin
VAD=/mnt/data/kaimom/models/ggml-silero-v5.1.2.bin
OUT=/home/kojima/work/kaimom/outputs/vad_bench
mkdir -p "$OUT"
IN="$1"; BASE=$(basename "$IN" | sed 's/\.[^.]*$//')
WAV="$OUT/$BASE.16k.wav"
ffmpeg -y -loglevel error -i "$IN" -ar 16000 -ac 1 -f wav "$WAV"
THREADS=$(nproc)
for mode in off on; do
  args=(-m "$MODEL" -l ja -t "$THREADS" -oj -of "$OUT/$BASE.$mode" --no-prints "$WAV")
  [ "$mode" = on ] && args=(--vad -vm "$VAD" "${args[@]}")
  s=$(date +%s.%N)
  "$BIN" "${args[@]}" >/dev/null 2>&1
  e=$(date +%s.%N)
  printf "VAD=%-3s  所要 %6.2f秒\n" "$mode" "$(echo "$e - $s" | bc)"
done
