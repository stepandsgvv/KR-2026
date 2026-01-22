<?php
require_once 'config.php';

// Завершаем сессию
logout();

// Редирект на страницу входа
header('Location: login.php');
exit();
?>