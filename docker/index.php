<?php
// 0. 设置时区 GMT+8
date_default_timezone_set('Asia/Shanghai');

// 1. 动态读取运行时的环境变量
$CORPID = getenv('CORPID') ?: '';
$CORPSECRET = getenv('CORPSECRET') ?: '';
$AGENTID = getenv('AGENTID') ?: '';
$SENDKEY = getenv('SENDKEY') ?: '';

// 2. 全能数据解析器：合并 GET参数、POST表单 和 JSON Body
$raw_input = file_get_contents('php://input');

// 记录详细请求日志输出到 Docker 日志流
$log_msg = sprintf(
    "[%s] %s %s | GET: %s | POST: %s | BODY: %s\n",
    date('Y-m-d H:i:s'),
    $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    $_SERVER['REQUEST_URI'] ?? '/',
    json_encode($_GET, JSON_UNESCAPED_UNICODE),
    json_encode($_POST, JSON_UNESCAPED_UNICODE),
    $raw_input
);
file_put_contents('php://stderr', $log_msg);

$json_data = json_decode($raw_input, true);
if (!is_array($json_data)) $json_data = [];

// 将所有可能的数据来源合并到一个数组里
$data = array_merge($_GET, $_POST, $json_data);

// 3. 身份验证
$req_key = $data['sendkey'] ?? '';
if (empty($SENDKEY) || $req_key !== $SENDKEY) {
    http_response_code(403);
    die(json_encode(["errcode" => 403, "errmsg" => "Sendkey Error!"]));
}

// 4. 智能提取标题与内容 (兼容所有常见的推送字段名)
$title = $data['text'] ?? $data['title'] ?? '';
$content = $data['msg'] ?? $data['desp'] ?? $data['content'] ?? '';

$full_message = "";

// 如果有标题，且标题和内容不一样，就加上标题和两个换行
if (!empty($title) && $title !== $content) {
    $full_message .= $title . "\n\n";
}

// 加上正文内容
$full_message .= $content;

// 5. 获取企业微信 Access Token
$token_url = "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid={$CORPID}&corpsecret={$CORPSECRET}";
$token_resp = json_decode(file_get_contents($token_url), true);
if (!isset($token_resp['access_token'])) {
    die(json_encode(["errcode" => 500, "errmsg" => "Failed to get access token"]));
}

// 6. 组装发送给企业微信的数据
$msg_payload = [
    "touser" => "@all",
    "msgtype" => "text",
    "agentid" => $AGENTID,
    "text" => [
        "content" => $full_message
    ]
];

// 7. 发送请求
$opts = [
    "http" => [
        "method"  => "POST",
        "header"  => "Content-Type: application/json",
        "content" => json_encode($msg_payload, JSON_UNESCAPED_UNICODE)
    ]
];
echo file_get_contents("https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token={$token_resp['access_token']}", false, stream_context_create($opts));
