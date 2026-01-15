<?php
// Настройки сайта
define('SITE_NAME', 'СкладPRO');
define('SITE_URL', 'http://skladpro');
define('ADMIN_EMAIL', 'admin@skladpro.local');

// Настройки базы данных
define('DB_HOST', 'localhost:3307');
define('DB_NAME', 'skladpro');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Настройки сессии
define('SESSION_LIFETIME', 3600);
define('SESSION_NAME', 'SKLADPRO_SESSID');

// Пути
define('ROOT_PATH', __DIR__);
define('INCLUDE_PATH', ROOT_PATH . '/includes/');
define('MODULE_PATH', ROOT_PATH . '/modules/');
define('CSS_PATH', SITE_URL . '/css/');
define('JS_PATH', SITE_URL . '/js/');

// Режим разработки (true - разработка, false - продакшен)
define('DEBUG_MODE', true);

// Проверяем, это AJAX запрос?
define('IS_AJAX', isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

// Для AJAX запросов отключаем некоторые проверки
if (IS_AJAX) {
    // Отключаем вывод ошибок в ответ AJAX
    ini_set('display_errors', 0);
    
    // Устанавливаем заголовок JSON по умолчанию
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
}

// Обработка ошибок
if (DEBUG_MODE) {
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', ROOT_PATH . '/error.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 0);
}

// Настройки времени
date_default_timezone_set('Europe/Moscow');

// Запуск сессии
session_name(SESSION_NAME);
session_start();

// Включаем буферизацию вывода
ob_start();

// Включаем безопасные функции
require_once 'fix_warnings.php';

// Автозагрузка файлов конфигурации
require_once INCLUDE_PATH . 'functions.php';
require_once INCLUDE_PATH . 'db_connect.php';
require_once INCLUDE_PATH . 'security.php';
require_once INCLUDE_PATH . 'auth.php';

// Инициализация CSRF токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Инициализация сессий для модулей
if (!isset($_SESSION['receipt_session'])) {
    $_SESSION['receipt_session'] = [
        'items' => [],
        'supplier' => '',
        'document_number' => 'ПРИХ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'document_date' => date('Y-m-d')
    ];
}

if (!isset($_SESSION['shipment_session'])) {
    $_SESSION['shipment_session'] = [
        'items' => [],
        'customer' => '',
        'document_number' => 'ОТГР-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'document_date' => date('Y-m-d')
    ];
}

if (!isset($_SESSION['movement_session'])) {
    $_SESSION['movement_session'] = [
        'items' => [],
        'document_number' => 'ПЕРЕМ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'document_date' => date('Y-m-d'),
        'comments' => ''
    ];
}
?>