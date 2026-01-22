<?php
// Проверка прав
check_role(['admin', 'manager', 'storekeeper']);

$page_title = 'Приемка товара';

// Инициализация сессии для временного хранения товаров
if (!isset($_SESSION['receipt_session'])) {
    $_SESSION['receipt_session'] = [
        'items' => [],
        'supplier' => '',
        'document_number' => 'ПРИХ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'document_date' => date('Y-m-d')
    ];
}

$receipt_session = &$_SESSION['receipt_session'];

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_csrf();
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_item':
            $product_id = (int)$_POST['product_id'];
            $quantity = (float)$_POST['quantity'];
            $batch_number = clean_input($_POST['batch_number'] ?? '');
            $location_id = (int)$_POST['location_id'];
            $purchase_price = (float)($_POST['purchase_price'] ?? 0);
            $supplier = clean_input($_POST['supplier'] ?? '');
            $expiry_date = $_POST['expiry_date'] ?? null;
            
            // Валидация
            if ($product_id <= 0 || $quantity <= 0 || $location_id <= 0) {
                $_SESSION['error'] = 'Заполните обязательные поля правильно';
                break;
            }
            
            // Получение информации о товаре
            $product = db_fetch_one("
                SELECT id, article, name, unit 
                FROM products 
                WHERE id = ? AND is_active = 1 AND is_deleted = 0
            ", [$product_id]);
            
            if (!$product) {
                $_SESSION['error'] = 'Товар не найден или неактивен';
                break;
            }
            
            // Получение информации о месте хранения
            $location = db_fetch_one("
                SELECT id, code, name 
                FROM storage_locations 
                WHERE id = ? AND is_active = 1
            ", [$location_id]);
            
            if (!$location) {
                $_SESSION['error'] = 'Место хранения не найдено';
                break;
            }
            
            // Добавление в сессию
            $item = [
                'id' => uniqid(),
                'product_id' => $product_id,
                'product_article' => $product['article'],
                'product_name' => $product['name'],
                'product_unit' => $product['unit'],
                'quantity' => $quantity,
                'batch_number' => $batch_number,
                'location_id' => $location_id,
                'location_code' => $location['code'],
                'location_name' => $location['name'],
                'purchase_price' => $purchase_price,
                'supplier' => $supplier,
                'expiry_date' => $expiry_date,
                'total_price' => $purchase_price * $quantity,
                'added_at' => date('Y-m-d H:i:s')
            ];
            
            $receipt_session['items'][] = $item;
            $receipt_session['supplier'] = $supplier;
            
            $_SESSION['success'] = 'Товар добавлен в приемку';
            break;
            
        case 'remove_item':
            $item_id = $_POST['item_id'] ?? '';
            if ($item_id) {
                $receipt_session['items'] = array_filter(
                    $receipt_session['items'],
                    function($item) use ($item_id) {
                        return $item['id'] !== $item_id;
                    }
                );
                $_SESSION['success'] = 'Позиция удалена';
            }
            break;
            
        case 'clear_all':
            $receipt_session['items'] = [];
            $_SESSION['success'] = 'Список товаров очищен';
            break;
            
        case 'complete_receipt':
            if (empty($receipt_session['items'])) {
                $_SESSION['error'] = 'Нет товаров для приемки';
                break;
            }
            
            try {
                $db = Database::getConnection();
                $db->beginTransaction();
                
                foreach ($receipt_session['items'] as $item) {
                    // 1. Создаем или находим партию
                    $batch_id = null;
                    if (!empty($item['batch_number'])) {
                        $batch = db_fetch_one("
                            SELECT id FROM batches 
                            WHERE product_id = ? AND batch_number = ?
                        ", [$item['product_id'], $item['batch_number']]);
                        
                        if ($batch) {
                            $batch_id = $batch['id'];
                            // Обновляем существующую партию
                            db_query("
                                UPDATE batches 
                                SET current_quantity = current_quantity + ?,
                                    initial_quantity = initial_quantity + ?
                                WHERE id = ?
                            ", [$item['quantity'], $item['quantity'], $batch_id]);
                        } else {
                            // Создаем новую партию
                            $batch_id = db_insert('batches', [
                                'product_id' => $item['product_id'],
                                'batch_number' => $item['batch_number'],
                                'initial_quantity' => $item['quantity'],
                                'current_quantity' => $item['quantity'],
                                'supplier' => $item['supplier'],
                                'purchase_price' => $item['purchase_price'],
                                'expiry_date' => $item['expiry_date'] ?: null,
                                'production_date' => date('Y-m-d')
                            ]);
                        }
                    }
                    
                    // 2. Создаем транзакцию
                    $transaction_id = db_insert('inventory_transactions', [
                        'product_id' => $item['product_id'],
                        'batch_id' => $batch_id,
                        'location_id_to' => $item['location_id'],
                        'quantity' => $item['quantity'],
                        'operation_type' => 'receipt',
                        'document_number' => $receipt_session['document_number'],
                        'document_date' => $receipt_session['document_date'],
                        'user_id' => $_SESSION['user_id'],
                        'comments' => 'Приемка от поставщика: ' . $item['supplier']
                    ]);
                    
                    // 3. Обновляем остатки
                    $stock = db_fetch_one("
                        SELECT id, quantity 
                        FROM stock_balances 
                        WHERE product_id = ? AND batch_id = ? AND location_id = ?
                    ", [$item['product_id'], $batch_id, $item['location_id']]);
                    
                    if ($stock) {
                        db_query("
                            UPDATE stock_balances 
                            SET quantity = quantity + ? 
                            WHERE id = ?
                        ", [$item['quantity'], $stock['id']]);
                    } else {
                        db_insert('stock_balances', [
                            'product_id' => $item['product_id'],
                            'batch_id' => $batch_id,
                            'location_id' => $item['location_id'],
                            'quantity' => $item['quantity']
                        ]);
                    }
                }
                
                $db->commit();
                
                // Логирование
                log_action('RECEIPT_COMPLETE', 
                    "Приемка товаров по документу {$receipt_session['document_number']} ({$item['supplier']})");
                
                // Очистка сессии
                $completed_doc = $receipt_session['document_number'];
                unset($_SESSION['receipt_session']);
                
                $_SESSION['success'] = "Приемка завершена. Документ: {$completed_doc}";
                header('Location: index.php?module=transactions&action=receipt_completed&doc=' . urlencode($completed_doc));
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = 'Ошибка при сохранении приемки: ' . $e->getMessage();
            }
            break;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?module=transactions&action=receipt');
    exit();
}

// Получение данных для форм
$products = db_fetch_all("
    SELECT id, article, name, unit 
    FROM products 
    WHERE is_active = 1 AND is_deleted = 0 
    ORDER BY article
");

$locations = db_fetch_all("
    SELECT id, code, name, zone 
    FROM storage_locations 
    WHERE is_active = 1 AND zone IN ('receiving', 'storage') 
    ORDER BY zone, code
");

// Расчет итогов
$total_items = count($receipt_session['items']);
$total_quantity = 0;
$total_value = 0;

foreach ($receipt_session['items'] as $item) {
    $total_quantity += $item['quantity'];
    $total_value += $item['total_price'];
}
?>

<div class="row">
    <div class="col-lg-8">
        <!-- Форма добавления товара -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Добавление товара</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="addItemForm">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Товар *</label>
                            <select class="form-select" id="product_id" name="product_id" required>
                                <option value="">Выберите товар...</option>
                                <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['article'] . ' - ' . $product['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Количество *</label>
                            <input type="number" class="form-control" name="quantity" 
                                   step="0.001" min="0.001" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Ед. изм.</label>
                            <input type="text" class="form-control" id="product_unit" readonly>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Номер партии</label>
                            <input type="text" class="form-control" name="batch_number" 
                                   placeholder="Необязательно">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Срок годности</label>
                            <input type="date" class="form-control" name="expiry_date">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Цена закупки</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="purchase_price" 
                                       step="0.01" min="0">
                                <span class="input-group-text">₽</span>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Место хранения *</label>
                            <select class="form-select" name="location_id" required>
                                <option value="">Выберите место...</option>
                                <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>">
                                    <?php echo htmlspecialchars($location['code'] . ' - ' . $location['name']); ?>
                                    (<?php echo $location['zone']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Поставщик *</label>
                            <input type="text" class="form-control" name="supplier" 
                                   value="<?php echo htmlspecialchars($receipt_session['supplier']); ?>" 
                                   required>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> Добавить в приемку
                            </button>
                            <button type="reset" class="btn btn-secondary">Очистить форму</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Список товаров в приемке -->
        <?php if (!empty($receipt_session['items'])): ?>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Товары к приемке</h5>
                <div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="clear_all">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" 
                                onclick="return confirm('Очистить весь список товаров?')">
                            <i class="bi bi-trash"></i> Очистить все
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Товар</th>
                                <th class="text-end">Кол-во</th>
                                <th>Партия</th>
                                <th>Место</th>
                                <th class="text-end">Стоимость</th>
                                <th class="text-center">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receipt_session['items'] as $item): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($item['product_article']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    <div class="small"><?php echo $item['product_unit']; ?></div>
                                </td>
                                <td class="text-end fw-bold"><?php echo format_number($item['quantity']); ?></td>
                                <td>
                                    <?php if ($item['batch_number']): ?>
                                    <code><?php echo htmlspecialchars($item['batch_number']); ?></code>
                                    <?php if ($item['expiry_date']): ?>
                                    <br><small class="text-muted">Годен до: <?php echo $item['expiry_date']; ?></small>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($item['location_code']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($item['location_name']); ?></div>
                                </td>
                                <td class="text-end">
                                    <?php if ($item['purchase_price'] > 0): ?>
                                    <div class="fw-bold"><?php echo format_number($item['total_price'], 2); ?> ₽</div>
                                    <div class="small text-muted"><?php echo format_number($item['purchase_price'], 2); ?> ₽/ед.</div>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="remove_item">
                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                onclick="return confirm('Удалить эту позицию?')">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="1">Итого:</th>
                                <th class="text-end"><?php echo format_number($total_quantity); ?></th>
                                <th colspan="2"></th>
                                <th class="text-end fw-bold">
                                    <?php echo $total_value > 0 ? format_number($total_value, 2) . ' ₽' : '—'; ?>
                                </th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <form method="POST">
                    <input type="hidden" name="action" value="complete_receipt">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <button type="submit" class="btn btn-success w-100 py-2" 
                            onclick="return confirm('Завершить приемку? Все товары будут оприходованы.')">
                        <i class="bi bi-check-circle"></i> Завершить приемку
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-4">
        <!-- Информация о документе -->
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Информация о приемке</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6>Документ:</h6>
                    <div class="alert alert-info mb-0">
                        <div class="fw-bold"><?php echo $receipt_session['document_number']; ?></div>
                        <small>Дата: <?php echo format_date($receipt_session['document_date']); ?></small>
                    </div>
                </div>
                
                <div class="mb-3">
                    <h6>Статистика:</h6>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Позиций:</span>
                            <span class="fw-bold"><?php echo $total_items; ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Количество:</span>
                            <span class="fw-bold"><?php echo format_number($total_quantity); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Общая стоимость:</span>
                            <span class="fw-bold"><?php echo $total_value > 0 ? format_number($total_value, 2) . ' ₽' : '—'; ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Ответственный:</span>
                            <span class="fw-bold"><?php echo $_SESSION['full_name'] ?? 'Неизвестно'; ?></span>
                        </li>
                    </ul>
                </div>
                
                <div class="mb-3">
                    <h6>Быстрые действия:</h6>
                    <div class="d-grid gap-2">
                        <a href="index.php?module=products&action=add" class="btn btn-outline-primary">
                            <i class="bi bi-plus-circle"></i> Добавить новый товар
                        </a>
                        <a href="index.php?module=storage&action=locations" class="btn btn-outline-secondary">
                            <i class="bi bi-geo-alt"></i> Управление местами
                        </a>
                        <a href="index.php?module=transactions&action=shipment" class="btn btn-outline-danger">
                            <i class="bi bi-dash-circle"></i> Перейти к отгрузке
                        </a>
                    </div>
                </div>
                
                <div class="mt-3">
                    <h6>История за сегодня:</h6>
                    <?php
                    $today_receipts = db_fetch_all("
                        SELECT COUNT(*) as count, SUM(quantity) as total 
                        FROM inventory_transactions 
                        WHERE DATE(created_at) = CURDATE() 
                        AND operation_type = 'receipt'
                        AND user_id = ?
                    ", [$_SESSION['user_id']]);
                    ?>
                    <div class="alert alert-light">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="fw-bold"><?php echo $today_receipts[0]['count'] ?? 0; ?></div>
                                <small class="text-muted">приемок</small>
                            </div>
                            <div class="col-6">
                                <div class="fw-bold"><?php echo format_number($today_receipts[0]['total'] ?? 0); ?></div>
                                <small class="text-muted">единиц</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Последние приемки -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="card-title mb-0">Последние приемки</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php
                    $recent_receipts = db_fetch_all("
                        SELECT DISTINCT document_number, COUNT(*) as items, 
                               SUM(quantity) as total_qty, MAX(created_at) as last_date
                        FROM inventory_transactions 
                        WHERE operation_type = 'receipt'
                        GROUP BY document_number 
                        ORDER BY last_date DESC 
                        LIMIT 5
                    ");
                    
                    foreach ($recent_receipts as $receipt):
                    ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="fw-bold"><?php echo $receipt['document_number']; ?></div>
                                <small class="text-muted"><?php echo format_date($receipt['last_date'], 'H:i'); ?></small>
                            </div>
                            <div class="text-end">
                                <div class="badge bg-info"><?php echo $receipt['items']; ?> поз.</div>
                                <div class="small"><?php echo format_number($receipt['total_qty']); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Динамическое обновление единицы измерения при выборе товара
document.getElementById('product_id').addEventListener('change', function() {
    const productId = this.value;
    const productUnit = document.getElementById('product_unit');
    
    if (productId) {
        // Здесь можно добавить AJAX запрос для получения данных о товаре
        // Для простоты показываем пустое поле
        productUnit.value = '';
    } else {
        productUnit.value = '';
    }
});

// Валидация формы
document.getElementById('addItemForm').addEventListener('submit', function(e) {
    const quantity = this.elements['quantity'].value;
    if (parseFloat(quantity) <= 0) {
        e.preventDefault();
        alert('Количество должно быть больше 0');
        this.elements['quantity'].focus();
    }
});

// Автозаполнение даты срока годности (через 1 год по умолчанию)
const expiryDateInput = document.querySelector('input[name="expiry_date"]');
if (expiryDateInput && !expiryDateInput.value) {
    const nextYear = new Date();
    nextYear.setFullYear(nextYear.getFullYear() + 1);
    expiryDateInput.value = nextYear.toISOString().split('T')[0];
}
</script>