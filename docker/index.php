<?php
// 0. 设置时区
date_default_timezone_set('Asia/Shanghai');

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

// 输出请求日志到 stderr
$log_msg = sprintf(
    "[%s] %s %s | FIXED: %s | GET: %s | POST: %s | BODY: %s\n",
    date('Y-m-d H:i:s'),
    $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    $_SERVER['REQUEST_URI'] ?? '/',
    $repaired ? implode('+', $repaired) : 'none',
    json_encode($get_data, JSON_UNESCAPED_UNICODE),
    json_encode($form_data, JSON_UNESCAPED_UNICODE),
    $raw_input
);
file_put_contents('php://stderr', $log_msg);

// 3. 解析 JSON Body
$json_data = json_decode($raw_input, true);
if (!is_array($json_data)) $json_data = [];

// 4. 合并所有来源的数据
$data = array_merge($get_data, $form_data, $json_data);

// 5. 身份验证
$req_key = $data['sendkey'] ?? '';
if (empty($SENDKEY) || $req_key !== $SENDKEY) {
    http_response_code(403);
    die(json_encode(["errcode" => 403, "errmsg" => "Sendkey Error!"]));
}

// 6. 提取并组装消息内容
$title = $data['text'] ?? $data['title'] ?? '';
$content = $data['msg'] ?? $data['desp'] ?? $data['content'] ?? '';

$full_message = (!empty($title) && $title !== $content) ? ($title . "\n\n" . $content) : $content;

// 7. 获取 Access Token
$token_url = "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid={$CORPID}&corpsecret={$CORPSECRET}";
$token_resp = json_decode(file_get_contents($token_url), true);
if (!isset($token_resp['access_token'])) {
    die(json_encode(["errcode" => 500, "errmsg" => "Failed to get access token"]));
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
echo file_get_contents("https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token={$token_resp['access_token']}", false, stream_context_create($opts));