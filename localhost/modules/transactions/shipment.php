<?php
// Проверка прав
check_role(['admin', 'manager', 'storekeeper']);

$page_title = 'Отгрузка товара';

// Инициализация сессии для отгрузки
if (!isset($_SESSION['shipment_session'])) {
    $_SESSION['shipment_session'] = [
        'items' => [],
        'customer' => '',
        'document_number' => 'ОТГР-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'document_date' => date('Y-m-d')
    ];
}

$shipment_session = &$_SESSION['shipment_session'];

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_csrf();
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_item':
            $product_id = (int)$_POST['product_id'];
            $quantity = (float)$_POST['quantity'];
            $batch_id = (int)($_POST['batch_id'] ?? 0);
            $location_id = (int)$_POST['location_id'];
            $price = (float)($_POST['price'] ?? 0);
            $customer = clean_input($_POST['customer'] ?? '');
            
            // Валидация
            if ($product_id <= 0 || $quantity <= 0 || $location_id <= 0) {
                $_SESSION['error'] = 'Заполните обязательные поля правильно';
                break;
            }
            
            // Проверка наличия товара
            $product = db_fetch_one("
                SELECT p.id, p.article, p.name, p.unit, 
                       COALESCE(SUM(sb.quantity), 0) as available
                FROM products p
                LEFT JOIN stock_balances sb ON p.id = sb.product_id
                WHERE p.id = ? AND p.is_active = 1 AND p.is_deleted = 0
                GROUP BY p.id
            ", [$product_id]);
            
            if (!$product) {
                $_SESSION['error'] = 'Товар не найден';
                break;
            }
            
            if ($product['available'] < $quantity) {
                $_SESSION['error'] = 'Недостаточно товара на складе. Доступно: ' . $product['available'];
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
            
            // Получение информации о партии
            $batch_info = null;
            if ($batch_id > 0) {
                $batch_info = db_fetch_one("
                    SELECT batch_number, current_quantity 
                    FROM batches 
                    WHERE id = ? AND product_id = ?
                ", [$batch_id, $product_id]);
            }
            
            // Добавление в сессию
            $item = [
                'id' => uniqid(),
                'product_id' => $product_id,
                'product_article' => $product['article'],
                'product_name' => $product['name'],
                'product_unit' => $product['unit'],
                'quantity' => $quantity,
                'batch_id' => $batch_id,
                'batch_number' => $batch_info['batch_number'] ?? '',
                'location_id' => $location_id,
                'location_code' => $location['code'],
                'location_name' => $location['name'],
                'price' => $price,
                'customer' => $customer,
                'total' => $price * $quantity,
                'added_at' => date('Y-m-d H:i:s')
            ];
            
            $shipment_session['items'][] = $item;
            $shipment_session['customer'] = $customer;
            
            $_SESSION['success'] = 'Товар добавлен в отгрузку';
            break;
            
        case 'remove_item':
            $item_id = $_POST['item_id'] ?? '';
            if ($item_id) {
                $shipment_session['items'] = array_filter(
                    $shipment_session['items'],
                    function($item) use ($item_id) {
                        return $item['id'] !== $item_id;
                    }
                );
                $_SESSION['success'] = 'Позиция удалена';
            }
            break;
            
        case 'clear_all':
            $shipment_session['items'] = [];
            $_SESSION['success'] = 'Список товаров очищен';
            break;
            
        case 'complete_shipment':
            if (empty($shipment_session['items'])) {
                $_SESSION['error'] = 'Нет товаров для отгрузки';
                break;
            }
            
            try {
                $db = Database::getConnection();
                $db->beginTransaction();
                
                // Создаем документ
                $document_id = db_insert('documents', [
                    'document_number' => $shipment_session['document_number'],
                    'operation_type_id' => 2, // SHIPMENT
                    'document_date' => $shipment_session['document_date'],
                    'counterparty' => $shipment_session['customer'],
                    'warehouse_from' => 5, // Зона отгрузки по умолчанию
                    'status' => 'completed',
                    'created_by' => $_SESSION['user_id'],
                    'completed_at' => date('Y-m-d H:i:s'),
                    'total_amount' => array_sum(array_column($shipment_session['items'], 'total'))
                ]);
                
                foreach ($shipment_session['items'] as $item) {
                    // Проверяем наличие перед списанием
                    $available = db_fetch_one("
                        SELECT COALESCE(SUM(quantity), 0) as available
                        FROM stock_balances 
                        WHERE product_id = ? AND location_id = ?
                    ", [$item['product_id'], $item['location_id']]);
                    
                    if ($available['available'] < $item['quantity']) {
                        throw new Exception("Недостаточно товара {$item['product_article']} на месте {$item['location_code']}");
                    }
                    
                    // Создаем транзакцию
                    $transaction_id = db_insert('inventory_transactions', [
                        'document_id' => $document_id,
                        'product_id' => $item['product_id'],
                        'batch_id' => $item['batch_id'] > 0 ? $item['batch_id'] : null,
                        'location_id_from' => $item['location_id'],
                        'quantity' => -$item['quantity'],
                        'price' => $item['price']
                    ]);
                    
                    // Обновляем остатки
                    if ($item['batch_id'] > 0) {
                        // Списание с конкретной партии
                        db_query("
                            UPDATE stock_balances 
                            SET quantity = quantity - ? 
                            WHERE product_id = ? AND batch_id = ? AND location_id = ?
                        ", [$item['quantity'], $item['product_id'], $item['batch_id'], $item['location_id']]);
                        
                        // Обновляем количество в партии
                        db_query("
                            UPDATE batches 
                            SET current_quantity = current_quantity - ? 
                            WHERE id = ?
                        ", [$item['quantity'], $item['batch_id']]);
                    } else {
                        // Списание без указания партии (FIFO)
                        db_query("
                            UPDATE stock_balances 
                            SET quantity = quantity - ? 
                            WHERE product_id = ? AND location_id = ? AND quantity >= ?
                            ORDER BY 
                                CASE WHEN batch_id IS NOT NULL THEN 1 ELSE 2 END,
                                created_at
                            LIMIT 1
                        ", [$item['quantity'], $item['product_id'], $item['location_id'], $item['quantity']]);
                    }
                }
                
                $db->commit();
                
                // Логирование
                log_action('SHIPMENT_COMPLETE', 
                    "Отгрузка товаров по документу {$shipment_session['document_number']} ({$shipment_session['customer']})");
                
                // Очистка сессии и редирект
                $completed_doc = $shipment_session['document_number'];
                unset($_SESSION['shipment_session']);
                
                $_SESSION['success'] = "Отгрузка завершена. Документ: {$completed_doc}";
                header('Location: index.php?module=transactions&action=shipment_completed&doc=' . urlencode($completed_doc));
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = 'Ошибка при отгрузке: ' . $e->getMessage();
            }
            break;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?module=transactions&action=shipment');
    exit();
}

// Получение доступных товаров (с остатками)
$products = db_fetch_all("
    SELECT p.id, p.article, p.name, p.unit, 
           COALESCE(SUM(sb.quantity), 0) as available
    FROM products p
    LEFT JOIN stock_balances sb ON p.id = sb.product_id
    WHERE p.is_active = 1 AND p.is_deleted = 0
    GROUP BY p.id
    HAVING available > 0
    ORDER BY p.article
");

$locations = db_fetch_all("
    SELECT id, code, name, zone 
    FROM storage_locations 
    WHERE is_active = 1 AND zone IN ('storage', 'shipping') 
    ORDER BY zone, code
");

// Расчет итогов
$total_items = count($shipment_session['items']);
$total_quantity = 0;
$total_amount = 0;

foreach ($shipment_session['items'] as $item) {
    $total_quantity += $item['quantity'];
    $total_amount += $item['total'];
}
?>

<div class="row">
    <div class="col-lg-8">
        <!-- Форма добавления товара -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Добавление товара в отгрузку</h5>
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
                                <option value="<?php echo $product['id']; ?>" 
                                        data-available="<?php echo $product['available']; ?>">
                                    <?php echo htmlspecialchars($product['article'] . ' - ' . $product['name']); ?>
                                    (доступно: <?php echo format_number($product['available']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Количество *</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" 
                                   step="0.001" min="0.001" required>
                            <div class="form-text">
                                <span id="available_text">Доступно: 0</span>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Ед. изм.</label>
                            <input type="text" class="form-control" id="product_unit" readonly>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Партия (опционально)</label>
                            <select class="form-select" id="batch_id" name="batch_id">
                                <option value="0">Любая партия</option>
                                <!-- Заполнится через AJAX -->
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Место отгрузки *</label>
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
                            <label class="form-label">Цена продажи</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="price" 
                                       step="0.01" min="0" value="0">
                                <span class="input-group-text">₽</span>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Клиент *</label>
                            <input type="text" class="form-control" name="customer" 
                                   value="<?php echo htmlspecialchars($shipment_session['customer']); ?>" 
                                   required>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> Добавить в отгрузку
                            </button>
                            <button type="reset" class="btn btn-secondary">Очистить форму</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Список товаров в отгрузке -->
        <?php if (!empty($shipment_session['items'])): ?>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Товары к отгрузке</h5>
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
                                <th class="text-end">Цена</th>
                                <th class="text-end">Сумма</th>
                                <th class="text-center">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipment_session['items'] as $item): ?>
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
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($item['location_code']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($item['location_name']); ?></div>
                                </td>
                                <td class="text-end">
                                    <?php if ($item['price'] > 0): ?>
                                    <div class="fw-bold"><?php echo format_number($item['price'], 2); ?> ₽</div>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold">
                                    <?php echo $item['total'] > 0 ? format_number($item['total'], 2) . ' ₽' : '—'; ?>
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
                                <th colspan="3"></th>
                                <th class="text-end fw-bold">
                                    <?php echo format_number($total_amount, 2); ?> ₽
                                </th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <form method="POST">
                    <input type="hidden" name="action" value="complete_shipment">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <button type="submit" class="btn btn-success w-100 py-2" 
                            onclick="return confirm('Завершить отгрузку? Товары будут списаны со склада.')">
                        <i class="bi bi-check-circle"></i> Завершить отгрузку
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-4">
        <!-- Информация об отгрузке -->
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Информация об отгрузке</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6>Накладная:</h6>
                    <div class="alert alert-info mb-0">
                        <div class="fw-bold"><?php echo $shipment_session['document_number']; ?></div>
                        <small>Дата: <?php echo format_date($shipment_session['document_date']); ?></small>
                    </div>
                </div>
                
                <div class="mb-3">
                    <h6>Клиент:</h6>
                    <div class="fw-bold"><?php echo htmlspecialchars($shipment_session['customer']); ?></div>
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
                            <span>Общая сумма:</span>
                            <span class="fw-bold"><?php echo format_number($total_amount, 2); ?> ₽</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Ответственный:</span>
                            <span class="fw-bold"><?php echo $_SESSION['full_name']; ?></span>
                        </li>
                    </ul>
                </div>
                
                <div class="mb-3">
                    <h6>Быстрые действия:</h6>
                    <div class="d-grid gap-2">
                        <a href="index.php?module=reports&action=stock" class="btn btn-outline-primary">
                            <i class="bi bi-box"></i> Проверить остатки
                        </a>
                        <a href="index.php?module=transactions&action=receipt" class="btn btn-outline-success">
                            <i class="bi bi-plus-circle"></i> Перейти к приемке
                        </a>
                        <button type="button" class="btn btn-outline-secondary" onclick="printPackingList()">
                            <i class="bi bi-printer"></i> Печать упаковочного листа
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Последние отгрузки -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="card-title mb-0">Последние отгрузки</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php
                    $recent_shipments = db_fetch_all("
                        SELECT d.document_number, d.counterparty, d.total_amount, 
                               COUNT(it.id) as items, d.completed_at
                        FROM documents d
                        LEFT JOIN inventory_transactions it ON d.id = it.document_id
                        WHERE d.operation_type_id = 2 AND d.status = 'completed'
                        GROUP BY d.id
                        ORDER BY d.completed_at DESC 
                        LIMIT 5
                    ");
                    
                    foreach ($recent_shipments as $shipment):
                    ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="fw-bold"><?php echo $shipment['document_number']; ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($shipment['counterparty']); ?></small>
                            </div>
                            <div class="text-end">
                                <div class="badge bg-info"><?php echo $shipment['items']; ?> поз.</div>
                                <div class="fw-bold"><?php echo format_number($shipment['total_amount'], 2); ?> ₽</div>
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
// Обновление информации о доступном количестве
document.getElementById('product_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const available = selectedOption.dataset.available || 0;
    const unit = selectedOption.text.split('(')[0].split('-').pop().trim();
    
    document.getElementById('available_text').textContent = 'Доступно: ' + available;
    document.getElementById('product_unit').value = unit;
    document.getElementById('quantity').max = available;
    
    // Загрузка партий для выбранного товара
    if (this.value) {
        loadBatches(this.value);
    }
});

// Загрузка партий товара
function loadBatches(productId) {
    const batchSelect = document.getElementById('batch_id');
    
    fetch(`ajax.php?action=get_batches&product_id=${productId}`)
        .then(response => response.json())
        .then(data => {
            batchSelect.innerHTML = '<option value="0">Любая партия</option>';
            
            data.forEach(batch => {
                const option = document.createElement('option');
                option.value = batch.id;
                option.textContent = `${batch.batch_number} (доступно: ${batch.available})`;
                option.dataset.available = batch.available;
                batchSelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// Обновление максимального количества при выборе партии
document.getElementById('batch_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const available = selectedOption.dataset.available || 0;
    const productSelect = document.getElementById('product_id');
    const productAvailable = productSelect.options[productSelect.selectedIndex].dataset.available || 0;
    
    const maxAvailable = this.value != 0 ? available : productAvailable;
    document.getElementById('quantity').max = maxAvailable;
    document.getElementById('available_text').textContent = 'Доступно: ' + maxAvailable;
});

// Валидация формы
document.getElementById('addItemForm').addEventListener('submit', function(e) {
    const quantity = parseFloat(this.elements['quantity'].value);
    const max = parseFloat(this.elements['quantity'].max);
    
    if (quantity <= 0) {
        e.preventDefault();
        alert('Количество должно быть больше 0');
        this.elements['quantity'].focus();
        return;
    }
    
    if (quantity > max) {
        e.preventDefault();
        alert(`Недостаточно товара. Максимально доступно: ${max}`);
        this.elements['quantity'].focus();
        return;
    }
    
    const customer = this.elements['customer'].value.trim();
    if (!customer) {
        e.preventDefault();
        alert('Укажите клиента');
        this.elements['customer'].focus();
    }
});

// Печать упаковочного листа
function printPackingList() {
    if (<?php echo $total_items; ?> === 0) {
        alert('Нет товаров для печати');
        return;
    }
    
    // Здесь можно реализовать печать упаковочного листа
    // Например, открыть новое окно с печатной формой
    window.open('print_packing_list.php', '_blank');
}
</script>