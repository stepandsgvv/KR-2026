<?php
/**
 * Функции безопасности
 */

if (!function_exists('safe_sql')) {
    /**
     * Защита от SQL-инъекций
     * @param mixed $value Значение
     * @param string $type Тип данных
     * @return mixed Безопасное значение
     */
    function safe_sql($value, $type = 'string') {
        if ($value === null) {
            return 'NULL';
        }
        
        switch ($type) {
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'bool':
                return $value ? 1 : 0;
            case 'date':
                return $value ? "'" . date('Y-m-d', strtotime($value)) . "'" : 'NULL';
            case 'datetime':
                return $value ? "'" . date('Y-m-d H:i:s', strtotime($value)) . "'" : 'NULL';
            default:
                return "'" . addslashes($value) . "'";
        }
    }
}

if (!function_exists('xss_clean')) {
    /**
     * Защита от XSS
     * @param mixed $data Входные данные
     * @return mixed Очищенные данные
     */
    function xss_clean($data) {
        if (is_array($data)) {
            return array_map('xss_clean', $data);
        }
        
        if ($data === null) {
            return '';
        }
        
        $data = str_replace(['&amp;', '&lt;', '&gt;'], ['&amp;amp;', '&amp;lt;', '&amp;gt;'], $data);
        $data = preg_replace('/(&#*\w+)[\x00-\x20]+;/u', '$1;', $data);
        $data = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $data);
        $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $data;
    }
}

if (!function_exists('validate_input')) {
    /**
     * Валидация входных данных
     * @param array $data Данные
     * @param array $rules Правила валидации
     * @return array Ошибки валидации
     */
    function validate_input($data, $rules) {
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? '';
            $rules_array = explode('|', $rule);
            
            foreach ($rules_array as $single_rule) {
                if ($single_rule == 'required' && empty(trim($value ?? ''))) {
                    $errors[$field] = 'Поле обязательно для заполнения';
                    break;
                }
                
                if (strpos($single_rule, 'min:') === 0) {
                    $min = (int)str_replace('min:', '', $single_rule);
                    if (strlen(trim($value ?? '')) < $min) {
                        $errors[$field] = "Минимальная длина: $min символов";
                    }
                }
                
                if (strpos($single_rule, 'max:') === 0) {
                    $max = (int)str_replace('max:', '', $single_rule);
                    if (strlen(trim($value ?? '')) > $max) {
                        $errors[$field] = "Максимальная длина: $max символов";
                    }
                }
                
                if ($single_rule == 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = 'Неверный формат email';
                }
                
                if ($single_rule == 'numeric' && !empty($value) && !is_numeric($value)) {
                    $errors[$field] = 'Только цифры';
                }
            }
        }
        
        return $errors;
    }
}

if (!function_exists('hash_password')) {
    /**
     * Шифрование пароля
     * @param string $password Пароль
     * @return string Захешированный пароль
     */
    function hash_password($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('verify_password')) {
    /**
     * Проверка пароля
     * @param string $password Пароль
     * @param string $hash Хеш пароля
     * @return bool Совпадает ли пароль
     */
    function verify_password($password, $hash) {
        if (empty($password) || empty($hash)) {
            return false;
        }
        return password_verify($password, $hash);
    }
}

if (!function_exists('check_login_attempts')) {
    /**
     * Ограничение попыток входа
     * @param string $username Имя пользователя
     * @param int $max_attempts Максимальное количество попыток
     * @return bool Можно ли выполнить вход
     */
    function check_login_attempts($username, $max_attempts = 5) {
        try {
            $result = db_fetch_one("
                SELECT COUNT(*) as attempts 
                FROM login_attempts 
                WHERE username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ", [$username]);
            
            return ($result['attempts'] ?? 0) < $max_attempts;
        } catch (Exception $e) {
            // Если таблицы нет, разрешаем вход
            return true;
        }
    }
}

if (!function_exists('log_login_attempt')) {
    /**
     * Регистрация попытки входа
     * @param string $username Имя пользователя
     * @param bool $success Успешность входа
     */
    function log_login_attempt($username, $success) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            db_insert('login_attempts', [
                'username' => $username,
                'ip_address' => $ip,
                'success' => $success ? 1 : 0
            ]);
        } catch (Exception $e) {
            // Игнорируем ошибку логирования
        }
    }
}
?>