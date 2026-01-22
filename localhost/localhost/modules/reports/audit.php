<?php
// Проверка прав
check_role(['admin', 'manager']);

$page_title = 'Журнал действий';

// Параметры фильтрации
$user_id = (int)($_GET['user_id'] ?? 0);
$action = clean_input($_GET['action'] ?? '');
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$ip_address = clean_input($_GET['ip_address'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Построение запроса
$where = ["DATE(al.created_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($user_id > 0) {
    $where[] = "al.user_id = ?";
    $params[] = $user_id;
}

if ($action) {
    $where[] = "al.action = ?";
    $params[] = $action;
}

if ($ip_address) {
    $where[] = "al.ip_address LIKE ?";
    $params[] = "%{$ip_address}%";
}

$where_clause = "WHERE " . implode(" AND ", $where);

// Получение записей журнала
$logs = db_fetch_all("
    SELECT al.*, u.username, u.full_name, u.role
    FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    {$where_clause}
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
", array_merge($params, [$limit, $offset]));

// Подсчет общего количества
$total_result = db_fetch_one("SELECT COUNT(*) as total FROM audit_log al {$where_clause}", $params);
$total_items = $total_result['total'] ?? 0;
$total_pages = ceil($total_items / $limit);

// Статистика
$stats = db_fetch_one("
    SELECT 
        COUNT(*) as total_logs,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT action) as unique_actions,
        COUNT(DISTINCT ip_address) as unique_ips
    FROM audit_log al
    {$where_clause}
", $params);

// Получение данных для фильтров
$users = db_fetch_all("
    SELECT DISTINCT u.id, u.username, u.full_name 
    FROM audit_log al
    JOIN users u ON al.user_id = u.id
    WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY u.username
    LIMIT 100
");

$actions = db_fetch_all("
    SELECT DISTINCT action, COUNT(*) as count
    FROM audit_log 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY action
    ORDER BY count DESC
    LIMIT 20
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Журнал действий</h1>
        <p class="text-muted mb-0">История всех операций в системе</p>
    </div>
    <div>
        <div class="btn-group">
            <button type="button" class="btn btn-primary" onclick="exportAuditLog()">
                <i class="bi bi-download"></i> Экспорт
            </button>
            <button type="button" class="btn btn-danger" onclick="clearOldLogs()">
                <i class="bi bi-trash"></i> Очистить старые
            </button>
        </div>
    </div>
</div>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Всего записей</h6>
                        <h3 class="mb-0"><?php echo $stats['total_logs'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-journal-text text-primary fs-3"></i>
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
                        <h6 class="text-muted">Пользователей</h6>
                        <h3 class="mb-0"><?php echo $stats['unique_users'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-people text-success fs-3"></i>
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
                        <h6 class="text-muted">Действий</h6>
                        <h3 class="mb-0"><?php echo $stats['unique_actions'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-activity text-warning fs-3"></i>
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
                        <h6 class="text-muted">IP-адресов</h6>
                        <h3 class="mb-0"><?php echo $stats['unique_ips'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-globe text-info fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title mb-0">Фильтры журнала</h6>
    </div>
    <div class="card-body">
        <form method="GET" id="auditFilter">
            <input type="hidden" name="module" value="reports">
            <input type="hidden" name="action" value="audit">
            
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Период с</label>
                    <input type="date" class="form-control" name="date_from" 
                           value="<?php echo $date_from; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Период по</label>
                    <input type="date" class="form-control" name="date_to" 
                           value="<?php echo $date_to; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Пользователь</label>
                    <select name="user_id" class="form-select">
                        <option value="0">Все пользователи</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" 
                            <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['username'] . ' - ' . $user['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Действие</label>
                    <select name="action" class="form-select">
                        <option value="">Все действия</option>
                        <?php foreach ($actions as $act): ?>
                        <option value="<?php echo $act['action']; ?>" 
                            <?php echo $action == $act['action'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($act['action']); ?> (<?php echo $act['count']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">IP-адрес</label>
                    <input type="text" class="form-control" name="ip_address" 
                           placeholder="192.168..." value="<?php echo htmlspecialchars($ip_address); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i> Применить
                    </button>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" onclick="resetFilters()" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Таблица журнала -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="50">#</th>
                        <th>Время</th>
                        <th>Пользователь</th>
                        <th>Действие</th>
                        <th>Детали</th>
                        <th>IP-адрес</th>
                        <th class="text-center">Браузер</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <div class="text-muted">
                                <i class="bi bi-journal display-6 d-block mb-2"></i>
                                Записи не найдены
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $index => $log): 
                        $row_number = $offset + $index + 1;
                        
                        // Определение типа действия
                        $action_types = [
                            'LOGIN' => ['color' => 'success', 'icon' => 'box-arrow-in-right'],
                            'LOGOUT' => ['color' => 'secondary', 'icon' => 'box-arrow-right'],
                            'USER_' => ['color' => 'primary', 'icon' => 'person'],
                            'PRODUCT_' => ['color' => 'info', 'icon' => 'box'],
                            'RECEIPT' => ['color' => 'success', 'icon' => 'plus-circle'],
                            'SHIPMENT' => ['color' => 'danger', 'icon' => 'dash-circle'],
                            'MOVEMENT' => ['color' => 'warning', 'icon' => 'arrow-left-right'],
                            'INVENTORY' => ['color' => 'info', 'icon' => 'clipboard-check'],
                            'DELETE' => ['color' => 'danger', 'icon' => 'trash'],
                            'UPDATE' => ['color' => 'warning', 'icon' => 'pencil'],
                            'CREATE' => ['color' => 'success', 'icon' => 'plus']
                        ];
                        
                        $action_color = 'secondary';
                        $action_icon = 'activity';
                        
                        foreach ($action_types as $prefix => $type) {
                            if (strpos($log['action'], $prefix) === 0) {
                                $action_color = $type['color'];
                                $action_icon = $type['icon'];
                                break;
                            }
                        }
                        
                        // Определение пользователя
                        $user_display = $log['username'] ? 
                            htmlspecialchars($log['username']) . ' (' . htmlspecialchars($log['full_name']) . ')' : 
                            'Система';
                    ?>
                    <tr>
                        <td class="text-muted"><?php echo $row_number; ?></td>
                        <td>
                            <div class="fw-bold"><?php echo format_date($log['created_at'], 'H:i:s'); ?></div>
                            <div class="small text-muted"><?php echo format_date($log['created_at'], 'd.m.Y'); ?></div>
                        </td>
                        <td>
                            <div><?php echo $user_display; ?></div>
                            <?php if ($log['role']): ?>
                            <div class="small">
                                <span class="badge bg-secondary"><?php echo ucfirst($log['role']); ?></span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $action_color; ?>">
                                <i class="bi bi-<?php echo $action_icon; ?> me-1"></i>
                                <?php echo htmlspecialchars($log['action']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="text-truncate" style="max-width: 300px;" 
                                 title="<?php echo htmlspecialchars($log['details']); ?>">
                                <?php echo htmlspecialchars($log['details']); ?>
                            </div>
                            <?php if (strlen($log['details']) > 100): ?>
                            <button type="button" class="btn btn-sm btn-link p-0" 
                                    onclick="showDetails(<?php echo $log['id']; ?>)">
                                Показать полностью
                            </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code><?php echo htmlspecialchars($log['ip_address']); ?></code>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-info" 
                                    onclick="showUserAgent(<?php echo $log['id']; ?>)"
                                    title="Показать информацию о браузере">
                                <i class="bi bi-browser-chrome"></i>
                            </button>
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
                    Показано <?php echo count($logs); ?> из <?php echo $total_items; ?> записей
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

<!-- Модальное окно деталей -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Детали записи</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="detailsContent" class="bg-light p-3 rounded"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно User-Agent -->
<div class="modal fade" id="userAgentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Информация о браузере</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <code id="userAgentContent"></code>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<script>
// Сброс фильтров
function resetFilters() {
    window.location.href = 'index.php?module=reports&action=audit';
}

// Показать детали записи
function showDetails(logId) {
    fetch(`ajax.php?action=get_audit_details&id=${logId}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('detailsContent').textContent = data.details;
            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            modal.show();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при загрузке деталей');
        });
}

// Показать информацию о браузере
function showUserAgent(logId) {
    fetch(`ajax.php?action=get_user_agent&id=${logId}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('userAgentContent').textContent = data.user_agent;
            const modal = new bootstrap.Modal(document.getElementById('userAgentModal'));
            modal.show();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при загрузке информации');
        });
}

// Экспорт журнала
function exportAuditLog() {
    const params = new URLSearchParams(window.location.search);
    params.set('format', 'excel');
    window.open(`index.php?module=reports&action=audit_export&${params.toString()}`, '_blank');
}

// Очистка старых записей
function clearOldLogs() {
    if (confirm('Удалить записи старше 90 дней? Это действие нельзя отменить.')) {
        const formData = new FormData();
        formData.append('action', 'clear_old_logs');
        formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
        
        fetch('index.php?module=reports&action=clear_audit_logs', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Удалено ${data.deleted} записей`);
                location.reload();
            } else {
                alert(data.message || 'Ошибка при очистке журнала');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка сети');
        });
    }
}

// Автоматическое обновление каждые 30 секунд (для мониторинга)
let autoRefreshInterval;
const refreshCheckbox = document.getElementById('autoRefresh');

if (refreshCheckbox) {
    refreshCheckbox.addEventListener('change', function() {
        if (this.checked) {
            autoRefreshInterval = setInterval(() => {
                location.reload();
            }, 30000);
        } else {
            clearInterval(autoRefreshInterval);
        }
    });
}

// Добавляем checkbox автообновления в DOM если его нет
if (!document.getElementById('autoRefreshContainer')) {
    const container = document.createElement('div');
    container.id = 'autoRefreshContainer';
    container.className = 'mt-3';
    container.innerHTML = `
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="autoRefresh">
            <label class="form-check-label" for="autoRefresh">
                Автообновление каждые 30 секунд
            </label>
        </div>
    `;
    document.querySelector('.card-body').appendChild(container);
}
</script>

<?php
// Вспомогательная функция для построения URL с параметрами
function build_pagination_url($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'index.php?' . http_build_query($params);
}
?>