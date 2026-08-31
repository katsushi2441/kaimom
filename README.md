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

処理時間の実測: 同じ24秒の録音・同じ設定(large-v3-turbo・16スレッド)でも、サーバーの状況により17.7〜93.0秒とばらつきました(6回測定)。「◯分で終わる」前提の運用設計はせず、導入前にご自身のサーバーで実測してください。VAD(`KAIMOM_WHISPER_VAD_MODEL`)を設定すると、沈黙の多い録音は当社実測で2.9〜3.5倍速くなります。

## AIエージェント（Claude Code）から議事録を引く

同梱の `kaimom_mcp.php` を Claude Code や Claude Desktop に登録すると、
**過去の議事録がAIの調べもの先になります**。「先月の定例で決まったことは?」
「山田さんの宿題は?」と聞けば、AIが自社の議事録を根拠に答えます。
追加のインストールは不要です（PHPだけで動きます）。

```bash
claude mcp add kaimom -- php /path/to/kaimom_mcp.php
```

Claude Desktop の場合は `claude_desktop_config.json` に:

```json
{"mcpServers":{"kaimom":{"command":"php","args":["/path/to/kaimom_mcp.php"]}}}
```

| ツール | できること |
|---|---|
| `kaimom_list` | 確定済みの議事録を新しい順に一覧（日付で絞り込み可） |
| `kaimom_search` | 会議名・出席者・本文の全文検索（一致箇所の抜粋つき） |
| `kaimom_get` | 議事録1件の全文（決定事項・宿題・要約）を取得 |

**参照できるのは「人が承認して確定した議事録」だけです。** 処理中・未承認の
AI下書きは出てきません（人が確認していない文章を、AIに事実として使わせない
ため）。読み取り専用で、AIから議事録を書き換える口はありません。逐語の全文は
既定では返さず、必要なときだけ `KAIMOM_MCP_INCLUDE_TRANSCRIPT=1` で許可します。

## 話者分離について(正直な注記)

v1に話者分離はありません。話者ラベルが必要な場合は「文字起こし全文」を人が編集して話者名を付けます。AIが話者をでっち上げるより、人が確定するほうが議事録としては正しい、という設計判断です。

## 開発

```
php scripts/check_kaimom.php   # 自己テスト(関門・検証・状態遷移・帳票をAIなしで機械検証)
```

構築・運用の詳しい手順書(サーバー選び、whisperモデルの選び方、systemd化、カスタマイズのAI用プロンプト集)は有償で提供しています: [Kurage App Store](https://kappstore.exbridge.jp/) / [解説と入手先](https://kurage.exbridge.jp/)

© EXBRIDGE, Inc. / MIT License
