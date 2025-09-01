<?php
// events.php — phiên bản đã sửa full: IP thật + geo ổn định + tái sử dụng location + cache + fallback
require_once __DIR__ . '/config.php';

date_default_timezone_set(TIMEZONE);

// ==== Chuẩn hóa thời gian ====
function normalize_datetime($str) {
    if (!$str) return date('Y-m-d H:i:s');
    if (strpos($str, 'T') !== false) {
        $time = strtotime($str);
        if ($time !== false) return date('Y-m-d H:i:s', $time);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $str)) return $str;
    $time = strtotime($str);
    if ($time !== false) return date('Y-m-d H:i:s', $time);
    return date('Y-m-d H:i:s');
}

// ==== Escape text cho parse_mode HTML ====
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ==== HTTP GET (cURL ưu tiên, fallback stream) ====
function http_get($url, $timeout = 5) {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeout,
                'header'  => "User-Agent: gpo-tele/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        return @file_get_contents($url, false, $ctx);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'gpo-tele/1.0',
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// ==== Gửi Telegram (một chat) ====
function sendTelegramMessage($botToken, $chat_id, $message) {
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text'    => $message,
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

// ==== Subscribers (broadcast) ====
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

function sendTelegramBroadcast($botToken, $message) {
    $subs = load_subscribers();
    if (empty($subs['chat_ids'])) return;
    foreach ($subs['chat_ids'] as $cid) {
        sendTelegramMessage($botToken, $cid, $message);
        // usleep(50000); // 0.05s nếu nhiều người nhận
    }
}
// ==== Lấy IP thật sự (ưu tiên X-Forwarded-For) ====
function get_client_ip() {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (strpos($ip, ',') !== false) {
        $parts = explode(',', $ip);
        $ip = trim($parts[0]); // quan trọng: lấy phần tử đầu
    }
    $ip_clean = $ip;
    if (filter_var($ip_clean, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        $fallback = $_SERVER['REMOTE_ADDR'] ?? '';
        if (filter_var($fallback, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $ip_clean = $fallback;
        }
    }
    return $ip_clean ?: 'UNKNOWN';
}

// ==== CACHE IP → LOCATION ====
function ip_cache_get($ip, $file = 'ip_cache.json', $ttl_seconds = 120) { // TTL 120s để test nhanh
    if (!file_exists($file)) return null;
    $raw = file_get_contents($file);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    if (!isset($data[$ip])) return null;
    $entry = $data[$ip];
    if (!isset($entry['location']) || !isset($entry['time'])) return null;
    if (time() - (int)$entry['time'] > $ttl_seconds) return null;
    return (string)$entry['location'];
}

function ip_cache_set($ip, $location, $file = 'ip_cache.json') {
    $data = [];
    if (file_exists($file)) {
        $raw = file_get_contents($file);
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) $data = $tmp;
    }
    $data[$ip] = ['location' => $location, 'time' => time()];
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

// ==== Lấy location gần nhất từ log ====
function get_last_location_for_user($user) {
    $logFile = 'log.txt';
    if (!file_exists($logFile)) return null;
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $last = null;
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (!$entry) continue;
        if (($entry['user'] ?? '') === $user) {
            if (!empty($entry['location']) && $entry['location'] !== 'UNKNOWN') {
                $last = $entry['location'];
            }
        }
    }
    return $last;
}

// ==== Lấy vị trí từ IP (HTTPS + fallback + cache) ====
function get_location($ip) {
    if (!$ip || $ip === 'UNKNOWN') return 'UNKNOWN';

    $cached = ip_cache_get($ip);
    if ($cached !== null) return $cached;

    // 1) ip-api
    $resp = @http_get("https://ip-api.com/json/{$ip}?fields=status,country,regionName,city,message");
    if ($resp) {
        $j = json_decode($resp, true);
        if (($j['status'] ?? '') === 'success') {
            $city    = $j['city'] ?? '';
            $region  = $j['regionName'] ?? '';
            $country = $j['country'] ?? '';
            $location = trim(trim("$city, $region", ', ') . ', ' . $country, ', ');
            if ($location !== '') {
                ip_cache_set($ip, $location);
                return $location;
            }
        } else {
            // Debug khi cần:
            // file_put_contents('geo_debug.log', date('c')." ip-api fail {$ip}: ".($j['message'] ?? 'no-msg')."\n", FILE_APPEND);
        }
    } else {
        // file_put_contents('geo_debug.log', date('c')." ip-api no-response {$ip}\n", FILE_APPEND);
    }

    // 2) ipinfo fallback
    $resp2 = @http_get("https://ipinfo.io/{$ip}/json");
    if ($resp2) {
        $k = json_decode($resp2, true);
        if (is_array($k)) {
            $city    = $k['city'] ?? '';
            $region  = $k['region'] ?? '';
            $country = $k['country'] ?? '';
            $location = trim(trim("$city, $region", ', ') . ', ' . $country, ', ');
            if ($location !== '') {
                ip_cache_set($ip, $location);
                return $location;
            }
        }
    } else {
        // file_put_contents('geo_debug.log', date('c')." ipinfo no-response {$ip}\n", FILE_APPEND);
    }

    ip_cache_set($ip, 'UNKNOWN');
    return 'UNKNOWN';
}
// ==== Stats ====
function get_user_stats($user, $current_time) {
    $logFile = 'log.txt';
    $today = date('Y-m-d', strtotime($current_time));
    $count_today = 0;
    $last_visit_time = null;

    if (!file_exists($logFile)) {
        return ['last_online' => '-', 'today_count' => 0];
    }
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (!$entry) continue;

        if (isset($entry['user']) && $entry['user'] === $user && isset($entry['action']) && isset($entry['time'])) {
            $entry_time = normalize_datetime($entry['time']);
            if ($entry['action'] === 'start' && strpos($entry_time, $today) === 0) {
                $count_today++;
            }
            if (strtotime($entry_time) < strtotime($current_time)) {
                if ($last_visit_time === null || strtotime($entry_time) > strtotime($last_visit_time)) {
                    $last_visit_time = $entry_time;
                }
            }
        }
    }
    $last_online = $last_visit_time ?? '-';
    return ['last_online' => $last_online, 'today_count' => $count_today];
}

// ==== Xử lý dữ liệu ====
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo "Không có dữ liệu hoặc dữ liệu không hợp lệ.";
    exit;
}

$action = $data['action'] ?? '';
$ip = get_client_ip();

$device_id = $data['device_id'] ?? 'UNKNOWN';

// ==== Gán user ====
$device_users_file = 'device_users.json';
if (!file_exists($device_users_file)) file_put_contents($device_users_file, '{}', LOCK_EX);
$device_users = json_decode(file_get_contents($device_users_file), true) ?: [];

if (!isset($device_users[$device_id])) {
    $next_index = count($device_users) + 1;
    $device_users[$device_id] = 'user' . $next_index;
    file_put_contents($device_users_file, json_encode($device_users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
$user = $device_users[$device_id];

$time = normalize_datetime($data['time'] ?? '');

// ==== Ưu tiên location: client -> log -> API ====
$location = $data['location'] ?? '';
if ($location === '' || $location === 'UNKNOWN') {
    $prev = get_last_location_for_user($user);
    if ($prev) $location = $prev;
}
if ($location === '' || $location === 'UNKNOWN') {
    $location = get_location($ip);
}
if ($location === '' || $location === null) $location = 'UNKNOWN';

// ==== Stats ====
$stats = get_user_stats($user, $time);
$last_online = $stats['last_online'];
$today_count = $stats['today_count'];

if ($action === 'start') {
    $today_count++;
}

// ==== Extra fields ====
$online_time = $data['online_time'] ?? '-';
$note = $data['note'] ?? '';
$edit = $data['edit'] ?? '';
if ($action === 'button_click') {
    $btn_id = $data['btn_id'] ?? '';
    $btn_label = $data['btn_label'] ?? '';
    $page = $data['page'] ?? '';

    // Ghi log
    $log = [
        'time' => $time,
        'ip' => $ip,
        'device_id' => $device_id,
        'user' => $user,
        'action' => 'button_click',
        'btn_id' => $btn_id,
        'btn_label' => $btn_label,
        'page' => $page,
        'location' => $location
    ];
    file_put_contents('log.txt', json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    $check = $btn_label ?: '--';

    $message = "🔔 <b>Alo alo: Có thằng bấm nút</b>\n";
    $message .= "- <b>Thời gian vào:</b> " . esc($time) . "\n";
    $message .= "- <b>Địa chỉ IP:</b> " . esc($ip) . "\n";
    $message .= "- <b>Thiết bị:</b> " . esc($user) . "\n";
    $message .= "- <b>Vị trí:</b> " . esc($location) . "\n";
    $message .= "- <b>Bấm:</b> " . esc($check) . "\n";
    if ($note) $message .= "- <b>Ghi chú:</b> " . esc($note) . "\n";
    if ($edit) $message .= "- <b>Sửa:</b> " . esc($edit) . "\n";

    sendTelegramBroadcast(BOT_TOKEN, $message);
    exit();
}

if ($action === 'start') {
    $referrer = $data['referrer'] ?? '';
    $page = $data['page'] ?? '';

    $log = [
        'time' => $time,
        'ip' => $ip,
        'device_id' => $device_id,
        'user' => $user,
        'location' => $location,
        'action' => 'start',
        'page' => $page,
        'referrer' => $referrer
    ];
    file_put_contents('log.txt', json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    $message = "👤 <b>Alo alo: Có thằng truy cập web</b>\n";
    $message .= "- <b>Thời gian vào:</b> " . esc($time) . "\n";
    $message .= "- <b>Địa chỉ IP:</b> " . esc($ip) . "\n";
    $message .= "- <b>Thiết bị:</b> " . esc($user) . "\n";
    $message .= "- <b>Vị trí:</b> " . esc($location) . "\n";
    $message .= "- <b>Online lần cuối:</b> " . esc($last_online) . "\n";
    $message .= "- <b>Số lần truy cập hôm nay:</b> " . esc($today_count) . "\n";
    if ($referrer) $message .= "- <b>Referrer:</b> " . esc($referrer) . "\n";
    if ($note) $message .= "- <b>Ghi chú:</b> " . esc($note) . "\n";
    if ($edit) $message .= "- <b>Sửa:</b> " . esc($edit) . "\n";

    sendTelegramBroadcast(BOT_TOKEN, $message);
    exit();
}

if ($action === 'end') {
    $duration = $data['duration'] ?? 0;
    $page = $data['page'] ?? '';

    $log = [
        'time' => $time,
        'ip' => $ip,
        'device_id' => $device_id,
        'user' => $user,
        'action' => 'end',
        'page' => $page,
        'duration' => $duration,
        'location' => $location
    ];
    file_put_contents('log.txt', json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    $duration = intval($duration);
    if ($duration < 60) {
        $duration_str = $duration . ' giây';
    } else {
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;
        $duration_str = $minutes . ' phút';
        if ($seconds > 0) {
            $duration_str .= ' ' . $seconds . ' giây';
        }
    }

    $message = "⏹️ <b>Thằng này kết thúc phiên</b>\n";
    $message .= "- <b>Địa chỉ IP:</b> " . esc($ip) . "\n";
    $message .= "- <b>Thiết bị:</b> " . esc($user) . "\n";
    $message .= "- <b>Vị trí:</b> " . esc($location) . "\n";
    $message .= "- <b>Thời gian online:</b> " . esc($duration_str) . "\n";
    if ($note) $message .= "- <b>Ghi chú:</b> " . esc($note) . "\n";
    if ($edit) $message .= "- <b>Sửa:</b> " . esc($edit) . "\n";

    sendTelegramBroadcast(BOT_TOKEN, $message);
    exit();
}

http_response_code(400);
echo "Action không hợp lệ hoặc không được hỗ trợ.";
