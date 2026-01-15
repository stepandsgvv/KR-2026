<?php
// Проверка прав
check_role(['admin', 'manager', 'storekeeper']);

$page_title = 'Отчет по остаткам';

// Параметры фильтра
$category_id = (int)($_GET['category_id'] ?? 0);
$location_id = (int)($_GET['location_id'] ?? 0);
$product_id = (int)($_GET['product_id'] ?? 0);
$batch_number = clean_input($_GET['batch_number'] ?? '');
$show_zero = isset($_GET['show_zero']) ? 1 : 0;
$low_stock_only = isset($_GET['low_stock']) ? 1 : 0;
$format = $_GET['format'] ?? 'html';

// Построение запроса
$where = ['sb.quantity > 0'];
$params = [];
$joins = [];

if (!$show_zero) {
    $where = ['sb.quantity > 0'];
}

if ($low_stock_only) {
    $joins[] = "LEFT JOIN products p ON sb.product_id = p.id";
    $where[] = "p.min_stock > 0";
    $where[] = "sb.quantity <= p.min_stock";
}

if ($category_id > 0) {
    if (!in_array("LEFT JOIN products p ON sb.product_id = p.id", $joins)) {
        $joins[] = "LEFT JOIN products p ON sb.product_id = p.id";
    }
    $where[] = "p.category_id = ?";
    $params[] = $category_id;
}

if ($location_id > 0) {
    $where[] = "sb.location_id = ?";
    $params[] = $location_id;
}

if ($product_id > 0) {
    $where[] = "sb.product_id = ?";
    $params[] = $product_id;
}

if ($batch_number) {
    $where[] = "b.batch_number LIKE ?";
    $params[] = "%{$batch_number}%";
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$join_clause = implode(' ', $joins);

// Основной запрос
$query = "
    SELECT 
        sb.*,
        p.article,
        p.name as product_name,
        p.unit,
        p.min_stock,
        p.max_stock,
        c.name as category_name,
        b.batch_number,
        b.expiry_date,
        b.supplier,
        b.purchase_price,
        l.code as location_code,
        l.name as location_name,
        l.zone as location_zone,
        u.full_name as created_by_name
    FROM stock_balances sb
    LEFT JOIN products p ON sb.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN batches b ON sb.batch_id = b.id
    LEFT JOIN storage_locations l ON sb.location_id = l.id
    LEFT JOIN users u ON p.created_by = u.id
    {$join_clause}
    {$where_clause}
    ORDER BY p.article, l.code, b.batch_number
";

$stock_items = db_fetch_all($query, $params);

// Подсчет итогов
$total_items = count($stock_items);
$total_quantity = 0;
$total_value = 0;
$items_by_location = [];
$items_by_category = [];

foreach ($stock_items as $item) {
    $total_quantity += $item['quantity'];
    $total_value += $item['quantity'] * ($item['purchase_price'] ?? 0);
    
    // Группировка по местам хранения
    $location_key = $item['location_code'] ?? 'Без места';
    if (!isset($items_by_location[$location_key])) {
        $items_by_location[$location_key] = 0;
    }
    $items_by_location[$location_key] += $item['quantity'];
    
    // Группировка по категориям
    $category_key = $item['category_name'] ?? 'Без категории';
    if (!isset($items_by_category[$category_key])) {
        $items_by_category[$category_key] = 0;
    }
    $items_by_category[$category_key] += $item['quantity'];
}

// Получение данных для фильтров
$categories = db_fetch_all("SELECT id, name FROM categories ORDER BY name");
$locations = db_fetch_all("SELECT id, code, name FROM storage_locations WHERE is_active = 1 ORDER BY code");
$products = db_fetch_all("SELECT id, article, name FROM products WHERE is_active = 1 ORDER BY article LIMIT 100");

// Экспорт в Excel
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="stock_report_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<table border="1">';
    echo '<tr><th colspan="10">Отчет по остаткам на складе</th></tr>';
    echo '<tr><th colspan="10">Дата формирования: ' . date('d.m.Y H:i:s') . '</th></tr>';
    echo '<tr><th colspan="10">Пользователь: ' . $_SESSION['full_name'] . '</th></tr>';
    echo '<tr></tr>';
    echo '<tr>';
    echo '<th>Артикул</th>';
    echo '<th>Наименование</th>';
    echo '<th>Категория</th>';
    echo '<th>Партия</th>';
    echo '<th>Срок годности</th>';
    echo '<th>Поставщик</th>';
    echo '<th>Место хранения</th>';
    echo '<th>Остаток</th>';
    echo '<th>Ед. изм.</th>';
    echo '<th>Цена закупки</th>';
    echo '<th>Стоимость</th>';
    echo '</tr>';
    
    foreach ($stock_items as $item) {
        $item_value = $item['quantity'] * ($item['purchase_price'] ?? 0);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($item['article']) . '</td>';
        echo '<td>' . htmlspecialchars($item['product_name']) . '</td>';
        echo '<td>' . htmlspecialchars($item['category_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['batch_number'] ?? '') . '</td>';
        echo '<td>' . ($item['expiry_date'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['supplier'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($item['location_code'] ?? '') . '</td>';
        echo '<td>' . $item['quantity'] . '</td>';
        echo '<td>' . $item['unit'] . '</td>';
        echo '<td>' . ($item['purchase_price'] ? number_format($item['purchase_price'], 2) : '') . '</td>';
        echo '<td>' . number_format($item_value, 2) . '</td>';
        echo '</tr>';
    }
    
    echo '<tr><td colspan="7"><strong>Итого:</strong></td>';
    echo '<td><strong>' . $total_quantity . '</strong></td>';
    echo '<td colspan="2"></td>';
    echo '<td><strong>' . number_format($total_value, 2) . '</strong></td>';
    echo '</tr>';
    echo '</table>';
    exit();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Отчет по остаткам на складе</h1>
        <p class="text-muted mb-0">Актуальная информация о наличии товаров</p>
    </div>
    <div>
        <div class="btn-group">
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer"></i> Печать
            </button>
            <a href="?module=reports&action=stock&format=excel&<?php echo http_build_query($_GET); ?>" 
               class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> Excel
            </a>
            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#statsModal">
                <i class="bi bi-bar-chart"></i> Статистика
            </button>
        </div>
    </div>
</div>

<!-- Фильтры -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title mb-0">Фильтры отчета</h6>
    </div>
    <div class="card-body">
        <form method="GET" id="reportFilter">
            <input type="hidden" name="module" value="reports">
            <input type="hidden" name="action" value="stock">
            
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Категория</label>
                    <select name="category_id" class="form-select">
                        <option value="0">Все категории</option>
                        <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>" 
                            <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Место хранения</label>
                    <select name="location_id" class="form-select">
                        <option value="0">Все места</option>
                        <?php foreach ($locations as $location): ?>
                        <option value="<?php echo $location['id']; ?>" 
                            <?php echo $location_id == $location['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($location['code'] . ' - ' . $location['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Товар</label>
                    <select name="product_id" class="form-select">
                        <option value="0">Все товары</option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>" 
                            <?php echo $product_id == $product['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($product['article'] . ' - ' . $product['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Партия</label>
                    <input type="text" name="batch_number" class="form-control" 
                           placeholder="Номер партии" value="<?php echo htmlspecialchars($batch_number); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Опции</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_zero" 
                               id="show_zero" value="1" <?php echo $show_zero ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="show_zero">
                            Показать нулевые остатки
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="low_stock" 
                               id="low_stock" value="1" <?php echo $low_stock_only ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="low_stock">
                            Только низкий остаток
                        </label>
                    </div>
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

<!-- Сводная статистика -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Всего позиций</h6>
                        <h3 class="mb-0"><?php echo $total_items; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-list text-primary fs-3"></i>
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
                        <h6 class="text-muted">Общий остаток</h6>
                        <h3 class="mb-0"><?php echo format_number($total_quantity); ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-box text-success fs-3"></i>
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
                        <h6 class="text-muted">Общая стоимость</h6>
                        <h3 class="mb-0"><?php echo format_number($total_value, 2); ?> ₽</h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-currency-ruble text-warning fs-3"></i>
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
                        <h6 class="text-muted">Дата отчета</h6>
                        <h3 class="mb-0"><?php echo date('d.m.Y'); ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-calendar text-info fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Таблица отчета -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="30">#</th>
                        <th>Артикул</th>
                        <th>Наименование</th>
                        <th>Категория</th>
                        <th>Партия</th>
                        <th>Срок годности</th>
                        <th>Поставщик</th>
                        <th>Место хранения</th>
                        <th class="text-end">Остаток</th>
                        <th>Ед. изм.</th>
                        <th class="text-end">Мин.</th>
                        <th class="text-end">Цена</th>
                        <th class="text-end">Стоимость</th>
                        <th class="text-center">Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stock_items)): ?>
                    <tr>
                        <td colspan="14" class="text-center py-4">
                            <div class="text-muted">
                                <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                Остатки не найдены
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($stock_items as $index => $item): 
                        // Определение статуса
                        $expiry_status = check_expiry($item['expiry_date'] ?? '');
                        $stock_ratio = $item['min_stock'] > 0 ? $item['quantity'] / $item['min_stock'] : 1;
                        $item_value = $item['quantity'] * ($item['purchase_price'] ?? 0);
                        
                        $status_class = 'success';
                        $status_text = 'OK';
                        
                        if ($item['quantity'] <= 0) {
                            $status_class = 'danger';
                            $status_text = 'Отсутствует';
                        } elseif ($item['min_stock'] > 0 && $item['quantity'] <= $item['min_stock']) {
                            $status_class = 'warning';
                            $status_text = 'Низкий';
                        } elseif ($expiry_status['status'] === 'expired') {
                            $status_class = 'danger';
                            $status_text = 'Просрочен';
                        } elseif ($expiry_status['status'] === 'warning') {
                            $status_class = 'warning';
                            $status_text = 'Скоро истекает';
                        }
                    ?>
                    <tr>
                        <td class="text-muted"><?php echo $index + 1; ?></td>
                        <td>
                            <code><?php echo htmlspecialchars($item['article']); ?></code>
                        </td>
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['category_name'] ?? '-'); ?></td>
                        <td>
                            <?php if ($item['batch_number']): ?>
                            <code><?php echo htmlspecialchars($item['batch_number']); ?></code>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($item['expiry_date']): ?>
                            <div><?php echo format_date($item['expiry_date'], 'd.m.Y'); ?></div>
                            <?php if ($expiry_status['status'] !== 'ok'): ?>
                            <small class="text-<?php echo $expiry_status['status'] === 'expired' ? 'danger' : 'warning'; ?>">
                                <?php echo $expiry_status['status'] === 'expired' ? 'Просрочен' : 'Осталось ' . $expiry_status['days'] . ' дн.'; ?>
                            </small>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($item['supplier'] ?? '-'); ?></td>
                        <td>
                            <div class="fw-bold"><?php echo htmlspecialchars($item['location_code']); ?></div>
                            <div class="small text-muted">
                                <?php echo htmlspecialchars($item['location_name']); ?>
                                (<?php echo $item['location_zone']; ?>)
                            </div>
                        </td>
                        <td class="text-end fw-bold"><?php echo format_number($item['quantity']); ?></td>
                        <td><?php echo $item['unit']; ?></td>
                        <td class="text-end">
                            <?php echo $item['min_stock'] > 0 ? format_number($item['min_stock']) : '<span class="text-muted">-</span>'; ?>
                        </td>
                        <td class="text-end">
                            <?php echo $item['purchase_price'] ? format_number($item['purchase_price'], 2) . ' ₽' : '<span class="text-muted">-</span>'; ?>
                        </td>
                        <td class="text-end fw-bold">
                            <?php echo $item_value > 0 ? format_number($item_value, 2) . ' ₽' : '<span class="text-muted">-</span>'; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="8" class="text-end">Итого:</th>
                        <th class="text-end"><?php echo format_number($total_quantity); ?></th>
                        <th colspan="3"></th>
                        <th class="text-end"><?php echo format_number($total_value, 2); ?> ₽</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <div class="card-footer">
        <div class="row align-items-center">
            <div class="col-md-6">
                <small class="text-muted">
                    Сформировано: <?php echo date('d.m.Y H:i:s'); ?><br>
                    Пользователь: <?php echo $_SESSION['full_name']; ?>
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small class="text-muted">
                    <?php echo SITE_NAME; ?> v1.0<br>
                    Позиций: <?php echo $total_items; ?>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно статистики -->
<div class="modal fade" id="statsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Статистика остатков</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Распределение по местам хранения:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Место хранения</th>
                                        <th class="text-end">Количество</th>
                                        <th class="text-end">%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items_by_location as $location => $quantity): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($location); ?></td>
                                        <td class="text-end"><?php echo format_number($quantity); ?></td>
                                        <td class="text-end">
                                            <?php echo $total_quantity > 0 ? number_format(($quantity / $total_quantity) * 100, 1) : '0'; ?>%
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h6>Распределение по категориям:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Категория</th>
                                        <th class="text-end">Количество</th>
                                        <th class="text-end">%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items_by_category as $category => $quantity): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($category); ?></td>
                                        <td class="text-end"><?php echo format_number($quantity); ?></td>
                                        <td class="text-end">
                                            <?php echo $total_quantity > 0 ? number_format(($quantity / $total_quantity) * 100, 1) : '0'; ?>%
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h6>Товары с низким остатком:</h6>
                        <?php
                        $low_stock_items = array_filter($stock_items, function($item) {
                            return $item['min_stock'] > 0 && $item['quantity'] <= $item['min_stock'];
                        });
                        ?>
                        
                        <?php if (empty($low_stock_items)): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i> Все товары в наличии
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Товар</th>
                                        <th class="text-end">Остаток</th>
                                        <th class="text-end">Мин. запас</th>
                                        <th class="text-end">Дефицит</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($low_stock_items as $item): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($item['article'] . ' - ' . $item['product_name']); ?>
                                        </td>
                                        <td class="text-end"><?php echo format_number($item['quantity']); ?></td>
                                        <td class="text-end"><?php echo format_number($item['min_stock']); ?></td>
                                        <td class="text-end text-danger">
                                            <?php echo format_number($item['min_stock'] - $item['quantity']); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .sidebar, .navbar, .card:first-child, .btn, .modal, .card-footer {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .table {
        font-size: 11px !important;
    }
    .badge {
        border: 1px solid #000 !important;
        background: none !important;
        color: #000 !important;
    }
}
</style>

<script>
// Сброс фильтров
function resetFilters() {
    window.location.href = 'index.php?module=reports&action=stock';
}

// Автоматическая отправка формы при изменении чекбоксов
document.querySelectorAll('#reportFilter input[type="checkbox"]').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        document.getElementById('reportFilter').submit();
    });
});

// Печать страницы
function printReport() {
    window.print();
}

// Генерация графика (если подключена библиотека Chart.js)
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart !== 'undefined') {
        // Можно добавить графики распределения
    }
});
</script>