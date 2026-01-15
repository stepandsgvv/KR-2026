<?php
// Проверка прав - только администратор
check_role(['admin']);

$page_title = 'Пользователи системы';

// Параметры фильтрации
$search = clean_input($_GET['search'] ?? '');
$role = clean_input($_GET['role'] ?? '');
$show_inactive = isset($_GET['show_inactive']) ? 1 : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Построение запроса
$where = ['is_deleted = 0'];
$params = [];

if (!$show_inactive) {
    $where[] = 'is_active = 1';
}

if ($role) {
    $where[] = 'role = ?';
    $params[] = $role;
}

if ($search) {
    $where[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
    $search_term = "%{$search}%";
    array_push($params, $search_term, $search_term, $search_term);
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Получение пользователей
$users = db_fetch_all("
    SELECT * FROM users 
    {$where_clause}
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
", array_merge($params, [$limit, $offset]));

// Подсчет общего количества
$total_result = db_fetch_one("SELECT COUNT(*) as total FROM users {$where_clause}", $params);
$total_items = $total_result['total'] ?? 0;
$total_pages = ceil($total_items / $limit);

// Статистика
$stats = db_fetch_one("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_users,
        COUNT(DISTINCT role) as roles_count
    FROM users 
    WHERE is_deleted = 0
");

// Роли для фильтра
$roles = ['admin', 'manager', 'storekeeper', 'viewer'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Пользователи системы</h1>
        <p class="text-muted mb-0">Управление учетными записями</p>
    </div>
    <div>
        <a href="index.php?module=users&action=add" class="btn btn-primary">
            <i class="bi bi-person-plus"></i> Добавить пользователя
        </a>
    </div>
</div>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Всего пользователей</h6>
                        <h3 class="mb-0"><?php echo $stats['total_users'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-people text-primary fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Активных</h6>
                        <h3 class="mb-0"><?php echo $stats['active_users'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-check-circle text-success fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Неактивных</h6>
                        <h3 class="mb-0"><?php echo $stats['inactive_users'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-x-circle text-warning fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Ролей</h6>
                        <h3 class="mb-0"><?php echo $stats['roles_count'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-shield-check text-info fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title mb-0">Фильтры</h6>
    </div>
    <div class="card-body">
        <form method="GET" action="">
            <input type="hidden" name="module" value="users">
            
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Поиск</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Логин, имя, email..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Роль</label>
                    <select name="role" class="form-select">
                        <option value="">Все роли</option>
                        <?php foreach ($roles as $role_option): ?>
                        <option value="<?php echo $role_option; ?>" 
                            <?php echo $role == $role_option ? 'selected' : ''; ?>>
                            <?php echo ucfirst($role_option); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Статус</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_inactive" 
                               id="show_inactive" value="1" <?php echo $show_inactive ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="show_inactive">
                            Показать неактивных
                        </label>
                    </div>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i> Применить
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Таблица пользователей -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="50">#</th>
                        <th>Логин</th>
                        <th>ФИО</th>
                        <th>Email</th>
                        <th>Роль</th>
                        <th>Телефон</th>
                        <th class="text-center">Последний вход</th>
                        <th class="text-center">Статус</th>
                        <th class="text-end">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <div class="text-muted">
                                <i class="bi bi-people display-6 d-block mb-2"></i>
                                Пользователи не найдены
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($users as $index => $user): 
                        $row_number = $offset + $index + 1;
                        
                        // Определение цвета роли
                        $role_colors = [
                            'admin' => 'danger',
                            'manager' => 'warning',
                            'storekeeper' => 'info',
                            'viewer' => 'secondary'
                        ];
                        $role_color = $role_colors[$user['role']] ?? 'secondary';
                        
                        // Статус активности
                        $active_status = $user['is_active'] ? 'success' : 'secondary';
                        $active_text = $user['is_active'] ? 'Активен' : 'Неактивен';
                    ?>
                    <tr>
                        <td class="text-muted"><?php echo $row_number; ?></td>
                        <td>
                            <div class="fw-bold"><?php echo htmlspecialchars($user['username']); ?></div>
                            <small class="text-muted">Создан: <?php echo format_date($user['created_at'], 'd.m.Y'); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td>
                            <?php if ($user['email']): ?>
                            <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>" 
                               class="text-decoration-none">
                                <?php echo htmlspecialchars($user['email']); ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $role_color; ?>">
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($user['phone'] ?? '—'); ?></td>
                        <td class="text-center">
                            <?php if ($user['last_login']): ?>
                            <?php echo format_date($user['last_login'], 'd.m.Y H:i'); ?>
                            <?php else: ?>
                            <span class="text-muted">Никогда</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $active_status; ?>">
                                <?php echo $active_text; ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?module=users&action=view&id=<?php echo $user['id']; ?>" 
                                   class="btn btn-outline-info" title="Просмотр">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="index.php?module=users&action=edit&id=<?php echo $user['id']; ?>" 
                                   class="btn btn-outline-primary" title="Редактировать">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <button type="button" class="btn btn-outline-warning" 
                                        onclick="resetUserPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')"
                                        title="Сбросить пароль">
                                    <i class="bi bi-key"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger" 
                                        onclick="toggleUserStatus(<?php echo $user['id']; ?>, <?php echo $user['is_active'] ? '0' : '1'; ?>)"
                                        title="<?php echo $user['is_active'] ? 'Деактивировать' : 'Активировать'; ?>">
                                    <i class="bi bi-power"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Пагинация -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer">
        <div class="row align-items-center">
            <div class="col-md-6">
                <small class="text-muted">
                    Показано <?php echo count($users); ?> из <?php echo $total_items; ?> пользователей
                </small>
            </div>
            <div class="col-md-6">
                <nav aria-label="Навигация">
                    <ul class="pagination justify-content-end mb-0">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo build_pagination_url($page - 1); ?>">
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
                            <a class="page-link" href="<?php echo build_pagination_url($i); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo build_pagination_url($page + 1); ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Статистика по ролям -->
<div class="card mt-4">
    <div class="card-header">
        <h6 class="card-title mb-0">Статистика по ролям</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <?php
            $role_stats = db_fetch_all("
                SELECT role, COUNT(*) as count,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count
                FROM users 
                WHERE is_deleted = 0
                GROUP BY role
                ORDER BY 
                    CASE role 
                        WHEN 'admin' THEN 1
                        WHEN 'manager' THEN 2
                        WHEN 'storekeeper' THEN 3
                        WHEN 'viewer' THEN 4
                        ELSE 5
                    END
            ");
            
            foreach ($role_stats as $stat):
                $percentage = $stat['count'] > 0 ? round(($stat['count'] / $total_items) * 100, 1) : 0;
            ?>
            <div class="col-md-3">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0"><?php echo ucfirst($stat['role']); ?></h6>
                                <div class="text-muted small">Всего: <?php echo $stat['count']; ?></div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold"><?php echo $stat['active_count']; ?></div>
                                <div class="small text-success">Активных</div>
                            </div>
                        </div>
                        <div class="progress mt-2" style="height: 10px;">
                            <div class="progress-bar bg-<?php echo $role_colors[$stat['role']] ?? 'secondary'; ?>" 
                                 style="width: <?php echo $percentage; ?>%">
                            </div>
                        </div>
                        <div class="small text-muted text-end mt-1"><?php echo $percentage; ?>% от всех</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
// Функция для построения URL пагинации
function build_pagination_url(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    return url.toString();
}

// Сброс пароля пользователя
function resetUserPassword(userId, username) {
    if (confirm(`Сбросить пароль пользователю ${username}? Новый пароль будет отправлен на email.`)) {
        const formData = new FormData();
        formData.append('action', 'reset_password');
        formData.append('user_id', userId);
        formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
        
        fetch('index.php?module=users&action=reset_password', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Пароль успешно сброшен. Новый пароль отправлен на email пользователя.');
            } else {
                alert(data.message || 'Ошибка при сбросе пароля');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка сети');
        });
    }
}

// Переключение статуса пользователя
function toggleUserStatus(userId, newStatus) {
    if (confirm(newStatus ? 'Активировать пользователя?' : 'Деактивировать пользователя?')) {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('user_id', userId);
        formData.append('is_active', newStatus);
        formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
        
        fetch('index.php?module=users&action=toggle_status', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Ошибка при изменении статуса');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка сети');
        });
    }
}

// Автоматическое скрытие алертов
setTimeout(() => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

<?php
// Вспомогательная функция для построения URL с параметрами
function build_pagination_url($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'index.php?' . http_build_query($params);
}
?>