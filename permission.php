<?php
function fixAllPermissions($dir) {
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            chmod($path, 0755); // Folder jadi 0755
            echo "✅ Folder: $path → 0755<br>";
            fixAllPermissions($path); // Rekursif
        } elseif (is_file($path)) {
            chmod($path, 0644); // File jadi 0644
            echo "✅ File:   $path → 0644<br>";
        }
    }
}

// Ganti ini jika kamu ingin target folder tertentu
$rootPath = __DIR__; // Folder tempat file PHP ini berada
fixAllPermissions($rootPath);

echo "<br>🎉 Selesai ubah semua permission.";
?>
