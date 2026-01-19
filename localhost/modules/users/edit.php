<?php
// Проверка прав - только администратор
check_role(['admin']);

$user_id = (int)($_GET['id'] ?? 0);
$user = db_fetch_one("SELECT * FROM users WHERE id = ? AND is_deleted = 0", [$user_id]);

if (!$user) {
    $_SESSION['error'] = 'Пользователь не найден';
    header('Location: index.php?module=users');
    exit();
}

$page_title = 'Редактирование пользователя: ' . htmlspecialchars($user['username']);

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_csrf();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $data = [
            'username' => clean_input($_POST['username'] ?? ''),
            'full_name' => clean_input($_POST['full_name'] ?? ''),
            'email' => clean_input($_POST['email'] ?? ''),
            'role' => clean_input($_POST['role'] ?? 'viewer'),
            'phone' => clean_input($_POST['phone'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $errors = [];
        
        // Валидация
        if (empty($data['username'])) {
            $errors[] = 'Имя пользователя обязательно';
        }
        
        if (empty($data['full_name'])) {
            $errors[] = 'Полное имя обязательно';
        }
        
        if (empty($data['email'])) {
            $errors[] = 'Email обязателен';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Неверный формат email';
        }
        
        // Проверка уникальности username (кроме текущего пользователя)
        if ($data['username'] != $user['username']) {
            $existing = db_fetch_one("SELECT id FROM users WHERE username = ? AND id != ?", 
                                    [$data['username'], $user_id]);
            if ($existing) {
                $errors[] = 'Пользователь с таким логином уже существует';
            }
        }
        
        // Проверка уникальности email (кроме текущего пользователя)
        if ($data['email'] != $user['email']) {
            $existing = db_fetch_one("SELECT id FROM users WHERE email = ? AND id != ?", 
                                    [$data['email'], $user_id]);
            if ($existing) {
                $errors[] = 'Пользователь с таким email уже существует';
            }
        }
        
        if (empty($errors)) {
            $updated = db_update('users', $data, 'id = ?', [$user_id]);
            
            if ($updated) {
                log_action('USER_UPDATE', "Обновлен пользователь: {$data['username']} (ID: $user_id)");
                $_SESSION['success'] = 'Данные пользователя успешно обновлены';
                header('Location: index.php?module=users&action=edit&id=' . $user_id);
                exit();
            } else {
                $_SESSION['error'] = 'Ошибка при обновлении данных';
            }
        } else {
            $_SESSION['error'] = implode('<br>', $errors);
        }
    }
    
    if ($action === 'update_password') {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($new_password)) {
            $_SESSION['error'] = 'Введите новый пароль';
        } elseif ($new_password !== $confirm_password) {
            $_SESSION['error'] = 'Пароли не совпадают';
        } else {
            // Проверка сложности пароля
            $strength = validate_password_strength($new_password);
            if (!$strength['valid']) {
                $_SESSION['error'] = implode('<br>', $strength['errors']);
            } else {
                // Хэшируем новый пароль
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                
                $updated = db_query("UPDATE users SET password = ? WHERE id = ?", [
                    $hashed_password,
                    $user_id
                ]);
                
                if ($updated) {
                    log_action('PASSWORD_CHANGE_ADMIN', 
                        "Пароль пользователя {$user['username']} изменен администратором");
                    $_SESSION['success'] = 'Пароль успешно изменен';
                } else {
                    $_SESSION['error'] = 'Ошибка при изменении пароля';
                }
            }
        }
    }
}
?>

<div class="container-fluid">
    <!-- Заголовок и навигация -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Редактирование пользователя</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?module=users">Пользователи</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($user['username']); ?></li>
                </ol>
            </nav>
        </div>
        <div class="btn-group">
            <a href="index.php?module=users&action=view&id=<?php echo $user_id; ?>" 
               class="btn btn-outline-info">
                <i class="bi bi-eye"></i> Просмотр
            </a>
            <a href="index.php?module=users" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Назад
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Форма редактирования профиля -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Основные данные</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="profileForm">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Имя пользователя *</label>
                                <input type="text" class="form-control" name="username" 
                                       value="<?php echo htmlspecialchars($user['username']); ?>" 
                                       required pattern="[a-zA-Z0-9_]+" minlength="3" maxlength="50">
                                <div class="form-text">Только латинские буквы, цифры и подчеркивание</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Роль *</label>
                                <select class="form-select" name="role" required>
                                    <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Администратор</option>
                                    <option value="manager" <?php echo $user['role'] == 'manager' ? 'selected' : ''; ?>>Менеджер</option>
                                    <option value="storekeeper" <?php echo $user['role'] == 'storekeeper' ? 'selected' : ''; ?>>Кладовщик</option>
                                    <option value="viewer" <?php echo $user['role'] == 'viewer' ? 'selected' : ''; ?>>Наблюдатель</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Полное имя *</label>
                                <input type="text" class="form-control" name="full_name" 
                                       value="<?php echo htmlspecialchars($user['full_name']); ?>" 
                                       required maxlength="100">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>" 
                                       required maxlength="100">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Телефон</label>
                                <input type="tel" class="form-control" name="phone" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                       maxlength="20">
                            </div>
                            
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" 
                                           id="is_active" value="1" 
                                           <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_active">
                                        Учетная запись активна
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Сохранить изменения
                                </button>
                                <button type="reset" class="btn btn-secondary">Сбросить</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Смена пароля -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Смена пароля</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="passwordForm">
                        <input type="hidden" name="action" value="update_password">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Новый пароль</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="new_password" 
                                           id="new_password" minlength="8">
                                    <button class="btn btn-outline-secondary" type="button" id="toggleNewPassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    Минимум 8 символов, заглавные и строчные буквы, цифры
                                </div>
                                <div class="password-strength mt-2">
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar" id="passwordStrengthBar" style="width: 0%"></div>
                                    </div>
                                    <small id="passwordStrengthText" class="text-muted"></small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Подтверждение пароля</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="confirm_password" 
                                           id="confirm_password">
                                    <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    Повторите новый пароль
                                </div>
                                <div id="passwordMatch" class="mt-2"></div>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-key"></i> Сменить пароль
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="generatePassword()">
                                    <i class="bi bi-shuffle"></i> Сгенерировать пароль
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Информация о пользователе -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="card-title mb-0">Информация о пользователе</h6>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">ID:</dt>
                        <dd class="col-sm-7"><?php echo $user['id']; ?></dd>
                        
                        <dt class="col-sm-5">Создан:</dt>
                        <dd class="col-sm-7"><?php echo format_date($user['created_at']); ?></dd>
                        
                        <dt class="col-sm-5">Последний вход:</dt>
                        <dd class="col-sm-7">
                            <?php if ($user['last_login']): ?>
                            <?php echo format_date($user['last_login']); ?>
                            <?php else: ?>
                            <span class="text-muted">Никогда</span>
                            <?php endif; ?>
                        </dd>
                        
                        <dt class="col-sm-5">Обновлен:</dt>
                        <dd class="col-sm-7">
                            <?php 
                            $updated_at = db_fetch_one("SELECT updated_at FROM users WHERE id = ?", [$user_id]);
                            echo $updated_at['updated_at'] ? format_date($updated_at['updated_at']) : format_date($user['created_at']);
                            ?>
                        </dd>
                        
                        <dt class="col-sm-5">Всего действий:</dt>
                        <dd class="col-sm-7">
                            <?php 
                            $actions_count = db_fetch_one("SELECT COUNT(*) as count FROM audit_log WHERE user_id = ?", [$user_id]);
                            echo $actions_count['count'] ?? 0;
                            ?>
                        </dd>
                    </dl>
                </div>
            </div>
            
            <!-- Активность пользователя -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="card-title mb-0">Последняя активность</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php
                        $recent_activity = db_fetch_all("
                            SELECT action, details, created_at 
                            FROM audit_log 
                            WHERE user_id = ? 
                            ORDER BY created_at DESC 
                            LIMIT 5
                        ", [$user_id]);
                        
                        if (empty($recent_activity)): ?>
                        <div class="list-group-item text-center text-muted py-3">
                            Активность отсутствует
                        </div>
                        <?php else: ?>
                        <?php foreach ($recent_activity as $activity): ?>
                        <div class="list-group-item">
                            <div class="fw-bold"><?php echo htmlspecialchars($activity['action']); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($activity['details']); ?></div>
                            <div class="small"><?php echo format_date($activity['created_at'], 'H:i:s'); ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="index.php?module=reports&action=audit&user_id=<?php echo $user_id; ?>" 
                       class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-clock-history"></i> Полная история
                    </a>
                </div>
            </div>
            
            <!-- Быстрые действия -->
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">Быстрые действия</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-danger" 
                                onclick="deleteUser(<?php echo $user_id; ?>, '<?php echo addslashes($user['username']); ?>')">
                            <i class="bi bi-trash"></i> Удалить пользователя
                        </button>
                        <button type="button" class="btn btn-outline-warning" 
                                onclick="deactivateUser(<?php echo $user_id; ?>, <?php echo $user['is_active'] ? 0 : 1; ?>)">
                            <i class="bi bi-power"></i> 
                            <?php echo $user['is_active'] ? 'Деактивировать' : 'Активировать'; ?>
                        </button>
                        <button type="button" class="btn btn-outline-info" 
                                onclick="resetUserPassword(<?php echo $user_id; ?>)">
                            <i class="bi bi-envelope"></i> Отправить сброс пароля
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Показать/скрыть пароль
document.getElementById('toggleNewPassword').addEventListener('click', function() {
    const input = document.getElementById('new_password');
    const icon = this.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
});

document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
    const input = document.getElementById('confirm_password');
    const icon = this.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
});

// Проверка силы пароля
document.getElementById('new_password').addEventListener('input', function() {
    const password = this.value;
    const bar = document.getElementById('passwordStrengthBar');
    const text = document.getElementById('passwordStrengthText');
    
    let strength = 0;
    let color = 'danger';
    let message = 'Слабый';
    
    if (password.length >= 8) strength++;
    if (/[a-z]/.test(password)) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    switch (strength) {
        case 5:
            color = 'success';
            message = 'Очень сильный';
            break;
        case 4:
            color = 'info';
            message = 'Сильный';
            break;
        case 3:
            color = 'warning';
            message = 'Средний';
            break;
        default:
            color = 'danger';
            message = 'Слабый';
    }
    
    bar.style.width = (strength * 20) + '%';
    bar.className = 'progress-bar bg-' + color;
    text.textContent = message;
    text.className = 'text-' + color;
});

// Проверка совпадения паролей
document.getElementById('confirm_password').addEventListener('input', function() {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = this.value;
    const matchDiv = document.getElementById('passwordMatch');
    
    if (!newPassword) {
        matchDiv.innerHTML = '';
        return;
    }
    
    if (newPassword === confirmPassword) {
        matchDiv.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Пароли совпадают</span>';
    } else {
        matchDiv.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Пароли не совпадают</span>';
    }
});

// Генерация пароля
function generatePassword() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    let password = '';
    
    // Гарантируем наличие разных типов символов
    password += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[Math.floor(Math.random() * 26)];
    password += 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)];
    password += '0123456789'[Math.floor(Math.random() * 10)];
    password += '!@#$%^&*'[Math.floor(Math.random() * 8)];
    
    // Добавляем случайные символы до длины 12
    for (let i = 0; i < 8; i++) {
        password += chars[Math.floor(Math.random() * chars.length)];
    }
    
    // Перемешиваем пароль
    password = password.split('').sort(() => Math.random() - 0.5).join('');
    
    // Заполняем поля
    document.getElementById('new_password').value = password;
    document.getElementById('confirm_password').value = password;
    
    // Триггерим события для обновления индикаторов
    document.getElementById('new_password').dispatchEvent(new Event('input'));
    document.getElementById('confirm_password').dispatchEvent(new Event('input'));
}

// Удаление пользователя
function deleteUser(userId, username) {
    if (confirm(`Вы уверены, что хотите удалить пользователя "${username}"? Это действие нельзя отменить.`)) {
        window.location.href = 'index.php?module=users&action=delete&id=' + userId;
    }
}

// Деактивация/активация пользователя
function deactivateUser(userId, newStatus) {
    const action = newStatus ? 'активировать' : 'деактивировать';
    
    if (confirm(`Вы уверены, что хотите ${action} этого пользователя?`)) {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('user_id', userId);
        formData.append('is_active', newStatus);
        formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
        
        fetch('index.php?module=users&action=toggle_status', {
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

// Отправка сброса пароля
function resetUserPassword(userId) {
    if (confirm('Отправить пользователю инструкцию по сбросу пароля на email?')) {
        const formData = new FormData();
        formData.append('action', 'send_reset_link');
        formData.append('user_id', userId);
        formData.append('csrf_token', '<?php echo generate_csrf_token(); ?>');
        
        fetch('index.php?module=users&action=send_reset_link', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Инструкция по сбросу пароля отправлена на email пользователя');
            } else {
                alert(data.message || 'Ошибка при отправке');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка сети');
        });
    }
}
</script>