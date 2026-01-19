<?php
// Проверка авторизации и прав
check_role(['admin', 'manager', 'storekeeper', 'viewer']);

$product_id = (int)($_GET['id'] ?? 0);
$product = db_fetch_one("
    SELECT p.*, c.name as category_name, u.full_name as created_by_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.id = ? AND p.is_deleted = 0
", [$product_id]);

if (!$product) {
    $_SESSION['error'] = 'Товар не найден';
    header('Location: index.php?module=products');
    exit();
}

$page_title = 'Просмотр товара: ' . htmlspecialchars($product['article']);

// Получение остатков по местам хранения
$stock_details = db_fetch_all("
    SELECT sb.*, sl.code as location_code, sl.name as location_name, 
           sl.zone as location_zone, b.batch_number, b.expiry_date
    FROM stock_balances sb
    LEFT JOIN storage_locations sl ON sb.location_id = sl.id
    LEFT JOIN batches b ON sb.batch_id = b.id
    WHERE sb.product_id = ? AND sb.quantity > 0
    ORDER BY sl.code
", [$product_id]);

// Статистика движения товара
$movement_stats = db_fetch_all("
    SELECT DATE(it.created_at) as date, 
           ot.code as operation_type,
           ot.name as operation_name,
           SUM(CASE WHEN it.quantity > 0 THEN it.quantity ELSE 0 END) as incoming,
           SUM(CASE WHEN it.quantity < 0 THEN ABS(it.quantity) ELSE 0 END) as outgoing,
           SUM(it.quantity) as net_change
    FROM inventory_transactions it
    JOIN documents d ON it.document_id = d.id
    JOIN operation_types ot ON d.operation_type_id = ot.id
    WHERE it.product_id = ? AND d.status = 'completed'
    GROUP BY DATE(it.created_at), ot.code
    ORDER BY date DESC
    LIMIT 30
", [$product_id]);
?>

<div class="container-fluid">
    <!-- Заголовок и действия -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Просмотр товара</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?module=products">Номенклатура</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($product['article']); ?></li>
                </ol>
            </nav>
        </div>
        <div class="btn-group">
            <a href="index.php?module=products&action=edit&id=<?php echo $product_id; ?>" 
               class="btn btn-outline-primary">
                <i class="bi bi-pencil"></i> Редактировать
            </a>
            <a href="index.php?module=transactions&action=receipt&product_id=<?php echo $product_id; ?>" 
               class="btn btn-outline-success">
                <i class="bi bi-plus-circle"></i> Приемка
            </a>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Печать
            </button>
        </div>
    </div>

    <!-- Основная информация о товаре -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Основная информация</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Артикул:</th>
                                    <td><code class="fw-bold"><?php echo htmlspecialchars($product['article']); ?></code></td>
                                </tr>
                                <tr>
                                    <th>Наименование:</th>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Категория:</th>
                                    <td><?php echo htmlspecialchars($product['category_name'] ?? '—'); ?></td>
                                </tr>
                                <tr>
                                    <th>Единица измерения:</th>
                                    <td><?php echo htmlspecialchars($product['unit']); ?></td>
                                </tr>
                                <tr>
                                    <th>Штрих-код:</th>
                                    <td>
                                        <?php if ($product['barcode']): ?>
                                        <code><?php echo htmlspecialchars($product['barcode']); ?></code>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Мин. запас:</th>
                                    <td class="<?php echo $product['min_stock'] > 0 ? 'fw-bold' : 'text-muted'; ?>">
                                        <?php echo $product['min_stock'] > 0 ? format_number($product['min_stock']) : '—'; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Макс. запас:</th>
                                    <td class="<?php echo $product['max_stock'] > 0 ? 'fw-bold' : 'text-muted'; ?>">
                                        <?php echo $product['max_stock'] > 0 ? format_number($product['max_stock']) : '—'; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Статус:</th>
                                    <td>
                                        <span class="badge bg-<?php echo $product['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $product['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Создал:</th>
                                    <td><?php echo htmlspecialchars($product['created_by_name'] ?? 'Неизвестно'); ?></td>
                                </tr>
                                <tr>
                                    <th>Создан:</th>
                                    <td><?php echo format_date($product['created_at']); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($product['description']): ?>
                    <div class="mt-3">
                        <h6>Описание:</h6>
                        <div class="border rounded p-3 bg-light">
                            <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Остатки по местам хранения -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Остатки на складе</h5>
                    <span class="badge bg-primary">
                        <?php 
                        $total_stock = array_sum(array_column($stock_details, 'quantity'));
                        echo format_number($total_stock) . ' ' . $product['unit'];
                        ?>
                    </span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($stock_details)): ?>
                    <div class="text-center py-4">
                        <div class="text-muted">
                            <i class="bi bi-inbox display-6 d-block mb-2"></i>
                            Товара нет в наличии
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Место хранения</th>
                                    <th>Партия</th>
                                    <th class="text-end">Срок годности</th>
                                    <th class="text-end">Количество</th>
                                    <th class="text-end">Резерв</th>
                                    <th class="text-end">Свободно</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stock_details as $stock): 
                                    $expiry_status = check_expiry($stock['expiry_date'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($stock['location_code']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($stock['location_name']); ?></div>
                                        <span class="badge bg-secondary"><?php echo $stock['location_zone']; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($stock['batch_number']): ?>
                                        <code><?php echo htmlspecialchars($stock['batch_number']); ?></code>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($stock['expiry_date']): ?>
                                        <div><?php echo format_date($stock['expiry_date'], 'd.m.Y'); ?></div>
                                        <?php if ($expiry_status['status'] !== 'ok'): ?>
                                        <div class="small text-<?php echo $expiry_status['status'] === 'expired' ? 'danger' : 'warning'; ?>">
                                            <?php echo $expiry_status['status'] === 'expired' ? 'Просрочен' : 'Осталось ' . $expiry_status['days'] . ' дн.'; ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold"><?php echo format_number($stock['quantity']); ?></td>
                                    <td class="text-end"><?php echo format_number($stock['reserved_quantity']); ?></td>
                                    <td class="text-end text-success fw-bold">
                                        <?php echo format_number($stock['quantity'] - $stock['reserved_quantity']); ?>
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
        
        <div class="col-lg-4">
            <!-- Быстрая статистика -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="card-title mb-0">Быстрые действия</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="index.php?module=transactions&action=receipt&product_id=<?php echo $product_id; ?>" 
                           class="btn btn-success">
                            <i class="bi bi-plus-circle"></i> Принять на склад
                        </a>
                        <a href="index.php?module=transactions&action=shipment&product_id=<?php echo $product_id; ?>" 
                           class="btn btn-danger">
                            <i class="bi bi-dash-circle"></i> Отгрузить со склада
                        </a>
                        <a href="index.php?module=transactions&action=movement&product_id=<?php echo $product_id; ?>" 
                           class="btn btn-warning">
                            <i class="bi bi-arrow-left-right"></i> Переместить
                        </a>
                        <a href="index.php?module=transactions&action=inventory&product_id=<?php echo $product_id; ?>&action=count" 
                           class="btn btn-info">
                            <i class="bi bi-clipboard-check"></i> Инвентаризировать
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- История движения -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="card-title mb-0">Последние операции</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (empty($movement_stats)): ?>
                        <div class="list-group-item text-center text-muted py-4">
                            <i class="bi bi-activity display-6 d-block mb-2"></i>
                            Операций нет
                        </div>
                        <?php else: ?>
                        <?php foreach ($movement_stats as $stat): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold"><?php echo format_date($stat['date'], 'd.m.Y'); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($stat['operation_name']); ?></div>
                                </div>
                                <div class="text-end">
                                    <?php if ($stat['incoming'] > 0): ?>
                                    <div class="text-success fw-bold">+<?php echo format_number($stat['incoming']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($stat['outgoing'] > 0): ?>
                                    <div class="text-danger fw-bold">-<?php echo format_number($stat['outgoing']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="index.php?module=reports&action=stock&product_id=<?php echo $product_id; ?>" 
                       class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-clock-history"></i> Полная история
                    </a>
                </div>
            </div>
            
            <!-- QR код и информация -->
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">Идентификация</h6>
                </div>
                <div class="card-body text-center">
                    <div id="qrcode" class="mb-3"></div>
                    <div class="small text-muted">
                        <?php echo SITE_NAME; ?><br>
                        ID: <?php echo $product['id']; ?><br>
                        Обновлен: <?php echo format_date($product['updated_at'] ?? $product['created_at']); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Подключение библиотеки QR Code -->
<script src="https://cdn.jsdelivr.net/npm/qrcodejs/qrcode.min.js"></script>
<script>
// Генерация QR кода
document.addEventListener('DOMContentLoaded', function() {
    const qrData = {
        type: 'product',
        id: <?php echo $product['id']; ?>,
        article: '<?php echo $product['article']; ?>',
        name: '<?php echo addslashes($product['name']); ?>',
        url: window.location.href
    };
    
    new QRCode(document.getElementById("qrcode"), {
        text: JSON.stringify(qrData),
        width: 150,
        height: 150,
        colorDark : "#000000",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
    });
});

// Печать карточки товара
function printProductCard() {
    const printContent = `
        <div style="padding: 20px; font-family: Arial, sans-serif;">
            <h2>Карточка товара</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #000;"><strong>Артикул:</strong></td>
                    <td style="padding: 10px; border: 1px solid #000;"><?php echo $product['article']; ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #000;"><strong>Наименование:</strong></td>
                    <td style="padding: 10px; border: 1px solid #000;"><?php echo $product['name']; ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #000;"><strong>Ед. изм.:</strong></td>
                    <td style="padding: 10px; border: 1px solid #000;"><?php echo $product['unit']; ?></td>
                </tr>
            </table>
            <p>Сформировано: <?php echo date('d.m.Y H:i:s'); ?></p>
        </div>
    `;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.print();
}
</script>

<style>
@media print {
    .sidebar, .navbar, .btn, .breadcrumb, .card-header .btn-group {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>