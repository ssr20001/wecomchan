<?php
// 0. 设置时区
date_default_timezone_set('Asia/Shanghai');

// 关闭原始错误输出，避免泄露密钥。
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$request_id = bin2hex(random_bytes(16));
$request_started = microtime(true);
$request_outcome = 'internal_error';
$request_log = [];
$log_level = strtolower(trim(getenv('LOG_LEVEL') ?: 'info'));
header('Content-Type: application/json; charset=utf-8');
header('X-Request-ID: ' . $request_id);

register_shutdown_function(static function () {
    global $request_started, $request_outcome, $request_log;
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $request_outcome = 'internal_error';
        http_response_code(500);
        $request_log = ['reason' => 'fatal_error', 'line' => $error['line']];
        echo json_encode(['errcode' => 500, 'errmsg' => 'Internal server error']);
    }
    log_event('request_completed', array_merge($request_log, [
        'http_status' => http_response_code() ?: 200,
        'outcome' => $request_outcome,
        'duration_ms' => round((microtime(true) - $request_started) * 1000, 2),
    ]), $request_outcome === 'success' ? 'info' : ($request_outcome === 'auth_rejected' || $request_outcome === 'invalid_request' ? 'warning' : 'error'));
});

// 1. 读取环境变量
$CORPID = getenv('CORPID') ?: '';
$CORPSECRET = getenv('CORPSECRET') ?: '';
$AGENTID = getenv('AGENTID') ?: '';
$SENDKEY = getenv('SENDKEY') ?: '';

// 2. 获取请求原始数据
$raw_input = file_get_contents('php://input');
$raw_query = (string)($_SERVER['QUERY_STRING'] ?? '');

$content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$is_form_body = $content_type === '' || strpos($content_type, 'application/x-www-form-urlencoded') !== false;

// 读取 FIX_UNENCODED 环境变量（默认开启），用于修复未编码 '&' 造成的参数截断
$fix_env = getenv('FIX_UNENCODED');
$fix_flag = strtolower(trim(is_string($fix_env) ? $fix_env : ''));
$fix_on = !in_array($fix_flag, ['0', 'false', 'off', 'no'], true);

/**
 * 修复被 '&' 截断的参数
 * 基于已知参数白名单，将误截断的内容重新拼接回正文字段
 */
function repair_unencoded_params($raw, array $standard)
{
    // 已知参数白名单
    static $known_keys = ['sendkey', 'key', 'msg', 'msg_type', 'type', 'to_user', 'touser', 'title', 'text', 'desp', 'content', 'uid'];
    // 允许拼接被截断内容的字段名
    static $message_keys = ['msg', 'desp', 'content'];

    if ($raw === '' || strpos($raw, '&') === false) {
        return null;
    }

    $raw_values = [];
    $merged = [];
    $current = null;

    foreach (explode('&', $raw) as $segment) {
        $eq = strpos($segment, '=');
        $name = $eq === false ? '' : substr($segment, 0, $eq);

        // 遇到已知参数：作为新参数处理
        if ($eq !== false && in_array(strtolower($name), $known_keys, true)) {
            $current = $name;
            $raw_values[$name] = substr($segment, $eq + 1);
            continue;
        }

        // 未知片段且当前处理的是正文字段：将其拼接回去
        if ($current !== null && in_array(strtolower($current), $message_keys, true)) {
            $raw_values[$current] .= '&' . $segment;
            $merged[$current] = true;
            continue;
        }
    }

    if (!$merged) {
        return null;
    }

    $data = $standard;
    foreach (array_keys($merged) as $name) {
        // 还原 URL 编码（仅 %XX 解码，保留原生加号）
        $data[$name] = rawurldecode($raw_values[$name]);
    }

    return $data;
}

$get_data = $_GET;
$form_data = $_POST;
$repaired = [];

if ($fix_on) {
    // 修复 GET 请求 URL 参数截断
    $fixed_get = repair_unencoded_params($raw_query, $_GET);
    if ($fixed_get !== null) {
        $get_data = $fixed_get;
        $repaired[] = 'query';
    }

    // 修复 POST 表单 Body 参数截断
    if ($is_form_body && $raw_input !== '') {
        $fixed_post = repair_unencoded_params($raw_input, $_POST);
        if ($fixed_post !== null) {
            $form_data = $fixed_post;
            $repaired[] = 'body';
        }
    }
}

// 每次请求默认只记录最终结果；过程信息仅在 debug 下输出，不记录密钥、正文或 URL。
function log_event($event, array $metadata = [], $level = 'info')
{
    global $request_id, $log_level;
    $levels = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];
    if (!isset($levels[$level]) || $levels[$level] < ($levels[$log_level] ?? 20)) return;
    $outcomes = ['success' => '推送成功', 'auth_rejected' => '鉴权失败（SENDKEY 不匹配或未配置）',
        'invalid_request' => '请求无效', 'upstream_error' => '推送失败', 'internal_error' => '内部错误'];
    $reasons = ['fatal_error' => 'PHP 致命错误', 'invalid_field' => '消息字段必须是字符串',
        'invalid_utf8' => '消息不是有效的 UTF-8', 'transport_error' => '网络连接失败或超时',
        'http_error' => '上游 HTTP 异常', 'invalid_response' => '上游响应格式异常',
        'invalid_credential' => '凭证无效', 'invalid_corpid' => 'CORPID 无效',
        'invalid_access_token' => 'Access Token 无效', 'expired_access_token' => 'Access Token 已过期',
        'ip_not_allowed' => '出口 IP 不在企业微信白名单', 'upstream_rejected' => '企业微信拒绝请求',
        'missing_token' => '上游未返回 Access Token'];
    $stages = ['token' => '获取 Token', 'send' => '发送消息'];
    $labels = ['stage' => '阶段', 'upstream_http_status' => '上游HTTP', 'upstream_errcode' => '错误码',
        'reason' => '原因', 'line' => '代码行', 'http_status' => 'HTTP',
        'method' => '方法', 'fixed' => '参数修复', 'duration_ms' => '耗时'];
    $message = $event === 'request_completed' ? ($outcomes[$metadata['outcome']] ?? '未知结果')
        : ($event === 'request' ? '收到请求' : '上游调用');
    $parts = [];
    foreach ($labels as $key => $label) {
        if (!isset($metadata[$key]) || $metadata[$key] === []) continue;
        if ($key === 'http_status' && ($metadata['outcome'] ?? '') === 'success') continue;
        $value = $metadata[$key];
        if ($key === 'stage') $value = $stages[$value] ?? $value;
        elseif ($key === 'reason') $value = $reasons[$value] ?? $value;
        elseif ($key === 'fixed') $value = implode(',', $value);
        elseif ($key === 'duration_ms') $value .= 'ms';
        $parts[] = $label . '=' . $value;
    }
    file_put_contents('php://stdout', date('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] [' . $request_id . '] '
        . $message . ($parts ? ' | ' . implode(' | ', $parts) : '') . "\n");
}

// 记录上游状态与耗时，不记录 URL 或原始响应。
function request_upstream($stage, $url, array $opts = [])
{
    global $request_log;
    $opts['http']['timeout'] = 10;
    $opts['http']['ignore_errors'] = true;
    $opts['http']['follow_location'] = 0;
    $started = microtime(true);
    http_clear_last_response_headers();
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $status = 0;
    foreach (http_get_last_response_headers() ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) $status = (int) $matches[1];
    }
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $code = is_array($data) && isset($data['errcode']) && is_int($data['errcode'])
        && $data['errcode'] >= -1 && $data['errcode'] <= 999999 ? $data['errcode'] : null;
    $meta = ['stage' => $stage, 'upstream_http_status' => $status,
        'duration_ms' => round((microtime(true) - $started) * 1000, 2)];
    if ($code !== null) $meta['upstream_errcode'] = $code;
    $reason = null;
    if ($raw === false) $reason = 'transport_error';
    elseif ($status < 200 || $status >= 300) $reason = 'http_error';
    elseif ($code === null) $reason = 'invalid_response';
    elseif ($code !== 0) $reason = [40001 => 'invalid_credential', 40013 => 'invalid_corpid',
        40014 => 'invalid_access_token', 42001 => 'expired_access_token',
        40164 => 'ip_not_allowed', 60020 => 'ip_not_allowed'][$code] ?? 'upstream_rejected';
    elseif ($stage === 'token' && (!isset($data['access_token']) || !is_string($data['access_token']) || $data['access_token'] === '')) $reason = 'missing_token';
    if ($reason !== null) {
        $meta['reason'] = $reason;
        $request_log = $meta;
    }
    log_event('upstream_result', $meta, 'debug');
    return ['raw' => $raw, 'data' => $data, 'ok' => $reason === null,
        'valid_response' => $raw !== false && $status >= 200 && $status < 300 && $code !== null];
}

// 3. 解析 JSON Body
$json_data = json_decode($raw_input, true);
if (!is_array($json_data)) $json_data = [];

// 4. 合并所有来源的数据
$data = array_merge($get_data, $form_data, $json_data);

// 5. 身份验证
$req_key = $data['sendkey'] ?? '';
$authenticated = $SENDKEY !== '' && is_string($req_key) && hash_equals($SENDKEY, $req_key);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
log_event('request', [
    'method' => in_array($method, ['GET', 'POST', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true) ? $method : 'OTHER',
    'fixed' => $repaired,
], 'debug');
if (!$authenticated) {
    $request_outcome = 'auth_rejected';
    http_response_code(403);
    die(json_encode(["errcode" => 403, "errmsg" => "Sendkey Error!"]));
}

// 6. 提取并组装消息内容
$title = $data['text'] ?? $data['title'] ?? '';
$content = $data['msg'] ?? $data['desp'] ?? $data['content'] ?? '';

if (!is_string($title) || !is_string($content)) {
    $request_outcome = 'invalid_request';
    $request_log = ['reason' => 'invalid_field'];
    http_response_code(400);
    die(json_encode(['errcode' => 400, 'errmsg' => 'Message fields must be strings']));
}

$full_message = (!empty($title) && $title !== $content) ? ($title . "\n\n" . $content) : $content;

// 7. 获取 Access Token
$request_outcome = 'upstream_error';
$token_url = 'https://qyapi.weixin.qq.com/cgi-bin/gettoken?' . http_build_query(
    ['corpid' => $CORPID, 'corpsecret' => $CORPSECRET], '', '&', PHP_QUERY_RFC3986);
$token = request_upstream('token', $token_url);
if (!$token['ok']) {
    http_response_code(502);
    die(json_encode(['errcode' => 500, 'errmsg' => 'Failed to get access token']));
}

// 8. 推送至企业微信
$msg_payload = [
    "touser" => "@all",
    "msgtype" => "text",
    "agentid" => $AGENTID,
    "text" => [
        "content" => $full_message
    ]
];

$opts = [
    "http" => [
        "method"  => "POST",
        "header"  => "Content-Type: application/json",
        "content" => json_encode($msg_payload, JSON_UNESCAPED_UNICODE)
    ]
];
if ($opts['http']['content'] === false) {
    $request_outcome = 'invalid_request';
    $request_log = ['reason' => 'invalid_utf8'];
    http_response_code(400);
    die(json_encode(['errcode' => 400, 'errmsg' => 'Message must be valid UTF-8']));
}
$sent = request_upstream('send', 'https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=' . rawurlencode($token['data']['access_token']), $opts);
if (!$sent['valid_response']) {
    http_response_code(502);
    die(json_encode(['errcode' => 502, 'errmsg' => 'Failed to send message']));
}
$request_outcome = $sent['ok'] ? 'success' : 'upstream_error';
echo $sent['raw'];
