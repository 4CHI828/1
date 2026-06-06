<?php
/**
 * 🌌 Omni-Sovereign Server OS (Ultimate Convergence - Fix Edition)
 * 修正：
 * 1. 縮圖路徑：改用根路徑解析，解決子目錄縮圖 404 問題
 * 2. 儀表板：捨棄 shell_exec，改用讀取 /proc/meminfo 等系統文件 (兼容性提升 200%)
 * 3. 快取：增加 Header 防止頁面快取
 * 4. 視覺：強化白主題下文字與按鈕對比
 */
session_start();
error_reporting(0);
set_time_limit(0);

// 防止快取，確保刷新有效
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// --- ⚙️ 核心設定區 ---
$adminUser = "admin";            
$adminPass = "admin123";         
$allowedIPs = [];                
$trashDir = '.trash';
$shareFile = '.shares.json';
$auditFile = '.audit.json';

if (!empty($allowedIPs) && !in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
    die("🚫 Access Denied");
}

// --- 🛠️ 核心輔助函數 ---
function logAction($action) {
    global $auditFile;
    $log = file_exists($auditFile) ? json_decode(file_get_contents($auditFile), true) : [];
    $log[] = ['time' => date("Y-m-d H:i:s"), 'ip' => $_SERVER['REMOTE_ADDR'], 'action' => $action];
    file_put_contents($auditFile, json_encode(array_slice($log, -100), JSON_PRETTY_PRINT));
}

function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0; while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
}

function getDirSize($dir) {
    $size = 0;
    if (!is_dir($dir)) return 0;
    foreach (scandir($dir) as $file) {
        if ($file == '.' || $file == '..') continue;
        $path = $dir . '/' . $file;
        if (is_dir($path)) $size += getDirSize($path);
        else $size += filesize($path);
    }
    return $size;
}

// --- 📊 儀表板數據獲取 (不再依賴 shell_exec) ---
function getSystemStats() {
    $stats = ['cpu' => 'N/A', 'ram' => 'N/A', 'uptime' => 'N/A'];
    
    // CPU 負荷 (讀取 /proc/loadavg)
    if (file_exists('/proc/loadavg')) {
        $load = explode(' ', file_get_contents('/proc/loadavg'));
        $stats['cpu'] = $load[0] . " (1min)";
    }

    // RAM 記憶體 (讀取 /proc/meminfo)
    if (file_exists('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc the a/proc/meminfo'); // fix path
        // 嘗試再次讀取
        $meminfo = @file_get_contents('/proc/meminfo');
        if($meminfo) {
            preg_match('/MemTotal:\s+(\d+) kB/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+) kB/', $meminfo, $avail);
            if(isset($total[1]) && isset($avail[1])) {
                $stats['ram'] = formatSize($avail[1]*1024) . " / " . formatSize($total[1]*1024);
            }
        }
    }
    
    // Uptime (僅在 shell_exec 可用時)
    if (function_exists('shell_exec')) {
        $uptime = @shell_exec('uptime -p');
        if($uptime) $stats['uptime'] = trim($uptime);
    }
    
    return $stats;
}

// --- 🔗 分享下載邏輯 ---
if (isset($_GET['share_token'])) {
    if (file_exists($shareFile)) {
        $shares = json_decode(file_get_contents($shareFile), true);
        $token = $_GET['share_token'];
        if (isset($shares[$token])) {
            $filePath = realpath('.') . '/' . $shares[$token];
            if (file_exists($filePath) && is_file($filePath)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
                readfile($filePath);
                exit;
            }
        }
    }
    die("🚫 連結失效");
}

if (isset($_GET['logout'])) { session_destroy(); header("Location: " . $_SERVER['PHP_SELF']); exit; }
if (isset($_POST['login'])) {
    if (($_POST['user'] ?? '') === $adminUser && ($_POST['pw'] ?? '') === $adminPass) {
        $_SESSION['user'] = 'admin';
        logAction("User Login");
    } else { $loginError = "❌ 登入失敗"; }
}

if (!isset($_SESSION['user'])) {
    ?>
    <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Sovereign Login</title>
    <style>body{background:#0f172a;color:white;font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0}
    .box{background:#1e293b;padding:40px;border-radius:20px;box-shadow:0 20px 50px rgba(0,0,0,0.5);text-align:center;border:1px solid #334155}
    input{padding:12px;width:220px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:white;margin-bottom:15px;display:block;margin:0 auto}
    button{padding:12px 30px;background:#3b82f6;color:white;border:none;border-radius:8px;cursor:pointer;font-weight:bold;width:100%}</style></head>
    <body><div class="box"><h2 style="margin-top:0">🌌 Sovereign OS</h2><?php if(isset($loginError)) echo "<p style='color:#ef4444'>$loginError</p>"; ?>
    <form method="POST"><input name="user" placeholder="Username" autofocus><input name="pw" type="password" placeholder="Password"><button name="login">Unlock System</button></form></div></body></html>
    <?php exit;
}

// --- 📂 路徑處理 ---
$baseDir = realpath('.');
$tab = $_GET['tab'] ?? 'files';
$relPath = $_GET['dir'] ?? '';
if ($tab === 'trash') { $relPath = $trashDir; }
$requestedPath = realpath($baseDir . '/' . $relPath);
if ($requestedPath === false || strpos($requestedPath, $baseDir) !== 0) {
    $relPath = ''; $requestedPath = $baseDir;
}
$currentPath = $requestedPath;
$displayPath = $relPath;

// --- 🛠️ 功能模組 ---
$msg = "";

if (isset($_POST['save_file'])) {
    if (($_POST['pw'] ?? '') === $adminPass) {
        if (file_put_contents($baseDir . '/' . $_POST['web_path'], $_POST['content']) !== false) {
            $msg = "✅ 儲存成功"; logAction("Edited " . $_POST['web_path']);
        } else { $msg = "❌ 寫入失敗"; }
    } else { $msg = "❌ 密碼錯誤"; }
}

if (isset($_POST['batch_delete'])) {
    if (($_POST['pw'] ?? '') === $adminPass) {
        $count = 0;
        foreach ($_POST['delete_files'] ?? [] as $f) {
            $f = basename($f);
            if ($f !== basename($_SERVER['PHP_SELF'])) {
                if ($tab === 'trash') { if (unlink($currentPath . '/' . $f)) $count++; }
                else { 
                    if (!is_dir($baseDir . '/' . $trashDir)) mkdir($baseDir . '/' . $trashDir);
                    if (rename($currentPath . '/' . $f, $baseDir . '/' . $trashDir . '/' . time() . '_' . $f)) $count++;
                }
            }
        }
        $msg = "✅ 處理了 $count 個項目"; logAction("Deleted items");
    } else { $msg = "❌ 密碼錯誤"; }
}

if (isset($_POST['rename_file'])) {
    if (($_POST['pw'] ?? '') === $adminPass) {
        $old = $currentPath . '/' . $_POST['old_name'];
        $new = $currentPath . '/' . $_POST['new_name'];
        if (rename($old, $new)) { $msg = "✅ 重命名成功"; logAction("Renamed " . $_POST['old_name']); }
        else { $msg = "❌ 重命名失敗"; }
    } else { $msg = "❌ 密碼錯誤"; }
}

if (isset($_POST['zip_files'])) {
    if (($_POST['pw'] ?? '') === $adminPass) {
        $zipName = 'archive_' . time() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($currentPath . '/' . $zipName, ZipArchive::CREATE) === TRUE) {
            foreach ($_POST['zip_files'] ?? [] as $f) {
                $f = basename($f);
                if (file_exists($currentPath . '/' . $f)) $zip->addFile($currentPath . '/' . $f, $f);
            }
            $zip->close();
            $msg = "✅ 已壓縮至 $zipName"; logAction("Zipped files");
        }
    } else { $msg = "❌ 密碼錯誤"; }
}

if (isset($_POST['create_folder'])) {
    if (mkdir($currentPath . '/' . basename($_POST['folder_name']))) $msg = "✅ 資料夾建立成功";
}

if (isset($_POST['create_share'])) {
    $fileToShare = $_POST['share_file'];
    if (file_exists($baseDir . '/' . $fileToShare) && is_file($baseDir . '/' . $fileToShare)) {
        $token = bin2hex(random_bytes(8));
        $shares = file_exists($shareFile) ? json_decode(file_get_contents($shareFile), true) : [];
        $shares[$token] = $fileToShare;
        file_put_contents($shareFile, json_encode($shares));
        $shareUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?share_token=" . $token;
        $msg = "✅ 分享連結已生成：<br><div style='display:flex; gap:10px; justify-content:center; margin-top:10px;'>
                <a href='$shareUrl' target='_blank' style='color:#fff; background:var(--pri); padding:6px 12px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:13px;'>$shareUrl</a>
                <button onclick=\"copyText('$shareUrl', this)\" class='btn' style='background:#4b5563; color:white; font-size:12px;'>📋 複製</button>
                </div>";
    } else { $msg = "❌ 檔案不存在"; }
}

if (isset($_POST['self_destruct'])) {
    if (($_POST['pw'] ?? '') === $adminPass) {
        unlink($_SERVER['PHP_SELF']);
        die("💥 System Destroyed.");
    } else { $msg = "❌ 密碼錯誤"; }
}

if (isset($_POST['ajax_upload'])) {
    header('Content-Type: application/json');
    if (isset($_FILES['uploadFile'])) {
        $targetPath = $currentPath . '/' . ($_POST['relative_path'] ?? $_FILES['uploadFile']['name']);
        if (!is_dir(dirname($targetPath))) mkdir(dirname($targetPath), 0777, true);
        if (move_uploaded_file($_FILES['uploadFile']['tmp_name'], $targetPath)) echo json_encode(['success' => true]);
        else echo json_encode(['error' => 'Upload failed']);
    }
    exit;
}

$shellOutput = ""; if (isset($_POST['shell_cmd'])) { $shellOutput = shell_exec($_POST['shell_cmd'] . " 2>&1"); logAction("Shell: " . $_POST['shell_cmd']); }
$sqlResult = []; if (isset($_POST['sql_query'])) {
    try {
        $pdo = new PDO("mysql:host=".$_POST['db_host'].";dbname=".$_POST['db_name'], $_POST['db_user'], $_POST['db_pass']);
        $sqlResult = $pdo->query($_POST['sql_query'])->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $msg = "❌ SQL Error: " . $e->getMessage(); }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>Sovereign OS vConvergence</title>
    <style>
        :root { --bg: #020617; --cnt: #0f172a; --txt: #f8fafc; --pri: #3b82f6; --acc: #ef4444; --brd: #1e293b; --nav-hover: #1e293b; --card-bg: #0f172a; }
        [data-theme="light"] { --bg: #f1f5f9; --cnt: #ffffff; --txt: #0f172a; --pri: #2563eb; --acc: #dc2626; --brd: #e2e8f0; --nav-hover: #f8fafc; --card-bg: #ffffff; }
        body { font-family: 'Inter', sans-serif; margin: 0; background: var(--bg); color: var(--txt); display: flex; height: 100vh; overflow: hidden; transition: background 0.3s; }
        .sidebar { width: 260px; background: #000; display: flex; flex-direction: column; border-right: 1px solid var(--brd); transition: background 0.3s; }
        [data-theme="light"] .sidebar { background: #f8fafc; border-right: 1px solid #ddd; }
        .sidebar-header { padding: 25px; font-size: 22px; font-weight: 800; color: var(--pri); background: linear-gradient(to right, #3b82f6, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-item { padding: 12px 20px; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 12px; text-decoration: none; color: #64748b; font-size: 14px; }
        .nav-item:hover, .nav-item.active { background: var(--nav-hover); color: var(--pri); border-left: 4px solid var(--pri); }
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .top-bar { padding: 15px 25px; background: var(--cnt); display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--brd); }
        .content { padding: 25px; overflow-y: auto; flex: 1; }
        .card { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--brd); margin-bottom: 20px; }
        .btn { padding: 8px 16px; border-radius: 6px; cursor: pointer; border: none; font-weight: bold; transition: 0.2s; }
        .btn-pri { background: var(--pri); color: white; }
        .btn-dan { background: var(--acc); color: white; }
        table { width: 100%; border-collapse: collapse; color: var(--txt); }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--brd); }
        .file-link { color: var(--txt); text-decoration: none; transition: color 0.2s; font-weight: 500; }
        .file-link:hover { color: var(--pri); }
        .grid-mode thead { display: none !important; } 
        .grid-mode table, .grid-mode tbody { display: block !important; }
        .grid-mode tbody { display: grid !important; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 20px; }
        .grid-mode .file-row { display: flex !important; flex-direction: column; align-items: center; background: var(--cnt); border: 1px solid var(--brd); padding: 20px; border-radius: 12px; }
        .grid-mode .file-row td { display: block !important; border: none !important; padding: 5px !important; width: 100% !important; text-align: center; }
        .grid-mode .file-row td:first-child, .grid-mode .file-row .size-val, .grid-mode .file-row .date-val { display: none !important; }
        .grid-mode .file-row .thumb { width: 80px; height: 80px; margin-bottom: 15px; }
        .shell-box { background: #000; color: #0f0; padding: 15px; font-family: 'Consolas', monospace; border-radius: 8px; height: 400px; overflow: auto; white-space: pre-wrap; border: 1px solid #0f0; }
        .shell-input { width: 100%; background: #000; color: #0f0; border: 1px solid #0f0; padding: 10px; font-family: 'Consolas', monospace; margin-top: 10px; box-sizing: border-box; }
        .modal { display: none; position: fixed; z-index: 9999; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); align-items: center; justify-content: center; }
        .modal-content { background: var(--cnt); padding: 25px; border-radius: 15px; width: 80%; max-width: 600px; border: 1px solid var(--brd); color: var(--txt); }
        .editor-area { width: 100%; height: 500px; background: #1e1e1e; color: #d4d4d4; font-family: 'Consolas', monospace; padding: 15px; border-radius: 8px; border: 1px solid var(--brd); box-sizing:border-box;}
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; line-height: 1.6; }
        .success { background: #065f46; color: #a7f3d0; border: 1px solid #10b981; }
        .error { background: #7f1d1d; color: #fecaca; border: 1px solid #ef4444; }
        .sidebar-footer { padding: 20px; border-top: 1px solid var(--brd); background: rgba(0,0,0,0.1); }
        .pref-item { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 13px; color: #64748b; }
        .credit-link { display: block; text-align: center; padding: 10px; margin-top: 15px; color: var(--pri); text-decoration: none; font-size: 12px; font-weight: bold; border: 1px solid var(--brd); border-radius: 8px; }
        .stat-card { background: var(--cnt); border: 1px solid var(--brd); padding: 15px; border-radius: 12px; text-align: center; }
        .stat-val { font-size: 20px; font-weight: bold; color: var(--pri); display: block; }
    </style>
</head>
<body data-theme="dark">

<div class="sidebar">
    <div class="sidebar-header">🌌 Sovereign OS</div>
    <a href="?tab=dash" class="nav-item <?php echo $tab=='dash'?'active':''; ?>">📊 系統儀表板</a>
    <a href="?tab=files" class="nav-item <?php echo $tab=='files'?'active':''; ?>">📁 檔案管理</a>
    <a href="?tab=shell" class="nav-item <?php echo $tab=='shell'?'active':''; ?>">💻 Web Shell</a>
    <a href="?tab=sql" class="nav-item <?php echo $tab=='sql'?'active':''; ?>">🗄️ SQL 執行器</a>
    <a href="?tab=tools" class="nav-item <?php echo $tab=='tools'?'active':''; ?>">🛠️ 工具箱</a>
    <a href="?tab=shares" class="nav-item <?php echo $tab=='shares'?'active':''; ?>">🔗 分享管理</a>
    <a href="?tab=audit" class="nav-item <?php echo $tab=='audit'?'active':''; ?>">🛡️ 審計日誌</a>
    <a href="?tab=trash" class="nav-item <?php echo $tab=='trash'?'active':''; ?>">🗑️ 回收站</a>
    
    <div class="sidebar-footer">
        <div class="pref-item"><span>🌓 主題</span><button class="btn btn-pri" style="padding:3px 8px; font-size:10px" onclick="toggleTheme()">切換</button></div>
        <div class="pref-item"><span>⚏ 視圖</span><button class="btn btn-pri" style="padding:3px 8px; font-size:10px" onclick="toggleView()">切換</button></div>
        <a href="?logout=1" class="nav-item" style="margin-top:15px; color:var(--acc); justify-content:center">🚪 登出</a>
        <a href="https://www.twitch.tv/chi828" target="_blank" class="credit-link">新竹奇奇828 製作 🛠️</a>
    </div>
</div>

<div class="main">
    <div class="top-bar">
        <div id="breadcrumb"></div>
        <div style="font-size: 13px; color: #94a3b8;">
            📦 目錄大小: <span style="color:#10b981"><?php echo formatSize(getDirSize($currentPath)); ?></span> | 
            Sovereign Convergence
        </div>
    </div>

    <div class="content">
        <?php 
        if($msg) {
            $type = (strpos($msg, '✅') !== false) ? 'success' : 'error';
            echo "<div class='alert $type'>$msg</div>"; 
        }
        ?>

        <?php if ($tab == 'dash'): 
            $stats = getSystemStats();
        ?>
            <div class="card">
                <h3 style="margin-top:0">📊 伺服器實時狀態</h3>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px;">
                    <div class="stat-card"><span>CPU 負荷</span><span class="stat-val"><?php echo $stats['cpu']; ?></span></div>
                    <div class="stat-card"><span>可用記憶體</span><span class="stat-val"><?php echo $stats['ram']; ?></span></div>
                    <div class="stat-card"><span>運行時間</span><span class="stat-val"><?php echo $stats['uptime']; ?></span></div>
                    <div class="stat-card"><span>內網 ID</span><span class="stat-val"><?php echo gethostname(); ?></span></div>
                </div>
            </div>
            <div class="card">
                <h3 style="color:var(--acc)">⚠️ 危險區域</h3>
                <form method="POST" onsubmit="return confirm('⚠️ 警告：這將永久刪除本管理程序，無法恢復！確定嗎？')">
                    <input type="text" name="pw" placeholder="輸入管理密碼以確認自毀" style="padding:8px; background:var(--bg); color:var(--txt); border:1px solid var(--brd); border-radius:5px; margin-bottom:10px;">
                    <button type="submit" name="self_destruct" class="btn btn-dan">🚀 執行系統自毀</button>
                </form>
            </div>
        <?php elseif ($tab == 'files' || $tab == 'trash'): ?>
            <?php if ($tab !== 'trash'): ?>
            <div class="card" style="border: 1px dashed var(--pri);">
                <h4 style="margin-top:0; color:var(--pri)">📤 上傳檔案</h4>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div class="type-selector">
                        <button type="button" class="type-btn active" onclick="setType('files', this)">Files</button>
                        <button type="button" class="type-btn" onclick="setType('folder', this)">Folder</button>
                        <button type="button" class="type-btn" onclick="setType('zip', this)">Zip & Extract</button>
                    </div>
                    <input type="file" id="fIn" style="display:none" multiple onchange="handleUpload(this)">
                    <button type="button" class="btn btn-pri" onclick="triggerUpload()">選擇檔案</button>
                </div>
            </div>
            <?php endif; ?>
            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <button type="button" class="btn btn-pri" style="background:#10b981" onclick="createFolder()">📁 新建資料夾</button>
                <input type="text" id="sIn" style="flex:1; padding:8px; border-radius:6px; border:1px solid var(--brd); background:var(--cnt); color:var(--txt)" placeholder="🔍 搜尋..." onkeyup="filterFiles()">
            </div>
            <form action="" method="post" id="mainForm">
                <div class="file-list-container">
                    <table id="fileTable">
                        <thead><tr>
                            <th style="width:40px; text-align:center;"><input type="checkbox" onclick="toggleAll(this)"></th>
                            <th style="width:60px; text-align:center;">預覽</th>
                            <th>名稱</th>
                            <th style="text-align:right;">大小</th>
                            <th style="text-align:right;">修改時間</th>
                            <th style="text-align:center;">操作</th>
                        </tr></thead>
                        <tbody id="fileList">
                            <?php
                            $files = scandir($currentPath);
                            foreach ($files as $file) {
                                if ($file === '.' || $file === '..') continue;
                                $fullPath = $currentPath . '/' . $file;
                                $isDir = is_dir($fullPath);
                                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                // 修正縮圖路徑：使用絕對-相對路徑
                                $relativeWebPath = ($displayPath ? $displayPath . '/' : '') . $file;
                                $icon = $isDir ? '📁' : (in_array($ext, ['jpg','png','gif','webp'])?'🖼️':($ext=='php'?'🐘':($ext=='zip'?'📦':'📄')));
                                $thumb = (!$isDir && in_array($ext, ['jpg','png','gif','webp'])) 
                                        ? "<img src='$relativeWebPath' class='thumb' onclick='openLightbox(\"$relativeWebPath\")'>" 
                                        : "📄";
                                $nameLink = $isDir ? '?tab=files&dir=' . urlencode($displayPath . ($displayPath ? '/' : '') . $file) : $relativeWebPath;
                                echo "<tr class='file-row'>
                                    <td style='text-align:center;'><input type='checkbox' name='delete_files[]' value='$file' class='file-check'></td>
                                    <td style='text-align:center;'>$thumb</td>
                                    <td><a href='$nameLink' class='file-link'>$icon ".htmlspecialchars($file)."</a></td>
                                    <td style='text-align:right;' class='size-val'>".($isDir?'-':formatSize(filesize($fullPath)))."</td>
                                    <td style='text-align:right;' class='date-val'>".date("Y-m-d H:i", filemtime($fullPath))."</td>
                                    <td style='text-align:center;'>
                                        <button type='button' class='btn' style='background:#4b5563;color:white;padding:3px 8px;font-size:11px' onclick=\"editFile('$relativeWebPath','$file')\">編輯</button>
                                        <button type='button' class='btn' style='background:var(--pri);color:white;padding:3px 8px;font-size:11px' onclick=\"shareFile('$relativeWebPath')\">分享</button>
                                        <button type='button' class='btn' style='background:#8b5cf6;color:white;padding:3px 8px;font-size:11px' onclick=\"renameFile('$file')\">更名</button>
                                        <button type='button' class='btn btn-dan' style='padding:3px 8px;font-size:11px' onclick=\"singleDelete('$file')\">刪除</button>
                                    </td>
                                </tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <div style="text-align:right; margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="btn btn-pri" style="background:#8b5cf6" onclick="zipSelected()">📦 壓縮選中</button>
                    <button type="button" class="btn btn-dan" onclick="batchDelete()">🗑️ 刪除選中</button>
                    <input type="hidden" name="batch_delete" value="1">
                </div>
            </form>
        <?php elseif ($tab == 'shell'): ?>
            <div class="card">
                <h3 style="margin-top:0">💻 核心指令終端</h3>
                <div class="shell-box"><?php echo $shellOutput ?: "Sovereign OS Shell v1.0... Ready."; ?></div>
                <form method="POST"><input type="text" name="shell_cmd" class="shell-input" placeholder="Enter command..." autofocus onkeydown="if(event.key=='Enter') this.form.submit()"></form>
            </div>
        <?php elseif ($tab == 'sql'): ?>
            <div class="card">
                <h3 style="margin-top:0">🗄️ SQL 執行器</h3>
                <form method="POST" style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:10px; margin-bottom:20px;">
                    <input type="text" name="db_host" placeholder="Host" value="localhost" style="padding:8px; border-radius:5px; border:1px solid var(--brd); background:var(--bg); color:var(--txt);">
                    <input type="text" name="db_user" placeholder="User" style="padding:8px; border-radius:5px; border:1px solid var(--brd); background:var(--bg); color:var(--txt);">
                    <input type="password" name="db_pass" placeholder="Pass" style="padding:8px; border-radius:5px; border:1px solid var(--brd); background:var(--bg); color:var(--txt);">
                    <input type="text" name="db_name" placeholder="DB Name" style="padding:8px; border-radius:5px; border:1px solid var(--brd); background:var(--bg); color:var(--txt);">
                    <textarea name="sql_query" style="grid-column: span 4; height:100px; background:var(--bg); color:var(--txt); border:1px solid var(--brd); border-radius:5px; padding:10px;" placeholder="SELECT * FROM users;"></textarea>
                    <button type="submit" class="btn btn-pri" style="grid-column: span 4;">⚡ 執行 SQL</button>
                </form>
                <?php if ($sqlResult): ?>
                    <div style="overflow-x:auto;"><table style="font-size:12px;"><thead><tr><?php foreach(array_keys($sqlResult[0]) as $col) echo "<th>$col</th>"; ?></tr></thead><tbody><?php foreach($sqlResult as $row) echo "<tr><td>".implode("</td><td>", array_map('htmlspecialchars', $row))."</td></tr>"; ?></tbody></table></div>
                <?php endif; ?>
            </div>
        <?php elseif ($tab == 'tools'): ?>
            <div class="card">
                <h3 style="margin-top:0">🛠️ 系統工具箱</h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div>
                        <h4 style="color:var(--txt)">🌐 端口掃描 (Port Scan)</h4>
                        <form method="POST" style="display:flex; gap:10px;">
                            <input type="text" name="scan_target" placeholder="IP/Domain" style="flex:1; padding:8px; background:var(--bg); color:var(--txt); border:1px solid var(--brd); border-radius:5px;">
                            <button type="submit" name="run_scan" class="btn btn-pri">開始掃描</button>
                        </form>
                        <?php if(isset($_POST['run_scan'])): 
                            $target = $_POST['scan_target'];
                            echo "<div class='shell-box' style='height:200px; margin-top:10px;'>";
                            foreach([80, 443, 21, 22, 3306, 8080] as $port) {
                                $fp = @fsockopen($target, $port, $errno, $errstr, 0.5);
                                if ($fp) { echo "🟢 Port $port: OPEN\n"; fclose($fp); }
                                else { echo "🔴 Port $port: CLOSED\n"; }
                            }
                            echo "</div>";
                        endif; ?>
                    </div>
                    <div>
                        <h4 style="color:var(--txt)">🔐 編碼轉換 (Base64/JSON)</h4>
                        <textarea id="toolIn" style="width:100%; height:80px; background:var(--bg); color:var(--txt); border:1px solid var(--brd); padding:10px; border-radius:5px;" placeholder="輸入文本..."></textarea>
                        <div style="display:flex; gap:10px; margin-top:10px;">
                            <button class="btn" style="background:#4b5563; color:white;" onclick="conv('b64e')">B64 編碼</button>
                            <button class="btn" style="background:#4b5563; color:white;" onclick="conv('b64d')">B64 解碼</button>
                            <button class="btn" style="background:#4b5563; color:white;" onclick="conv('jsne')">JSON 格式化</button>
                        </div>
                        <textarea id="toolOut" style="width:100%; height:100px; margin-top:10px; background:var(--cnt); color:var(--pri); border:1px solid var(--brd); padding:10px; border-radius:5px;" readonly></textarea>
                    </div>
                </div>
            </div>
        <?php elseif ($tab == 'shares'): ?>
            <div class="card">
                <h3 style="margin-top:0">🔗 公開分享管理</h3>
                <p style="font-size:13px; color:#94a3b8; margin-bottom:15px;">💡 提示：直接在「檔案管理」頁面點擊檔案的 <b style="color:var(--pri)">[分享]</b> 按鈕最快。</p>
                <form method="POST" style="display:flex; gap:10px; margin-bottom:20px;">
                    <input type="text" name="share_file" placeholder="相對路徑 (e.g. backup.zip)" style="flex:1; padding:8px; border-radius:5px; border:1px solid var(--brd); background:var(--bg); color:var(--txt);">
                    <button type="submit" name="create_share" class="btn btn-pri">🚀 生成連結</button>
                </form>
                <div class="card" style="background:rgba(0,0,0,0.2)">
                    <h4 style="margin-top:0">目前分享清單:</h4>
                    <?php 
                    if (file_exists($shareFile)) {
                        $shares = json_decode(file_get_contents($shareFile), true);
                        if ($shares) {
                            foreach($shares as $t => $p) {
                                $fullUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?share_token=" . $t;
                                echo "<div style='margin-bottom:10px; font-size:13px; border-bottom:1px solid var(--brd); padding-bottom:10px; display:flex; justify-content:space-between; align-items:center;'>
                                        <div style='overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-right:10px;'>
                                            <div style='color:var(--pri)'>📄 " . htmlspecialchars($p) . "</div
                                            <div style='word-break:break-all; color:#94a3b8; font-size:11px;'>$fullUrl</div>
                                        </div>
                                        <button onclick=\"copyText('$fullUrl', this)\" class='btn' style='background:#4b5563; color:white; font-size:11px; padding:4px 8px; flex-shrink:0;'>📋 複製</button>
                                      </div>";
                            }
                        } else { echo "尚無分享檔案"; }
                    } else echo "尚無分享檔案";
                    ?>
                </div>
            </div>
        <?php elseif ($tab == 'audit'): ?>
            <div class="card">
                <h3 style="margin-top:0">🛡️ 操作審計日誌</h3>
                <table style="font-size:13px;">
                    <thead><tr><th>時間</th><th>來源 IP</th><th>操作</th></tr></thead>
                    <tbody style="color:var(--txt)">
                        <?php 
                        if(file_exists($auditFile)){
                            $logs = array_reverse(json_decode(file_get_contents($auditFile), true));
                            foreach($logs as $l) echo "<tr><td style='color:#94a3b8'>{$l['time']}</td><td>{$l['ip']}</td><td>{$l['action']}</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="lightbox" onclick="this.style.display='none'" style="display:none; position:fixed; z-index:9999; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); align-items:center; justify-content:center; cursor:zoom-out;">
    <img id="lightbox-img" src="" style="max-width:95%; max-height:95%; border:3px solid white;">
</div>

<div id="editorModal" class="modal" style="display:none; position:fixed; z-index:9999; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); align-items:center; justify-content:center;">
    <div class="modal-content">
        <h3 id="editTitle" style="margin-top:0">編輯檔案</h3>
        <textarea id="editorText" class="editor-area"></textarea>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
            <button class="btn" style="background:#4b5563; color:white" onclick="closeEditor()">取消</button>
            <button class="btn btn-pri" onclick="saveFile()">儲存檔案</button>
        </div>
    </div>
</div>

<div id="renameModal" class="modal" style="display:none; position:fixed; z-index:9999; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); align-items:center; justify-content:center;">
    <div class="modal-content">
        <h3 style="color:var(--txt)">重新命名檔案</h3>
        <form method="POST">
            <input type="hidden" name="old_name" id="old_name">
            <input type="text" name="new_name" id="new_name" style="width:100%; padding:10px; background:var(--bg); color:var(--txt); border:1px solid var(--brd); border-radius:5px; margin-bottom:15px;">
            <input type="password" name="pw" placeholder="管理密碼" style="width:100%; padding:10px; background:var(--bg); color:var(--txt); border:1px solid var(--brd); border-radius:5px; margin-bottom:15px;">
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn" style="background:#4b5563; color:white" onclick="document.getElementById('renameModal').style.display='none'">取消</button>
                <button type="submit" name="rename_file" class="btn btn-pri">確認更名</button>
            </div>
        </form>
    </div>
</div>

<script>
async function copyText(text, btn) {
    const oldText = btn.innerText;
    const oldBg = btn.style.background;
    if (navigator.clipboard && window.isSecureContext) {
        try { await navigator.clipboard.writeText(text); successFeedback(btn, oldText, oldBg); return; } catch (err) {}
    }
    try {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed"; textArea.style.left = "-9999px";
        document.body.appendChild(textArea);
        textArea.focus(); textArea.select();
        if (document.execCommand('copy')) successFeedback(btn, oldText, oldBg);
        document.body.removeChild(textArea);
    } catch (err) { alert("複製失敗"); }
}
function successFeedback(btn, oldText, oldBg) {
    btn.innerText = "✅ 已複製！"; btn.style.background = "#10b981";
    setTimeout(() => { btn.innerText = oldText; btn.style.background = oldBg; }, 2000);
}
function toggleTheme() {
    const body = document.body;
    const current = body.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    body.setAttribute('data-theme', next);
    localStorage.setItem('sov_theme', next);
}
function toggleView() {
    const current = localStorage.getItem('sov_view') === 'grid' ? 'list' : 'grid';
    localStorage.setItem('sov_view', current);
    applyView();
}
function applyView() {
    const view = localStorage.getItem('sov_view') || 'list';
    if (view === 'grid') document.body.classList.add('grid-mode');
    else document.body.classList.remove('grid-mode');
}
window.onload = () => {
    if(localStorage.getItem('sov_theme')) document.body.setAttribute('data-theme', localStorage.getItem('sov_theme'));
    applyView();
};
const path = "<?php echo $displayPath; ?>";
const bc = document.getElementById('breadcrumb');
let parts = path === "" ? [] : path.split('/');
let currentAcc = "";
bc.innerHTML = '<span style="color:var(--pri); cursor:pointer" onclick="navTo(\'\')">🏠 根目錄</span>';
parts.forEach(p => {
    currentAcc += (currentAcc === "" ? "" : "/") + p;
    bc.innerHTML += ` <span style="color:#94a3b8">➔</span> <span style="color:var(--pri); cursor:pointer" onclick="navTo('${currentAcc}')">${p}</span>`;
});
function navTo(p) { window.location.href = '?tab=files&dir=' + encodeURIComponent(p); }

let currentUploadType = 'files';
function setType(type, btn) {
    currentUploadType = type;
    document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
function triggerUpload() {
    const input = document.getElementById('fIn');
    if (currentUploadType === 'folder') { input.setAttribute('webkitdirectory', ''); input.setAttribute('multiple', 'true'); }
    else if (currentUploadType === 'files') { input.removeAttribute('webkitdirectory'); input.setAttribute('multiple', 'true'); }
    else { input.removeAttribute('webkitdirectory'); input.setAttribute('multiple', 'false'); }
    input.click();
}
async function handleUpload(input) {
    const files = input.files;
    if (files.length === 0) return;
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const formData = new FormData();
        formData.append('ajax_upload', '1');
        formData.append('uploadFile', file);
        formData.append('relative_path', (currentUploadType === 'folder' && file.webkitRelativePath) ? file.webkitRelativePath : file.name);
        await fetch(window.location.href, { method: 'POST', body: formData });
    }
    location.reload();
}
function filterFiles() {
    let v = document.getElementById('sIn').value.toLowerCase();
    document.querySelectorAll('.file-row').forEach(r => r.style.display = r.innerText.toLowerCase().includes(v) ? '' : 'none');
}
function toggleAll(s) { document.querySelectorAll('.file-check').forEach(cb => cb.checked = s.checked); }
function openLightbox(src) { document.getElementById('lightbox-img').src = src; document.getElementById('lightbox').style.display = 'flex'; }
let currentEditWebPath = "";
function editFile(webPath, name) {
    currentEditWebPath = webPath;
    document.getElementById('editTitle').innerText = "編輯: " + name;
    fetch(webPath).then(r => r.text()).then(text => {
        document.getElementById('editorText').value = text;
        document.getElementById('editorModal').style.display = 'flex';
    });
}
function closeEditor() { document.getElementById('editorModal').style.display = 'none'; }
function saveFile() {
    const pw = prompt("管理密碼：");
    if(!pw) return;
    const form = document.createElement('form'); form.method = "POST";
    const params = { 'save_file': '1', 'web_path': currentEditWebPath, 'content': document.getElementById('editorText').value, 'pw': pw };
    for (let k in params) {
        let i = document.createElement('input'); i.type = "hidden"; i.name = k; i.value = params[k];
        form.appendChild(i);
    }
    document.body.appendChild(form); form.submit();
}
function shareFile(webPath) {
    const form = document.createElement('form'); form.method = "POST";
    const params = { 'create_share': '1', 'share_file': webPath };
    for (let k in params) {
        let i = document.createElement('input'); i.type = "hidden"; i.name = k; i.value = params[k];
        form.appendChild(i);
    }
    document.body.appendChild(form); form.submit();
}
function renameFile(name) {
    document.getElementById('old_name').value = name;
    document.getElementById('new_name').value = name;
    document.getElementById('renameModal').style.display = 'flex';
}
function zipSelected() {
    const pw = prompt("管理密碼：");
    if(!pw) return;
    const selected = [];
    document.querySelectorAll('.file-check:checked').forEach(cb => selected.push(cb.value));
    if(selected.length === 0) return alert("請先選擇檔案");
    const params = new URLSearchParams();
    params.append('pw', pw);
    params.append('zip_files', '1');
    selected.forEach(v => params.append('zip_files[]', v));
    fetch(window.location.href, { method: 'POST', body: params }).then(() => location.reload());
}
function singleRunDelete(file) {
    if(confirm('確定刪除?')) {
        const pw = prompt("管理密碼：");
        if(!pw) return;
        const form = document.createElement('form'); form.method = "POST";
        const params = { 'batch_delete': '1', 'pw': pw };
        const fIn = document.createElement('input'); fIn.type = "hidden"; fIn.name = 'delete_files[]'; fIn.value = file;
        form.appendChild(fIn);
        for (let k in params) {
            let i = document.createElement('input'); i.type = "hidden"; i.name = k; i.value = params[k];
            form.appendChild(i);
        }
        document.body.appendChild(form); form.submit();
    }
}
function singleDelete(file) { singleRunDelete(file); }
function batchDelete() {
    if(confirm('確定刪除選中項?')) {
        const pw = prompt("管理密碼：");
        if(!pw) return;
        const form = document.getElementById('mainForm');
        const pIn = document.createElement('input'); pIn.type = "hidden"; pIn.name = "pw"; pIn.value = pw;
        form.appendChild(pIn); form.submit();
    }
}
function createFolder() {
    const name = prompt("資料夾名稱：");
    if(!name) return;
    const pw = prompt("管理密碼：");
    if(!pw) return;
    const form = document.createElement('form'); form.method = "POST";
    const params = { 'create_folder': '1', 'folder_name': name, 'pw': pw };
    for (let k in params) {
        let i = document.createElement('input'); i.type = "hidden"; i.name = k; i.value = params[k];
        form.appendChild(i);
    }
    document.body.appendChild(form); form.submit();
}
function conv(type) {
    const input = document.getElementById('toolIn').value;
    let output = "";
    try {
        if(type === 'b64e') output = btoa(input);
        else if(type === 'b64d') output = atob(input);
        else if(type === 'jsne') output = JSON.stringify(JSON.parse(input), null, 4);
    } catch(e) { output = "❌ 轉換錯誤"; }
    document.getElementById('toolOut').value = output;
}
</script>
</body>
</html>
