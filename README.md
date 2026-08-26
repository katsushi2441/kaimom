# Kurage AI MOM (kaimom)

Kurage AI議事録作成システム。会議の録音をアップすると、**同じサーバーの whisper.cpp** が文字起こしし、AIが議事録の**下書き**(概要・決定事項・ToDo・課題・次回)を作り、人が録音・全文と見比べて修正・**承認**したものだけが確定議事録になる、1ファイルPHPの議事録システム。

- デモ: https://proto.exbridge.jp/kaimom/
- **音声を外部SaaSに送らない** — 文字起こしは自社サーバー内の whisper.cpp(買い切り設置型)
- 下書きはAI、確定はあなた — AIは議事録を承認できません(`km_can()`が関門)
- 録音原本・文字起こし全文を保持し、確定議事録といつでも突き合わせ可能(監査ログつき)
- ICレコーダー・スマホ録音(wav/mp3/m4a/flac等)をそのまま投入可。対面会議に強い
- 確定議事録は印刷ビュー・Markdownダウンロード対応
- DBサーバー不要(SQLite)。要約LLMは自社Ollama(gemma等)/OpenAI互換/なし(手入力)の3択

## 設置

1. `public/` の中身(kaimom.php・kaimom_config.php.example・kaimom_data/)をサーバーへ
2. [whisper.cpp](https://github.com/ggml-org/whisper.cpp) をビルドし、ggmlモデル(推奨: `ggml-large-v3-turbo.bin`)を配置
3. `kaimom_config.php.example` を `kaimom_config.php` にコピーし、パスワード・whisperのパスを設定
4. workerを起動: `php scripts/kaimom_worker.php`(systemd/cronどちらでも)
5. ブラウザで開いてログイン

要件: PHP 7.4+ / pdo_sqlite / curl。m4a等の変換に ffmpeg(任意)。データと録音は `kaimom_data/` に保存(このフォルダごとバックアップ)。録音はログイン済みセッションにのみ配信されます。

実測の目安: 24秒の録音を約11秒で文字起こし(large-v3-turbo・8コアCPU/16スレッド)。1時間会議で30分弱。

## 話者分離について(正直な注記)

v1に話者分離はありません。話者ラベルが必要な場合は「文字起こし全文」を人が編集して話者名を付けます。AIが話者をでっち上げるより、人が確定するほうが議事録としては正しい、という設計判断です。

## 開発

```
php scripts/check_kaimom.php   # 自己テスト(関門・検証・状態遷移・帳票をAIなしで機械検証)
```

構築・運用の詳しい手順書(サーバー選び、whisperモデルの選び方、systemd化、カスタマイズのAI用プロンプト集)は有償で提供しています: [Kurage App Store](https://kappstore.exbridge.jp/) / [解説と入手先](https://kurage.exbridge.jp/)

© EXBRIDGE, Inc. / MIT License
