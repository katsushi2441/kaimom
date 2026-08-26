<?php
/** デモ環境の設定(https://proto.exbridge.jp/kaimom/)。トークン類はデプロイ時に注入される。 */
define('KAIMOM_TITLE', 'Kurage AI議事録作成 デモ');
define('KAIMOM_PASSWORD', '__KAIMOM_DEMO_PASSWORD__');
define('KAIMOM_TRANSCRIBE', 'relay');
define('KAIMOM_RELAY_URL', 'http://exbridge.ddns.net:18344');
define('KAIMOM_RELAY_TOKEN', '__KAIMOM_RELAY_TOKEN__');
/* 要約はrelayの/summarize(自社サーバーのgemma)を使う: LLM_KIND空でrelay分岐に入る */
define('KAIMOM_LLM_KIND', '');
define('KAIMOM_MAX_UPLOAD_MB', 15);
define('KAIMOM_RATE_PER_HOUR', 6);
define('KAIMOM_DEMO', true);
