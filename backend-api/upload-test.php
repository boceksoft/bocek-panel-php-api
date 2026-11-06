<?php
// Gecici teshis sayfasi: is bitince sunucudan silin.
// 1) Sayfayi tarayicida acin: PHP'nin hangi ini'yi ve ayarlari kullandigini gosterir.
// 2) Formdan bir JPG yukleyin: PHP dosyayi alabiliyor mu, hangi hata/uyari var gosterir.
$startupError = error_get_last();
header('Content-Type: text/html; charset=utf-8');
echo '<pre>';

$tmp = (string) ini_get('upload_tmp_dir');
echo "PHP surumu       : " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
echo "Yuklu php.ini    : " . (php_ini_loaded_file() ?: '(yok)') . "\n";
echo "Ek ini dosyalari : " . (php_ini_scanned_files() ?: '(yok)') . "\n";
echo "Calisan kullanici: " . get_current_user() . " / " . (getenv('USERNAME') ?: '?') . "\n";
foreach (['error_log', 'log_errors', 'display_errors', 'upload_tmp_dir', 'open_basedir', 'upload_max_filesize',
             'post_max_size', 'memory_limit', 'file_uploads', 'max_input_time'] as $key) {
    printf("%-17s: %s\n", $key, var_export(ini_get($key), true));
}
echo "sys_get_temp_dir : " . sys_get_temp_dir() . "\n";
echo "upload_tmp_dir var / yazilabilir: " . var_export($tmp !== '' && is_dir($tmp), true) . " / "
    . var_export($tmp !== '' && is_writable($tmp), true) . "\n";
$log = (string) ini_get('error_log');
echo "error_log yazilabilir: " . var_export($log !== '' && @file_put_contents($log, '[' . date('c') . "] upload-test yazma denemesi\n", FILE_APPEND) !== false, true) . "\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "\n===== YUKLEME SONUCU =====\n";
    echo "CONTENT_LENGTH   : " . ($_SERVER['CONTENT_LENGTH'] ?? '-') . "\n";
    echo "Istek okunurken PHP uyarisi: " . ($startupError ? $startupError['message'] : '(yok)') . "\n";
    echo htmlspecialchars(print_r($_FILES, true));
    if (isset($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        echo "Gecici dosya okunabilir: " . var_export(is_readable($_FILES['image']['tmp_name']), true) . "\n";
        echo "getimagesize: " . json_encode(@getimagesize($_FILES['image']['tmp_name'])) . "\n";
    }
    echo '</pre>';
    exit;
}
echo '</pre>';
?>

<form method="post" enctype="multipart/form-data">
    <input type="file" name="image" accept="image/jpeg">
    <button type="submit">Yukle</button>
</form>