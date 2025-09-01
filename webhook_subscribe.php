<?php
// webhook_subscribe.php
// Nhận update từ Telegram để quản lý subscribers

// ==== CẤU HÌNH ====
$botToken = 'YOUR_TELEGRAM_BOT_TOKEN';
$subsFile = __DIR__ . '/subscribers.json';

// ==== HÀM GỬI TIN NHẮN ====
function tg_send($botToken, $chat_id, $text) {
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    $ctx = stream_context_create([
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 10,
        ]
    ]);
    @file_get_contents($url, false, $ctx);
}

// ==== HÀM TẢI/LƯU SUBSCRIBERS ====
function load_subs($file) {
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}
function save_subs($file, $subs) {
    $json = json_encode(array_values(array_unique($subs)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    // Khóa file để tránh race condition
    $fp = fopen($file, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    return false;
}

// ==== XỬ LÝ UPDATE ====
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) {
    http_response_code(200);
    exit; // Telegram không cần body
}

$message = $update['message'] ?? $update['edited_message'] ?? null;
if (!$message) { http_response_code(200); exit; }

$chat = $message['chat'] ?? null;
$chat_id = $chat['id'] ?? null;
$text = trim($message['text'] ?? '');

if (!$chat_id) { http_response_code(200); exit; }

$subs = load_subs($subsFile);

// Lệnh
$lc = mb_strtolower($text);
if ($lc === '/start' || $lc === '/subscribe') {
    if (!in_array($chat_id, $subs, true)) {
        $subs[] = $chat_id;
        save_subs($subsFile, $subs);
        tg_send($botToken, $chat_id, "Đăng ký nhận thông báo thành công! ✅\nBạn sẽ nhận cảnh báo mỗi khi có sự kiện.");
    } else {
        tg_send($botToken, $chat_id, "Bạn đã đăng ký rồi. ✅");
    }
} elseif ($lc === '/unsubscribe') {
    $new = array_values(array_filter($subs, fn($id) => $id != $chat_id));
    save_subs($subsFile, $new);
    tg_send($botToken, $chat_id, "Đã hủy đăng ký nhận thông báo. ❎");
} else {
    // Gợi ý lệnh
    tg_send($botToken, $chat_id, "Các lệnh:\n/subscribe — đăng ký nhận cảnh báo\n/unsubscribe — hủy đăng ký");
}

http_response_code(200);
