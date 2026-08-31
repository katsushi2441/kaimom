#!/usr/bin/env bash
# VAD検証用の「会議っぽい」音声を作る。
# 実会議は発話より無音のほうが長いことが多いので、発話24秒×4 の間に
# 30/45/30秒の間を入れる（全体200秒・無音52%）。
# 無音は2種類: 完全な無音と、実録音に近い暗騒音入り(-50dB相当)。
set -euo pipefail
SRC=/home/kojima/work/kaimom/outputs/vad_bench/src.wav
OUT=/home/kojima/work/kaimom/outputs/vad_bench
gap() { ffmpeg -y -loglevel error -f lavfi -i "anullsrc=r=16000:cl=mono" -t "$1" "$OUT/gap$1.wav"; }
gapn() { ffmpeg -y -loglevel error -f lavfi -i "anoisesrc=r=16000:c=brown:a=0.004" -ac 1 -t "$1" "$OUT/gapn$1.wav"; }
for s in 30 45; do gap $s; gapn $s; done
for kind in quiet noise; do
  p=gap; [ "$kind" = noise ] && p=gapn
  printf "file '%s'\n" "$SRC" "$OUT/${p}30.wav" "$SRC" "$OUT/${p}45.wav" "$SRC" "$OUT/${p}30.wav" "$SRC" > "$OUT/list_$kind.txt"
  ffmpeg -y -loglevel error -f concat -safe 0 -i "$OUT/list_$kind.txt" -ar 16000 -ac 1 "$OUT/meeting_$kind.wav"
done
for f in "$OUT"/meeting_*.wav; do
  printf "%-26s %s秒\n" "$(basename "$f")" "$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$f")"
done
