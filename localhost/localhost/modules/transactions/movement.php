<?php
// Проверка прав
check_role(['admin', 'manager', 'storekeeper']);

$page_title = 'Перемещение товаров';

// Инициализация сессии для перемещения
if (!isset($_SESSION['movement_session'])) {
    $_SESSION['movement_session'] = [
        'items' => [],
        'document_number' => 'ПЕРЕМ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'document_date' => date('Y-m-d'),
        'comments' => ''
    ];
}

$movement_session = &$_SESSION['movement_session'];

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_csrf();
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_item':
            $product_id = (int)$_POST['product_id'];
            $quantity = (float)$_POST['quantity'];
            $batch_id = (int)($_POST['batch_id'] ?? 0);
            $location_from = (int)$_POST['location_from'];
            $location_to = (int)$_POST['location_to'];
            $comments = clean_input($_POST['comments'] ?? '');
            
            // Валидация
            if ($product_id <= 0 || $quantity <= 0 || $location_from <= 0 || $location_to <= 0) {
                $_SESSION['error'] = 'Заполните обязательные поля правильно';
                break;
            }
            
            if ($location_from == $location_to) {
                $_SESSION['error'] = 'Места отправления и получения должны быть разными';
                break;
            }
            
            // Проверка наличия товара на исходном месте
            $available = db_fetch_one("
                SELECT COALESCE(SUM(quantity), 0) as available
                FROM stock_balances 
                WHERE product_id = ? AND location_id = ?
            ", [$product_id, $location_from]);
            
            if ($available['available'] < $quantity) {
                $_SESSION['error'] = 'Недостаточно товара на исходном месте. Доступно: ' . $available['available'];
                break;
            }
            
            // Получение информации о товаре
            $product = db_fetch_one("
                SELECT id, article, name, unit 
                FROM products 
                WHERE id = ? AND is_active = 1
            ", [$product_id]);
            
            if (!$product) {
                $_SESSION['error'] = 'Товар не найден';
                break;
            }
            
            // Получение информации о местах хранения
            $location_from_info = db_fetch_one("
                SELECT id, code, name 
                FROM storage_locations 
                WHERE id = ? AND is_active = 1
            ", [$location_from]);
            
            $location_to_info = db_fetch_one("
                SELECT id, code, name 
                FROM storage_locations 
                WHERE id = ? AND is_active = 1
            ", [$location_to]);
            
            if (!$location_from_info || !$location_to_info) {
                $_SESSION['error'] = 'Место хранения не найдено';
                break;
            }
            
            // Получение информации о партии
            $batch_info = null;
            if ($batch_id > 0) {
                $batch_info = db_fetch_one("
                    SELECT batch_number 
                    FROM batches 
                    WHERE id = ?
                ", [$batch_id]);
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
                'location_from_id' => $location_from,
                'location_from_code' => $location_from_info['code'],
                'location_from_name' => $location_from_info['name'],
                'location_to_id' => $location_to,
                'location_to_code' => $location_to_info['code'],
                'location_to_name' => $location_to_info['name'],
                'comments' => $comments,
                'added_at' => date('Y-m-d H:i:s')
            ];
            
            $movement_session['items'][] = $item;
            $movement_session['comments'] = $comments;
            
            $_SESSION['success'] = 'Товар добавлен в перемещение';
            break;
            
        case 'remove_item':
            $item_id = $_POST['item_id'] ?? '';
            if ($item_id) {
                $movement_session['items'] = array_filter(
                    $movement_session['items'],
                    function($item) use ($item_id) {
                        return $item['id'] !== $item_id;
                    }
                );
                $_SESSION['success'] = 'Позиция удалена';
            }
            break;
            
        case 'clear_all':
            $movement_session['items'] = [];
            $_SESSION['success'] = 'Список товаров очищен';
            break;
            
        case 'complete_movement':
            if (empty($movement_session['items'])) {
                $_SESSION['error'] = 'Нет товаров для перемещения';
                break;
            }
            
            try {
                $db = Database::getConnection();
                $db->beginTransaction();
                
                // Создаем документ
                $document_id = db_insert('documents', [
                    'document_number' => $movement_session['document_number'],
                    'operation_type_id' => 3, // MOVEMENT
                    'document_date' => $movement_session['document_date'],
                    'warehouse_from' => 1, // Склад по умолчанию
                    'warehouse_to' => 1,
                    'status' => 'completed',
                    'created_by' => $_SESSION['user_id'],
                    'completed_at' => date('Y-m-d H:i:s'),
                    'comments' => $movement_session['comments']
                ]);
                
                foreach ($movement_session['items'] as $item) {
                    // Проверяем наличие перед перемещением
                    $available = db_fetch_one("
                        SELECT COALESCE(SUM(quantity), 0) as available
                        FROM stock_balances 
                        WHERE product_id = ? AND location_id = ?
                    ", [$item['product_id'], $item['location_from_id']]);
                    
                    if ($available['available'] < $item['quantity']) {
                        throw new Exception("Недостаточно товара {$item['product_article']} на месте {$item['location_from_code']}");
                    }
                    
                    // Создаем транзакцию
                    $transaction_id = db_insert('inventory_transactions', [
                        'document_id' => $document_id,
                        'product_id' => $item['product_id'],
                        'batch_id' => $item['batch_id'] > 0 ? $item['batch_id'] : null,
                        'location_id_from' => $item['location_from_id'],
                        'location_id_to' => $item['location_to_id'],
                        'quantity' => $item['quantity']
                    ]);
                    
                    // Списание с исходного места
                    if ($item['batch_id'] > 0) {
                        // Перемещение конкретной партии
                        $updated = db_query("
                            UPDATE stock_balances 
                            SET quantity = quantity - ? 
                            WHERE product_id = ? AND batch_id = ? AND location_id = ? AND quantity >= ?
                        ", [$item['quantity'], $item['product_id'], $item['batch_id'], $item['location_from_id'], $item['quantity']]);
                        
                        if (!$updated) {
                            throw new Exception("Не удалось списать партию {$item['batch_number']}");
                        }
                    } else {
                        // Перемещение без указания партии (FIFO)
                        $updated = db_query("
                            UPDATE stock_balances 
                            SET quantity = quantity - ? 
                            WHERE product_id = ? AND location_id = ? AND quantity >= ?
                            ORDER BY created_at
                            LIMIT 1
                        ", [$item['quantity'], $item['product_id'], $item['location_from_id'], $item['quantity']]);
                        
                        if (!$updated) {
                            throw new Exception("Не удалось списать товар {$item['product_article']}");
                        }
                    }
                    
                    // Поступление на новое место
                    $existing = db_fetch_one("
                        SELECT id, quantity 
                        FROM stock_balances 
                        WHERE product_id = ? AND batch_id = ? AND location_id = ?
                    ", [$item['product_id'], $item['batch_id'] > 0 ? $item['batch_id'] : null, $item['location_to_id']]);
                    
                    if ($existing) {
                        db_query("
                            UPDATE stock_balances 
                            SET quantity = quantity + ? 
                            WHERE id = ?
                        ", [$item['quantity'], $existing['id']]);
                    } else {
                        db_insert('stock_balances', [
                            'product_id' => $item['product_id'],
                            'batch_id' => $item['batch_id'] > 0 ? $item['batch_id'] : null,
                            'location_id' => $item['location_to_id'],
                            'quantity' => $item['quantity']
                        ]);
                    }
                }
                
                $db->commit();
                
                // Логирование
                log_action('MOVEMENT_COMPLETE', 
                    "Перемещение товаров по документу {$movement_session['document_number']}");
                
                // Очистка сессии
                $completed_doc = $movement_session['document_number'];
                unset($_SESSION['movement_session']);
                
                $_SESSION['success'] = "Перемещение завершено. Документ: {$completed_doc}";
                header('Location: index.php?module=transactions&action=movement_completed&doc=' . urlencode($completed_doc));
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = 'Ошибка при перемещении: ' . $e->getMessage();
            }
            break;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?module=transactions&action=movement');
    exit();
}

// Получение товаров с остатками
$products = db_fetch_all("
    SELECT p.id, p.article, p.name, p.unit
    FROM products p
    WHERE p.is_active = 1 AND p.is_deleted = 0
    AND EXISTS (
        SELECT 1 FROM stock_balances sb 
        WHERE sb.product_id = p.id AND sb.quantity > 0
    )
    ORDER BY p.article
");

$locations = db_fetch_all("
    SELECT id, code, name, zone 
    FROM storage_locations 
    WHERE is_active = 1 
    ORDER BY zone, code
");

// Расчет итогов
$total_items = count($movement_session['items']);
$total_quantity = 0;

foreach ($movement_session['items'] as $item) {
    $total_quantity += $item['quantity'];
}
?>

<div class="row">
    <div class="col-lg-8">
        <!-- Форма добавления товара -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Добавление товара в перемещение</h5>
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
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Место отправления *</label>
                            <select class="form-select" id="location_from" name="location_from" required>
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
                            <label class="form-label">Место получения *</label>
                            <select class="form-select" name="location_to" required>
                                <option value="">Выберите место...</option>
                                <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>">
                                    <?php echo htmlspecialchars($location['code'] . ' - ' . $location['name']); ?>
                                    (<?php echo $location['zone']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Комментарий</label>
                            <textarea class="form-control" name="comments" rows="2" 
                                      placeholder="Причина перемещения..."><?php echo htmlspecialchars($movement_session['comments']); ?></textarea>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> Добавить в перемещение
                            </button>
                            <button type="reset" class="btn btn-secondary">Очистить форму</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Список товаров в перемещении -->
        <?php if (!empty($movement_session['items'])): ?>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Товары к перемещению</h5>
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
                                <th>Откуда</th>
                                <th>Куда</th>
                                <th class="text-center">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movement_session['items'] as $item): ?>
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
                                    <div class="fw-bold text-danger"><?php echo htmlspecialchars($item['location_from_code']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($item['location_from_name']); ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-success"><?php echo htmlspecialchars($item['location_to_code']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($item['location_to_name']); ?></div>
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
                                <th colspan="4"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <form method="POST">
                    <input type="hidden" name="action" value="complete_movement">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <button type="submit" class="btn btn-success w-100 py-2" 
                            onclick="return confirm('Завершить перемещение? Товары будут перемещены между местами хранения.')">
                        <i class="bi bi-check-circle"></i> Завершить перемещение
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-4">
        <!-- Информация о перемещении -->
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Информация о перемещении</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6>Документ:</h6>
                    <div class="alert alert-info mb-0">
                        <div class="fw-bold"><?php echo $movement_session['document_number']; ?></div>
                        <small>Дата: <?php echo format_date($movement_session['document_date']); ?></small>
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
                            <span>Комментарий:</span>
                            <span class="text-muted"><?php echo htmlspecialchars($movement_session['comments']); ?></span>
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
                        <a href="index.php?module=storage&action=locations" class="btn btn-outline-secondary">
                            <i class="bi bi-geo-alt"></i> Управление местами
                        </a>
                        <button type="button" class="btn btn-outline-info" onclick="generateTaskList()">
                            <i class="bi bi-list-task"></i> Создать задание
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Карта перемещений -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="card-title mb-0">Схема перемещений</h6>
            </div>
            <div class="card-body">
                <div class="text-center">
                    <div class="mb-3">
                        <div class="text-danger">
                            <i class="bi bi-arrow-up-circle fs-4"></i>
                            <div class="small">Отправление</div>
                        </div>
                        
                        <div class="my-2">
                            <i class="bi bi-arrow-down fs-3"></i>
                        </div>
                        
                        <div class="text-success">
                            <i class="bi bi-arrow-down-circle fs-4"></i>
                            <div class="small">Получение</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($movement_session['items'])): ?>
                    <div class="list-group list-group-flush">
                        <?php 
                        $unique_locations = [];
                        foreach ($movement_session['items'] as $item) {
                            $unique_locations[$item['location_from_id']] = $item['location_from_code'];
                            $unique_locations[$item['location_to_id']] = $item['location_to_code'];
                        }
                        ?>
                        
                        <div class="list-group-item">
                            <div class="fw-bold">Задействованные места:</div>
                            <div class="mt-1">
                                <?php foreach ($unique_locations as $code): ?>
                                <span class="badge bg-secondary me-1 mb-1"><?php echo htmlspecialchars($code); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Обновление информации о доступном количестве
document.getElementById('product_id').addEventListener('change', function() {
    const productId = this.value;
    const productSelect = this.options[this.selectedIndex];
    const unit = productSelect.text.split('(')[0].split('-').pop().trim();
    
    document.getElementById('product_unit').value = unit;
    
    if (productId) {
        updateAvailableQuantity();
        loadBatches(productId);
    }
});

// Обновление доступного количества при изменении места отправления
document.getElementById('location_from').addEventListener('change', function() {
    updateAvailableQuantity();
});

// Обновление доступного количества
function updateAvailableQuantity() {
    const productId = document.getElementById('product_id').value;
    const locationId = document.getElementById('location_from').value;
    const batchId = document.getElementById('batch_id').value;
    
    if (!productId || !locationId) {
        document.getElementById('available_text').textContent = 'Доступно: 0';
        return;
    }
    
    let url = `ajax.php?action=get_available_quantity&product_id=${productId}&location_id=${locationId}`;
    if (batchId && batchId != 0) {
        url += `&batch_id=${batchId}`;
    }
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            document.getElementById('available_text').textContent = 'Доступно: ' + data.available;
            document.getElementById('quantity').max = data.available;
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// Загрузка партий товара
function loadBatches(productId) {
    const batchSelect = document.getElementById('batch_id');
    const locationId = document.getElementById('location_from').value;
    
    if (!productId || !locationId) {
        batchSelect.innerHTML = '<option value="0">Любая партия</option>';
        return;
    }
    
    fetch(`ajax.php?action=get_batches_by_location&product_id=${productId}&location_id=${locationId}`)
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

// Обновление при выборе партии
document.getElementById('batch_id').addEventListener('change', function() {
    updateAvailableQuantity();
});

// Валидация формы
document.getElementById('addItemForm').addEventListener('submit', function(e) {
    const quantity = parseFloat(this.elements['quantity'].value);
    const max = parseFloat(this.elements['quantity'].max);
    const locationFrom = this.elements['location_from'].value;
    const locationTo = this.elements['location_to'].value;
    
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
    
    if (locationFrom == locationTo) {
        e.preventDefault();
        alert('Места отправления и получения должны быть разными');
        this.elements['location_to'].focus();
        return;
    }
});

// Создание задания на перемещение
function generateTaskList() {
    if (<?php echo $total_items; ?> === 0) {
        alert('Нет товаров для создания задания');
        return;
    }
    
    const items = <?php echo json_encode($movement_session['items']); ?>;
    let taskList = "ЗАДАНИЕ НА ПЕРЕМЕЩЕНИЕ\n";
    taskList += "Документ: <?php echo $movement_session['document_number']; ?>\n";
    taskList += "Дата: <?php echo date('d.m.Y'); ?>\n";
    taskList += "Ответственный: <?php echo $_SESSION['full_name']; ?>\n\n";
    
    items.forEach((item, index) => {
        taskList += `${index + 1}. ${item.product_article} - ${item.product_name}\n`;
        taskList += `   Количество: ${item.quantity} ${item.product_unit}\n`;
        taskList += `   Откуда: ${item.location_from_code} (${item.location_from_name})\n`;
        taskList += `   Куда: ${item.location_to_code} (${item.location_to_name})\n`;
        if (item.batch_number) {
            taskList += `   Партия: ${item.batch_number}\n`;
        }
        taskList += "\n";
    });
    
    // Открываем в новом окне для печати
    const printWindow = window.open('', '_blank');
    printWindow.document.write('<pre>' + taskList + '</pre>');
    printWindow.document.close();
    printWindow.print();
}
</script>