<?php
// Проверка авторизации
check_auth();

$page_title = 'Дашборд';

// Получение статистики
$stats = db_fetch_one("
    SELECT 
        (SELECT COUNT(*) FROM products WHERE is_active = 1 AND is_deleted = 0) as total_products,
        (SELECT COUNT(*) FROM storage_locations WHERE is_active = 1) as total_locations,
        (SELECT COUNT(*) FROM users WHERE is_active = 1 AND is_deleted = 0) as total_users,
        (SELECT COUNT(*) FROM documents WHERE DATE(created_at) = CURDATE()) as today_documents,
        (SELECT COALESCE(SUM(quantity), 0) FROM stock_balances) as total_stock,
        (SELECT COUNT(DISTINCT product_id) FROM stock_balances WHERE quantity <= 0) as out_of_stock,
        (SELECT COUNT(DISTINCT product_id) FROM stock_balances sb 
         JOIN products p ON sb.product_id = p.id 
         WHERE sb.quantity <= p.min_stock AND p.min_stock > 0) as low_stock
");

// Последние транзакции
$recent_transactions = db_fetch_all("
    SELECT d.document_number, d.document_date, ot.name as operation, 
           d.counterparty, COUNT(it.id) as items, u.full_name
    FROM documents d
    JOIN operation_types ot ON d.operation_type_id = ot.id
    LEFT JOIN inventory_transactions it ON d.id = it.document_id
    LEFT JOIN users u ON d.created_by = u.id
    WHERE d.status = 'completed'
    GROUP BY d.id
    ORDER BY d.created_at DESC
    LIMIT 10
");

// Товары с низким остатком
$low_stock_products = db_fetch_all("
    SELECT p.article, p.name, p.unit, p.min_stock,
           COALESCE(SUM(sb.quantity), 0) as current_stock
    FROM products p
    LEFT JOIN stock_balances sb ON p.id = sb.product_id
    WHERE p.is_active = 1 AND p.is_deleted = 0 AND p.min_stock > 0
    GROUP BY p.id
    HAVING current_stock <= p.min_stock
    ORDER BY (current_stock / p.min_stock)
    LIMIT 10
");

// Статистика по месяцам
$monthly_stats = db_fetch_all("
    SELECT 
        DATE_FORMAT(d.created_at, '%Y-%m') as month,
        COUNT(DISTINCT d.id) as documents,
        SUM(CASE WHEN ot.direction = 'in' THEN ABS(it.quantity) ELSE 0 END) as incoming,
        SUM(CASE WHEN ot.direction = 'out' THEN ABS(it.quantity) ELSE 0 END) as outgoing
    FROM documents d
    JOIN inventory_transactions it ON d.id = it.document_id
    JOIN operation_types ot ON d.operation_type_id = ot.id
    WHERE d.status = 'completed' AND d.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(d.created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
");

// Активность пользователей за сегодня
$user_activity = db_fetch_all("
    SELECT u.username, u.full_name, u.role,
           COUNT(al.id) as actions_count,
           MAX(al.created_at) as last_action
    FROM audit_log al
    JOIN users u ON al.user_id = u.id
    WHERE DATE(al.created_at) = CURDATE()
    GROUP BY u.id
    ORDER BY actions_count DESC
    LIMIT 5
");
?>

<div class="row">
    <!-- Статистика -->
    <div class="col-12">
        <h2 class="h4 mb-3">Общая статистика</h2>
    </div>
    
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Товаров</h6>
                        <h3 class="mb-0"><?php echo $stats['total_products'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-box text-primary fs-3"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        <?php echo $stats['low_stock'] ?? 0; ?> с низким остатком
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">На складе</h6>
                        <h3 class="mb-0"><?php echo format_number($stats['total_stock'] ?? 0); ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-archive text-success fs-3"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        <?php echo $stats['out_of_stock'] ?? 0; ?> отсутствуют
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Документов</h6>
                        <h3 class="mb-0"><?php echo $stats['today_documents'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-file-text text-warning fs-3"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        За сегодня
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Пользователей</h6>
                        <h3 class="mb-0"><?php echo $stats['total_users'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-people text-info fs-3"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        Активных в системе
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <!-- Последние операции -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Последние операции</h5>
                <a href="index.php?module=transactions" class="btn btn-sm btn-outline-primary">
                    Все операции
                </a>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if (empty($recent_transactions)): ?>
                    <div class="list-group-item text-center text-muted py-4">
                        <i class="bi bi-inbox display-6 d-block mb-2"></i>
                        Операций нет
                    </div>
                    <?php else: ?>
                    <?php foreach ($recent_transactions as $transaction): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold"><?php echo $transaction['document_number']; ?></div>
                                <div class="small text-muted">
                                    <?php echo format_date($transaction['document_date'], 'd.m.Y'); ?> | 
                                    <?php echo htmlspecialchars($transaction['operation']); ?>
                                </div>
                                <?php if ($transaction['counterparty']): ?>
                                <div class="small"><?php echo htmlspecialchars($transaction['counterparty']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <div class="badge bg-info"><?php echo $transaction['items']; ?> поз.</div>
                                <div class="small text-muted"><?php echo htmlspecialchars($transaction['full_name']); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Товары с низким остатком -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Товары с низким остатком</h5>
                <a href="index.php?module=reports&action=stock&low_stock=1" class="btn btn-sm btn-outline-warning">
                    Все товары
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Товар</th>
                                <th class="text-end">Остаток</th>
                                <th class="text-end">Мин.</th>
                                <th class="text-end">Дефицит</th>
                                <th class="text-center">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($low_stock_products)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    <div class="text-success">
                                        <i class="bi bi-check-circle display-6 d-block mb-2"></i>
                                        Все товары в норме
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($low_stock_products as $product): 
                                $deficit = max(0, $product['min_stock'] - $product['current_stock']);
                                $percentage = $product['min_stock'] > 0 ? 
                                    round(($product['current_stock'] / $product['min_stock']) * 100, 0) : 100;
                                $progress_color = $percentage > 50 ? 'success' : ($percentage > 25 ? 'warning' : 'danger');
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($product['article']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($product['name']); ?></div>
                                </td>
                                <td class="text-end fw-bold"><?php echo format_number($product['current_stock']); ?></td>
                                <td class="text-end"><?php echo format_number($product['min_stock']); ?></td>
                                <td class="text-end text-danger fw-bold"><?php echo format_number($deficit); ?></td>
                                <td class="text-center">
                                    <a href="index.php?module=transactions&action=receipt&product_id=<?php echo $product['id']; ?>" 
                                       class="btn btn-sm btn-outline-success" title="Заказать">
                                        <i class="bi bi-cart-plus"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <!-- Статистика по месяцам -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Движение товаров (6 месяцев)</h5>
            </div>
            <div class="card-body">
                <canvas id="monthlyChart" height="150"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Активность пользователей -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Активность сегодня</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if (empty($user_activity)): ?>
                    <div class="list-group-item text-center text-muted py-4">
                        <i class="bi bi-activity display-6 d-block mb-2"></i>
                        Активности нет
                    </div>
                    <?php else: ?>
                    <?php foreach ($user_activity as $user): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                <div class="small text-muted">
                                    <span class="badge bg-secondary"><?php echo ucfirst($user['role']); ?></span>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold"><?php echo $user['actions_count']; ?></div>
                                <div class="small text-muted">действий</div>
                            </div>
                        </div>
                        <div class="small text-muted mt-1">
                            Последняя активность: <?php echo format_date($user['last_action'], 'H:i'); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Быстрые действия -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Быстрые действия</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <a href="index.php?module=transactions&action=receipt" class="btn btn-success w-100 h-100">
                            <div class="py-3">
                                <i class="bi bi-plus-circle display-6 d-block mb-2"></i>
                                <div>Приемка товара</div>
                            </div>
                        </a>
                    </div>
                    
                    <div class="col-md-3">
                        <a href="index.php?module=transactions&action=shipment" class="btn btn-danger w-100 h-100">
                            <div class="py-3">
                                <i class="bi bi-dash-circle display-6 d-block mb-2"></i>
                                <div>Отгрузка товара</div>
                            </div>
                        </a>
                    </div>
                    
                    <div class="col-md-3">
                        <a href="index.php?module=transactions&action=movement" class="btn btn-warning w-100 h-100">
                            <div class="py-3">
                                <i class="bi bi-arrow-left-right display-6 d-block mb-2"></i>
                                <div>Перемещение</div>
                            </div>
                        </a>
                    </div>
                    
                    <div class="col-md-3">
                        <a href="index.php?module=transactions&action=inventory" class="btn btn-info w-100 h-100">
                            <div class="py-3">
                                <i class="bi bi-clipboard-check display-6 d-block mb-2"></i>
                                <div>Инвентаризация</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Инициализация графика
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('monthlyChart').getContext('2d');
    
    const months = <?php echo json_encode(array_column($monthly_stats, 'month')); ?>;
    const incoming = <?php echo json_encode(array_column($monthly_stats, 'incoming')); ?>;
    const outgoing = <?php echo json_encode(array_column($monthly_stats, 'outgoing')); ?>;
    
    // Форматируем месяцы
    const formattedMonths = months.map(month => {
        const [year, m] = month.split('-');
        const monthNames = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
        return `${monthNames[parseInt(m) - 1]} ${year}`;
    }).reverse();
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: formattedMonths,
            datasets: [
                {
                    label: 'Приход',
                    data: incoming.reverse(),
                    backgroundColor: 'rgba(40, 167, 69, 0.8)',
                    borderColor: 'rgb(40, 167, 69)',
                    borderWidth: 1
                },
                {
                    label: 'Расход',
                    data: outgoing.reverse(),
                    backgroundColor: 'rgba(220, 53, 69, 0.8)',
                    borderColor: 'rgb(220, 53, 69)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: true,
                    text: 'Движение товаров по месяцам'
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
                        text: 'Месяц'
                    }
                }
            }
        }
    });
});

// Автообновление дашборда каждые 60 секунд
setTimeout(() => {
    location.reload();
}, 60000);
</script>