<?php
/**
 * Kurage AI MOM (kaimom) — Kurage AI議事録作成システム。1ファイル。
 *
 * 会議の録音をアップ→同じサーバーの whisper.cpp が文字起こし→AIが議事録の
 * 「下書き」(概要・決定事項・ToDo)を作成→人が録音・全文と見比べて修正・承認
 * →確定議事録。DBサーバー不要(SQLite)・買い切り設置型。
 *
 * 【設計の芯】(kaima / kcrmagent / kdbagent と同じ思想)
 *  1. AIに自己採点させない — 文字起こしも要約も必ず「下書き」まで。確定
 *     議事録を作れるのは人の承認だけ。入口が違っても同じ関門(km_can)を通り、
 *     AI側の入口(worker)には承認の権限がそもそも無い。
 *  2. 音声を外に出さない — 文字起こしは同一サーバーの whisper.cpp バイナリ。
 *     外部SaaSに音声を送らない(デモ環境のみ自社中継ユニットを使う)。
 *  3. 録音原本は証拠 — 承認後も音声・文字起こし全文を保持し、確定議事録と
 *     いつでも突き合わせられる。監査ログに誰がいつ何をしたかを残す。
 *  4. 話者ラベルは人が付ける — v1に話者分離はない。AIが話者をでっち上げる
 *     より、人が全文を読んで確定するほうが議事録としては正しい。
 *
 * カスタマイズは kaimom_config.php を編集。PHP 7.4+ / pdo_sqlite / curl。
 * 文字起こし: whisper.cpp (whisper-cli) + ggmlモデル / m4a等は ffmpeg で変換。
 */

date_default_timezone_set('Asia/Tokyo');

$cfg = __DIR__ . '/kaimom_config.php';
if (!is_file($cfg)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'kaimom_config.php がありません。kaimom_config.php.example をコピーして作成してください。';
    exit;
}
require $cfg;

if (!defined('KAIMOM_TITLE'))         { define('KAIMOM_TITLE', 'Kurage AI議事録作成'); }
if (!defined('KAIMOM_BRAND_COLOR'))   { define('KAIMOM_BRAND_COLOR', '#1f6f78'); }
if (!defined('KAIMOM_PASSWORD'))      { define('KAIMOM_PASSWORD', ''); }
if (!defined('KAIMOM_PASSWORD_HASH')) { define('KAIMOM_PASSWORD_HASH', ''); }
if (!defined('KAIMOM_DATA_DIR'))      { define('KAIMOM_DATA_DIR', __DIR__ . '/kaimom_data'); }
/* 文字起こし: local=同一サーバーのwhisper.cpp / relay=中継ユニット(デモ用) */
if (!defined('KAIMOM_TRANSCRIBE'))    { define('KAIMOM_TRANSCRIBE', 'local'); }
if (!defined('KAIMOM_WHISPER_BIN'))   { define('KAIMOM_WHISPER_BIN', '/usr/local/bin/whisper-cli'); }
if (!defined('KAIMOM_WHISPER_MODEL')) { define('KAIMOM_WHISPER_MODEL', '/usr/local/share/kaimom/ggml-large-v3-turbo.bin'); }
if (!defined('KAIMOM_WHISPER_THREADS')) { define('KAIMOM_WHISPER_THREADS', 4); }
/* 音声区間検出(VAD)。無音を whisper に渡さないためのモデル。
 * 会議録音は沈黙が長く、無音区間で whisper が実在しない発言を出力する
 * (当社実測: 30秒の沈黙に「ご視聴ありがとうございました」、暗騒音入りでは
 *  実在する語を組み合わせた偽の発言が出て、日付がすり替わった)。
 * whisper.cpp の models/download-vad-model.sh で取得したモデルのパスを
 * 設定すると、--vad が付いて無音が渡らなくなる。空なら従来どおり無効。 */
if (!defined('KAIMOM_WHISPER_VAD_MODEL')) { define('KAIMOM_WHISPER_VAD_MODEL', ''); }
if (!defined('KAIMOM_FFMPEG'))        { define('KAIMOM_FFMPEG', 'ffmpeg'); }
if (!defined('KAIMOM_RELAY_URL'))     { define('KAIMOM_RELAY_URL', ''); }
if (!defined('KAIMOM_RELAY_TOKEN'))   { define('KAIMOM_RELAY_TOKEN', ''); }
/* 要約LLM: ollama(既定・自社サーバー) / openai(OpenAI互換) / 空=テンプレのみ */
if (!defined('KAIMOM_LLM_KIND'))      { define('KAIMOM_LLM_KIND', ''); }
if (!defined('KAIMOM_LLM_URL'))       { define('KAIMOM_LLM_URL', 'http://127.0.0.1:11434'); }
if (!defined('KAIMOM_LLM_MODEL'))     { define('KAIMOM_LLM_MODEL', 'gemma4:12b-it-qat'); }
if (!defined('KAIMOM_LLM_KEY'))       { define('KAIMOM_LLM_KEY', ''); }
if (!defined('KAIMOM_MAX_UPLOAD_MB')) { define('KAIMOM_MAX_UPLOAD_MB', 200); }
if (!defined('KAIMOM_RATE_PER_HOUR')) { define('KAIMOM_RATE_PER_HOUR', 20); }
if (!defined('KAIMOM_DEMO'))          { define('KAIMOM_DEMO', false); }

/* ================= ユーティリティ ================= */

function km_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function km_now() { return date('Y-m-d H:i:s'); }

function km_json_out($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function km_max_upload_bytes() {
    $mb = (int)KAIMOM_MAX_UPLOAD_MB;
    if (KAIMOM_DEMO) { $mb = min($mb, 15); }
    return $mb * 1024 * 1024;
}

function km_rate_max() { $n = (int)KAIMOM_RATE_PER_HOUR; if (KAIMOM_DEMO) { $n = min($n, 6); } return $n; }

/** 会議日の検証 (YYYY-MM-DD) */
function km_valid_date($s) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$s, $m)) { return false; }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** 音声拡張子の検証(ホワイトリスト)。 */
function km_audio_ext($name) {
    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    $ok = array('wav','mp3','m4a','mp4','aac','flac','ogg','oga','webm','wma','amr','3gp');
    return in_array($ext, $ok, true) ? $ext : '';
}

/* ================= 関門 km_can ================= */
/**
 * 入口(entry)と操作(action)の宣言表。表に無い組み合わせは全部拒否。
 * human = ログイン済みの人(Web) / ai = 文字起こしworker・要約LLM。
 * AIの入口に承認(minutes.approve)が無いことがこのシステムの芯。
 */
function km_can($entry, $action) {
    static $matrix = array(
        'human' => array(
            'meeting.create', 'meeting.delete',
            'transcript.edit', 'draft.request',
            'minutes.edit', 'minutes.approve', 'minutes.reopen',
        ),
        'ai' => array(
            'transcript.draft', 'summary.draft',
        ),
    );
    return isset($matrix[$entry]) && in_array($action, $matrix[$entry], true);
}

/** 関門を通す。通らなければその場で終了(Webは403 / CLIは例外)。 */
function km_gate($entry, $action) {
    if (km_can($entry, $action)) { return true; }
    if (PHP_SAPI === 'cli' || defined('KAIMOM_CLI')) {
        throw new RuntimeException("km_can拒否: {$entry} は {$action} を宣言していません");
    }
    km_json_out(403, array('error' => "この入口({$entry})に {$action} の権限はありません"));
}

/* ================= DB (SQLite) ================= */

function km_db() {
    static $db = null;
    if ($db) { return $db; }
    if (!is_dir(KAIMOM_DATA_DIR)) { @mkdir(KAIMOM_DATA_DIR, 0775, true); }
    $db = new PDO('sqlite:' . KAIMOM_DATA_DIR . '/kaimom.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=8000');
    $db->exec('CREATE TABLE IF NOT EXISTS meetings(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        meeting_date TEXT NOT NULL,
        location TEXT NOT NULL DEFAULT "",
        attendees TEXT NOT NULL DEFAULT "",
        status TEXT NOT NULL DEFAULT "queued",
        audio_file TEXT NOT NULL DEFAULT "",
        audio_name TEXT NOT NULL DEFAULT "",
        duration_sec REAL NOT NULL DEFAULT 0,
        relay_job TEXT NOT NULL DEFAULT "",
        transcript TEXT NOT NULL DEFAULT "",
        segments TEXT NOT NULL DEFAULT "",
        draft_json TEXT NOT NULL DEFAULT "",
        minutes_json TEXT NOT NULL DEFAULT "",
        minutes_md TEXT NOT NULL DEFAULT "",
        error TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        approved_at TEXT NOT NULL DEFAULT "",
        approved_by TEXT NOT NULL DEFAULT ""
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS audit(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL,
        meeting_id INTEGER NOT NULL DEFAULT 0, detail TEXT NOT NULL DEFAULT ""
    )');
    return $db;
}

function km_log($actor, $action, $mid = 0, $detail = '') {
    $st = km_db()->prepare('INSERT INTO audit(ts,actor,action,meeting_id,detail) VALUES(?,?,?,?,?)');
    $st->execute(array(km_now(), $actor, $action, (int)$mid, mb_substr((string)$detail, 0, 500)));
}

function km_meeting($id) {
    $st = km_db()->prepare('SELECT * FROM meetings WHERE id=?');
    $st->execute(array((int)$id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function km_update($id, $fields) {
    $fields['updated_at'] = km_now();
    $sets = array(); $vals = array();
    foreach ($fields as $k => $v) {
        if (!preg_match('/^[a-z_]+$/', $k)) { throw new RuntimeException('bad column'); }
        $sets[] = "$k=?"; $vals[] = $v;
    }
    $vals[] = (int)$id;
    km_db()->prepare('UPDATE meetings SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
}

/** 議事録の状態遷移表。表に無い遷移は拒否する。 */
function km_can_move($from, $to) {
    static $moves = array(
        'queued'       => array('transcribing', 'failed'),
        'transcribing' => array('transcribed', 'failed'),
        'transcribed'  => array('approved', 'transcribing'),
        'failed'       => array('queued'),
        'approved'     => array('transcribed'), // 差し戻し(minutes.reopen)
    );
    return isset($moves[$from]) && in_array($to, $moves[$from], true);
}

function km_move($id, $to, $extra = array()) {
    $m = km_meeting($id);
    if (!$m) { throw new RuntimeException('会議がありません'); }
    if (!km_can_move($m['status'], $to)) {
        throw new RuntimeException("状態遷移 {$m['status']}→{$to} は許可されていません");
    }
    $extra['status'] = $to;
    km_update($id, $extra);
}

/* ================= 文字起こし (whisper.cpp) ================= */

/** 必要なら ffmpeg で 16kHz mono wav に変換して、そのパスを返す。 */
function km_prepare_wav($audioPath) {
    $ext = strtolower(pathinfo($audioPath, PATHINFO_EXTENSION));
    if (in_array($ext, array('wav','mp3','flac'), true)) { return array($audioPath, false); }
    $wav = $audioPath . '.16k.wav';
    $cmd = escapeshellcmd(KAIMOM_FFMPEG) . ' -y -i ' . escapeshellarg($audioPath)
         . ' -ar 16000 -ac 1 -f wav ' . escapeshellarg($wav) . ' 2>&1';
    exec($cmd, $out, $rc);
    if ($rc !== 0 || !is_file($wav)) {
        throw new RuntimeException('ffmpeg変換に失敗: ' . implode(' / ', array_slice($out, -3)));
    }
    return array($wav, true);
}

/** whisper.cpp の -oj JSON を [text, segments, duration] に整形。 */
function km_parse_whisper_json($json) {
    $d = json_decode($json, true);
    if (!is_array($d) || !isset($d['transcription'])) {
        throw new RuntimeException('whisper JSONを解釈できません');
    }
    $segs = array(); $texts = array(); $dur = 0.0;
    foreach ($d['transcription'] as $t) {
        $from = isset($t['offsets']['from']) ? $t['offsets']['from'] / 1000.0 : 0;
        $to   = isset($t['offsets']['to'])   ? $t['offsets']['to'] / 1000.0   : 0;
        $txt  = trim(isset($t['text']) ? $t['text'] : '');
        if ($txt === '') { continue; }
        $segs[] = array(round($from, 2), round($to, 2), $txt);
        $texts[] = $txt;
        if ($to > $dur) { $dur = $to; }
    }
    return array(implode("\n", $texts), $segs, $dur);
}

/** 同一サーバーの whisper-cli で文字起こし(音声はサーバーの外に出ない)。 */
function km_transcribe_local($audioPath) {
    if (!is_file(KAIMOM_WHISPER_BIN)) { throw new RuntimeException('whisper-cliがありません: ' . KAIMOM_WHISPER_BIN); }
    if (!is_file(KAIMOM_WHISPER_MODEL)) { throw new RuntimeException('whisperモデルがありません: ' . KAIMOM_WHISPER_MODEL); }
    list($wav, $tmp) = km_prepare_wav($audioPath);
    $of = $audioPath . '.whisper';
    $cmd = escapeshellcmd(KAIMOM_WHISPER_BIN)
         . ' -m ' . escapeshellarg(KAIMOM_WHISPER_MODEL)
         . ' -l ja -t ' . (int)KAIMOM_WHISPER_THREADS
         . ' -oj -of ' . escapeshellarg($of);
    if (KAIMOM_WHISPER_VAD_MODEL !== '' && is_file(KAIMOM_WHISPER_VAD_MODEL)) {
        $cmd .= ' --vad -vm ' . escapeshellarg(KAIMOM_WHISPER_VAD_MODEL);
    }
    $cmd .= ' --no-prints ' . escapeshellarg($wav) . ' 2>&1';
    exec($cmd, $out, $rc);
    if ($tmp) { @unlink($wav); }
    if ($rc !== 0 || !is_file($of . '.json')) {
        throw new RuntimeException('whisper実行に失敗: ' . implode(' / ', array_slice($out, -3)));
    }
    $res = km_parse_whisper_json(file_get_contents($of . '.json'));
    @unlink($of . '.json');
    return $res;
}

/* ---- 中継ユニット(デモ用)。音声を自社中継に送り、ジョブIDで受け取る ---- */

function km_relay_post($path, $body, $contentType, $timeout = 30) {
    $ch = curl_init(rtrim(KAIMOM_RELAY_URL, '/') . $path);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => array('Content-Type: ' . $contentType, 'Authorization: Bearer ' . KAIMOM_RELAY_TOKEN),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
    ));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) { throw new RuntimeException("中継エラー(HTTP {$code})"); }
    $d = json_decode($res, true);
    if (!is_array($d)) { throw new RuntimeException('中継応答を解釈できません'); }
    return $d;
}

function km_relay_get($path, $timeout = 20) {
    $ch = curl_init(rtrim(KAIMOM_RELAY_URL, '/') . $path);
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . KAIMOM_RELAY_TOKEN),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
    ));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) { throw new RuntimeException("中継エラー(HTTP {$code})"); }
    $d = json_decode($res, true);
    if (!is_array($d)) { throw new RuntimeException('中継応答を解釈できません'); }
    return $d;
}

/** 中継に音声を送ってジョブIDを得る。 */
function km_relay_transcribe_start($audioPath, $origName) {
    $d = km_relay_post('/transcribe?name=' . rawurlencode($origName),
        file_get_contents($audioPath), 'application/octet-stream', 60);
    if (empty($d['job'])) { throw new RuntimeException('中継がジョブIDを返しません'); }
    return $d['job'];
}

/* ================= 要約LLM (下書きのみ) ================= */

function km_summary_prompt($meta, $transcript) {
    $tr = mb_substr($transcript, 0, 24000);
    return "あなたは議事録の下書き係です。以下は会議の文字起こしです。\n"
        . "会議名: {$meta['title']} / 開催日: {$meta['meeting_date']}\n\n"
        . "この内容から議事録の下書きをJSONだけで出力してください。キーは\n"
        . "gaiyo(概要2〜4文の文字列), kettei(決定事項の配列),\n"
        . "todo(配列。各要素は item/tanto/kigen のオブジェクト。不明な担当や期限は空文字),\n"
        . "kadai(積み残し課題の配列), jikai(次回予定の文字列。無ければ空文字)。\n"
        . "文字起こしに無いことを書いてはいけません。推測で人名を作らないこと。\n\n"
        . "---文字起こし---\n{$tr}";
}

function km_parse_draft($text) {
    $text = trim((string)$text);
    if (preg_match('/\{.*\}/s', $text, $m)) { $text = $m[0]; }
    $d = json_decode($text, true);
    if (!is_array($d)) { return null; }
    $todo = array();
    foreach ((array)(isset($d['todo']) ? $d['todo'] : array()) as $t) {
        if (is_string($t)) { $todo[] = array('item' => $t, 'tanto' => '', 'kigen' => ''); }
        elseif (is_array($t)) {
            $todo[] = array(
                'item'  => (string)(isset($t['item']) ? $t['item'] : ''),
                'tanto' => (string)(isset($t['tanto']) ? $t['tanto'] : ''),
                'kigen' => (string)(isset($t['kigen']) ? $t['kigen'] : ''),
            );
        }
    }
    return array(
        'gaiyo'  => (string)(isset($d['gaiyo']) ? $d['gaiyo'] : ''),
        'kettei' => array_values(array_filter(array_map('strval', (array)(isset($d['kettei']) ? $d['kettei'] : array())))),
        'todo'   => $todo,
        'kadai'  => array_values(array_filter(array_map('strval', (array)(isset($d['kadai']) ? $d['kadai'] : array())))),
        'jikai'  => (string)(isset($d['jikai']) ? $d['jikai'] : ''),
    );
}

/** LLM無し・LLM失敗時のテンプレ下書き。文字起こし冒頭を概要枠に入れるだけで、捏造しない。 */
function km_template_draft($transcript) {
    $head = trim(mb_substr(preg_replace('/\s+/u', ' ', (string)$transcript), 0, 200));
    return array(
        'gaiyo'  => $head === '' ? '' : "(自動要約なし) 冒頭: {$head}…",
        'kettei' => array(), 'todo' => array(), 'kadai' => array(), 'jikai' => '',
    );
}

function km_llm_draft($meta, $transcript) {
    $prompt = km_summary_prompt($meta, $transcript);
    if (KAIMOM_LLM_KIND === 'ollama') {
        $body = array(
            'model' => KAIMOM_LLM_MODEL,
            'messages' => array(array('role' => 'user', 'content' => $prompt)),
            'stream' => false, 'format' => 'json',
            'options' => array('temperature' => 0.2, 'num_predict' => 1600),
        );
        /* gemma4系は思考型。think:false を必ず指定(隠れ推論トークン対策) */
        if (stripos(KAIMOM_LLM_MODEL, 'gemma') !== false) { $body['think'] = false; }
        $ch = curl_init(rtrim(KAIMOM_LLM_URL, '/') . '/api/chat');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ));
        $res = curl_exec($ch); curl_close($ch);
        $d = json_decode((string)$res, true);
        $text = isset($d['message']['content']) ? $d['message']['content'] : '';
    } elseif (KAIMOM_LLM_KIND === 'openai') {
        $body = array(
            'model' => KAIMOM_LLM_MODEL,
            'messages' => array(array('role' => 'user', 'content' => $prompt)),
            'temperature' => 0.2,
            'response_format' => array('type' => 'json_object'),
        );
        $ch = curl_init(rtrim(KAIMOM_LLM_URL, '/') . '/v1/chat/completions');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . KAIMOM_LLM_KEY),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ));
        $res = curl_exec($ch); curl_close($ch);
        $d = json_decode((string)$res, true);
        $text = isset($d['choices'][0]['message']['content']) ? $d['choices'][0]['message']['content'] : '';
    } elseif (KAIMOM_TRANSCRIBE === 'relay' && KAIMOM_RELAY_URL !== '') {
        /* デモ: 要約も中継経由(音声もテキストも自社サーバーの外に出さない) */
        $d = km_relay_post('/summarize', json_encode(array('prompt' => $prompt)), 'application/json', 300);
        $text = isset($d['text']) ? $d['text'] : '';
    } else {
        return null;
    }
    return km_parse_draft($text);
}

/* ================= AI処理の実行(worker/同期) ================= */

/** 文字起こし→下書き作成まで。worker(CLI)とrelay完了時の両方から呼ぶ。 */
function km_run_transcription($id) {
    km_gate('ai', 'transcript.draft');
    $m = km_meeting($id);
    if (!$m || $m['status'] !== 'transcribing') { return false; }
    try {
        list($text, $segs, $dur) = km_transcribe_local(KAIMOM_DATA_DIR . '/audio/' . $m['audio_file']);
        km_store_transcript($id, $text, $segs, $dur);
        return true;
    } catch (Exception $e) {
        km_move($id, 'failed', array('error' => $e->getMessage()));
        km_log('worker', 'transcribe.failed', $id, $e->getMessage());
        return false;
    }
}

/** 文字起こし結果の保存＋AI下書き作成(下書きまで。承認はしない・できない)。 */
function km_store_transcript($id, $text, $segs, $dur) {
    km_gate('ai', 'transcript.draft');
    km_move($id, 'transcribed', array(
        'transcript' => $text,
        'segments' => json_encode($segs, JSON_UNESCAPED_UNICODE),
        'duration_sec' => round((float)$dur, 1),
        'error' => '',
    ));
    km_log('worker', 'transcript.draft', $id, mb_strlen($text) . '文字');
    $m = km_meeting($id);
    km_gate('ai', 'summary.draft');
    $draft = null;
    try { $draft = km_llm_draft($m, $text); } catch (Exception $e) { $draft = null; }
    if (!$draft) { $draft = km_template_draft($text); }
    km_update($id, array('draft_json' => json_encode($draft, JSON_UNESCAPED_UNICODE)));
    km_log('worker', 'summary.draft', $id, $draft['gaiyo'] === '' ? '(空)' : mb_substr($draft['gaiyo'], 0, 60));
}

/** CLI worker 本体: queued を拾って処理する。--once で1周だけ。 */
function km_worker_run($loop = true) {
    while (true) {
        $st = km_db()->query("SELECT id FROM meetings WHERE status='queued' ORDER BY id LIMIT 1");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $id = (int)$row['id'];
            km_move($id, 'transcribing');
            km_log('worker', 'transcribe.start', $id);
            km_run_transcription($id);
        } elseif (!$loop) {
            break;
        } else {
            sleep(3);
        }
        if (!$loop && !$row) { break; }
    }
}

/* ================= 確定議事録 (Markdown) ================= */

function km_minutes_data($m) {
    $d = json_decode((string)$m['minutes_json'], true);
    if (!is_array($d)) { $d = json_decode((string)$m['draft_json'], true); }
    if (!is_array($d)) { $d = km_template_draft($m['transcript']); }
    return $d;
}

function km_minutes_md($m, $d) {
    $md = "# 議事録: {$m['title']}\n\n";
    $md .= "- 開催日: {$m['meeting_date']}\n";
    if ($m['location'] !== '')  { $md .= "- 場所: {$m['location']}\n"; }
    if ($m['attendees'] !== '') { $md .= "- 出席者: {$m['attendees']}\n"; }
    if ($m['approved_at'] !== '') { $md .= "- 承認: {$m['approved_by']} ({$m['approved_at']})\n"; }
    $md .= "\n## 概要\n\n" . trim((string)$d['gaiyo']) . "\n";
    $md .= "\n## 決定事項\n\n";
    if ($d['kettei']) { foreach ($d['kettei'] as $k) { $md .= "- {$k}\n"; } } else { $md .= "- (なし)\n"; }
    $md .= "\n## ToDo\n\n";
    if ($d['todo']) {
        foreach ($d['todo'] as $t) {
            $line = "- [ ] {$t['item']}";
            if ($t['tanto'] !== '') { $line .= " (担当: {$t['tanto']}"; $line .= $t['kigen'] !== '' ? " / 期限: {$t['kigen']})" : ')'; }
            elseif ($t['kigen'] !== '') { $line .= " (期限: {$t['kigen']})"; }
            $md .= $line . "\n";
        }
    } else { $md .= "- (なし)\n"; }
    if (!empty($d['kadai'])) {
        $md .= "\n## 課題(積み残し)\n\n";
        foreach ($d['kadai'] as $k) { $md .= "- {$k}\n"; }
    }
    if (trim((string)$d['jikai']) !== '') { $md .= "\n## 次回\n\n" . trim((string)$d['jikai']) . "\n"; }
    return $md;
}

/* ================= CLIモード(worker用): ここで終わり ================= */

if (defined('KAIMOM_CLI')) { return; }

/* ================= 認証・セッション ================= */

session_name('KAIMOMSESS');
session_start();

function km_logged_in() { return !empty($_SESSION['km_ok']); }

function km_check_password($pw) {
    if (KAIMOM_PASSWORD_HASH !== '') { return password_verify($pw, KAIMOM_PASSWORD_HASH); }
    if (KAIMOM_PASSWORD !== '') { return hash_equals(KAIMOM_PASSWORD, $pw); }
    return false;
}

function km_csrf() {
    if (empty($_SESSION['km_csrf'])) { $_SESSION['km_csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['km_csrf'];
}

function km_check_csrf() {
    $t = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if (empty($_SESSION['km_csrf']) || !hash_equals($_SESSION['km_csrf'], $t)) {
        km_json_out(403, array('error' => 'CSRFトークンが不正です。ページを開き直してください。'));
    }
}

/** アップロードのレート制限(ファイルベース・時間窓)。 */
function km_rate_ok($key) {
    $f = KAIMOM_DATA_DIR . '/rate_' . preg_replace('/[^a-z0-9_.]/i', '', $key) . '.json';
    $now = time();
    $lst = array();
    if (is_file($f)) { $lst = json_decode(file_get_contents($f), true) ?: array(); }
    $lst = array_values(array_filter($lst, function ($t) use ($now) { return $t > $now - 3600; }));
    if (count($lst) >= km_rate_max()) { return false; }
    $lst[] = $now;
    file_put_contents($f, json_encode($lst), LOCK_EX);
    return true;
}

/* ---- ログイン処理 ---- */
if (isset($_POST['do']) && $_POST['do'] === 'login') {
    $pw = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $name = trim(isset($_POST['name']) ? (string)$_POST['name'] : '');
    if (!km_rate_ok('login_' . md5((string)($_SERVER['REMOTE_ADDR'] ?? '')))) {
        km_log('web', 'login.rate', 0, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $login_error = '試行回数が多すぎます。しばらく待ってください。';
    } elseif (km_check_password($pw)) {
        session_regenerate_id(true);
        $_SESSION['km_ok'] = true;
        $_SESSION['km_name'] = $name !== '' ? mb_substr($name, 0, 40) : '担当者';
        km_log('web', 'login.ok', 0, $_SESSION['km_name']);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        km_log('web', 'login.ng', 0, '');
        $login_error = 'パスワードが違います。';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$BRAND = KAIMOM_BRAND_COLOR;

/* ================= 画面共通 ================= */

function km_page_head($title) {
    $b = KAIMOM_BRAND_COLOR;
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex">';
    echo '<title>' . km_h($title) . ' | ' . km_h(KAIMOM_TITLE) . '</title><style>';
    echo 'body{font-family:"Hiragino Sans","Noto Sans CJK JP",Meiryo,sans-serif;margin:0;background:#f4f6f7;color:#233}';
    echo 'header{background:' . $b . ';color:#fff;padding:10px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}';
    echo 'header a{color:#fff;text-decoration:none}header .t{font-weight:700;font-size:17px}header .sub{opacity:.85;font-size:12px}';
    echo 'main{max-width:1080px;margin:18px auto;padding:0 14px}';
    echo '.card{background:#fff;border:1px solid #dde3e6;border-radius:10px;padding:16px 18px;margin-bottom:16px}';
    echo 'h1{font-size:20px}h2{font-size:16px;border-left:4px solid ' . $b . ';padding-left:8px}';
    echo 'table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #e4e9ec;padding:8px 6px;text-align:left;font-size:14px;vertical-align:top}';
    echo 'input[type=text],input[type=date],input[type=password],textarea,select{width:100%;box-sizing:border-box;padding:8px;border:1px solid #c8d2d6;border-radius:6px;font-size:14px;font-family:inherit}';
    echo 'textarea{min-height:90px}label{font-size:13px;color:#456;display:block;margin:10px 0 4px}';
    echo '.btn{display:inline-block;background:' . $b . ';color:#fff;border:none;border-radius:6px;padding:9px 18px;font-size:14px;cursor:pointer;text-decoration:none}';
    echo '.btn.sub{background:#7b8a90}.btn.warn{background:#a33}.btn:disabled{opacity:.5}';
    echo '.badge{display:inline-block;border-radius:12px;padding:2px 10px;font-size:12px;color:#fff}';
    echo '.st-queued{background:#8a97a0}.st-transcribing{background:#c9862a}.st-transcribed{background:#2a76c9}.st-approved{background:#2c8a4b}.st-failed{background:#a33}';
    echo '.muted{color:#789;font-size:12px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}';
    echo '@media(max-width:700px){.grid2{grid-template-columns:1fr}}';
    echo '.seg{font-size:13px;line-height:1.7}.seg .ts{color:#96a5ad;font-size:11px;margin-right:6px}';
    echo '.demo{background:#fff6e0;border:1px solid #e5c97a;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px}';
    echo '@media print{header,.noprint{display:none}body{background:#fff}main{max-width:none;margin:0}.card{border:none;padding:0}}';
    echo '</style></head><body>';
    echo '<header><a class="t" href="?">' . km_h(KAIMOM_TITLE) . '</a><span class="sub">Kurage AI MOM — 録音から議事録の下書きまでAI、確定するのはあなた</span>';
    if (km_logged_in()) {
        echo '<span style="margin-left:auto" class="sub">' . km_h($_SESSION['km_name'] ?? '') . ' さん | <a href="?logout=1">ログアウト</a></span>';
    }
    echo '</header><main>';
    if (KAIMOM_DEMO) {
        echo '<div class="demo">デモ環境です: アップロードは15MB・1時間6回まで。データは定期的に消去されます。音声はデモ用中継サーバー(自社運用)で文字起こしされます。</div>';
    }
}

function km_page_foot() {
    echo '<p class="muted" style="margin:24px 0">Kurage AI MOM (Kurage AI議事録作成システム) — 文字起こしは whisper.cpp・確定は人。'
       . '音声も議事録もこのサーバーから出ません。</p></main></body></html>';
}

function km_status_badge($st) {
    $names = array('queued' => '待機中', 'transcribing' => '文字起こし中', 'transcribed' => '下書きあり(未承認)', 'approved' => '承認済み', 'failed' => '失敗');
    $n = isset($names[$st]) ? $names[$st] : $st;
    return '<span class="badge st-' . km_h($st) . '">' . km_h($n) . '</span>';
}

/* ================= ログイン画面 ================= */

if (!km_logged_in()) {
    km_page_head('ログイン');
    echo '<div class="card" style="max-width:420px;margin:40px auto"><h1>ログイン</h1>';
    if (!empty($login_error)) { echo '<p style="color:#a33">' . km_h($login_error) . '</p>'; }
    echo '<form method="post"><input type="hidden" name="do" value="login">';
    echo '<label>お名前(承認記録に残ります)</label><input type="text" name="name" placeholder="例: 小嶋">';
    echo '<label>パスワード</label><input type="password" name="password" autofocus>';
    echo '<p><button class="btn" type="submit">入る</button></p></form></div>';
    km_page_foot();
    exit;
}

/* ================= AJAX API ================= */

if (isset($_GET['api'])) {
    $api = $_GET['api'];
    if ($api === 'poll') {
        $m = km_meeting((int)($_GET['id'] ?? 0));
        if (!$m) { km_json_out(404, array('error' => 'not found')); }
        /* relayモード: 中継ジョブの完了をここで取り込む */
        if ($m['status'] === 'transcribing' && KAIMOM_TRANSCRIBE === 'relay' && $m['relay_job'] !== '') {
            try {
                $d = km_relay_get('/status/' . rawurlencode($m['relay_job']));
                if (($d['status'] ?? '') === 'done') {
                    km_store_transcript($m['id'], (string)$d['text'], (array)($d['segments'] ?? array()), (float)($d['duration'] ?? 0));
                    $m = km_meeting($m['id']);
                } elseif (($d['status'] ?? '') === 'failed') {
                    km_move($m['id'], 'failed', array('error' => (string)($d['error'] ?? '中継側で失敗')));
                    $m = km_meeting($m['id']);
                }
            } catch (Exception $e) { /* 一時的な中継エラーは次のポーリングで再試行 */ }
        }
        km_json_out(200, array('status' => $m['status'], 'error' => $m['error']));
    }
    km_json_out(404, array('error' => 'unknown api'));
}

/* ================= POST操作 ================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do']) && $_POST['do'] !== 'login') {
    km_check_csrf();
    $do = $_POST['do'];
    $actor = 'web:' . ($_SESSION['km_name'] ?? '');

    if ($do === 'create') {
        km_gate('human', 'meeting.create');
        $title = trim((string)($_POST['title'] ?? ''));
        $date  = trim((string)($_POST['meeting_date'] ?? ''));
        $loc   = trim((string)($_POST['location'] ?? ''));
        $att   = trim((string)($_POST['attendees'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) { km_json_out(400, array('error' => '会議名は1〜120文字で入力してください')); }
        if (!km_valid_date($date)) { km_json_out(400, array('error' => '開催日が不正です(YYYY-MM-DD)')); }
        $uerr = isset($_FILES['audio']['error']) ? (int)$_FILES['audio']['error'] : UPLOAD_ERR_NO_FILE;
        if ($uerr === UPLOAD_ERR_INI_SIZE || $uerr === UPLOAD_ERR_FORM_SIZE) {
            km_json_out(400, array('error' => 'ファイルがサーバーの上限を超えています。php.ini の upload_max_filesize / post_max_size を確認してください(現在 ' . ini_get('upload_max_filesize') . ')'));
        }
        if ($uerr !== UPLOAD_ERR_OK || empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
            km_json_out(400, array('error' => '録音ファイルを選択してください'));
        }
        $ext = km_audio_ext($_FILES['audio']['name']);
        if ($ext === '') { km_json_out(400, array('error' => '対応していない形式です(wav/mp3/m4a/flac/ogg等)')); }
        if ($_FILES['audio']['size'] > km_max_upload_bytes()) {
            km_json_out(400, array('error' => 'ファイルが大きすぎます(上限' . round(km_max_upload_bytes() / 1048576) . 'MB)'));
        }
        if (!km_rate_ok('up_' . session_id())) { km_json_out(429, array('error' => 'アップロードが多すぎます。1時間おいてください。')); }
        if (!is_dir(KAIMOM_DATA_DIR . '/audio')) { @mkdir(KAIMOM_DATA_DIR . '/audio', 0775, true); }
        $db = km_db();
        $db->prepare('INSERT INTO meetings(title,meeting_date,location,attendees,audio_name,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
           ->execute(array($title, $date, $loc, $att, (string)$_FILES['audio']['name'], km_now(), km_now()));
        $id = (int)$db->lastInsertId();
        $file = $id . '.' . $ext;
        if (!move_uploaded_file($_FILES['audio']['tmp_name'], KAIMOM_DATA_DIR . '/audio/' . $file)) {
            km_json_out(500, array('error' => 'ファイル保存に失敗しました'));
        }
        km_update($id, array('audio_file' => $file));
        km_log($actor, 'meeting.create', $id, $title);
        if (KAIMOM_TRANSCRIBE === 'relay' && KAIMOM_RELAY_URL !== '') {
            try {
                $job = km_relay_transcribe_start(KAIMOM_DATA_DIR . '/audio/' . $file, (string)$_FILES['audio']['name']);
                km_move($id, 'transcribing', array('relay_job' => $job));
            } catch (Exception $e) {
                km_move($id, 'failed', array('error' => '中継への送信に失敗: ' . $e->getMessage()));
            }
        }
        /* localモードは worker が queued を拾う */
        header('Location: ?p=m&id=' . $id);
        exit;
    }

    if ($do === 'retry') {
        km_gate('human', 'meeting.create');
        $m = km_meeting((int)($_POST['id'] ?? 0));
        if ($m && $m['status'] === 'failed') {
            km_move($m['id'], 'queued', array('error' => '', 'relay_job' => ''));
            km_log($actor, 'meeting.retry', $m['id']);
            if (KAIMOM_TRANSCRIBE === 'relay' && KAIMOM_RELAY_URL !== '') {
                try {
                    $job = km_relay_transcribe_start(KAIMOM_DATA_DIR . '/audio/' . $m['audio_file'], $m['audio_name']);
                    km_move($m['id'], 'transcribing', array('relay_job' => $job));
                } catch (Exception $e) {
                    km_move($m['id'], 'failed', array('error' => '中継への送信に失敗: ' . $e->getMessage()));
                }
            }
        }
        header('Location: ?p=m&id=' . (int)($_POST['id'] ?? 0));
        exit;
    }

    if ($do === 'delete') {
        km_gate('human', 'meeting.delete');
        $m = km_meeting((int)($_POST['id'] ?? 0));
        if ($m) {
            if ($m['audio_file'] !== '') { @unlink(KAIMOM_DATA_DIR . '/audio/' . $m['audio_file']); }
            km_db()->prepare('DELETE FROM meetings WHERE id=?')->execute(array((int)$m['id']));
            km_log($actor, 'meeting.delete', $m['id'], $m['title']);
        }
        header('Location: ?');
        exit;
    }

    if ($do === 'save_transcript') {
        km_gate('human', 'transcript.edit');
        $m = km_meeting((int)($_POST['id'] ?? 0));
        if (!$m || $m['status'] === 'approved') { km_json_out(400, array('error' => '編集できない状態です')); }
        km_update($m['id'], array('transcript' => (string)($_POST['transcript'] ?? '')));
        km_log($actor, 'transcript.edit', $m['id']);
        header('Location: ?p=m&id=' . $m['id'] . '#transcript');
        exit;
    }

    if ($do === 'redraft') {
        km_gate('human', 'draft.request');
        $m = km_meeting((int)($_POST['id'] ?? 0));
        if (!$m || $m['status'] !== 'transcribed') { km_json_out(400, array('error' => '下書きを作れる状態ではありません')); }
        km_gate('ai', 'summary.draft');
        $draft = null;
        try { $draft = km_llm_draft($m, $m['transcript']); } catch (Exception $e) { $draft = null; }
        if (!$draft) { $draft = km_template_draft($m['transcript']); }
        km_update($m['id'], array('draft_json' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'minutes_json' => ''));
        km_log($actor, 'draft.request', $m['id']);
        header('Location: ?p=m&id=' . $m['id'] . '#minutes');
        exit;
    }

    if ($do === 'save_minutes' || $do === 'approve') {
        km_gate('human', $do === 'approve' ? 'minutes.approve' : 'minutes.edit');
        $m = km_meeting((int)($_POST['id'] ?? 0));
        if (!$m || $m['status'] !== 'transcribed') { km_json_out(400, array('error' => 'この状態では保存/承認できません')); }
        $todo = array();
        $items = (array)($_POST['todo_item'] ?? array());
        foreach ($items as $i => $it) {
            $it = trim((string)$it);
            if ($it === '') { continue; }
            $todo[] = array(
                'item' => $it,
                'tanto' => trim((string)($_POST['todo_tanto'][$i] ?? '')),
                'kigen' => trim((string)($_POST['todo_kigen'][$i] ?? '')),
            );
        }
        $d = array(
            'gaiyo'  => trim((string)($_POST['gaiyo'] ?? '')),
            'kettei' => array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['kettei'] ?? ''))))),
            'todo'   => $todo,
            'kadai'  => array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['kadai'] ?? ''))))),
            'jikai'  => trim((string)($_POST['jikai'] ?? '')),
        );
        km_update($m['id'], array('minutes_json' => json_encode($d, JSON_UNESCAPED_UNICODE)));
        km_log($actor, 'minutes.edit', $m['id']);
        if ($do === 'approve') {
            /* 承認は人だけ。km_can('ai','minutes.approve') は false — これが関門 */
            $by = $_SESSION['km_name'] ?? '担当者';
            $m2 = km_meeting($m['id']);
            $md = km_minutes_md(array_merge($m2, array('approved_at' => km_now(), 'approved_by' => $by)), $d);
            km_move($m['id'], 'approved', array('minutes_md' => $md, 'approved_at' => km_now(), 'approved_by' => $by));
            km_log($actor, 'minutes.approve', $m['id']);
        }
        header('Location: ?p=m&id=' . $m['id'] . '#minutes');
        exit;
    }

    if ($do === 'reopen') {
        km_gate('human', 'minutes.reopen');
        $m = km_meeting((int)($_POST['id'] ?? 0));
        if ($m && $m['status'] === 'approved') {
            km_move($m['id'], 'transcribed', array('approved_at' => '', 'approved_by' => '', 'minutes_md' => ''));
            km_log($actor, 'minutes.reopen', $m['id']);
        }
        header('Location: ?p=m&id=' . (int)($_POST['id'] ?? 0));
        exit;
    }

    km_json_out(400, array('error' => '不明な操作です'));
}

/* ================= GET画面 ================= */

$p = isset($_GET['p']) ? $_GET['p'] : 'list';

/* ---- 音声配信(ログイン済みのみ) ---- */
if ($p === 'audio') {
    $m = km_meeting((int)($_GET['id'] ?? 0));
    if (!$m || $m['audio_file'] === '') { http_response_code(404); exit('not found'); }
    $f = KAIMOM_DATA_DIR . '/audio/' . $m['audio_file'];
    if (!is_file($f)) { http_response_code(404); exit('not found'); }
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $types = array('wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'mp4' => 'audio/mp4',
                   'flac' => 'audio/flac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'webm' => 'audio/webm');
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($f));
    header('X-Content-Type-Options: nosniff');
    readfile($f);
    exit;
}

/* ---- Markdownダウンロード ---- */
if ($p === 'dl') {
    $m = km_meeting((int)($_GET['id'] ?? 0));
    if (!$m) { http_response_code(404); exit('not found'); }
    $md = $m['status'] === 'approved' ? $m['minutes_md'] : km_minutes_md($m, km_minutes_data($m)) . "\n> ※未承認の下書きです\n";
    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Disposition: attachment; filename="minutes-' . (int)$m['id'] . '.md"');
    echo $md;
    exit;
}

/* ---- 印刷ビュー ---- */
if ($p === 'print') {
    $m = km_meeting((int)($_GET['id'] ?? 0));
    if (!$m) { http_response_code(404); exit('not found'); }
    $d = km_minutes_data($m);
    km_page_head('印刷: ' . $m['title']);
    echo '<div class="card"><p class="noprint"><a class="btn" href="javascript:print()">印刷</a> <a class="btn sub" href="?p=m&id=' . (int)$m['id'] . '">戻る</a></p>';
    echo '<h1>議事録: ' . km_h($m['title']) . '</h1>';
    echo '<table style="max-width:560px"><tr><th>開催日</th><td>' . km_h($m['meeting_date']) . '</td></tr>';
    if ($m['location'] !== '') { echo '<tr><th>場所</th><td>' . km_h($m['location']) . '</td></tr>'; }
    if ($m['attendees'] !== '') { echo '<tr><th>出席者</th><td>' . km_h($m['attendees']) . '</td></tr>'; }
    if ($m['approved_at'] !== '') { echo '<tr><th>承認</th><td>' . km_h($m['approved_by']) . ' (' . km_h($m['approved_at']) . ')</td></tr>'; }
    else { echo '<tr><th>状態</th><td>未承認の下書き</td></tr>'; }
    echo '</table>';
    echo '<h2>概要</h2><p>' . nl2br(km_h($d['gaiyo'])) . '</p>';
    echo '<h2>決定事項</h2><ul>';
    if ($d['kettei']) { foreach ($d['kettei'] as $k) { echo '<li>' . km_h($k) . '</li>'; } } else { echo '<li>(なし)</li>'; }
    echo '</ul><h2>ToDo</h2><ul>';
    if ($d['todo']) {
        foreach ($d['todo'] as $t) {
            $s = $t['item'];
            if ($t['tanto'] !== '') { $s .= ' (担当: ' . $t['tanto'] . ($t['kigen'] !== '' ? ' / 期限: ' . $t['kigen'] : '') . ')'; }
            elseif ($t['kigen'] !== '') { $s .= ' (期限: ' . $t['kigen'] . ')'; }
            echo '<li>' . km_h($s) . '</li>';
        }
    } else { echo '<li>(なし)</li>'; }
    echo '</ul>';
    if (!empty($d['kadai'])) { echo '<h2>課題(積み残し)</h2><ul>'; foreach ($d['kadai'] as $k) { echo '<li>' . km_h($k) . '</li>'; } echo '</ul>'; }
    if (trim((string)$d['jikai']) !== '') { echo '<h2>次回</h2><p>' . nl2br(km_h($d['jikai'])) . '</p>'; }
    echo '</div>';
    km_page_foot();
    exit;
}

/* ---- 会議詳細 ---- */
if ($p === 'm') {
    $m = km_meeting((int)($_GET['id'] ?? 0));
    if (!$m) { http_response_code(404); exit('not found'); }
    $d = km_minutes_data($m);
    km_page_head($m['title']);
    echo '<div class="card"><h1>' . km_h($m['title']) . ' ' . km_status_badge($m['status']) . '</h1>';
    echo '<p class="muted">開催日 ' . km_h($m['meeting_date'])
       . ($m['location'] !== '' ? ' / ' . km_h($m['location']) : '')
       . ($m['attendees'] !== '' ? ' / 出席: ' . km_h($m['attendees']) : '')
       . ($m['duration_sec'] > 0 ? ' / 録音 ' . gmdate('i分s秒', (int)$m['duration_sec']) : '') . '</p>';
    if ($m['audio_file'] !== '') {
        echo '<audio controls preload="none" style="width:100%" src="?p=audio&id=' . (int)$m['id'] . '"></audio>';
    }
    if ($m['status'] === 'failed') {
        echo '<p style="color:#a33">失敗: ' . km_h($m['error']) . '</p>';
        echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . km_csrf() . '"><input type="hidden" name="do" value="retry"><input type="hidden" name="id" value="' . (int)$m['id'] . '"><button class="btn">再実行</button></form>';
    }
    if ($m['status'] === 'queued' || $m['status'] === 'transcribing') {
        echo '<p id="waitmsg">文字起こし中です。このページを開いたままお待ちください(自動で更新されます)。</p>';
        echo '<script>function poll(){fetch("?api=poll&id=' . (int)$m['id'] . '").then(r=>r.json()).then(d=>{'
           . 'if(d.status==="transcribed"||d.status==="approved"||d.status==="failed"){location.reload();}else{setTimeout(poll,4000);}'
           . '}).catch(()=>setTimeout(poll,6000));}poll();</script>';
    }
    echo '<p class="noprint" style="margin-top:10px">'
       . '<a class="btn sub" href="?p=print&id=' . (int)$m['id'] . '">印刷ビュー</a> '
       . '<a class="btn sub" href="?p=dl&id=' . (int)$m['id'] . '">Markdown</a> ';
    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'この会議を録音ごと削除します。よろしいですか?\')">'
       . '<input type="hidden" name="csrf" value="' . km_csrf() . '"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . (int)$m['id'] . '">'
       . '<button class="btn warn">削除</button></form></p>';
    echo '</div>';

    /* 議事録(下書き→編集→承認) */
    if ($m['status'] === 'transcribed' || $m['status'] === 'approved') {
        echo '<div class="card" id="minutes"><h2>議事録' . ($m['status'] === 'approved' ? '(承認済み・確定)' : '(下書き — 内容はAIの提案。全文と見比べて確定してください)') . '</h2>';
        if ($m['status'] === 'approved') {
            echo '<p class="muted">承認: ' . km_h($m['approved_by']) . ' / ' . km_h($m['approved_at']) . '</p>';
            echo '<h2>概要</h2><p>' . nl2br(km_h($d['gaiyo'])) . '</p>';
            echo '<h2>決定事項</h2><ul>';
            if ($d['kettei']) { foreach ($d['kettei'] as $k) { echo '<li>' . km_h($k) . '</li>'; } } else { echo '<li>(なし)</li>'; }
            echo '</ul><h2>ToDo</h2><ul>';
            if ($d['todo']) { foreach ($d['todo'] as $t) { echo '<li>' . km_h($t['item'] . ($t['tanto'] !== '' ? ' (担当: ' . $t['tanto'] . ($t['kigen'] !== '' ? ' / 期限: ' . $t['kigen'] : '') . ')' : '')) . '</li>'; } } else { echo '<li>(なし)</li>'; }
            echo '</ul>';
            if (!empty($d['kadai'])) { echo '<h2>課題</h2><ul>'; foreach ($d['kadai'] as $k) { echo '<li>' . km_h($k) . '</li>'; } echo '</ul>'; }
            if (trim((string)$d['jikai']) !== '') { echo '<h2>次回</h2><p>' . nl2br(km_h($d['jikai'])) . '</p>'; }
            echo '<form method="post" onsubmit="return confirm(\'承認を取り消して下書きに戻します。よろしいですか?\')">'
               . '<input type="hidden" name="csrf" value="' . km_csrf() . '"><input type="hidden" name="do" value="reopen"><input type="hidden" name="id" value="' . (int)$m['id'] . '">'
               . '<button class="btn sub">差し戻す(承認取り消し)</button></form>';
        } else {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . km_csrf() . '"><input type="hidden" name="id" value="' . (int)$m['id'] . '">';
            echo '<label>概要</label><textarea name="gaiyo">' . km_h($d['gaiyo']) . '</textarea>';
            echo '<div class="grid2"><div><label>決定事項(1行1件)</label><textarea name="kettei">' . km_h(implode("\n", $d['kettei'])) . '</textarea></div>';
            echo '<div><label>課題・積み残し(1行1件)</label><textarea name="kadai">' . km_h(implode("\n", $d['kadai'])) . '</textarea></div></div>';
            echo '<label>ToDo</label><table id="todos"><tr><th style="width:55%">内容</th><th>担当</th><th>期限</th></tr>';
            $rows = $d['todo']; $rows[] = array('item' => '', 'tanto' => '', 'kigen' => '');
            $rows[] = array('item' => '', 'tanto' => '', 'kigen' => '');
            foreach ($rows as $t) {
                echo '<tr><td><input type="text" name="todo_item[]" value="' . km_h($t['item']) . '"></td>'
                   . '<td><input type="text" name="todo_tanto[]" value="' . km_h($t['tanto']) . '"></td>'
                   . '<td><input type="text" name="todo_kigen[]" value="' . km_h($t['kigen']) . '"></td></tr>';
            }
            echo '</table>';
            echo '<label>次回</label><input type="text" name="jikai" value="' . km_h($d['jikai']) . '">';
            echo '<p style="margin-top:14px"><button class="btn sub" name="do" value="save_minutes">下書き保存</button> '
               . '<button class="btn" name="do" value="approve" onclick="return confirm(\'この内容で議事録を確定します。よろしいですか?\')">承認して確定</button></p>';
            echo '</form>';
            echo '<form method="post" style="margin-top:6px"><input type="hidden" name="csrf" value="' . km_csrf() . '"><input type="hidden" name="do" value="redraft"><input type="hidden" name="id" value="' . (int)$m['id'] . '">'
               . '<button class="btn sub" onclick="return confirm(\'AI下書きを作り直します。編集中の内容は消えます。\')">AI下書きを作り直す</button></form>';
        }
        echo '</div>';
    }

    /* 文字起こし全文 */
    if ($m['transcript'] !== '') {
        echo '<div class="card" id="transcript"><h2>文字起こし全文(原本)</h2>';
        $segs = json_decode((string)$m['segments'], true);
        if (is_array($segs) && $segs) {
            echo '<div class="seg" style="max-height:340px;overflow:auto;border:1px solid #e4e9ec;border-radius:6px;padding:10px">';
            foreach ($segs as $s) {
                echo '<div><span class="ts">' . gmdate('i:s', (int)$s[0]) . '</span>' . km_h($s[2]) . '</div>';
            }
            echo '</div>';
        }
        if ($m['status'] !== 'approved') {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . km_csrf() . '"><input type="hidden" name="do" value="save_transcript"><input type="hidden" name="id" value="' . (int)$m['id'] . '">';
            echo '<label>全文の修正(話者名を頭に付けるなど。確定議事録には影響しません)</label>';
            echo '<textarea name="transcript" style="min-height:180px">' . km_h($m['transcript']) . '</textarea>';
            echo '<p><button class="btn sub">全文を保存</button></p></form>';
        } else {
            echo '<pre style="white-space:pre-wrap;font-family:inherit;font-size:13px">' . km_h($m['transcript']) . '</pre>';
        }
        echo '</div>';
    }
    km_page_foot();
    exit;
}

/* ---- 新規アップロード ---- */
if ($p === 'new') {
    km_page_head('新しい会議');
    echo '<div class="card"><h1>録音をアップロード</h1>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="csrf" value="' . km_csrf() . '"><input type="hidden" name="do" value="create">';
    echo '<div class="grid2"><div><label>会議名 *</label><input type="text" name="title" required placeholder="例: 8月度 定例会議"></div>';
    echo '<div><label>開催日 *</label><input type="date" name="meeting_date" required value="' . date('Y-m-d') . '"></div></div>';
    echo '<div class="grid2"><div><label>場所</label><input type="text" name="location" placeholder="例: 本社会議室 / オンライン"></div>';
    echo '<div><label>出席者</label><input type="text" name="attendees" placeholder="例: 小嶋、佐藤、鈴木"></div></div>';
    echo '<label>録音ファイル * (wav/mp3/m4a/flac等・上限' . round(km_max_upload_bytes() / 1048576) . 'MB)</label>';
    echo '<input type="file" name="audio" accept="audio/*,video/mp4" required>';
    echo '<p style="margin-top:14px"><button class="btn">アップロードして文字起こし開始</button></p></form>';
    echo '<p class="muted">ICレコーダー・スマホ録音のファイルをそのまま入れられます。音声はこのサーバーの whisper.cpp で処理され、外部に送信されません。</p>';
    echo '</div>';
    km_page_foot();
    exit;
}

/* ---- 一覧(トップ) ---- */
$q = trim((string)($_GET['q'] ?? ''));
km_page_head('会議一覧');
echo '<div class="card"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h1 style="margin:0">会議一覧</h1>';
echo '<a class="btn" href="?p=new">+ 録音をアップロード</a>';
echo '<form method="get" style="margin-left:auto;display:flex;gap:6px"><input type="text" name="q" value="' . km_h($q) . '" placeholder="検索(会議名・出席者・全文)">'
   . '<button class="btn sub">検索</button></form></div></div>';
$db = km_db();
if ($q !== '') {
    $st = $db->prepare('SELECT * FROM meetings WHERE title LIKE ? OR attendees LIKE ? OR transcript LIKE ? ORDER BY meeting_date DESC, id DESC LIMIT 200');
    $like = '%' . $q . '%';
    $st->execute(array($like, $like, $like));
} else {
    $st = $db->query('SELECT * FROM meetings ORDER BY meeting_date DESC, id DESC LIMIT 200');
}
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
echo '<div class="card">';
if (!$rows) {
    echo '<p>まだ会議がありません。「録音をアップロード」から始めてください。</p>';
} else {
    echo '<table><tr><th>開催日</th><th>会議名</th><th>状態</th><th>録音</th><th>承認</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>' . km_h($r['meeting_date']) . '</td>';
        echo '<td><a href="?p=m&id=' . (int)$r['id'] . '">' . km_h($r['title']) . '</a></td>';
        echo '<td>' . km_status_badge($r['status']) . '</td>';
        echo '<td>' . ($r['duration_sec'] > 0 ? gmdate('i分s秒', (int)$r['duration_sec']) : '—') . '</td>';
        echo '<td class="muted">' . km_h($r['approved_by']) . '</td></tr>';
    }
    echo '</table>';
}
echo '</div>';
km_page_foot();
