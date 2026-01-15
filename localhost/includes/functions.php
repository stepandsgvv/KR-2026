<?php
/**
 * Вспомогательные функции системы
 */

// Проверка существования функции перед объявлением
if (!function_exists('clean_input')) {
    /**
     * Очистка входных данных
     * @param mixed $data Входные данные
     * @return mixed Очищенные данные
     */
    function clean_input($data) {
        if (is_array($data)) {
            return array_map('clean_input', $data);
        }
        
        // Проверяем, что $data не null и не пусто
        if ($data === null || $data === '') {
            return '';
        }
        
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        
        return $data;
    }
}

if (!function_exists('check_auth')) {
    /**
     * Проверка авторизации пользователя
     */
    function check_auth() {
        if (!isset($_SESSION['user_id'])) {
            header('Location: login.php');
            exit();
        }
    }
}

if (!function_exists('check_role')) {
    /**
     * Проверка роли пользователя
     * @param array|string $roles Разрешенные роли
     */
    function check_role($roles) {
        if (!isset($_SESSION['user_role'])) {
            header('Location: login.php');
            exit();
        }
        
        $roles = (array)$roles;
        if (!in_array($_SESSION['user_role'], $roles)) {
            $_SESSION['error'] = 'У вас недостаточно прав для выполнения этого действия';
            header('Location: index.php?module=dashboard');
            exit();
        }
    }
}

if (!function_exists('format_number')) {
    /**
     * Форматирование числа
     * @param float|null $number Число
     * @param int $decimals Количество знаков после запятой
     * @return string Отформатированное число
     */
    function format_number($number, $decimals = 3) {
        if ($number === null || $number === '') {
            return '0';
        }
        
        $number = (float)$number;
        if ($number == 0) {
            return '0';
        }
        return number_format($number, $decimals, ',', ' ');
    }
}

if (!function_exists('format_date')) {
    /**
     * Форматирование даты
     * @param string|null $date Дата в формате MySQL
     * @param string $format Формат вывода
     * @return string Отформатированная дата
     */
    function format_date($date, $format = 'd.m.Y H:i') {
        if (empty($date) || $date == '0000-00-00 00:00:00' || $date == '0000-00-00') {
            return '';
        }
        return date($format, strtotime($date));
    }
}

if (!function_exists('generate_csrf_token')) {
    /**
     * Генерация CSRF токена
     * @return string CSRF токен
     */
    function generate_csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('check_csrf')) {
    /**
     * Проверка CSRF токена
     * @param string|null $token Токен для проверки
     * @return bool Результат проверки
     */
    function check_csrf($token = null) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }
        
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? '';
        }
        
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            $_SESSION['error'] = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
            $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
            header('Location: ' . $referer);
            exit();
        }
        
        return true;
    }
}

if (!function_exists('get_current_user')) {
    /**
     * Получение информации о текущем пользователе
     * @return array|null Данные пользователя
     */
    function get_current_user() {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        return db_fetch_one("
            SELECT id, username, full_name, email, role, phone, is_active
            FROM users 
            WHERE id = ? AND is_active = 1
        ", [$_SESSION['user_id']]);
    }
}

if (!function_exists('has_role')) {
    /**
     * Проверка наличия роли у пользователя
     * @param string $role Проверяемая роль
     * @return bool
     */
    function has_role($role) {
        if (!isset($_SESSION['user_role'])) {
            return false;
        }
        return $_SESSION['user_role'] === $role;
    }
}

if (!function_exists('has_any_role')) {
    /**
     * Проверка наличия хотя бы одной из ролей
     * @param array $roles Массив ролей
     * @return bool
     */
    function has_any_role($roles) {
        if (!isset($_SESSION['user_role'])) {
            return false;
        }
        return in_array($_SESSION['user_role'], $roles);
    }
}

if (!function_exists('log_action')) {
    /**
     * Логирование действий
     * @param string $action Действие
     * @param string $details Детали
     * @param int|null $user_id ID пользователя
     * @return bool Успешность логирования
     */
    function log_action($action, $details = '', $user_id = null) {
        if ($user_id === null) {
            $user_id = $_SESSION['user_id'] ?? null;
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        try {
            return db_insert('audit_log', [
                'user_id' => $user_id,
                'action' => $action,
                'details' => $details,
                'ip_address' => $ip,
                'user_agent' => substr($user_agent, 0, 255)
            ]);
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            error_log('Ошибка при логировании: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('redirect_with_message')) {
    /**
     * Редирект с сообщением
     * @param string $url URL для редиректа
     * @param string $type Тип сообщения (success, error, warning, info)
     * @param string $message Сообщение
     */
    function redirect_with_message($url, $type = 'success', $message = '') {
        $_SESSION[$type] = $message;
        header('Location: ' . $url);
        exit();
    }
}

if (!function_exists('get_setting')) {
    /**
     * Получение настроек из конфигурации
     * @param string $key Ключ настройки
     * @param mixed $default Значение по умолчанию
     * @return mixed Значение настройки
     */
    function get_setting($key, $default = null) {
        static $settings = null;
        
        if ($settings === null) {
            $settings = [];
            try {
                $result = db_fetch_all("SELECT `key`, `value` FROM settings");
                foreach ($result as $row) {
                    $settings[$row['key']] = $row['value'];
                }
            } catch (Exception $e) {
                // Таблица settings может не существовать
            }
        }
        
        return $settings[$key] ?? $default;
    }
}

if (!function_exists('validate_email')) {
    /**
     * Валидация email
     * @param string|null $email Email адрес
     * @return bool Валидный ли email
     */
    function validate_email($email) {
        if (empty($email)) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validate_phone')) {
    /**
     * Валидация телефона
     * @param string|null $phone Номер телефона
     * @return bool Валидный ли телефон
     */
    function validate_phone($phone) {
        if (empty($phone)) {
            return false;
        }
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return preg_match('/^\+?[1-9]\d{10,14}$/', $phone);
    }
}

if (!function_exists('paginate')) {
    /**
     * Создание пагинации
     * @param int $total_items Всего элементов
     * @param int $items_per_page Элементов на странице
     * @param int $current_page Текущая страница
     * @param string $base_url Базовый URL
     * @return string HTML пагинации
     */
    function paginate($total_items, $items_per_page = 20, $current_page = 1, $base_url = '') {
        $total_pages = ceil($total_items / $items_per_page);
        
        if ($total_pages <= 1) {
            return '';
        }
        
        $html = '<nav aria-label="Навигация"><ul class="pagination justify-content-center">';
        
        // Кнопка "Назад"
        if ($current_page > 1) {
            $html .= '<li class="page-item">';
            $html .= '<a class="page-link" href="' . $base_url . '&page=' . ($current_page - 1) . '">';
            $html .= '<i class="bi bi-chevron-left"></i>';
            $html .= '</a></li>';
        }
        
        // Страницы
        $start = max(1, $current_page - 2);
        $end = min($total_pages, $current_page + 2);
        
        for ($i = $start; $i <= $end; $i++) {
            $active = $i == $current_page ? ' active' : '';
            $html .= '<li class="page-item' . $active . '">';
            $html .= '<a class="page-link" href="' . $base_url . '&page=' . $i . '">' . $i . '</a>';
            $html .= '</li>';
        }
        
        // Кнопка "Вперед"
        if ($current_page < $total_pages) {
            $html .= '<li class="page-item">';
            $html .= '<a class="page-link" href="' . $base_url . '&page=' . ($current_page + 1) . '">';
            $html .= '<i class="bi bi-chevron-right"></i>';
            $html .= '</a></li>';
        }
        
        $html .= '</ul></nav>';
        
        return $html;
    }
}

if (!function_exists('get_file_extension')) {
    /**
     * Получение расширения файла
     * @param string|null $filename Имя файла
     * @return string Расширение
     */
    function get_file_extension($filename) {
        if (empty($filename)) {
            return '';
        }
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}

if (!function_exists('validate_uploaded_file')) {
    /**
     * Проверка загружаемого файла
     * @param array|null $file Массив $_FILES
     * @param array $allowed_types Разрешенные типы
     * @param int $max_size Максимальный размер (в МБ)
     * @return array Результат проверки
     */
    function validate_uploaded_file($file, $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf'], $max_size = 5) {
        $result = ['success' => false, 'message' => ''];
        
        if (!isset($file['error'])) {
            $result['message'] = 'Файл не был загружен';
            return $result;
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Файл слишком большой',
                UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой',
                UPLOAD_ERR_PARTIAL => 'Файл загружен частично',
                UPLOAD_ERR_NO_FILE => 'Файл не выбран',
                UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
                UPLOAD_ERR_CANT_WRITE => 'Ошибка записи на диск',
                UPLOAD_ERR_EXTENSION => 'Расширение PHP остановило загрузку'
            ];
            $result['message'] = $errors[$file['error']] ?? 'Неизвестная ошибка загрузки';
            return $result;
        }
        
        $extension = get_file_extension($file['name']);
        if (!in_array($extension, $allowed_types)) {
            $result['message'] = 'Недопустимый тип файла. Разрешены: ' . implode(', ', $allowed_types);
            return $result;
        }
        
        $max_size_bytes = $max_size * 1024 * 1024;
        if ($file['size'] > $max_size_bytes) {
            $result['message'] = "Файл слишком большой. Максимальный размер: {$max_size} МБ";
            return $result;
        }
        
        $result['success'] = true;
        $result['extension'] = $extension;
        return $result;
    }
}

if (!function_exists('generate_password')) {
    /**
     * Генерация случайного пароля
     * @param int $length Длина пароля
     * @return string Сгенерированный пароль
     */
    function generate_password($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        return substr(str_shuffle($chars), 0, $length);
    }
}

if (!function_exists('days_between')) {
    /**
     * Получение количества дней между датами
     * @param string|null $date1 Первая дата
     * @param string|null $date2 Вторая дата
     * @return int Количество дней
     */
    function days_between($date1, $date2) {
        if (empty($date1) || empty($date2)) {
            return 0;
        }
        
        try {
            $datetime1 = new DateTime($date1);
            $datetime2 = new DateTime($date2);
            $interval = $datetime1->diff($datetime2);
            return $interval->days;
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('check_expiry')) {
    /**
     * Проверка срока годности
     * @param string|null $expiry_date Дата окончания срока годности
     * @param int $warning_days Количество дней для предупреждения
     * @return array Статус и оставшееся количество дней
     */
    function check_expiry($expiry_date, $warning_days = 30) {
        if (empty($expiry_date) || $expiry_date == '0000-00-00') {
            return ['status' => 'unknown', 'days' => null];
        }
        
        try {
            $today = new DateTime();
            $expiry = new DateTime($expiry_date);
            
            if ($expiry < $today) {
                return ['status' => 'expired', 'days' => 0];
            }
            
            $days_left = $today->diff($expiry)->days;
            
            if ($days_left <= $warning_days) {
                return ['status' => 'warning', 'days' => $days_left];
            }
            
            return ['status' => 'ok', 'days' => $days_left];
        } catch (Exception $e) {
            return ['status' => 'unknown', 'days' => null];
        }
    }
}

if (!function_exists('check_session_timeout')) {
    /**
     * Проверка времени сессии
     * @param int $timeout Таймаут в секундах (по умолчанию 2 часа)
     */
    function check_session_timeout($timeout = 7200) {
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $timeout)) {
            session_destroy();
            header('Location: login.php?timeout=1');
            exit();
        }
        
        // Обновляем время активности
        $_SESSION['last_activity'] = time();
    }
}

if (!function_exists('is_logged_in')) {
    /**
     * Проверка, авторизован ли пользователь
     * @return bool
     */
    function is_logged_in() {
        return isset($_SESSION['user_id']);
    }
}
?>