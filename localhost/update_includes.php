<?php
/**
 * Обновленный includes/functions.php с дополнительными функциями
 */

// Добавляем в конец файла functions.php

if (!function_exists('generate_barcode')) {
    /**
     * Генерация штрих-кода EAN-13
     * @return string Сгенерированный штрих-код
     */
    function generate_barcode() {
        $prefix = '590'; // Код Польши (можно изменить)
        $random = str_pad(rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        $code = $prefix . $random;
        
        // Расчет контрольной суммы для EAN-13
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int)$code[$i];
            $sum += ($i % 2 == 0) ? $digit : $digit * 3;
        }
        $checksum = (10 - ($sum % 10)) % 10;
        
        return $code . $checksum;
    }
}

if (!function_exists('calculate_inventory_value')) {
    /**
     * Расчет стоимости инвентаря
     * @return array Результаты расчета
     */
    function calculate_inventory_value() {
        $result = db_fetch_one("
            SELECT 
                COUNT(DISTINCT sb.product_id) as products_count,
                SUM(sb.quantity) as total_quantity,
                SUM(sb.quantity * COALESCE(b.purchase_price, 0)) as total_value,
                AVG(COALESCE(b.purchase_price, 0)) as avg_price
            FROM stock_balances sb
            LEFT JOIN batches b ON sb.batch_id = b.id
            WHERE sb.quantity > 0
        ");
        
        return $result ?: [
            'products_count' => 0,
            'total_quantity' => 0,
            'total_value' => 0,
            'avg_price' => 0
        ];
    }
}

if (!function_exists('get_daily_stats')) {
    /**
     * Получение статистики за день
     * @param string|null $date Дата (если null - текущий день)
     * @return array Статистика
     */
    function get_daily_stats($date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        return db_fetch_one("
            SELECT 
                COUNT(DISTINCT d.id) as documents_count,
                SUM(CASE WHEN ot.direction = 'in' THEN ABS(it.quantity) ELSE 0 END) as incoming,
                SUM(CASE WHEN ot.direction = 'out' THEN ABS(it.quantity) ELSE 0 END) as outgoing,
                COUNT(DISTINCT d.created_by) as users_count
            FROM documents d
            JOIN inventory_transactions it ON d.id = it.document_id
            JOIN operation_types ot ON d.operation_type_id = ot.id
            WHERE DATE(d.created_at) = ? AND d.status = 'completed'
        ", [$date]);
    }
}

if (!function_exists('get_expiring_products')) {
    /**
     * Получение товаров с истекающим сроком годности
     * @param int $days Количество дней для предупреждения
     * @return array Товары
     */
    function get_expiring_products($days = 30) {
        return db_fetch_all("
            SELECT p.article, p.name, p.unit,
                   b.batch_number, b.expiry_date,
                   sb.quantity, sl.code as location_code,
                   DATEDIFF(b.expiry_date, CURDATE()) as days_left
            FROM batches b
            JOIN products p ON b.product_id = p.id
            JOIN stock_balances sb ON b.id = sb.batch_id
            JOIN storage_locations sl ON sb.location_id = sl.id
            WHERE b.expiry_date IS NOT NULL 
            AND b.expiry_date >= CURDATE()
            AND DATEDIFF(b.expiry_date, CURDATE()) <= ?
            AND sb.quantity > 0
            ORDER BY b.expiry_date
            LIMIT 50
        ", [$days]);
    }
}

if (!function_exists('send_notification')) {
    /**
     * Отправка уведомления
     * @param int $user_id ID пользователя
     * @param string $title Заголовок
     * @param string $message Сообщение
     * @param string $type Тип (info, warning, danger, success)
     * @return bool Успешность отправки
     */
    function send_notification($user_id, $title, $message, $type = 'info') {
        return db_insert('notifications', [
            'user_id' => $user_id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}

if (!function_exists('check_low_stock')) {
    /**
     * Проверка товаров с низким остатком
     * @return array Товары с низким остатком
     */
    function check_low_stock() {
        return db_fetch_all("
            SELECT p.id, p.article, p.name, p.unit, p.min_stock,
                   COALESCE(SUM(sb.quantity), 0) as current_stock,
                   ROUND((COALESCE(SUM(sb.quantity), 0) / p.min_stock) * 100, 0) as percentage
            FROM products p
            LEFT JOIN stock_balances sb ON p.id = sb.product_id
            WHERE p.is_active = 1 AND p.is_deleted = 0 
            AND p.min_stock > 0
            GROUP BY p.id
            HAVING current_stock <= p.min_stock
            ORDER BY percentage
            LIMIT 20
        ");
    }
}

if (!function_exists('get_user_notifications')) {
    /**
     * Получение уведомлений пользователя
     * @param int $user_id ID пользователя
     * @param bool $unread_only Только непрочитанные
     * @param int $limit Лимит
     * @return array Уведомления
     */
    function get_user_notifications($user_id, $unread_only = false, $limit = 10) {
        $where = ['user_id = ?'];
        $params = [$user_id];
        
        if ($unread_only) {
            $where[] = 'is_read = 0';
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where);
        
        return db_fetch_all("
            SELECT * FROM notifications
            {$where_clause}
            ORDER BY created_at DESC
            LIMIT ?
        ", array_merge($params, [$limit]));
    }
}

if (!function_exists('mark_notification_read')) {
    /**
     * Пометить уведомление как прочитанное
     * @param int $notification_id ID уведомления
     * @param int $user_id ID пользователя
     * @return bool Успешность
     */
    function mark_notification_read($notification_id, $user_id) {
        return db_query("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND user_id = ?
        ", [$notification_id, $user_id]);
    }
}

if (!function_exists('mark_all_notifications_read')) {
    /**
     * Пометить все уведомления пользователя как прочитанные
     * @param int $user_id ID пользователя
     * @return bool Успешность
     */
    function mark_all_notifications_read($user_id) {
        return db_query("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE user_id = ? AND is_read = 0
        ", [$user_id]);
    }
}

// Дополняем header.php для отображения уведомлений
// Добавить после строки с выводом имени пользователя в navbar

<!-- Уведомления -->
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
        <i class="bi bi-bell"></i>
        <?php
        $unread_count = count(get_user_notifications($_SESSION['user_id'], true));
        if ($unread_count > 0): ?>
        <span class="badge bg-danger rounded-pill"><?php echo $unread_count; ?></span>
        <?php endif; ?>
    </a>
    <ul class="dropdown-menu dropdown-menu-end" style="min-width: 300px;">
        <li class="dropdown-header">Уведомления</li>
        <?php
        $notifications = get_user_notifications($_SESSION['user_id'], false, 5);
        if (empty($notifications)): ?>
        <li><a class="dropdown-item text-muted" href="#">Нет уведомлений</a></li>
        <?php else: ?>
        <?php foreach ($notifications as $notification): ?>
        <li>
            <a class="dropdown-item <?php echo $notification['is_read'] ? '' : 'fw-bold'; ?>" 
               href="#" onclick="markNotificationRead(<?php echo $notification['id']; ?>)">
                <div class="small text-<?php echo $notification['type']; ?>">
                    <i class="bi bi-<?php echo $notification['type'] == 'danger' ? 'exclamation-triangle' : 
                                       ($notification['type'] == 'warning' ? 'exclamation-circle' : 
                                       ($notification['type'] == 'success' ? 'check-circle' : 'info-circle')); ?>"></i>
                    <?php echo htmlspecialchars($notification['title']); ?>
                </div>
                <div class="small text-muted"><?php echo htmlspecialchars($notification['message']); ?></div>
                <div class="small text-muted"><?php echo format_date($notification['created_at'], 'H:i'); ?></div>
            </a>
        </li>
        <?php endforeach; ?>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-center" href="index.php?module=users&action=notifications">
            Все уведомления
        </a></li>
    </ul>
</li>
*/

// И добавить в footer.php перед закрывающим тегом </body>:
/*
<script>
// Отметить уведомление как прочитанное
function markNotificationRead(notificationId) {
    fetch('ajax.php?action=mark_notification_read&id=' + notificationId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        });
}
</script>
