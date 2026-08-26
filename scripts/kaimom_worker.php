<?php
/**
 * kaimom 文字起こしworker (localモード用)。
 *   php scripts/kaimom_worker.php           # 常駐(3秒ポーリング)
 *   php scripts/kaimom_worker.php --once    # 溜まっている分だけ処理して終了
 * systemd/cron どちらでも可。webと同じ kaimom.php を読み、同じ km_can 関門を通る。
 */
define('KAIMOM_CLI', true);
require __DIR__ . '/../public/kaimom.php';

$once = in_array('--once', $argv, true);
fwrite(STDERR, '[kaimom-worker] start mode=' . (KAIMOM_TRANSCRIBE) . ($once ? ' (once)' : '') . "\n");
if (KAIMOM_TRANSCRIBE !== 'local') {
    fwrite(STDERR, "[kaimom-worker] KAIMOM_TRANSCRIBE=local 以外ではworkerは不要です\n");
    exit(0);
}
km_worker_run(!$once);
fwrite(STDERR, "[kaimom-worker] done\n");
