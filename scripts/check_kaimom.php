<?php
/**
 * kaimom 自己テスト。AI・whisper・ネットワークなしで、関門と検証と状態遷移と
 * 議事録生成を機械検証する。デプロイ前に必ず通すこと。
 *   php scripts/check_kaimom.php
 */
define('KAIMOM_CLI', true);

/* テスト用の隔離データディレクトリ */
$tmp = sys_get_temp_dir() . '/kaimom_test_' . getmypid();
@mkdir($tmp, 0775, true);
define('KAIMOM_DATA_DIR', $tmp);

/* config読み込みをスキップするためexampleを一時コピー */
$pub = __DIR__ . '/../public';
if (!is_file($pub . '/kaimom_config.php')) {
    file_put_contents($pub . '/kaimom_config.php', "<?php /* auto for test */\n");
    $made_cfg = true;
}
require $pub . '/kaimom.php';

$fail = 0; $n = 0;
function ok($cond, $name) {
    global $fail, $n;
    $n++;
    if ($cond) { echo "ok  {$name}\n"; }
    else { $fail++; echo "NG  {$name}\n"; }
}

/* ---- 1. 関門 km_can: AIは承認できない ---- */
ok(km_can('human', 'minutes.approve') === true,  '人は承認できる');
ok(km_can('ai', 'minutes.approve') === false,    'AIは承認できない(芯)');
ok(km_can('ai', 'transcript.draft') === true,    'AIは文字起こし下書きを書ける');
ok(km_can('ai', 'summary.draft') === true,       'AIは要約下書きを書ける');
ok(km_can('ai', 'meeting.delete') === false,     'AIは会議を消せない');
ok(km_can('ai', 'transcript.edit') === false,    'AIは全文を書き換えられない(下書き投入のみ)');
ok(km_can('human', 'transcript.draft') === false, '人の入口に文字起こし下書きはない(workerの仕事)');
ok(km_can('nobody', 'minutes.approve') === false, '未宣言の入口は全部拒否');
try { km_gate('ai', 'minutes.approve'); ok(false, 'km_gateがAI承認を止める'); }
catch (RuntimeException $e) { ok(true, 'km_gateがAI承認を止める'); }

/* ---- 2. 入力検証 ---- */
ok(km_valid_date('2026-08-26') === true,  '日付OK');
ok(km_valid_date('2026-02-30') === false, '存在しない日付NG');
ok(km_valid_date('26/08/26') === false,   '形式違いNG');
ok(km_audio_ext('rec.m4a') === 'm4a',     'm4a許可');
ok(km_audio_ext('rec.MP3') === 'mp3',     '大文字拡張子');
ok(km_audio_ext('evil.php') === '',       'php拒否');
ok(km_audio_ext('evil.php.wav') === 'wav', '二重拡張子は最後で判定');

/* ---- 3. 状態遷移表 ---- */
ok(km_can_move('queued', 'transcribing') === true,   'queued→transcribing');
ok(km_can_move('transcribed', 'approved') === true,  'transcribed→approved');
ok(km_can_move('queued', 'approved') === false,      'queued→approved 直行は禁止');
ok(km_can_move('approved', 'transcribed') === true,  '承認済み→差し戻し');
ok(km_can_move('failed', 'queued') === true,         'failed→再実行');
ok(km_can_move('approved', 'queued') === false,      '承認済み→queuedは禁止');

/* ---- 4. DBと遷移の実動作 ---- */
$db = km_db();
$db->prepare('INSERT INTO meetings(title,meeting_date,created_at,updated_at) VALUES(?,?,?,?)')
   ->execute(array('テスト会議', '2026-08-26', km_now(), km_now()));
$id = (int)$db->lastInsertId();
$m = km_meeting($id);
ok($m && $m['status'] === 'queued', '新規はqueued');
km_move($id, 'transcribing');
ok(km_meeting($id)['status'] === 'transcribing', 'move動作');
try { km_move($id, 'approved'); ok(false, '不正遷移は例外'); }
catch (RuntimeException $e) { ok(true, '不正遷移は例外'); }

/* ---- 5. whisper JSON解釈 ---- */
$fixture = json_encode(array('transcription' => array(
    array('offsets' => array('from' => 0, 'to' => 4500), 'text' => ' こんにちは'),
    array('offsets' => array('from' => 4500, 'to' => 9000), 'text' => ' 会議を始めます'),
    array('offsets' => array('from' => 9000, 'to' => 9100), 'text' => '  '),
)));
list($text, $segs, $dur) = km_parse_whisper_json($fixture);
ok($text === "こんにちは\n会議を始めます", '全文整形(空セグメント除去)');
ok(count($segs) === 2 && $segs[1][0] === 4.5, 'セグメントと秒変換');
ok($dur === 9.0, '長さ');
try { km_parse_whisper_json('{"bad":1}'); ok(false, '壊れたJSONは例外'); }
catch (RuntimeException $e) { ok(true, '壊れたJSONは例外'); }

/* ---- 6. AI下書きの解釈(壊れた応答に耐える) ---- */
$d = km_parse_draft('{"gaiyo":"要約です","kettei":["A案で進める"],"todo":[{"item":"見積作成","tanto":"佐藤","kigen":"9/1"},"資料送付"],"kadai":[],"jikai":"9月2日"}');
ok($d['gaiyo'] === '要約です' && $d['kettei'][0] === 'A案で進める', '下書きJSON解釈');
ok($d['todo'][1]['item'] === '資料送付' && $d['todo'][1]['tanto'] === '', '文字列todoも吸収');
$d2 = km_parse_draft("前置きテキスト {\"gaiyo\":\"x\",\"kettei\":[]} 後置き");
ok(is_array($d2) && $d2['gaiyo'] === 'x', '前後にゴミがあるJSONを救出');
ok(km_parse_draft('こわれた応答') === null, '解釈不能はnull(テンプレへフォールバック)');
$t = km_template_draft('これは長い文字起こしのテストです。');
ok(strpos($t['gaiyo'], '自動要約なし') !== false && $t['kettei'] === array(), 'テンプレ下書きは捏造しない');

/* ---- 7. 議事録Markdown ---- */
km_move($id, 'transcribed', array('transcript' => 'テスト全文'));
$m = km_meeting($id);
$md = km_minutes_md(array_merge($m, array('approved_at' => '2026-08-26 12:00:00', 'approved_by' => '小嶋')),
    array('gaiyo' => '概要文', 'kettei' => array('決定1'), 'todo' => array(array('item' => 'タスク', 'tanto' => '佐藤', 'kigen' => '9/1')), 'kadai' => array(), 'jikai' => '次回9/2'));
ok(strpos($md, '# 議事録: テスト会議') === 0, 'Markdown見出し');
ok(strpos($md, '- [ ] タスク (担当: 佐藤 / 期限: 9/1)') !== false, 'ToDo行');
ok(strpos($md, '承認: 小嶋') !== false, '承認行');

/* ---- 8. 承認遷移(worker=AI入口からは通らないことをコードパスで確認済み) ---- */
km_move($id, 'approved', array('minutes_md' => $md, 'approved_at' => km_now(), 'approved_by' => 'テスト'));
ok(km_meeting($id)['status'] === 'approved', '承認遷移');
km_move($id, 'transcribed', array('approved_at' => '', 'approved_by' => ''));
ok(km_meeting($id)['status'] === 'transcribed', '差し戻し');

/* 後始末 */
@unlink($tmp . '/kaimom.sqlite'); @unlink($tmp . '/kaimom.sqlite-wal'); @unlink($tmp . '/kaimom.sqlite-shm');
@rmdir($tmp);
if (!empty($made_cfg)) { @unlink($pub . '/kaimom_config.php'); }

echo "----\n{$n}件中 " . ($n - $fail) . "件OK\n";
exit($fail ? 1 : 0);
