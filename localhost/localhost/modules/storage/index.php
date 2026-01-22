<?php
// Проверка прав
check_role(['admin', 'manager', 'storekeeper']);

$page_title = 'Складские места';

// Параметры фильтрации
$zone = clean_input($_GET['zone'] ?? '');
$search = clean_input($_GET['search'] ?? '');
$show_inactive = isset($_GET['show_inactive']) ? 1 : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Построение запроса
$where = [];
$params = [];

if (!$show_inactive) {
    $where[] = 'is_active = 1';
}

if ($zone) {
    $where[] = 'zone = ?';
    $params[] = $zone;
}

if ($search) {
    $where[] = '(code LIKE ? OR name LIKE ?)';
    $search_term = "%{$search}%";
    array_push($params, $search_term, $search_term);
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Получение мест хранения
$locations = db_fetch_all("
    SELECT sl.*, 
           COUNT(sb.id) as items_count,
           COALESCE(SUM(sb.quantity), 0) as total_quantity
    FROM storage_locations sl
    LEFT JOIN stock_balances sb ON sl.id = sb.location_id
    {$where_clause}
    GROUP BY sl.id
    ORDER BY zone, code
    LIMIT ? OFFSET ?
", array_merge($params, [$limit, $offset]));

// Подсчет общего количества
$total_result = db_fetch_one("SELECT COUNT(*) as total FROM storage_locations {$where_clause}", $params);
$total_items = $total_result['total'] ?? 0;
$total_pages = ceil($total_items / $limit);

// Статистика
$stats = db_fetch_one("
    SELECT 
        COUNT(*) as total_locations,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_locations,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_locations
    FROM storage_locations
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Складские места</h1>
        <p class="text-muted mb-0">Управление местами хранения товаров</p>
    </div>
    <div>
        <a href="index.php?module=storage&action=add" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Добавить место
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
                        <h6 class="text-muted">Всего мест</h6>
                        <h3 class="mb-0"><?php echo $stats['total_locations'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-geo-alt text-primary fs-3"></i>
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
                        <h3 class="mb-0"><?php echo $stats['active_locations'] ?? 0; ?></h3>
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
                        <h6 class="text-muted">Загруженность</h6>
                        <h3 class="mb-0">
                            <?php
                            $used = db_fetch_one("
                                SELECT COUNT(DISTINCT location_id) as used 
                                FROM stock_balances 
                                WHERE quantity > 0
                            ");
                            echo $used['used'] ?? 0;
                            ?>
                        </h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-box text-warning fs-3"></i>
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
                        <h6 class="text-muted">На странице</h6>
                        <h3 class="mb-0"><?php echo count($locations); ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-list text-info fs-3"></i>
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
            <input type="hidden" name="module" value="storage">
            <input type="hidden" name="action" value="locations">
            
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Зона</label>
                    <select name="zone" class="form-select">
                        <option value="">Все зоны</option>
                        <option value="receiving" <?php echo $zone == 'receiving' ? 'selected' : ''; ?>>Приемка</option>
                        <option value="storage" <?php echo $zone == 'storage' ? 'selected' : ''; ?>>Хранение</option>
                        <option value="shipping" <?php echo $zone == 'shipping' ? 'selected' : ''; ?>>Отгрузка</option>
                        <option value="quarantine" <?php echo $zone == 'quarantine' ? 'selected' : ''; ?>>Карантин</option>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Поиск</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Код или название..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Статус</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_inactive" 
                               id="show_inactive" value="1" <?php echo $show_inactive ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="show_inactive">
                            Показать неактивные
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

<!-- Таблица мест хранения -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Код</th>
                        <th>Название</th>
                        <th>Зона</th>
                        <th class="text-center">Емкость</th>
                        <th class="text-center">Товары</th>
                        <th class="text-end">Количество</th>
                        <th class="text-center">Статус</th>
                        <th class="text-end">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($locations)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <div class="text-muted">
                                <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                Места хранения не найдены
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($locations as $location): 
                        $zone_names = [
                            'receiving' => 'Приемка',
                            'storage' => 'Хранение',
                            'shipping' => 'Отгрузка',
                            'quarantine' => 'Карантин'
                        ];
                        $zone_name = $zone_names[$location['zone']] ?? $location['zone'];
                        
                        // Расчет загрузки
                        $load_percentage = $location['capacity'] > 0 ? 
                            min(100, ($location['current_load'] / $location['capacity']) * 100) : 0;
                        $load_class = $load_percentage > 90 ? 'danger' : 
                                     ($load_percentage > 70 ? 'warning' : 'success');
                    ?>
                    <tr>
                        <td>
                            <code class="fw-bold"><?php echo htmlspecialchars($location['code']); ?></code>
                        </td>
                        <td><?php echo htmlspecialchars($location['name']); ?></td>
                        <td>
                            <span class="badge bg-secondary"><?php echo $zone_name; ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($location['capacity']): ?>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar bg-<?php echo $load_class; ?>" 
                                     style="width: <?php echo $load_percentage; ?>%">
                                    <?php echo round($load_percentage); ?>%
                                </div>
                            </div>
                            <small class="text-muted">
                                <?php echo format_number($location['current_load']); ?> / 
                                <?php echo format_number($location['capacity']); ?>
                            </small>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo $location['items_count']; ?></td>
                        <td class="text-end fw-bold"><?php echo format_number($location['total_quantity']); ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $location['is_active'] ? 'success' : 'secondary'; ?>">
                                <?php echo $location['is_active'] ? 'Активен' : 'Неактивен'; ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?module=storage&action=view&id=<?php echo $location['id']; ?>" 
                                   class="btn btn-outline-info" title="Просмотр">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="index.php?module=storage&action=edit&id=<?php echo $location['id']; ?>" 
                                   class="btn btn-outline-primary" title="Редактировать">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if (has_role('admin') || has_role('manager')): ?>
                                <a href="index.php?module=transactions&action=movement&location_from=<?php echo $location['id']; ?>" 
                                   class="btn btn-outline-success" title="Переместить">
                                    <i class="bi bi-arrow-left-right"></i>
                                </a>
                                <button type="button" class="btn btn-outline-danger" 
                                        onclick="toggleLocationStatus(<?php echo $location['id']; ?>, <?php echo $location['is_active'] ? '0' : '1'; ?>)"
                                        title="<?php echo $location['is_active'] ? 'Деактивировать' : 'Активировать'; ?>">
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
                    Показано <?php echo count($locations); ?> из <?php echo $total_items; ?> мест
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

<!-- Карта склада -->
<div class="card mt-4">
    <div class="card-header">
        <h6 class="card-title mb-0">Карта склада</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <?php
            $zones = db_fetch_all("
                SELECT zone, COUNT(*) as count 
                FROM storage_locations 
                WHERE is_active = 1 
                GROUP BY zone 
                ORDER BY 
                    CASE zone 
                        WHEN 'receiving' THEN 1
                        WHEN 'storage' THEN 2
                        WHEN 'shipping' THEN 3
                        WHEN 'quarantine' THEN 4
                        ELSE 5
                    END
            ");
            ?>
            
            <?php foreach ($zones as $zone_data): 
                $zone_color = [
                    'receiving' => 'primary',
                    'storage' => 'success',
                    'shipping' => 'warning',
                    'quarantine' => 'danger'
                ][$zone_data['zone']] ?? 'secondary';
            ?>
            <div class="col-md-3">
                <div class="card border-<?php echo $zone_color; ?>">
                    <div class="card-header bg-<?php echo $zone_color; ?> text-white">
                        <h6 class="card-title mb-0">
                            <?php 
                            echo [
                                'receiving' => 'Зона приемки',
                                'storage' => 'Зона хранения',
                                'shipping' => 'Зона отгрузки',
                                'quarantine' => 'Карантин'
                            ][$zone_data['zone']] ?? $zone_data['zone'];
                            ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <div class="display-4 fw-bold"><?php echo $zone_data['count']; ?></div>
                            <p class="text-muted">мест хранения</p>
                        </div>
                        
                        <?php
                        $zone_locations = db_fetch_all("
                            SELECT code, name, capacity 
                            FROM storage_locations 
                            WHERE zone = ? AND is_active = 1 
                            ORDER BY code
                            LIMIT 10
                        ", [$zone_data['zone']]);
                        ?>
                        
                        <ul class="list-group list-group-flush">
                            <?php foreach ($zone_locations as $loc): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($loc['code']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($loc['name']); ?></div>
                                </div>
                                <div class="text-end">
                                    <?php if (isset($loc['capacity'])): ?>
                                    <div class="small">
                                        Емкость: <?php echo format_number($loc['capacity'] ?? 0); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function toggleLocationStatus(locationId, newStatus) {
    if (confirm(newStatus ? 'Активировать место хранения?' : 'Деактивировать место хранения?')) {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('location_id', locationId);
        formData.append('is_active', newStatus);
        formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
        
        fetch('index.php?module=storage&action=toggle_status', {
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

// Функция для построения URL пагинации
function build_pagination_url(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    return url.toString();
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

<style>
.progress {
    min-width: 100px;
}
</style>