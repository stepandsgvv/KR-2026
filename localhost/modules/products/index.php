<?php
// Проверка прав
check_role(['admin', 'manager', 'storekeeper']);

// Устанавливаем заголовок страницы
$page_title = 'Номенклатура товаров';

// Параметры фильтрации
$search = clean_input($_GET['search'] ?? '');
$category_id = (int)($_GET['category_id'] ?? 0);
$show_inactive = isset($_GET['show_inactive']) ? 1 : 0;
$low_stock_only = isset($_GET['low_stock']) ? 1 : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Построение запроса
$where = ['p.is_deleted = 0'];
$params = [];

if (!$show_inactive) {
    $where[] = 'p.is_active = 1';
}

if (!empty($search)) {
    $where[] = '(p.article LIKE ? OR p.name LIKE ? OR p.barcode LIKE ?)';
    $search_term = "%{$search}%";
    array_push($params, $search_term, $search_term, $search_term);
}

if ($category_id > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $category_id;
}

if ($low_stock_only) {
    $where[] = 'p.min_stock > 0';
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Основной запрос для получения товаров
$query = "
    SELECT p.*, 
           c.name as category_name,
           COALESCE(SUM(sb.quantity), 0) as current_stock,
           COUNT(DISTINCT b.id) as batch_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN stock_balances sb ON p.id = sb.product_id
    LEFT JOIN batches b ON p.id = b.product_id
    {$where_clause}
    GROUP BY p.id
    ORDER BY p.article
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$products = db_fetch_all($query, $params);

// Подсчет общего количества для пагинации
$count_query = "
    SELECT COUNT(*) as total
    FROM products p
    {$where_clause}
";

$total_result = db_fetch_one($count_query, array_slice($params, 0, -2));
$total_items = $total_result['total'] ?? 0;
$total_pages = ceil($total_items / $limit);

// Получение категорий для фильтра
$categories = db_fetch_all("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name");

// Получение статистики
$stats = db_fetch_one("
    SELECT 
        COUNT(*) as total_products,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_products,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_products
    FROM products 
    WHERE is_deleted = 0
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Номенклатура товаров</h1>
        <p class="text-muted mb-0">Управление товарным каталогом</p>
    </div>
    <div>
        <a href="index.php?module=products&action=add" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Добавить товар
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
                        <h6 class="text-muted">Всего товаров</h6>
                        <h3 class="mb-0"><?php echo $stats['total_products'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-box text-primary fs-3"></i>
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
                        <h3 class="mb-0"><?php echo $stats['active_products'] ?? 0; ?></h3>
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
                        <h6 class="text-muted">Неактивных</h6>
                        <h3 class="mb-0"><?php echo $stats['inactive_products'] ?? 0; ?></h3>
                    </div>
                    <div class="align-self-center">
                        <i class="bi bi-x-circle text-warning fs-3"></i>
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
                        <h3 class="mb-0"><?php echo count($products); ?></h3>
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
        <h6 class="card-title mb-0">Фильтры и поиск</h6>
    </div>
    <div class="card-body">
        <form method="GET" action="" id="filterForm">
            <input type="hidden" name="module" value="products">
            <input type="hidden" name="page" value="1">
            
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Поиск</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Артикул, название, штрих-код..."
                           value="<?php echo htmlspecialchars($search); ?>">
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
                    <label class="form-label">Остатки</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="low_stock" 
                               id="low_stock" value="1" <?php echo $low_stock_only ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="low_stock">
                            Только низкий остаток
                        </label>
                    </div>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i>
                    </button>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex justify-content-between">
                        <div>
                            <button type="button" onclick="resetFilters()" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-arrow-clockwise"></i> Сбросить фильтры
                            </button>
                        </div>
                        <div>
                            <a href="export.php?type=products" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-file-earmark-excel"></i> Экспорт
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Таблица товаров -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="50">#</th>
                        <th>Артикул</th>
                        <th>Наименование</th>
                        <th>Категория</th>
                        <th class="text-center">Ед. изм.</th>
                        <th class="text-end">Остаток</th>
                        <th class="text-end">Мин.</th>
                        <th class="text-center">Статус</th>
                        <th class="text-end">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <div class="text-muted">
                                <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                Товары не найдены
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($products as $index => $product): 
                        $row_number = $offset + $index + 1;
                        
                        // Определение статуса остатка
                        $stock_status = 'success';
                        $stock_text = 'В наличии';
                        
                        if ($product['current_stock'] <= 0) {
                            $stock_status = 'danger';
                            $stock_text = 'Нет в наличии';
                        } elseif ($product['min_stock'] > 0 && $product['current_stock'] <= $product['min_stock']) {
                            $stock_status = 'warning';
                            $stock_text = 'Низкий запас';
                        }
                        
                        // Статус активности
                        $active_status = $product['is_active'] ? 'success' : 'secondary';
                        $active_text = $product['is_active'] ? 'Активен' : 'Неактивен';
                    ?>
                    <tr>
                        <td class="text-muted"><?php echo $row_number; ?></td>
                        <td>
                            <div class="fw-bold"><?php echo htmlspecialchars($product['article']); ?></div>
                            <?php if ($product['barcode']): ?>
                            <small class="text-muted">ШК: <?php echo htmlspecialchars($product['barcode']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($product['name']); ?>
                            <?php if ($product['batch_count'] > 0): ?>
                            <br><small class="text-muted">Партий: <?php echo $product['batch_count']; ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($product['category_name'] ?? '-'); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($product['unit']); ?></td>
                        <td class="text-end fw-bold">
                            <?php echo format_number($product['current_stock']); ?>
                        </td>
                        <td class="text-end">
                            <?php echo $product['min_stock'] > 0 ? format_number($product['min_stock']) : '<span class="text-muted">-</span>'; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $stock_status; ?> me-1"><?php echo $stock_text; ?></span>
                            <span class="badge bg-<?php echo $active_status; ?>"><?php echo $active_text; ?></span>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?module=products&action=view&id=<?php echo $product['id']; ?>" 
                                   class="btn btn-outline-info" title="Просмотр">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="index.php?module=products&action=edit&id=<?php echo $product['id']; ?>" 
                                   class="btn btn-outline-primary" title="Редактировать">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if (has_role('admin') || has_role('manager')): ?>
                                <a href="index.php?module=transactions&action=receipt&product_id=<?php echo $product['id']; ?>" 
                                   class="btn btn-outline-success" title="Принять на склад">
                                    <i class="bi bi-plus-circle"></i>
                                </a>
                                <button type="button" class="btn btn-outline-danger" 
                                        onclick="toggleProductStatus(<?php echo $product['id']; ?>, <?php echo $product['is_active'] ? '0' : '1'; ?>)"
                                        title="<?php echo $product['is_active'] ? 'Деактивировать' : 'Активировать'; ?>">
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
                    Показано <?php echo count($products); ?> из <?php echo $total_items; ?> товаров
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

<script>
// Функция для построения URL пагинации
function build_pagination_url(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    return url.toString();
}

// Сброс фильтров
function resetFilters() {
    window.location.href = 'index.php?module=products';
}

// Переключение статуса товара
function toggleProductStatus(productId, newStatus) {
    if (confirm(newStatus ? 'Активировать товар?' : 'Деактивировать товар?')) {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('product_id', productId);
        formData.append('is_active', newStatus);
        formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
        
        fetch('index.php?module=products&action=toggle_status', {
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

// Автоматическая отправка формы при изменении чекбоксов
document.querySelectorAll('#filterForm input[type="checkbox"]').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
});
</script>

<?php
// Вспомогательная функция для построения URL с параметрами
function build_pagination_url($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'index.php?' . http_build_query($params);
}
?>