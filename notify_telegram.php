<?php
// ==== CẤU HÌNH ====
// Thay YOUR_BOT_TOKEN_HERE và YOUR_CHAT_ID_HERE bằng thông tin thật của bạn!
$botToken = '8406856068:AAEvnGekEH_h89-wQpYfMBYLAYwUX6-wWyE';
$chat_id = '7877886358';

// ==== HÀM GỬI TELEGRAM ====
function sendTelegramMessage($botToken, $chat_id, $message) {
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
        ],
    ];
    $context  = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

// ==== NHẬN DỮ LIỆU POST JSON ====
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo "Không có dữ liệu hoặc dữ liệu không hợp lệ.";
    exit;
}

// ==== LẤY DỮ LIỆU CÁC TRƯỜNG CẦN THIẾT ====
// Đặt giá trị mặc định nếu thiếu
$time          = $data['time']            ?? date('Y-m-d H:i:s');
$ip            = $data['ip']              ?? ($_SERVER['REMOTE_ADDR'] ?? 'N/A');
$country       = $data['country']         ?? 'N/A';
$check         = $data['check']           ?? ($data['btn_label'] ?? 'N/A');
$online_time   = $data['online_time']     ?? ($data['thoi_gian_online'] ?? '-');
$last_online   = $data['last_online']     ?? ($data['online_lan_cuoi'] ?? '-');
$today_count   = $data['today_count']     ?? ($data['so_lan_truy_cap_hom_nay'] ?? 1);

// ==== FORMAT NỘI DUNG GỬI TELEGRAM ====
$message = "🔔 <b>USER ACTION: Check Button!</b>\n\n"
         . "• <b>Thời gian vào web:</b> {$time}\n"
         . "• <b>Địa chỉ IP:</b> {$ip}\n"
         . "• <b>Quốc gia:</b> {$country}\n"
         . "• <b>CHECK:</b> {$check}\n"
         . "• <b>Thời gian online:</b> {$online_time}\n"
         . "• <b>Online lần cuối:</b> {$last_online}\n"
         . "• <b>Số lần truy cập hôm nay:</b> {$today_count}";

// ==== GỬI TELEGRAM ====
sendTelegramMessage($botToken, $chat_id, $message);

// ==== PHẢN HỒI VỀ CHO CLIENT ====
echo 'Đã gửi thông báo Telegram!';
?>
