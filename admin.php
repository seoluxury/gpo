<?php
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');

$admin_password = 'qpqqwppe';

// Xử lý lưu ghi chú IP qua AJAX
$ip_notes_file = 'ip_notes.json';
if (!file_exists($ip_notes_file)) file_put_contents($ip_notes_file, '{}');
$ip_notes = json_decode(file_get_contents($ip_notes_file), true) ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = json_decode(file_get_contents('php://input'), true);
    if (($post['action'] ?? '') === 'save_note') {
        $ip = $post['ip'] ?? '';
        $note = $post['note'] ?? '';
        if ($ip) {
            $ip_notes[$ip] = ['note'=>$note];
            file_put_contents($ip_notes_file, json_encode($ip_notes));
        }
        exit();
    }
}

// Xử lý đăng nhập
if (isset($_POST['password'])) {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['loggedin'] = true;
    } else {
        $error = 'Mật khẩu không đúng!';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <title>Đăng nhập Admin Log</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
        <style>
            body { background: #f7f7f9; font-family: 'Inter', Arial, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; }
            .login-box { background: #fff; padding: 32px 28px; border-radius: 10px; box-shadow: 0 2px 16px rgba(0,0,0,0.06);}
            .login-box h4 { font-weight: 600; margin-bottom: 18px;}
            .form-control { border-radius: 6px; border: 1px solid #ddd;}
            .btn { border-radius: 6px; font-weight: 600;}
        </style>
    </head>
    <body>
    <div class="login-box">
        <h4>Đăng nhập Admin Log</h4>
        <form method="post" action="">
            <div class="mb-3">
                <input type="password" name="password" class="form-control" placeholder="Nhập mật khẩu admin" required autofocus>
            </div>
            <button type="submit" class="btn btn-dark w-100">Đăng nhập</button>
        </form>
        <?php if (isset($error)) echo '<div class="mt-3 text-danger">'.htmlspecialchars($error).'</div>'; ?>
    </div>
    </body>
    </html>
    <?php
    exit();
}

// Đọc log từ file
$log_file = 'log.txt';
if (!file_exists($log_file)) file_put_contents($log_file, '');
$all_logs = [];
$lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $log = json_decode($line, true);
    if (is_array($log)) $all_logs[] = $log;
}

// Đếm số lần truy cập (action=start) của từng IP trong ngày hiện tại
$today = date('Y-m-d');
$access_count_by_ip = [];
foreach ($all_logs as $log) {
    if (($log['action'] ?? '') === 'start') {
        $ip = $log['ip'] ?? '';
        $log_date = substr($log['time'] ?? '', 0, 10);
        if ($log_date === $today && $ip) {
            if (!isset($access_count_by_ip[$ip])) $access_count_by_ip[$ip] = 0;
            $access_count_by_ip[$ip]++;
        }
    }
}

// Gom các lần start theo IP để lấy phiên gần nhất và phiên trước đó
$start_logs_by_ip = [];
foreach ($all_logs as $log) {
    if (($log['action'] ?? '') === 'start') {
        $ip = $log['ip'] ?? '';
        if ($ip) {
            $start_logs_by_ip[$ip][] = $log;
        }
    }
}

// Lấy phiên hiện tại (start mới nhất) và phiên trước đó cho mỗi IP
$log_by_ip = [];
foreach ($start_logs_by_ip as $ip => $starts) {
    usort($starts, function($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });
    $current = $starts[0];
    $last_online = isset($starts[1]) ? $starts[1]['time'] : '';
    $current['last_online'] = $last_online;

    $location = $current['location'] ?? '';
    $btn_label = '';
    $duration = '';
    foreach (array_reverse($all_logs) as $log2) {
        if (($log2['ip'] ?? '') === $ip) {
            if (!$location && !empty($log2['location'])) $location = $log2['location'];
            if (!$btn_label && !empty($log2['btn_label'])) $btn_label = $log2['btn_label'];
            if (!$duration && !empty($log2['duration'])) $duration = $log2['duration'];
            if ($location && $btn_label && $duration) break;
        }
    }
    $current['location'] = $location;
    $current['btn_label'] = $btn_label;
    $current['duration'] = $duration;
    $current['today_access_count'] = $access_count_by_ip[$ip] ?? 0;
    $current['note'] = $ip_notes[$ip]['note'] ?? '';
    $log_by_ip[$ip] = $current;
}

$logs = array_values($log_by_ip);
usort($logs, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});

// Tổng số IP đã truy cập
$total_ip = count($log_by_ip);

// Pagination
$per_page = 15;
$total_logs = count($logs);
$total_pages = max(1, ceil($total_logs / $per_page));
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
if ($page > $total_pages) $page = $total_pages;
$start_index = ($page - 1) * $per_page;
$logs_page = array_slice($logs, $start_index, $per_page);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Admin Log Truy Cập</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { background: #f7f7f9; font-family: 'Inter', Arial, sans-serif; color: #23272f; }
        .container { max-width: 1600px; margin: 32px auto 0 auto; background: #fff; border-radius: 14px; padding: 0 18px 18px 18px; box-shadow: 0 2px 16px rgba(0,0,0,0.07);}
        .header-bar { display: flex; align-items: center; justify-content: space-between; padding: 22px 0 18px 0; border-bottom: 1px solid #ececf0; margin-bottom: 18px;}
        .header-title { font-size: 1.45rem; font-weight: 700; letter-spacing: 1px; color: #23272f;}
        .header-actions { display: flex; align-items: center; gap: 10px;}
        .table-responsive { max-height: 65vh; overflow-x: auto; padding-bottom: 16px;}
        .table { border-radius: 8px; overflow: hidden; margin-bottom: 0; table-layout: fixed; width: 100%; min-width: 1500px;}
        .table thead th { background: #f2f2f5; color: #23272f; font-weight: 600; border: none; text-transform: uppercase; font-size: 0.98em; padding: 12px 7px;}
        .table td, .table th { border: none; border-bottom: 1px solid #ececf0; vertical-align: middle; font-size: 1.02em; padding: 10px 7px; text-align: center;}
        .table-striped > tbody > tr:hover { background: #f2f2f5;}
        .table th:nth-child(1), .table td:nth-child(1) { width: 13%; }
        .table th:nth-child(2), .table td:nth-child(2) { width: 25%; }
        .table th:nth-child(3), .table td:nth-child(3) { width: 8%; }
        .table th:nth-child(4), .table td:nth-child(4) { width: 10%; }
        .table th:nth-child(5), .table td:nth-child(5) { width: 10%; }
        .table th:nth-child(6), .table td:nth-child(6) { width: 13%; }
        .table th:nth-child(7), .table td:nth-child(7) { width: 8%; }
        .table th:nth-child(8), .table td:nth-child(8) { width: 10%; }
        .table th:nth-child(9), .table td:nth-child(9) { width: 3%; }
        .logout-link, #toggleDarkMode { border-radius: 6px; font-weight: 600; border: none; padding: 7px 18px; background: #f2f2f5; color: #23272f; transition: background 0.2s; display: flex; align-items: center; gap: 7px;}
        .logout-link:hover, #toggleDarkMode:hover { background: #e0e0e7; color: #23272f;}
        .btn-note { border: none; background: #f2f2f5; color: #23272f; border-radius: 50%; padding: 7px 8px 7px 8px; font-size: 1.09rem; cursor: pointer; transition: background 0.2s; display: flex; align-items: center; justify-content: center;}
        .btn-note:hover { background: #e0e0e7;}
        .search-bar { border-radius: 7px; border: 1px solid #ddd; padding: 9px 14px; margin-bottom: 22px; width: 100%; font-size: 1rem;}
        .footer { color: #999; font-size: 0.97rem; margin-top: 18px; text-align: right;}
        .pagination { display: flex; justify-content: center; gap: 8px; margin: 18px 0 0 0; min-width: max-content;}
        .pagination button, .pagination span { min-width: 36px; height: 36px; border: none; background: #f2f2f5; color: #23272f; border-radius: 50%; font-weight: 600; font-size: 1rem; cursor: pointer; transition: background 0.2s; display: flex; align-items: center; justify-content: center;}
        .pagination button:hover { background: #e0e0e7;}
        .pagination .active { background: #23272f; color: #fff; cursor: default;}
        @media (max-width: 900px) { .container { padding: 10px 4px; } .table-responsive { max-height: 45vh; } .table th, .table td { font-size: 0.95em;}}
        @media (max-width: 600px) { .container { padding: 2px 1px; margin-top: 10px;} .table-responsive { max-height: 35vh; } .header-title { font-size: 1.1rem;}}
        body.dark-mode { background: #18191a !important; color: #e4e6eb !important;}
        body.dark-mode .container { background: #23242a !important; color: #e4e6eb !important;}
        body.dark-mode .table { color: #e4e6eb !important;}
        body.dark-mode .table thead th { background: #23242a !important; color: #e4e6eb !important;}
        body.dark-mode .table-striped > tbody > tr:hover { background: #23272f !important;}
        body.dark-mode .search-bar, body.dark-mode .logout-link, body.dark-mode #toggleDarkMode, body.dark-mode .btn-note, body.dark-mode .pagination button, body.dark-mode .pagination span { background: #23242a !important; color: #e4e6eb !important; border-color: #444 !important;}
        body.dark-mode .search-bar:focus { background: #18191a !important;}
        body.dark-mode #noteModal { background: #23242a !important; color: #e4e6eb !important;}
        body.dark-mode .pagination .active { background: #e4e6eb !important; color: #23272f !important;}
    </style>
</head>
<body>
<div class="container">
    <div class="header-bar">
        <div class="header-title">507 </div>
        <div class="header-actions">
            <button id="toggleDarkMode" title="Chuyển chế độ sáng/tối">
                <svg id="darkIcon" width="18" height="18" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2"/><path d="M10 2v2M10 16v2M18 10h-2M4 10H2M15.07 15.07l-1.42-1.42M6.35 6.35L4.93 4.93M15.07 4.93l-1.42 1.42M6.35 13.65l-1.42 1.42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <a href="?logout=1" class="logout-link" title="Đăng xuất">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 10h6M13 10l-2-2m2 2l-2 2M3 10a7 7 0 1 1 14 0 7 7 0 0 1-14 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Đăng xuất
            </a>
        </div>
    </div>
    <div style="font-weight:600; margin-bottom:10px;">
        Tổng số IP đã truy cập: <?php echo $total_ip; ?>
    </div>
    <input type="text" id="searchInput" class="search-bar" placeholder="Tìm kiếm IP, quốc gia, nút menu, ghi chú..." onkeyup="searchTable()">
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
                <tr>
                    <th>Thời gian vào web</th>
                    <th>IP</th>
                    <th>Quốc gia</th>
                    <th>Check</th>
                    <th>Thời gian online</th>
                    <th>Online lần cuối</th>
                    <th>Số lần truy cập hôm nay</th>
                    <th>Ghi chú</th>
                    <th>Sửa</th>
                </tr>
            </thead>
            <tbody id="logTableBody">
            <?php if (count($logs_page) === 0): ?>
                <tr><td colspan="9" style="text-align:center;">Không có dữ liệu</td></tr>
            <?php else: 
                foreach ($logs_page as $log): 
                    $ip = $log['ip'] ?? '';
                    // Xử lý thời gian
                    $raw_time = $log['time'] ?? '';
                    $show_time = '-';
                    if ($raw_time) {
                        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw_time, new DateTimeZone('Asia/Ho_Chi_Minh'));
                        if ($dt) {
                            $show_time = $dt->format('d/m/Y H:i:s');
                        } else {
                            $show_time = htmlspecialchars($raw_time);
                        }
                    }
                    // Online lần cuối
                    $raw_last = $log['last_online'] ?? '';
                    $show_last = '-';
                    if ($raw_last) {
                        $dt2 = DateTime::createFromFormat('Y-m-d H:i:s', $raw_last, new DateTimeZone('Asia/Ho_Chi_Minh'));
                        if ($dt2) {
                            $show_last = $dt2->format('d/m/Y H:i:s');
                        } else {
                            $show_last = htmlspecialchars($raw_last);
                        }
                    }
                    echo '<tr>';
                    echo '<td>' . $show_time . '</td>';
                    echo '<td>' . htmlspecialchars($ip) . '</td>';
                    echo '<td>' . htmlspecialchars($log['location'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($log['btn_label'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($log['duration'] ?? '') . '</td>';
                    echo '<td>' . $show_last . '</td>';
                    echo '<td>' . htmlspecialchars($log['today_access_count'] ?? 0) . '</td>';
                    echo '<td class="ip-note">'.htmlspecialchars($log['note'] ?? '').'</td>';
                    echo '<td><button class="btn-note" onclick="editNote(\''.htmlspecialchars($ip).'\', this)" title="Sửa ghi chú"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 13.5V16h2.5l7.06-7.06a1.5 1.5 0 0 0-2.12-2.12L4 13.5ZM14.5 6.5l-1-1a1.5 1.5 0 0 1 2.12-2.12l1 1a1.5 1.5 0 0 1-2.12 2.12Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button></td>';
                    echo '</tr>';
                endforeach; 
            endif; ?>
            </tbody>
        </table>
        <div class="pagination" id="paginationNav">
        <?php
        if ($total_pages > 1) {
            if ($page > 1) {
                echo '<button onclick="gotoPage('.($page-1).')" aria-label="Trang trước">&laquo;</button>';
            } else {
                echo '<span>&laquo;</span>';
            }
            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i == $page) {
                    echo '<span class="active">'.$i.'</span>';
                } else if ($i == 1 || $i == $total_pages || abs($i-$page) <= 2) {
                    echo '<button onclick="gotoPage('.$i.')">'.$i.'</button>';
                } else if ($i == $page-3 || $i == $page+3) {
                    echo '<span>...</span>';
                }
            }
            if ($page < $total_pages) {
                echo '<button onclick="gotoPage('.($page+1).')" aria-label="Trang sau">&raquo;</button>';
            } else {
                echo '<span>&raquo;</span>';
            }
        }
        ?>
        </div>
    </div>
    <div class="footer">By Seo Công Chúa</div>
</div>

<div id="noteModal" style="display:none; position:fixed; top:30%; left:50%; transform:translate(-50%,-50%); background:#fff; padding:20px 18px 16px 18px; border-radius:12px; box-shadow:0 2px 16px rgba(0,0,0,0.13); z-index:9999; min-width:240px; max-width:95vw;">
    <h5 style="font-weight:600;font-size:1.13rem;margin-bottom:12px;">Ghi chú IP: <span id="modalIp"></span></h5>
    <input type="text" id="noteInput" class="form-control mb-2" placeholder="Ghi chú (VD: VIP, nghi ngờ...)" style="margin-bottom:12px;">
    <div style="display:flex;gap:8px;">
      <button class="btn btn-dark" onclick="saveNote()" style="flex:1;">Lưu</button>
      <button class="btn btn-outline-secondary" onclick="closeModal()" style="flex:1;">Đóng</button>
    </div>
</div>
<div id="modalBackdrop" style="display:none; position:fixed;top:0;left:0;width:100vw;height:100vh; background:rgba(0,0,0,0.17);z-index:9998;"></div>

<script>
document.getElementById('toggleDarkMode').onclick = function() {
    document.body.classList.toggle('dark-mode');
    if(document.body.classList.contains('dark-mode')) {
        localStorage.setItem('darkMode', '1');
        document.getElementById('darkIcon').innerHTML = '<circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2"/><path d="M10 2v2M10 16v2M18 10h-2M4 10H2M15.07 15.07l-1.42-1.42M6.35 6.35L4.93 4.93M15.07 4.93l-1.42 1.42M6.35 13.65l-1.42 1.42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>';
    } else {
        localStorage.removeItem('darkMode');
        document.getElementById('darkIcon').innerHTML = '<circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2"/><path d="M10 2v2M10 16v2M18 10h-2M4 10H2M15.07 15.07l-1.42-1.42M6.35 6.35L4.93 4.93M15.07 4.93l-1.42 1.42M6.35 13.65l-1.42 1.42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>';
    }
};
if(localStorage.getItem('darkMode')) {
    document.body.classList.add('dark-mode');
    document.getElementById('darkIcon').innerHTML = '<circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2"/><path d="M10 2v2M10 16v2M18 10h-2M4 10H2M15.07 15.07l-1.42-1.42M6.35 6.35L4.93 4.93M15.07 4.93l-1.42 1.42M6.35 13.65l-1.42 1.42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>';
}

function searchTable() {
  var input, filter, table, tr, td, i, txtValue;
  input = document.getElementById("searchInput");
  filter = input.value.toLowerCase();
  table = document.querySelector("table");
  tr = table.getElementsByTagName("tr");
  for (i = 1; i < tr.length; i++) {
    let found = false;
    let tds = tr[i].getElementsByTagName("td");
    for (let j = 0; j < tds.length; j++) {
      if (tds[j]) {
        txtValue = tds[j].textContent || tds[j].innerText;
        if (txtValue.toLowerCase().indexOf(filter) > -1) {
          found = true;
          break;
        }
      }
    }
    tr[i].style.display = found ? "" : "none";
  }
}
setInterval(function() {
  location.reload();
}, 500000000);

let editingIp = '';
let editingBtn = null;
function editNote(ip, btn) {
    editingIp = ip;
    editingBtn = btn;
    document.getElementById('modalIp').innerText = ip;
    let row = btn.closest('tr');
    document.getElementById('noteInput').value = row.querySelector('.ip-note').innerText;
    document.getElementById('noteModal').style.display = 'block';
    document.getElementById('modalBackdrop').style.display = 'block';
    document.getElementById('noteInput').focus();
}
function closeModal() {
    document.getElementById('noteModal').style.display = 'none';
    document.getElementById('modalBackdrop').style.display = 'none';
}
function saveNote() {
    const note = document.getElementById('noteInput').value;
    fetch('admin.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action:'save_note', ip: editingIp, note})
    }).then(res=>location.reload());
    closeModal();
}
function gotoPage(page) {
    let url = new URL(window.location.href);
    url.searchParams.set('page', page);
    window.location.href = url.toString();
}
</script>
</body>
</html>
