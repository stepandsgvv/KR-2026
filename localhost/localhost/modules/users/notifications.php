<?php
// Проверка авторизации
check_auth();

$page_title = 'Уведомления';

// Получение уведомлений текущего пользователя
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$notifications = db_fetch_all("
    SELECT * FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
", [$_SESSION['user_id'], $limit, $offset]);

// Подсчет общего количества
$total_result = db_fetch_one("
    SELECT COUNT(*) as total FROM notifications WHERE user_id = ?
", [$_SESSION['user_id']]);
$total_items = $total_result['total'] ?? 0;
$total_pages = ceil($total_items / $limit);

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_csrf();
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'mark_all_read':
            mark_all_notifications_read($_SESSION['user_id']);
            $_SESSION['success'] = 'Все уведомления отмечены как прочитанные';
            break;
            
        case 'delete_all':
            db_query("DELETE FROM notifications WHERE user_id = ?", [$_SESSION['user_id']]);
            $_SESSION['success'] = 'Все уведомления удалены';
            break;
            
        case 'delete_read':
            db_query("DELETE FROM notifications WHERE user_id = ? AND is_read = 1", [$_SESSION['user_id']]);
            $_SESSION['success'] = 'Прочитанные уведомления удалены';
            break;
    }
    
    header('Location: index.php?module=users&action=notifications');
    exit();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Мои уведомления</h1>
        <p class="text-muted mb-0">История системных уведомлений</p>
    </div>
    <div>
        <div class="btn-group">
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="mark_all_read">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-check-all"></i> Прочитать все
                </button>
            </form>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                <i class="bi bi-trash"></i> Очистить
            </button>
        </div>
    </div>
</div>

<!-- Статистика -->
<?php
$unread_count = count(get_user_notifications($_SESSION['user_id'], true));
$today_count = db_fetch_one("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND DATE(created_at) = CURDATE()
", [$_SESSION['user_id']])['count'] ?? 0;
?>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Всего</h6>
                        <h3 class="mb-0"><?php echo $total_items; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-bell text-primary fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Непрочитанных</h6>
                        <h3 class="mb-0"><?php echo $unread_count; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-bell-fill text-warning fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Сегодня</h6>
                        <h3 class="mb-0"><?php echo $today_count; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-calendar-day text-info fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Список уведомлений -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
        <div class="text-center py-5">
            <div class="text-muted">
                <i class="bi bi-bell-slash display-3 d-block mb-3"></i>
                <h4>Нет уведомлений</h4>
                <p class="mb-0">Здесь будут отображаться системные уведомления</p>
            </div>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($notifications as $notification): 
                $type_icons = [
                    'info' => 'info-circle',
                    'success' => 'check-circle',
                    'warning' => 'exclamation-circle',
                    'danger' => 'exclamation-triangle'
                ];
                $type_colors = [
                    'info' => 'text-info',
                    'success' => 'text-success',
                    'warning' => 'text-warning',
                    'danger' => 'text-danger'
                ];
                $icon = $type_icons[$notification['type']] ?? 'bell';
                $color = $type_colors[$notification['type']] ?? 'text-secondary';
            ?>
            <div class="list-group-item <?php echo $notification['is_read'] ? '' : 'bg-light'; ?>">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <i class="bi bi-<?php echo $icon; ?> fs-4 <?php echo $color; ?>"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="d-flex justify-content-between">
                            <h6 class="mb-1 <?php echo $notification['is_read'] ? '' : 'fw-bold'; ?>">
                                <?php echo htmlspecialchars($notification['title']); ?>
                            </h6>
                            <small class="text-muted">
                                <?php echo format_date($notification['created_at']); ?>
                            </small>
                        </div>
                        <p class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-muted">
                                <?php if ($notification['is_read']): ?>
                                <i class="bi bi-check-circle text-success"></i> Прочитано 
                                <?php echo $notification['read_at'] ? format_date($notification['read_at'], 'H:i') : ''; ?>
                                <?php else: ?>
                                <span class="badge bg-warning">Новое</span>
                                <?php endif; ?>
                            </small>
                            <div class="btn-group btn-group-sm">
                                <?php if (!$notification['is_read']): ?>
                                <button type="button" class="btn btn-outline-success btn-sm"
                                        onclick="markNotificationRead(<?php echo $notification['id']; ?>)">
                                    <i class="bi bi-check"></i> Прочитать
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        onclick="deleteNotification(<?php echo $notification['id']; ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Пагинация -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer">
        <nav aria-label="Навигация">
            <ul class="pagination justify-content-center mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?module=users&action=notifications&page=<?php echo $page - 1; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?module=users&action=notifications&page=<?php echo $i; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?module=users&action=notifications&page=<?php echo $page + 1; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Модальное окно очистки -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Очистка уведомлений</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Выберите действие:</p>
                <div class="list-group">
                    <form method="POST" class="list-group-item list-group-item-action p-3">
                        <input type="hidden" name="action" value="delete_read">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <button type="submit" class="btn btn-link text-start p-0 w-100">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Удалить прочитанные</h6>
                                <small class="text-muted"><?php echo $total_items - $unread_count; ?> шт.</small>
                            </div>
                            <p class="mb-1 small text-muted">Удалить все прочитанные уведомления</p>
                        </button>
                    </form>
                    
                    <form method="POST" class="list-group-item list-group-item-action p-3">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <button type="submit" class="btn btn-link text-start p-0 w-100 text-danger">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Удалить все</h6>
                                <small><?php echo $total_items; ?> шт.</small>
                            </div>
                            <p class="mb-1 small">Удалить все уведомления (включая непрочитанные)</p>
                        </button>
                    </form>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
            </div>
        </div>
    </div>
</div>

<script>
// Отметить уведомление как прочитанное
function markNotificationRead(notificationId) {
    fetch('ajax.php?action=mark_notification_read&id=' + notificationId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Ошибка при обновлении уведомления');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка сети');
        });
}

// Удалить уведомление
function deleteNotification(notificationId) {
    if (confirm('Удалить это уведомление?')) {
        fetch('ajax.php?action=delete_notification&id=' + notificationId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Ошибка при удалении уведомления');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ошибка сети');
            });
    }
}
</script>