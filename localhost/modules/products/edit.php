<?php
// Проверка прав
check_role(['admin', 'manager']);

$product_id = (int)($_GET['id'] ?? 0);
$product = db_fetch_one("SELECT * FROM products WHERE id = ? AND is_deleted = 0", [$product_id]);

if (!$product) {
    $_SESSION['error'] = 'Товар не найден';
    header('Location: index.php?module=products');
    exit();
}

// Получение категорий
$categories = db_fetch_all("SELECT id, name FROM categories ORDER BY name");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = [
        'article' => clean_input($_POST['article'] ?? ''),
        'name' => clean_input($_POST['name'] ?? ''),
        'category_id' => !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
        'unit' => clean_input($_POST['unit'] ?? ''),
        'barcode' => clean_input($_POST['barcode'] ?? ''),
        'min_stock' => (float)($_POST['min_stock'] ?? 0),
        'max_stock' => (float)($_POST['max_stock'] ?? 0),
        'description' => clean_input($_POST['description'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    $errors = [];
    
    // Валидация
    if (empty($data['article'])) {
        $errors[] = 'Артикул обязателен';
    }
    
    if (empty($data['name'])) {
        $errors[] = 'Наименование обязательно';
    }
    
    // Проверка уникальности артикула (кроме текущего товара)
    if ($data['article'] != $product['article']) {
        $existing = db_fetch_one("SELECT id FROM products WHERE article = ? AND id != ?", 
                                [$data['article'], $product_id]);
        if ($existing) {
            $errors[] = 'Товар с таким артикулом уже существует';
        }
    }
    
    if (empty($errors)) {
        $updated = db_update('products', $data, 'id = ?', [$product_id]);
        
        if ($updated) {
            log_action('PRODUCT_UPDATE', "Обновлен товар ID: $product_id ({$data['article']})");
            $_SESSION['success'] = 'Товар успешно обновлен';
            header('Location: index.php?module=products&action=edit&id=' . $product_id);
            exit();
        } else {
            $_SESSION['error'] = 'Ошибка при обновлении товара';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Редактирование товара</h5>
                <div class="btn-group">
                    <a href="index.php?module=products&action=view&id=<?php echo $product_id; ?>" 
                       class="btn btn-sm btn-outline-info">Просмотр</a>
                    <a href="index.php?module=products" class="btn btn-sm btn-outline-secondary">Назад к списку</a>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" id="editProductForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Артикул *</label>
                            <input type="text" class="form-control" name="article" 
                                   value="<?php echo htmlspecialchars($product['article']); ?>" 
                                   required pattern="[A-Za-z0-9\-_]+" maxlength="50">
                            <div class="form-text">Только латинские буквы, цифры, - и _</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Штрих-код</label>
                            <input type="text" class="form-control" name="barcode" 
                                   value="<?php echo htmlspecialchars($product['barcode']); ?>" 
                                   maxlength="100">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Наименование *</label>
                            <input type="text" class="form-control" name="name" 
                                   value="<?php echo htmlspecialchars($product['name']); ?>" 
                                   required maxlength="255">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Категория</label>
                            <select class="form-select" name="category_id">
                                <option value="">-- Без категории --</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                    <?php echo $product['category_id'] == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Единица измерения *</label>
                            <select class="form-select" name="unit" required>
                                <option value="">-- Выберите --</option>
                                <?php 
                                $units = ['шт', 'кг', 'г', 'м', 'см', 'л', 'мл', 'уп', 'кор', 'пал', 'рул', 'компл', 'пар', 'наб', 'бл'];
                                foreach ($units as $unit): ?>
                                <option value="<?php echo $unit; ?>" 
                                    <?php echo $product['unit'] == $unit ? 'selected' : ''; ?>>
                                    <?php echo $unit; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Минимальный запас</label>
                            <input type="number" class="form-control" name="min_stock" 
                                   value="<?php echo $product['min_stock']; ?>" 
                                   step="0.001" min="0" max="999999.999">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Максимальный запас</label>
                            <input type="number" class="form-control" name="max_stock" 
                                   value="<?php echo $product['max_stock']; ?>" 
                                   step="0.001" min="0" max="999999.999">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Описание</label>
                            <textarea class="form-control" name="description" rows="3" 
                                      maxlength="2000"><?php echo htmlspecialchars($product['description']); ?></textarea>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" 
                                       id="is_active" value="1" 
                                       <?php echo $product['is_active'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">
                                    Товар активен
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Сохранить изменения
                                    </button>
                                    <a href="index.php?module=products" class="btn btn-secondary">Отмена</a>
                                </div>
                                <div>
                                    <?php if (has_role('admin')): ?>
                                    <button type="button" class="btn btn-outline-danger" 
                                            onclick="deleteProduct(<?php echo $product_id; ?>)">
                                        <i class="bi bi-trash"></i> Удалить товар
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- История изменений -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="card-title mb-0">История изменений</h6>
            </div>
            <div class="card-body">
                <?php
                $history = db_fetch_all("
                    SELECT al.*, u.full_name 
                    FROM audit_log al
                    LEFT JOIN users u ON al.user_id = u.id
                    WHERE al.details LIKE ? 
                    ORDER BY al.created_at DESC 
                    LIMIT 10
                ", ["%товар ID: $product_id%"]);
                ?>
                
                <?php if (empty($history)): ?>
                <p class="text-muted text-center mb-0">История изменений отсутствует</p>
                <?php else: ?>
                <div class="timeline">
                    <?php foreach ($history as $item): ?>
                    <div class="timeline-item mb-3">
                        <div class="d-flex">
                            <div class="timeline-marker bg-primary rounded-circle" style="width: 10px; height: 10px;"></div>
                            <div class="ms-3">
                                <div class="fw-bold"><?php echo htmlspecialchars($item['full_name'] ?? 'Система'); ?></div>
                                <div class="text-muted small">
                                    <?php echo format_date($item['created_at']); ?>
                                </div>
                                <div class="mt-1"><?php echo htmlspecialchars($item['details']); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Информация о товаре -->
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Информация о товаре</h6>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">ID:</dt>
                    <dd class="col-sm-7"><?php echo $product['id']; ?></dd>
                    
                    <dt class="col-sm-5">Создан:</dt>
                    <dd class="col-sm-7"><?php echo format_date($product['created_at']); ?></dd>
                    
                    <dt class="col-sm-5">Обновлен:</dt>
                    <dd class="col-sm-7"><?php echo format_date($product['updated_at']); ?></dd>
                    
                    <dt class="col-sm-5">Создал:</dt>
                    <dd class="col-sm-7">
                        <?php 
                        $creator = db_fetch_one("SELECT full_name FROM users WHERE id = ?", 
                                               [$product['created_by']]);
                        echo htmlspecialchars($creator['full_name'] ?? 'Неизвестно');
                        ?>
                    </dd>
                </dl>
            </div>
        </div>
        
        <!-- Текущие остатки -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="card-title mb-0">Остатки на складе</h6>
            </div>
            <div class="card-body">
                <?php
                $stock = db_fetch_all("
                    SELECT sb.quantity, sl.code, sl.name, b.batch_number
                    FROM stock_balances sb
                    LEFT JOIN storage_locations sl ON sb.location_id = sl.id
                    LEFT JOIN batches b ON sb.batch_id = b.id
                    WHERE sb.product_id = ? AND sb.quantity > 0
                    ORDER BY sl.code
                ", [$product_id]);
                ?>
                
                <?php if (empty($stock)): ?>
                <p class="text-muted text-center mb-0">Товара нет в наличии</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php 
                    $total = 0;
                    foreach ($stock as $item): 
                        $total += $item['quantity'];
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold"><?php echo htmlspecialchars($item['code']); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($item['name']); ?></div>
                            <?php if ($item['batch_number']): ?>
                            <div class="small">Партия: <?php echo htmlspecialchars($item['batch_number']); ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-primary rounded-pill"><?php echo format_number($item['quantity']); ?></span>
                    </li>
                    <?php endforeach; ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center fw-bold">
                        <span>Итого:</span>
                        <span class="badge bg-success rounded-pill"><?php echo format_number($total); ?></span>
                    </li>
                </ul>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <a href="index.php?module=transactions&action=receipt&product_id=<?php echo $product_id; ?>" 
                   class="btn btn-sm btn-outline-success w-100">
                    <i class="bi bi-plus-circle"></i> Принять на склад
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function deleteProduct(productId) {
    if (confirm('Вы уверены, что хотите удалить этот товар? Все связанные данные будут удалены.')) {
        window.location.href = 'index.php?module=products&action=delete&id=' + productId;
    }
}

// Валидация формы
document.getElementById('editProductForm').addEventListener('submit', function(e) {
    const article = this.elements['article'].value;
    const articlePattern = /^[A-Za-z0-9\-_]+$/;
    
    if (!articlePattern.test(article)) {
        e.preventDefault();
        alert('Артикул может содержать только латинские буквы, цифры, дефис и подчеркивание');
        this.elements['article'].focus();
    }
});
</script>