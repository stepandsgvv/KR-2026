<?php
require_once 'config.php';

// Проверка авторизации
if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

// Проверяем, не истекла ли сессия
check_session_timeout();

// Получение параметров маршрута
$module = clean_input($_GET['module'] ?? 'dashboard');
$action = clean_input($_GET['action'] ?? 'index');
$id = clean_input($_GET['id'] ?? '');

// Устанавливаем глобальные переменные для шапки
global $page_title;
$page_title = ucfirst($module);

// Безопасность - список разрешенных модулей
$allowed_modules = [
    'dashboard', 'products', 'storage', 'transactions', 
    'reports', 'users', 'categories', 'locations'
];

if (!in_array($module, $allowed_modules)) {
    $_SESSION['error'] = 'Доступ к модулю запрещен';
    header('Location: index.php?module=dashboard');
    exit();
}

// Проверка прав доступа к модулю
$module_permissions = [
    'dashboard' => ['admin', 'manager', 'storekeeper', 'viewer'],
    'products' => ['admin', 'manager', 'storekeeper', 'viewer'],
    'storage' => ['admin', 'manager', 'storekeeper', 'viewer'],
    'transactions' => ['admin', 'manager', 'storekeeper'],
    'reports' => ['admin', 'manager', 'viewer'],
    'users' => ['admin'],
    'categories' => ['admin', 'manager'],
    'locations' => ['admin', 'manager', 'storekeeper']
];

$user_role = $_SESSION['user_role'] ?? '';
if (!isset($module_permissions[$module]) || 
    !in_array($user_role, $module_permissions[$module])) {
    $_SESSION['error'] = 'Недостаточно прав для доступа к этому модулю';
    header('Location: index.php?module=dashboard');
    exit();
}

// Подключаем шапку
require_once 'includes/header.php';

// Поиск файла модуля
$module_dir = __DIR__ . '/modules/' . $module;
$action_file = $module_dir . '/' . $action . '.php';
$default_file = $module_dir . '/index.php';

// Маршрутизация
if (file_exists($action_file)) {
    require_once $action_file;
} elseif (file_exists($default_file)) {
    require_once $default_file;
} else {
    echo '<div class="container mt-4">';
    echo '<div class="alert alert-danger">';
    echo '<h4><i class="bi bi-exclamation-triangle"></i> Страница не найдена</h4>';
    echo '<p>Запрошенная страница не существует или находится в разработке.</p>';
    echo '<a href="index.php?module=dashboard" class="btn btn-primary">Вернуться в дашборд</a>';
    echo '</div>';
    echo '</div>';
}

// Подключаем подвал
require_once 'includes/footer.php';
?>