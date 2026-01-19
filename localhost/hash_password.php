<?php
// hash_passwords.php
require_once 'config.php';

$demo_passwords = [
    'admin' => 'admin123',
    'manager' => 'manager123',
    'ivanov' => 'storekeeper123',
    'petrov' => 'viewer123'
];

foreach ($demo_passwords as $username => $password) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    
    $stmt = db_query("UPDATE users SET password = ? WHERE username = ?", [
        $hash,
        $username
    ]);
    
    if ($stmt) {
        echo "Обновлен пароль для $username: $hash<br>";
    } else {
        echo "Ошибка обновления для $username<br>";
    }
}

echo "Все пароли обновлены!";
?>