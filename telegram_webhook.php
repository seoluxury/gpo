<?php
// telegram_webhook.php
require_once __DIR__ . '/config.php';

date_default_timezone_set(TIMEZONE);

function load_subscribers($file = 'subscribers.json') {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode(['chat_ids' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || !isset($data['chat_ids']) || !is_array($data['chat_ids'])) {
        $data = ['chat_ids' => []];
    }
    return $data;
}

function save_subscribers($data, $file = 'subscribers.json') {
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function sendTelegramMessage($botToken, $chat_id, $message) {
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 8,
        ],
    ];
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

// Optional: nếu dùng shared secret để chắc chắn request từ Telegram
// if (defined('WEBHOOK_SECRET')) {
//     $hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_SECRET_TOKEN'] ?? '';
//     if ($hdr !== WEBHOOK_SECRET) { http_response_code(403); exit; }
// }

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { http_response_code(200); exit; }

$chat_id = null;
$startDetected = false;

// Trường hợp tin nhắn trực tiếp
if (isset($update['message']['chat']['id'])) {
    $chat_id = (string)$update['message']['chat']['id'];
    $text = trim($update['message']['text'] ?? '');
    if ($text === '/start') $startDetected = true;
}

// Trường hợp bot được add vào group/supergroup (tùy nhu cầu, có thể coi như đăng ký)
if (isset($update['my_chat_member']['chat']['id'])) {
    $chat_id = (string)$update['my_chat_member']['chat']['id'];
    // Nếu muốn auto-sub khi bot được thêm vào group:
    $startDetected = true;
}

// Trường hợp callback_query (nếu có inline keyboard)
if (isset($update['callback_query']['message']['chat']['id'])) {
    $chat_id = (string)$update['callback_query']['message']['chat']['id'];
}

// Nếu có chat_id → đưa vào subscribers
if ($chat_id) {
    $subs = load_subscribers();
    if (!in_array($chat_id, $subs['chat_ids'], true)) {
        $subs['chat_ids'][] = $chat_id;
        save_subscribers($subs);
    }
    // Phản hồi xác nhận nếu là /start hoặc add
    if ($startDetected) {
        sendTelegramMessage(BOT_TOKEN, $chat_id, "✅ Đã đăng ký nhận thông báo.");
    }
}

http_response_code(200);
