<?php
/**
 * Kurage AI MOM — MCPサーバー（1ファイル・依存ライブラリなし）
 *
 * Claude Code や Claude Desktop などのAIエージェントから、確定済みの議事録を
 * 検索・参照するための橋渡し。「先月の定例で決まったことは?」「山田さんの宿題は?」
 * といった質問に、AIが自社の議事録を根拠として答えられるようになる。
 *
 * 設置:
 *   1. kaimom.php と同じフォルダにこのファイルを置く
 *   2. Claude Code に登録:
 *        claude mcp add kaimom -- php /path/to/kaimom_mcp.php
 *      Claude Desktop の場合は claude_desktop_config.json に:
 *        {"mcpServers":{"kaimom":{"command":"php","args":["/path/to/kaimom_mcp.php"]}}}
 *
 * 参照できるのは「承認して確定した議事録」だけです。処理中・未承認のAI下書きは
 * 出しません（人が確認していない文章を、AIに事実として使わせないため）。
 * 音声ファイルと逐語の全文は返しません。読み取り専用で、書き込む口はありません。
 *
 * KAIMOM_MCP_INCLUDE_TRANSCRIPT=1 を設定すると、逐語(transcript)の参照を許可します。
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('KAIMOM_MCP_VERSION', '1.0.0');
define('KAIMOM_MCP', true);
define('KAIMOM_CLI', true);          // kaimom.php のCLI経路で読み込む（HTMLを出力させない）

$INCLUDE_TRANSCRIPT = (getenv('KAIMOM_MCP_INCLUDE_TRANSCRIPT') === '1');

$base = __DIR__ . '/kaimom.php';
if (!is_file($base)) { fwrite(STDERR, "kaimom.php が同じフォルダにありません: $base\n"); exit(1); }

// 設定と関数だけを読み込む（画面描画には入らない）
ob_start();
require $base;
ob_end_clean();

if (!function_exists('km_db')) { fwrite(STDERR, "kaimom.php を読み込めませんでした\n"); exit(1); }

/** 一覧・検索用の軽い行（本文は含めない） */
function kmcp_brief($r)
{
    return array(
        'id' => (int)$r['id'],
        'title' => $r['title'],
        'meeting_date' => $r['meeting_date'],
        'location' => $r['location'],
        'attendees' => $r['attendees'],
        'duration_min' => round(((float)$r['duration_sec']) / 60, 1),
        'updated_at' => $r['updated_at'],
    );
}

function kmcp_list($a)
{
    $db = km_db();
    $where = array("status='done'", "minutes_md<>''");
    $bind = array();
    if (!empty($a['from'])) { $where[] = 'meeting_date>=?'; $bind[] = $a['from']; }
    if (!empty($a['to']))   { $where[] = 'meeting_date<=?'; $bind[] = $a['to']; }
    $limit = isset($a['limit']) ? max(1, min(100, (int)$a['limit'])) : 20;
    $sql = 'SELECT * FROM meetings WHERE ' . implode(' AND ', $where)
         . ' ORDER BY meeting_date DESC, id DESC LIMIT ' . $limit;
    $st = $db->prepare($sql); $st->execute($bind);
    $rows = array_map('kmcp_brief', $st->fetchAll(PDO::FETCH_ASSOC));
    return array(true, json_encode(array('ok' => true, 'count' => count($rows), 'meetings' => $rows),
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function kmcp_search($a)
{
    $q = isset($a['query']) ? trim((string)$a['query']) : '';
    if ($q === '') { return array(false, json_encode(array('ok' => false, 'error' => '検索語を指定してください'), JSON_UNESCAPED_UNICODE)); }
    $limit = isset($a['limit']) ? max(1, min(50, (int)$a['limit'])) : 10;
    $st = km_db()->prepare(
        "SELECT * FROM meetings WHERE status='done' AND minutes_md<>''
         AND (title LIKE ? OR attendees LIKE ? OR minutes_md LIKE ?)
         ORDER BY meeting_date DESC, id DESC LIMIT " . $limit);
    $like = '%' . $q . '%';
    $st->execute(array($like, $like, $like));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b = kmcp_brief($r);
        // 一致箇所の周辺だけを抜粋して返す（全文は kaimom_get で取る）
        $md = (string)$r['minutes_md'];
        $pos = mb_stripos($md, $q);
        $b['excerpt'] = $pos === false ? mb_substr($md, 0, 120)
            : mb_substr($md, max(0, $pos - 60), 200);
        $out[] = $b;
    }
    return array(true, json_encode(array('ok' => true, 'query' => $q, 'count' => count($out), 'meetings' => $out),
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function kmcp_get($a, $includeTranscript)
{
    $id = isset($a['id']) ? (int)$a['id'] : 0;
    $r = km_meeting($id);
    if (!$r) { return array(false, json_encode(array('ok' => false, 'error' => 'その議事録はありません: ' . $id), JSON_UNESCAPED_UNICODE)); }
    if ($r['status'] !== 'done' || $r['minutes_md'] === '') {
        return array(false, json_encode(array('ok' => false,
            'error' => 'この議事録はまだ確定していません（状態: ' . $r['status'] . '）。人が承認した議事録だけを参照できます。'),
            JSON_UNESCAPED_UNICODE));
    }
    $d = kmcp_brief($r);
    $d['minutes_md'] = $r['minutes_md'];
    if ($includeTranscript && $r['transcript'] !== '') { $d['transcript'] = $r['transcript']; }
    return array(true, json_encode(array('ok' => true, 'meeting' => $d),
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$TOOLS = array(
    array(
        'name' => 'kaimom_list',
        'description' => '確定済みの議事録を新しい順に一覧する。日付で絞り込める。本文は含まないので、中身が必要なら kaimom_get を使う。',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'from'  => array('type' => 'string', 'description' => 'この日以降（YYYY-MM-DD、任意）'),
                'to'    => array('type' => 'string', 'description' => 'この日以前（YYYY-MM-DD、任意）'),
                'limit' => array('type' => 'integer', 'description' => '件数の上限（既定20・最大100）'),
            ),
            'required' => array(),
        ),
    ),
    array(
        'name' => 'kaimom_search',
        'description' => '確定済みの議事録を全文検索する。会議名・出席者・議事録本文が対象。一致箇所の抜粋つきで返る。「先月の定例で決まったこと」「山田さんの宿題」などを調べるときに使う。',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'query' => array('type' => 'string', 'description' => '検索する語句'),
                'limit' => array('type' => 'integer', 'description' => '件数の上限（既定10・最大50）'),
            ),
            'required' => array('query'),
        ),
    ),
    array(
        'name' => 'kaimom_get',
        'description' => '議事録1件の全文を取得する。決定事項・宿題（ToDo）・議論の要約を含む確定版のMarkdownが返る。回答の根拠にするときは、会議名と日付も一緒に示すこと。',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array('id' => array('type' => 'integer', 'description' => '議事録のID（kaimom_list / kaimom_search で得たもの）')),
            'required' => array('id'),
        ),
    ),
);

function kmcp_send($m) { echo json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; flush(); }
function kmcp_result($id, $r) { kmcp_send(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $r)); }

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') { continue; }
    $req = json_decode($line, true);
    if (!is_array($req)) { continue; }
    $id = isset($req['id']) ? $req['id'] : null;
    $method = isset($req['method']) ? $req['method'] : '';
    $params = isset($req['params']) && is_array($req['params']) ? $req['params'] : array();
    if ($id === null && strpos($method, 'notifications/') === 0) { continue; }

    switch ($method) {
        case 'initialize':
            kmcp_result($id, array(
                'protocolVersion' => isset($params['protocolVersion']) ? (string)$params['protocolVersion'] : '2024-11-05',
                'capabilities'    => array('tools' => new stdClass()),
                'serverInfo'      => array('name' => 'kaimom', 'version' => KAIMOM_MCP_VERSION),
                'instructions'    => '自社の議事録を検索・参照する窓口です。参照できるのは人が承認して確定した議事録だけで、未承認のAI下書きは出てきません。議事録を根拠に答えるときは、会議名と日付を必ず添えてください。',
            ));
            break;
        case 'ping':
            kmcp_result($id, new stdClass());
            break;
        case 'tools/list':
            kmcp_result($id, array('tools' => $GLOBALS['TOOLS']));
            break;
        case 'tools/call':
            $name = isset($params['name']) ? $params['name'] : '';
            $a = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();
            try {
                if ($name === 'kaimom_list')        { list($ok, $text) = kmcp_list($a); }
                elseif ($name === 'kaimom_search')  { list($ok, $text) = kmcp_search($a); }
                elseif ($name === 'kaimom_get')     { list($ok, $text) = kmcp_get($a, $GLOBALS['INCLUDE_TRANSCRIPT']); }
                else { $ok = false; $text = json_encode(array('ok' => false, 'error' => '使えないツールです: ' . $name), JSON_UNESCAPED_UNICODE); }
            } catch (Exception $e) {
                $ok = false; $text = json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
            }
            kmcp_result($id, array('content' => array(array('type' => 'text', 'text' => $text)), 'isError' => !$ok));
            break;
        default:
            if ($id !== null) {
                kmcp_send(array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => -32601, 'message' => 'Method not found: ' . $method)));
            }
    }
}
