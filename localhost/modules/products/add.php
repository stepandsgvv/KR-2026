<?php
// Проверка авторизации и прав
check_auth();
check_role(['admin', 'manager']);

// Получение категорий
$categories = db_fetch_all("SELECT id, name FROM categories ORDER BY name");

// Обработка AJAX запроса
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Проверяем, это AJAX запрос или обычный POST
    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    // Для AJAX запросов устанавливаем заголовок JSON
    if ($is_ajax) {
        header('Content-Type: application/json');
    }
    
    // Получаем данные
    $data = [
        'article' => clean_input($_POST['article'] ?? ''),
        'name' => clean_input($_POST['name'] ?? ''),
        'category_id' => !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
        'unit' => clean_input($_POST['unit'] ?? ''),
        'barcode' => clean_input($_POST['barcode'] ?? ''),
        'min_stock' => (float)($_POST['min_stock'] ?? 0),
        'max_stock' => (float)($_POST['max_stock'] ?? 0),
        'description' => clean_input($_POST['description'] ?? ''),
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => $_SESSION['user_id']
    ];
    
    $errors = [];
    
    // Валидация
    if (empty($data['article'])) {
        $errors[] = 'Артикул обязателен для заполнения';
    }
    
    if (empty($data['name'])) {
        $errors[] = 'Наименование обязательно для заполнения';
    }
    
    if (empty($data['unit'])) {
        $errors[] = 'Единица измерения обязательна';
    }
    
    // Проверка уникальности артикула
    if (!empty($data['article'])) {
        $existing = db_fetch_one("SELECT id FROM products WHERE article = ?", [$data['article']]);
        if ($existing) {
            $errors[] = 'Товар с таким артикулом уже существует';
        }
    }
    
    // Если ошибок нет - сохранение
    if (empty($errors)) {
        try {
            $product_id = db_insert('products', $data);
            
            if ($product_id) {
                log_action('PRODUCT_ADD', "Добавлен товар ID: $product_id ({$data['article']})");
                
                if ($is_ajax) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Товар успешно добавлен',
                        'redirect' => 'index.php?module=products&action=edit&id=' . $product_id,
                        'product_id' => $product_id
                    ]);
                } else {
                    $_SESSION['success'] = 'Товар успешно добавлен';
                    header('Location: index.php?module=products&action=edit&id=' . $product_id);
                }
                exit();
            } else {
                $error_msg = 'Ошибка при сохранении товара в базу данных';
                if ($is_ajax) {
                    echo json_encode([
                        'success' => false,
                        'message' => $error_msg
                    ]);
                } else {
                    $_SESSION['error'] = $error_msg;
                }
                exit();
            }
        } catch (Exception $e) {
            $error_msg = 'Ошибка базы данных: ' . $e->getMessage();
            if ($is_ajax) {
                echo json_encode([
                    'success' => false,
                    'message' => $error_msg
                ]);
            } else {
                $_SESSION['error'] = $error_msg;
            }
            exit();
        }
    } else {
        $error_msg = implode('<br>', $errors);
        if ($is_ajax) {
            echo json_encode([
                'success' => false,
                'message' => $error_msg
            ]);
        } else {
            $_SESSION['error'] = $error_msg;
        }
        exit();
    }
}
?>

<!-- HTML форма -->
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Добавление нового товара</h5>
            </div>
            <div class="card-body">
                <div id="message-container"></div>
                
                <form id="addProductForm" method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="article" class="form-label">Артикул *</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="article" name="article" 
                                       required pattern="[A-Za-z0-9\-_]+" maxlength="50"
                                       placeholder="PROD-001">
                                <button type="button" class="btn btn-outline-secondary" id="generateArticle">
                                    <i class="bi bi-shuffle"></i>
                                </button>
                            </div>
                            <div class="form-text">Только латинские буквы, цифры, - и _</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="barcode" class="form-label">Штрих-код</label>
                            <input type="text" class="form-control" id="barcode" name="barcode" 
                                   maxlength="100" placeholder="Необязательно">
                        </div>
                        
                        <div class="col-12">
                            <label for="name" class="form-label">Наименование *</label>
                            <input type="text" class="form-control" id="name" name="name" 
                                   required maxlength="255" placeholder="Наименование товара">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="category_id" class="form-label">Категория</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="">-- Без категории --</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="unit" class="form-label">Единица измерения *</label>
                            <select class="form-select" id="unit" name="unit" required>
                                <option value="">-- Выберите --</option>
                                <option value="шт">шт (штука)</option>
                                <option value="кг">кг (килограмм)</option>
                                <option value="г">г (грамм)</option>
                                <option value="м">м (метр)</option>
                                <option value="см">см (сантиметр)</option>
                                <option value="л">л (литр)</option>
                                <option value="мл">мл (миллилитр)</option>
                                <option value="уп">уп (упаковка)</option>
                                <option value="кор">кор (коробка)</option>
                                <option value="пал">пал (паллета)</option>
                                <option value="рул">рул (рулон)</option>
                                <option value="компл">компл (комплект)</option>
                                <option value="пар">пар (пара)</option>
                                <option value="наб">наб (набор)</option>
                                <option value="бл">бл (блок)</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="min_stock" class="form-label">Минимальный запас</label>
                            <input type="number" class="form-control" id="min_stock" name="min_stock" 
                                   step="0.001" min="0" max="999999.999" value="0">
                            <div class="form-text">Система покажет предупреждение</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="max_stock" class="form-label">Максимальный запас</label>
                            <input type="number" class="form-control" id="max_stock" name="max_stock" 
                                   step="0.001" min="0" max="999999.999" value="0">
                            <div class="form-text">Рекомендуемый максимальный остаток</div>
                        </div>
                        
                        <div class="col-12">
                            <label for="description" class="form-label">Описание</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="3" maxlength="2000" placeholder="Описание товара..."></textarea>
                            <div class="form-text">Максимум 2000 символов</div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Сохранить товар
                        </button>
                        <button type="reset" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Очистить
                        </button>
                        <a href="index.php?module=products" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Отмена
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('addProductForm');
    const messageContainer = document.getElementById('message-container');
    
    // Генерация артикула
    document.getElementById('generateArticle').addEventListener('click', function() {
        const timestamp = Date.now().toString().slice(-6);
        const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
        document.getElementById('article').value = 'PROD-' + timestamp + '-' + random;
    });
    
    // AJAX отправка формы
    <!-- Вместо AJAX отправки используйте обычную форму -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('addProductForm');
    
    // Отключаем AJAX отправку
    form.addEventListener('submit', function(e) {
        // Только валидация
        const articleInput = document.getElementById('article');
        const articlePattern = /^[A-Za-z0-9\-_]+$/;
        
        if (articleInput.value && !articlePattern.test(articleInput.value)) {
            e.preventDefault();
            alert('Артикул может содержать только латинские буквы, цифры, дефис и подчеркивание');
            articleInput.focus();
            return;
        }
        
        // Форма отправится обычным способом
    });
});
</script>
    
    // Валидация на стороне клиента
    form.addEventListener('input', function() {
        const articleInput = document.getElementById('article');
        const articlePattern = /^[A-Za-z0-9\-_]+$/;
        
        if (articleInput.value && !articlePattern.test(articleInput.value)) {
            articleInput.classList.add('is-invalid');
        } else {
            articleInput.classList.remove('is-invalid');
        }
    });
    
    // Тестирование AJAX подключения
    console.log('Testing AJAX connection...');
    fetch('ajax.php?action=test')
        .then(response => response.json())
        .then(data => {
            console.log('AJAX test response:', data);
            if (!data.success) {
                console.warn('AJAX test failed:', data.message);
            }
        })
        .catch(error => {
            console.error('AJAX test failed:', error);
        });
});
</script>

<style>
.is-invalid {
    border-color: #dc3545 !important;
}

#message-container {
    min-height: 60px;
}
</style>