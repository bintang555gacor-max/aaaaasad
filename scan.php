<?php
// ===============================================
// Smart Shell and Vulnerability Scanner
// ===============================================

if (isset($_POST['action']) && $_POST['action'] == 'scan') {
    scan();
    exit;
}

function scan() {
    $searchDirectory = '.'; // Directory to scan
    $signatures = [
        'drwxr-xr-x', 'Uname:', 'Yanz Webshell!', '-rw-r--r--', 'type="submit" name="actionUpload">Upload!',
        'input type="hidden" name="pilihan" value="upload"', 'input type="hidden" name="dir" value=',
        'input type="file" name="uploadFile"', 'WIBUHAX0R1337', '<title>Gecko [', 'Modified By #No_Identity',
        'input type="submit" value="upload"', '%PDF- %PDF-', 'Function putenv()', '#!*&@#!*&@#', 'Lambo [Beta]',
        './AlfaTeam', 'Hunter Neel', 'Gel4y Mini Shell', '{Ninja-Shell}', 'type="button">Upload File<',
        'Simple File Manage Design by index.php', 'x3x3x3x_5h3ll', 'LIT COUSRE TEAM', '403WebShell',
        'Indonesian Darknet', 'AnonSec Shell', 'Powered By Indonesian Darknet', '<title>MARIJUANA</title>',
        'File manager -', 'bondowoso black hat shell', 'BlackDragon', '| PHP 7.4.20 |',
        'xXx Kelelawar Cyber Team xXx', 'Code By Kelelawar Cyber Team', 'UnknownSec', 'shell bypass 403',
        'UnknownSec Shell', '[+[MAD TIGER]+]', 'Franz Private Shell', 'Webshell V1.0', '>Cassano Bypass <',
        'TEAM-0ROOT Uploader', 'Fighter Kamrul Plugin', '- FierzaXploit -', 'Simple,Responsive & Powerfull',
        '<title>FierzaXploit</title>', 'Current dir:', 'Minishell', 'Current directory:', '[ ! ] Cilent Shell Backdor [ ! ]',
        'Powered By Indonesian Darknet', 'Mini Shell', 'Mini Shell By Black_Shadow', 'FileManager Version 0.2 by ECWS',
        'aDriv4-Priv8 TOOL', 'B Ge Team File Manager', 'MARIJuANA', 'ineSec Team Shell', 'CHips L Pro sangad', 'Doc Root:',
        '[+] MINI SH3LL BYPASS [+]', 'TEAM-0ROOT', '#No_Identity 2.4.3', '[ Mini Shell ]', 'PHU Mini Shell',
        'MSQ_403', '#wp_config_error#', 'Graybyt3 Was Here', 'One Hat Cyber Team', 'Mr.Combet WebShell',
        'C0d3d By Dr.D3m0', 'input name="fnm" type="file"/><input type="submit"',
        'input type="file" name="uploaded_file"', 'input type="file" name="file"><input name="_upl" type="submit" id="_upl" value="Upload"',
        'input type="file" name="fileToUpload" id="fileToUpload"', 'input type="file" name="files[]" id="file-input"',
        'input type="file" name="file', 'input name="message" type="file"', 'input name="passw" value=""><input name="dir"',
        'input class="Input" type="file" name="file_n[]"', 'input type="file" name="__"><input name="_" type="submit" value="Upload"',
        'input type="file" class="input" name="file"', 'input name="fnm" type="file"/><input type="submit" value="',
        'input name="uploadedfile" type="file"/><input type="submit" value="Upload File"',
        'input type="file" name="files[]" id="file-input"', 'type="file"><br><input',
        'input type="file" name="filename"', 'input type="file" name="upload"',
        'input name="uploadedfile" type="file"/><input type="submit" value="Upload File"',
        'input type="submit" name="upload" value="Uploader"', 'input type="file" name="image"><input type="Submit" name="Submit" value="Submit"',
        'input type="file" name="mbdfiles"', 'input type="submit" name="upl_files" value="upload"',
        'input type="file" name="image"', 'input type="submit" name="test" value="Upload"',
        'input type="hidden" name="path" value=', 'Upload File : <input type="file" name="file"',
        'L I E R SHELL', '<pre align=center><form method=post>Password<br><input type=password name=pass',
        'type=password name=pass', '>Password<br><input', 'input type="submit" value=">>"',
        '%PDF- %PDF-0-1<form action="" method="post"><input type="text" name="_rg"><input type="submit" value=">>"'
    ];

    $totalFiles = 0;
    $suspiciousFiles = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($searchDirectory));

    foreach ($iterator as $file) {
        if ($file->isDir()) continue;

        $filepath = $file->getPathname();
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['php', 'phtml', 'php5', 'html'])) continue; // Support for more extensions

        $content = @file_get_contents($filepath);
        if ($content === false) continue;

        foreach ($signatures as $signature) {
            if (stripos($content, $signature) !== false) {
                // Save the full path of suspicious files starting from the base directory
                $relativePath = str_replace(getcwd(), '', realpath($filepath));
                $suspiciousFiles[] = htmlspecialchars($relativePath);
                break;
            }
        }

        $totalFiles++;
    }

    $results = [
        'total' => $totalFiles,
        'infected' => count($suspiciousFiles),
        'files' => $suspiciousFiles
    ];

    header('Content-Type: application/json');
    echo json_encode($results);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Backdoor and Vulnerability Scanner Telegram: @X7ROOT</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
    .container { max-width: 700px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0px 0px 10px rgba(0,0,0,0.1);}
    h2 { text-align: center; color: #333; }
    #startBtn { background: #28a745; color: white; padding: 10px 20px; font-size: 16px; border: none; border-radius: 5px; cursor: pointer;}
    #startBtn:hover { background: #218838; }
    #results { margin-top: 20px; }
    .result-box { margin-top: 10px; padding: 10px; background: #e9ecef; border-radius: 5px; }
    .infected { color: red; }
    .clean { color: green; }
</style>
</head>
<body>

<div class="container">
    <h2>🔎 Backdoor and Vulnerability Scanner  Telegram: @X7ROOT</h2>
    <div style="text-align:center;">
        <button id="startBtn">Start Scan</button>
    </div>

    <div id="results"></div>
</div>

<script>
document.getElementById('startBtn').addEventListener('click', function() {
    document.getElementById('results').innerHTML = 'Scanning... Please wait ⏳...';

    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=scan'
    })
    .then(response => response.json())
    .then(data => {
        let html = '<div class="result-box">';
        html += '<strong>Total Files Scanned:</strong> ' + data.total + '<br>';
        html += '<strong>Suspicious Files Found:</strong> <span class="' + (data.infected > 0 ? 'infected' : 'clean') + '">' + data.infected + '</span><br><br>';

        if (data.files.length > 0) {
            html += '<strong>List of Suspicious Files:</strong><ul>';
            data.files.forEach(file => {
                html += '<li>' + file + '</li>';
            });
            html += '</ul>';
        } else {
            html += '<strong>No suspicious files found. Your site is clean! ✅</strong>';
        }

        html += '</div>';
        document.getElementById('results').innerHTML = html;
    })
    .catch(error => {
        document.getElementById('results').innerHTML = 'Error during scanning: ' + error;
    });
});
</script>

</body>
</html>
