<?php
// Проверка прав
check_role(['admin', 'manager', 'viewer']);

$page_title = 'Отчет по оборотам';

// Параметры отчета
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$product_id = (int)($_GET['product_id'] ?? 0);
$category_id = (int)($_GET['category_id'] ?? 0);
$operation_type = clean_input($_GET['operation_type'] ?? '');
$format = $_GET['format'] ?? 'html';

// Валидация дат
if (strtotime($start_date) > strtotime($end_date)) {
    $start_date = $end_date;
}

// Построение запроса для отчета
$where = ["DATE(d.document_date) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if ($product_id > 0) {
    $where[] = "it.product_id = ?";
    $params[] = $product_id;
}

if ($category_id > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $category_id;
}

if ($operation_type) {
    $where[] = "ot.code = ?";
    $params[] = $operation_type;
}

$where_clause = "WHERE " . implode(" AND ", $where);

// Основной запрос для отчета
$query = "
    SELECT 
        DATE(d.document_date) as date,
        ot.code as operation_type,
        ot.name as operation_name,
        p.article,
        p.name as product_name,
        p.unit,
        c.name as category_name,
        SUM(CASE WHEN it.quantity > 0 THEN it.quantity ELSE 0 END) as incoming,
        SUM(CASE WHEN it.quantity < 0 THEN ABS(it.quantity) ELSE 0 END) as outgoing,
        SUM(it.quantity) as net_change,
        COUNT(DISTINCT d.id) as documents_count,
        COUNT(*) as transactions_count
    FROM inventory_transactions it
    JOIN documents d ON it.document_id = d.id
    JOIN operation_types ot ON d.operation_type_id = ot.id
    JOIN products p ON it.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    {$where_clause}
    GROUP BY DATE(d.document_date), ot.code, it.product_id
    ORDER BY d.document_date DESC, ot.code, p.article
";

$turnover_data = db_fetch_all($query, $params);

// Подсчет итогов
$total_incoming = 0;
$total_outgoing = 0;
$total_documents = 0;
$total_transactions = 0;

foreach ($turnover_data as $row) {
    $total_incoming += $row['incoming'];
    $total_outgoing += $row['outgoing'];
    $total_documents += $row['documents_count'];
    $total_transactions += $row['transactions_count'];
}

$net_change = $total_incoming - $total_outgoing;

// Получение данных для фильтров
$categories = db_fetch_all("SELECT id, name FROM categories ORDER BY name");
$products = db_fetch_all("
    SELECT id, article, name 
    FROM products 
    WHERE is_active = 1 
    ORDER BY article 
    LIMIT 100
");

$operation_types = db_fetch_all("
    SELECT code, name 
    FROM operation_types 
    WHERE affects_stock = 1 
    ORDER BY name
");

// Экспорт в Excel
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="turnover_report_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<table border="1">';
    echo '<tr><th colspan="9">Отчет по оборотам товаров</th></tr>';
    echo '<tr><th colspan="9">Период: ' . format_date($start_date, 'd.m.Y') . ' - ' . format_date($end_date, 'd.m.Y') . '</th></tr>';
    echo '<tr><th colspan="9">Сформирован: ' . date('d.m.Y H:i:s') . '</th></tr>';
    echo '<tr></tr>';
    echo '<tr>';
    echo '<th>Дата</th>';
    echo '<th>Тип операции</th>';
    echo '<th>Артикул</th>';
    echo '<th>Наименование</th>';
    echo '<th>Категория</th>';
    echo '<th>Ед. изм.</th>';
    echo '<th>Приход</th>';
    echo '<th>Расход</th>';
    echo '<th>Изменение</th>';
    echo '</tr>';
    
    foreach ($turnover_data as $row) {
        echo '<tr>';
        echo '<td>' . format_date($row['date'], 'd.m.Y') . '</td>';
        echo '<td>' . htmlspecialchars($row['operation_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['article']) . '</td>';
        echo '<td>' . htmlspecialchars($row['product_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['category_name'] ?? '') . '</td>';
        echo '<td>' . $row['unit'] . '</td>';
        echo '<td>' . $row['incoming'] . '</td>';
        echo '<td>' . $row['outgoing'] . '</td>';
        echo '<td>' . $row['net_change'] . '</td>';
        echo '</tr>';
    }
    
    echo '<tr><td colspan="6"><strong>Итого:</strong></td>';
    echo '<td><strong>' . $total_incoming . '</strong></td>';
    echo '<td><strong>' . $total_outgoing . '</strong></td>';
    echo '<td><strong>' . $net_change . '</strong></td>';
    echo '</tr>';
    echo '</table>';
    exit();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Отчет по оборотам товаров</h1>
        <p class="text-muted mb-0">Анализ движения товаров за период</p>
    </div>
    <div>
        <div class="btn-group">
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer"></i> Печать
            </button>
            <a href="?module=reports&action=turnover&format=excel&<?php echo http_build_query($_GET); ?>" 
               class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> Excel
            </a>
        </div>
    </div>
</div>

<!-- Фильтры отчета -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title mb-0">Параметры отчета</h6>
    </div>
    <div class="card-body">
        <form method="GET" id="reportFilter">
            <input type="hidden" name="module" value="reports">
            <input type="hidden" name="action" value="turnover">
            
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Дата с</label>
                    <input type="date" class="form-control" name="start_date" 
                           value="<?php echo $start_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Дата по</label>
                    <input type="date" class="form-control" name="end_date" 
                           value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                
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
                    <label class="form-label">Тип операции</label>
                    <select name="operation_type" class="form-select">
                        <option value="">Все операции</option>
                        <?php foreach ($operation_types as $type): ?>
                        <option value="<?php echo $type['code']; ?>" 
                            <?php echo $operation_type == $type['code'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-6">
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
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i> Сформировать
                    </button>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" onclick="resetFilters()" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-clockwise"></i> Сбросить
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
                        <h6 class="text-muted">Приход</h6>
                        <h3 class="mb-0"><?php echo format_number($total_incoming); ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-arrow-down-circle text-primary fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Расход</h6>
                        <h3 class="mb-0"><?php echo format_number($total_outgoing); ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-arrow-up-circle text-danger fs-3"></i>
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
                        <h6 class="text-muted">Изменение</h6>
                        <h3 class="mb-0"><?php echo format_number($net_change); ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-arrow-left-right text-success fs-3"></i>
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
                        <h6 class="text-muted">Документы</h6>
                        <h3 class="mb-0"><?php echo $total_documents; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-file-text text-info fs-3"></i>
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
                        <th>Дата</th>
                        <th>Операция</th>
                        <th>Артикул</th>
                        <th>Наименование</th>
                        <th>Категория</th>
                        <th class="text-end">Приход</th>
                        <th class="text-end">Расход</th>
                        <th class="text-end">Изменение</th>
                        <th class="text-center">Документы</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($turnover_data)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <div class="text-muted">
                                <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                Данные за указанный период отсутствуют
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($turnover_data as $index => $row): ?>
                    <tr>
                        <td class="text-muted"><?php echo $index + 1; ?></td>
                        <td><?php echo format_date($row['date'], 'd.m.Y'); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $row['operation_type'] == 'RECEIPT' ? 'success' : ($row['operation_type'] == 'SHIPMENT' ? 'danger' : 'warning'); ?>">
                                <?php echo htmlspecialchars($row['operation_name']); ?>
                            </span>
                        </td>
                        <td>
                            <code><?php echo htmlspecialchars($row['article']); ?></code>
                        </td>
                        <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></td>
                        <td class="text-end <?php echo $row['incoming'] > 0 ? 'fw-bold text-success' : ''; ?>">
                            <?php echo $row['incoming'] > 0 ? format_number($row['incoming']) : '-'; ?>
                        </td>
                        <td class="text-end <?php echo $row['outgoing'] > 0 ? 'fw-bold text-danger' : ''; ?>">
                            <?php echo $row['outgoing'] > 0 ? format_number($row['outgoing']) : '-'; ?>
                        </td>
                        <td class="text-end fw-bold <?php echo $row['net_change'] > 0 ? 'text-success' : ($row['net_change'] < 0 ? 'text-danger' : ''); ?>">
                            <?php echo format_number($row['net_change']); ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info"><?php echo $row['documents_count']; ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="6" class="text-end">Итого:</th>
                        <th class="text-end"><?php echo format_number($total_incoming); ?></th>
                        <th class="text-end"><?php echo format_number($total_outgoing); ?></th>
                        <th class="text-end"><?php echo format_number($net_change); ?></th>
                        <th class="text-center"><?php echo $total_documents; ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <div class="card-footer">
        <div class="row align-items-center">
            <div class="col-md-6">
                <small class="text-muted">
                    Период: <?php echo format_date($start_date, 'd.m.Y'); ?> - <?php echo format_date($end_date, 'd.m.Y'); ?><br>
                    Сформировано: <?php echo date('d.m.Y H:i:s'); ?>
                </small>
            </div>
            <div class="col-md-6 text-end">
                <small class="text-muted">
                    <?php echo SITE_NAME; ?> v1.0<br>
                    Позиций: <?php echo count($turnover_data); ?>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Графики (если есть данные) -->
<?php if (!empty($turnover_data)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h6 class="card-title mb-0">Графики движения товаров</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <canvas id="turnoverChart" height="300"></canvas>
            </div>
            <div class="col-md-4">
                <h6>Распределение по типам операций:</h6>
                <canvas id="operationsChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
}
</style>

<script>
// Сброс фильтров
function resetFilters() {
    window.location.href = 'index.php?module=reports&action=turnover';
}

// Инициализация графиков с Chart.js
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($turnover_data)): ?>
    
    // Подготовка данных для графиков
    const dates = [...new Set(<?php echo json_encode(array_column($turnover_data, 'date')); ?>)];
    const operations = [...new Set(<?php echo json_encode(array_column($turnover_data, 'operation_type')); ?>)];
    
    // Данные для линейного графика
    const incomingByDate = {};
    const outgoingByDate = {};
    
    <?php foreach ($turnover_data as $row): ?>
        const date = '<?php echo $row["date"]; ?>';
        if (!incomingByDate[date]) incomingByDate[date] = 0;
        if (!outgoingByDate[date]) outgoingByDate[date] = 0;
        
        incomingByDate[date] += <?php echo $row["incoming"]; ?>;
        outgoingByDate[date] += <?php echo $row["outgoing"]; ?>;
    <?php endforeach; ?>
    
    const incomingData = dates.map(date => incomingByDate[date] || 0);
    const outgoingData = dates.map(date => outgoingByDate[date] || 0);
    
    // Данные для круговой диаграммы
    const operationTotals = {};
    <?php foreach ($turnover_data as $row): ?>
        const op = '<?php echo $row["operation_type"]; ?>';
        if (!operationTotals[op]) operationTotals[op] = 0;
        operationTotals[op] += Math.abs(<?php echo $row["net_change"]; ?>);
    <?php endforeach; ?>
    
    // Создание линейного графика
    const turnoverCtx = document.getElementById('turnoverChart').getContext('2d');
    new Chart(turnoverCtx, {
        type: 'line',
        data: {
            labels: dates,
            datasets: [
                {
                    label: 'Приход',
                    data: incomingData,
                    borderColor: 'rgb(40, 167, 69)',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.1
                },
                {
                    label: 'Расход',
                    data: outgoingData,
                    borderColor: 'rgb(220, 53, 69)',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.1
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Динамика движения товаров'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Количество'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Дата'
                    }
                }
            }
        }
    });
    
    // Создание круговой диаграммы
    const operationsCtx = document.getElementById('operationsChart').getContext('2d');
    new Chart(operationsCtx, {
        type: 'doughnut',
        data: {
            labels: Object.keys(operationTotals),
            datasets: [{
                data: Object.values(operationTotals),
                backgroundColor: [
                    'rgb(40, 167, 69)',   // RECEIPT - зеленый
                    'rgb(220, 53, 69)',   // SHIPMENT - красный
                    'rgb(255, 193, 7)',   // MOVEMENT - желтый
                    'rgb(23, 162, 184)',  // RETURN - голубой
                    'rgb(108, 117, 125)'  // WRITEOFF - серый
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                title: {
                    display: true,
                    text: 'Распределение по операциям'
                }
            }
        }
    });
    
    <?php endif; ?>
});
</script>