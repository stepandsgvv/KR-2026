<?php
// Проверка прав
check_role(['admin', 'manager', 'storekeeper']);

$page_title = 'Инвентаризация';

// Получение параметров
$location_id = (int)($_GET['location_id'] ?? 0);
$category_id = (int)($_GET['category_id'] ?? 0);
$product_id = (int)($_GET['product_id'] ?? 0);
$action = clean_input($_GET['action'] ?? 'list');

// Если передан product_id, то показываем форму для инвентаризации этого товара
if ($product_id > 0 && $action == 'count') {
    // Получаем информацию о товаре
    $product = db_fetch_one("
        SELECT p.*, c.name as category_name,
               COALESCE(SUM(sb.quantity), 0) as system_quantity
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN stock_balances sb ON p.id = sb.product_id
        WHERE p.id = ? AND p.is_active = 1 AND p.is_deleted = 0
        GROUP BY p.id
    ", [$product_id]);
    
    if (!$product) {
        $_SESSION['error'] = 'Товар не найден';
        header('Location: index.php?module=transactions&action=inventory');
        exit();
    }
    
    // Получаем остатки по местам хранения
    $stock_locations = db_fetch_all("
        SELECT sb.location_id, sl.code, sl.name, sb.quantity
        FROM stock_balances sb
        JOIN storage_locations sl ON sb.location_id = sl.id
        WHERE sb.product_id = ? AND sb.quantity > 0
        ORDER BY sl.code
    ", [$product_id]);
    
    // Обработка формы подсчета
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        check_csrf();
        
        $actual_quantities = $_POST['actual'] ?? [];
        $comments = clean_input($_POST['comments'] ?? '');
        
        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            
            // Создаем документ инвентаризации
            $document_number = 'ИНВ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $document_id = db_insert('documents', [
                'document_number' => $document_number,
                'operation_type_id' => 4, // INVENTORY
                'document_date' => date('Y-m-d'),
                'status' => 'completed',
                'created_by' => $_SESSION['user_id'],
                'completed_at' => date('Y-m-d H:i:s'),
                'comments' => $comments
            ]);
            
            $total_difference = 0;
            
            // Обрабатываем каждое место хранения
            foreach ($stock_locations as $location) {
                $location_id = $location['location_id'];
                $system_qty = $location['quantity'];
                $actual_qty = (float)($actual_quantities[$location_id] ?? 0);
                
                if ($actual_qty != $system_qty) {
                    $difference = $actual_qty - $system_qty;
                    $total_difference += abs($difference);
                    
                    // Создаем транзакцию
                    db_insert('inventory_transactions', [
                        'document_id' => $document_id,
                        'product_id' => $product_id,
                        'location_id_from' => $difference < 0 ? $location_id : null,
                        'location_id_to' => $difference > 0 ? $location_id : null,
                        'quantity' => abs($difference),
                        'price' => 0 // В инвентаризации цена не используется
                    ]);
                    
                    // Корректируем остатки
                    if ($difference != 0) {
                        // Удаляем старую запись
                        db_query("
                            DELETE FROM stock_balances 
                            WHERE product_id = ? AND location_id = ?
                        ", [$product_id, $location_id]);
                        
                        // Если новое количество > 0, добавляем новую запись
                        if ($actual_qty > 0) {
                            db_insert('stock_balances', [
                                'product_id' => $product_id,
                                'location_id' => $location_id,
                                'quantity' => $actual_qty,
                                'batch_id' => null // В инвентаризации партии не учитываем
                            ]);
                        }
                    }
                }
            }
            
            $db->commit();
            
            // Логирование
            log_action('INVENTORY_COMPLETE', 
                "Инвентаризация товара: {$product['article']}. Разница: {$total_difference}");
            
            $_SESSION['success'] = "Инвентаризация завершена. Документ: {$document_number}";
            header('Location: index.php?module=transactions&action=inventory_result&doc=' . urlencode($document_number));
            exit();
            
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Ошибка при сохранении инвентаризации: ' . $e->getMessage();
        }
    }
    
    // Показываем форму инвентаризации для конкретного товара
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Инвентаризация товара</h5>
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <h6>Информация о товаре:</h6>
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Артикул:</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($product['article']); ?></dd>
                            
                            <dt class="col-sm-3">Наименование:</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($product['name']); ?></dd>
                            
                            <dt class="col-sm-3">Категория:</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($product['category_name'] ?? '-'); ?></dd>
                            
                            <dt class="col-sm-3">Ед. изм:</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($product['unit']); ?></dd>
                            
                            <dt class="col-sm-3">Системный остаток:</dt>
                            <dd class="col-sm-9 fw-bold"><?php echo format_number($product['system_quantity']); ?></dd>
                        </dl>
                    </div>
                    
                    <form method="POST" id="inventoryForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        
                        <h6 class="mb-3">Фактические остатки по местам хранения:</h6>
                        
                        <?php if (empty($stock_locations)): ?>
                        <div class="alert alert-warning">
                            Товар не числится на складе. Добавьте фактическое количество:
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Количество</label>
                            <input type="number" class="form-control" name="actual[0]" 
                                   step="0.001" min="0" value="0">
                            <div class="form-text">Укажите фактическое количество товара</div>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Место хранения</th>
                                        <th class="text-end">Системное количество</th>
                                        <th class="text-end">Фактическое количество</th>
                                        <th class="text-end">Разница</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stock_locations as $location): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($location['code']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($location['name']); ?></div>
                                        </td>
                                        <td class="text-end fw-bold"><?php echo format_number($location['quantity']); ?></td>
                                        <td class="text-end">
                                            <input type="number" class="form-control text-end" 
                                                   name="actual[<?php echo $location['location_id']; ?>]" 
                                                   step="0.001" min="0" 
                                                   value="<?php echo format_number($location['quantity']); ?>">
                                        </td>
                                        <td class="text-end">
                                            <span id="diff_<?php echo $location['location_id']; ?>" class="fw-bold">0</span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Комментарий</label>
                            <textarea class="form-control" name="comments" rows="2" 
                                      placeholder="Примечания по инвентаризации..."></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="index.php?module=transactions&action=inventory" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Назад
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> Сохранить результаты
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Расчет разницы при вводе
    document.querySelectorAll('input[name^="actual"]').forEach(input => {
        input.addEventListener('input', function() {
            const name = this.name;
            const locationId = name.match(/\[(\d+)\]/)[1];
            const systemQty = parseFloat(this.defaultValue) || 0;
            const actualQty = parseFloat(this.value) || 0;
            const difference = actualQty - systemQty;
            
            const diffElement = document.getElementById('diff_' + locationId);
            if (diffElement) {
                diffElement.textContent = difference.toFixed(3);
                diffElement.className = 'fw-bold ' + (difference > 0 ? 'text-success' : (difference < 0 ? 'text-danger' : ''));
            }
        });
    });
    </script>
    <?php
    exit();
}

// Основная страница инвентаризации (список товаров)
$where = ['p.is_active = 1', 'p.is_deleted = 0'];
$params = [];

if ($location_id > 0) {
    $where[] = 'sb.location_id = ?';
    $params[] = $location_id;
}

if ($category_id > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $category_id;
}

if ($product_id > 0) {
    $where[] = 'p.id = ?';
    $params[] = $product_id;
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Получаем товары для инвентаризации
$products = db_fetch_all("
    SELECT p.id, p.article, p.name, p.unit, c.name as category_name,
           COUNT(DISTINCT sb.location_id) as locations_count,
           COALESCE(SUM(sb.quantity), 0) as total_quantity
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN stock_balances sb ON p.id = sb.product_id
    {$where_clause}
    GROUP BY p.id
    HAVING total_quantity > 0
    ORDER BY p.article
    LIMIT 100
", $params);

// Получаем данные для фильтров
$categories = db_fetch_all("SELECT id, name FROM categories ORDER BY name");
$locations = db_fetch_all("
    SELECT DISTINCT sl.id, sl.code, sl.name 
    FROM storage_locations sl
    JOIN stock_balances sb ON sl.id = sb.location_id
    WHERE sl.is_active = 1
    ORDER BY sl.code
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Инвентаризация</h1>
        <p class="text-muted mb-0">Сверка фактических остатков с системными</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newInventoryModal">
            <i class="bi bi-clipboard-plus"></i> Новая инвентаризация
        </button>
    </div>
</div>

<!-- Фильтры -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title mb-0">Фильтры для инвентаризации</h6>
    </div>
    <div class="card-body">
        <form method="GET" action="">
            <input type="hidden" name="module" value="transactions">
            <input type="hidden" name="action" value="inventory">
            
            <div class="row g-3">
                <div class="col-md-4">
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
                
                <div class="col-md-4">
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
                
                <div class="col-md-4">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel"></i> Применить фильтры
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Список товаров для инвентаризации -->
<div class="card">
    <div class="card-header">
        <h6 class="card-title mb-0">Товары для инвентаризации</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($products)): ?>
        <div class="text-center py-4">
            <div class="text-muted">
                <i class="bi bi-inbox display-6 d-block mb-2"></i>
                Товары не найдены
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Артикул</th>
                        <th>Наименование</th>
                        <th>Категория</th>
                        <th class="text-center">Ед. изм.</th>
                        <th class="text-end">Системный остаток</th>
                        <th class="text-center">Мест хранения</th>
                        <th class="text-center">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <code class="fw-bold"><?php echo htmlspecialchars($product['article']); ?></code>
                        </td>
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><?php echo htmlspecialchars($product['category_name'] ?? '-'); ?></td>
                        <td class="text-center"><?php echo $product['unit']; ?></td>
                        <td class="text-end fw-bold"><?php echo format_number($product['total_quantity']); ?></td>
                        <td class="text-center"><?php echo $product['locations_count']; ?></td>
                        <td class="text-center">
                            <a href="index.php?module=transactions&action=inventory&product_id=<?php echo $product['id']; ?>&action=count" 
                               class="btn btn-sm btn-primary">
                                <i class="bi bi-clipboard-check"></i> Провести инвентаризацию
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Модальное окно новой инвентаризации -->
<div class="modal fade" id="newInventoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Новая инвентаризация</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Выберите тип инвентаризации:</p>
                
                <div class="list-group">
                    <a href="index.php?module=transactions&action=inventory_full" class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1">Полная инвентаризация</h6>
                            <small class="text-muted">Рекомендуется</small>
                        </div>
                        <p class="mb-1">Инвентаризация всех товаров на складе</p>
                    </a>
                    
                    <a href="index.php?module=transactions&action=inventory_by_location" class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1">Инвентаризация по местам</h6>
                        </div>
                        <p class="mb-1">Инвентаризация товаров на конкретных местах хранения</p>
                    </a>
                    
                    <a href="index.php?module=transactions&action=inventory_by_category" class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1">Инвентаризация по категориям</h6>
                        </div>
                        <p class="mb-1">Инвентаризация товаров определенных категорий</p>
                    </a>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
            </div>
        </div>
    </div>
</div>