#!/usr/bin/env python3
# kappstore用の商品画像。承認済み議事録の画面を主役にする。
# 実行: python3 scripts/make_product_image.py
from PIL import Image, ImageDraw, ImageFont
import os

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHOT = os.path.join(HERE, "outputs/km_detail.png")
OUT = os.path.join(HERE, "outputs/kaimom_product.png")

W, H = 1200, 675
BLACK = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
BOLD = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
MED = "/usr/share/fonts/opentype/noto/NotoSansCJK-Medium.ttc"

img = Image.new("RGB", (W, H), "#123f45")
d = ImageDraw.Draw(img)
d.rectangle([0, 300, W, H], fill="#eef4f6")

f_title = ImageFont.truetype(BLACK, 42)
f_name = ImageFont.truetype(BLACK, 27)
f_sub = ImageFont.truetype(MED, 21)
f_tag = ImageFont.truetype(BOLD, 18)

d.text((64, 62), "AI議事録を自社サーバーで", font=f_title, fill="#ffffff")
d.text((64, 126), "Kurage AI MOM", font=f_name, fill="#5fd0e0")
d.text((64, 178), "録音アップ→Whisperが文字起こし→AIが下書き→人が承認。", font=f_sub, fill="#bfd8de")
d.text((64, 212), "音声はサーバーの外に出ません。買い切り・改変自由。", font=f_sub, fill="#bfd8de")

tags = ["Whisper同梱手順", "音声を外に出さない", "買い切り"]
x = W - 64
for t in reversed(tags):
    tw = d.textlength(t, font=f_tag)
    d.rounded_rectangle([x - tw - 28, 62, x, 102], radius=20, outline="#5fd0e0", width=2)
    d.text((x - tw - 14, 71), t, font=f_tag, fill="#5fd0e0")
    x -= tw + 40

# 画面ショットを下段に(枠+影)
shot = Image.open(SHOT).convert("RGB")
sw = 880
sh = int(shot.height * sw / shot.width)
shot = shot.resize((sw, sh), Image.LANCZOS)
crop_h = min(sh, 400)
shot = shot.crop((0, 0, sw, crop_h))
sx, sy = (W - sw) // 2, 268
d.rounded_rectangle([sx - 6, sy - 6, sx + sw + 6, sy + crop_h + 6], radius=10, fill="#0b2d32")
img.paste(shot, (sx, sy))

img.save(OUT)
print("saved:", OUT)
