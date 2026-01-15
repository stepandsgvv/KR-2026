<?php
if (!isset($page_title)) {
    $page_title = SITE_NAME;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | <?php echo SITE_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <!-- Custom CSS -->
    <link href="<?php echo CSS_PATH; ?>style.css" rel="stylesheet">
    
    <style>
        .sidebar {
            min-height: calc(100vh - 56px);
            background: linear-gradient(180deg, #f8f9fa 0%, #e9ecef 100%);
            border-right: 1px solid #dee2e6;
        }
        .navbar-brand {
            font-weight: 600;
        }
        .stat-card {
            transition: all 0.3s;
            border: 1px solid #dee2e6;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .nav-link.active {
            background-color: #0d6efd;
            color: white !important;
            border-radius: 5px;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(13, 110, 253, 0.05);
        }
    </style>
</head>
<body>
    <!-- Навигационная панель -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-box-seam me-2"></i><?php echo SITE_NAME; ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i>
                            <?php echo $_SESSION['full_name'] ?? 'Гость'; ?>
                            <span class="badge bg-light text-dark ms-1">
                                <?php echo $_SESSION['user_role'] ?? 'guest'; ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="index.php?module=users&action=profile">
                                <i class="bi bi-person me-2"></i>Профиль
                            </a></li>
                            <li><a class="dropdown-item" href="index.php?module=users&action=settings">
                                <i class="bi bi-gear me-2"></i>Настройки
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (isset($_SESSION['user_id'])): ?>
                            <li><a class="dropdown-item text-danger" href="logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Выход
                            </a></li>
                            <?php else: ?>
                            <li><a class="dropdown-item" href="login.php">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Вход
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Боковое меню -->
            <div class="col-md-3 col-lg-2 p-0 sidebar d-none d-md-block">
                <nav class="nav flex-column p-3">
                    <div class="mb-3">
                        <small class="text-muted text-uppercase fw-bold">Главная</small>
                        <a class="nav-link <?php echo ($module == 'dashboard') ? 'active' : ''; ?>" 
                           href="index.php?module=dashboard">
                            <i class="bi bi-speedometer2 me-2"></i>Дашборд
                        </a>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted text-uppercase fw-bold">Справочники</small>
                        <a class="nav-link <?php echo ($module == 'products') ? 'active' : ''; ?>" 
                           href="index.php?module=products">
                            <i class="bi bi-box me-2"></i>Номенклатура
                        </a>
                        <a class="nav-link <?php echo ($module == 'storage') ? 'active' : ''; ?>" 
                           href="index.php?module=storage&action=locations">
                            <i class="bi bi-geo-alt me-2"></i>Складские места
                        </a>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted text-uppercase fw-bold">Операции</small>
                        <a class="nav-link <?php echo ($module == 'transactions' && $action == 'receipt') ? 'active' : ''; ?>" 
                           href="index.php?module=transactions&action=receipt">
                            <i class="bi bi-plus-circle me-2"></i>Приемка
                        </a>
                        <a class="nav-link <?php echo ($module == 'transactions' && $action == 'shipment') ? 'active' : ''; ?>" 
                           href="index.php?module=transactions&action=shipment">
                            <i class="bi bi-dash-circle me-2"></i>Отгрузка
                        </a>
                        <a class="nav-link <?php echo ($module == 'transactions' && $action == 'movement') ? 'active' : ''; ?>" 
                           href="index.php?module=transactions&action=movement">
                            <i class="bi bi-arrow-left-right me-2"></i>Перемещение
                        </a>
                        <a class="nav-link <?php echo ($module == 'transactions' && $action == 'inventory') ? 'active' : ''; ?>" 
                           href="index.php?module=transactions&action=inventory">
                            <i class="bi bi-clipboard-check me-2"></i>Инвентаризация
                        </a>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted text-uppercase fw-bold">Отчеты</small>
                        <a class="nav-link <?php echo ($module == 'reports' && $action == 'stock') ? 'active' : ''; ?>" 
                           href="index.php?module=reports&action=stock">
                            <i class="bi bi-bar-chart me-2"></i>Остатки
                        </a>
                        <a class="nav-link <?php echo ($module == 'reports' && $action == 'turnover') ? 'active' : ''; ?>" 
                           href="index.php?module=reports&action=turnover">
                            <i class="bi bi-graph-up me-2"></i>Обороты
                        </a>
                    </div>
                    
                    <?php if (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'manager'])): ?>
                    <div class="mb-3">
                        <small class="text-muted text-uppercase fw-bold">Администрирование</small>
                        <a class="nav-link <?php echo ($module == 'users') ? 'active' : ''; ?>" 
                           href="index.php?module=users&action=list">
                            <i class="bi bi-people me-2"></i>Пользователи
                        </a>
                        <a class="nav-link" href="index.php?module=reports&action=audit">
                            <i class="bi bi-journal-text me-2"></i>Журнал действий
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mt-4 pt-3 border-top">
                        <small class="text-muted d-block mb-2">Быстрые действия</small>
                        <div class="d-grid gap-2">
                            <a href="index.php?module=transactions&action=receipt" class="btn btn-sm btn-success">
                                <i class="bi bi-plus"></i> Быстрая приемка
                            </a>
                            <a href="index.php?module=reports&action=stock" class="btn btn-sm btn-info">
                                <i class="bi bi-eye"></i> Посмотреть остатки
                            </a>
                        </div>
                    </div>
                </nav>
            </div>
            
            <!-- Основное содержимое -->
            <div class="col-md-9 col-lg-10 p-4">
                <!-- Хлебные крошки -->
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php?module=dashboard">Главная</a></li>
                        <?php if ($module != 'dashboard'): ?>
                        <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
                        <?php endif; ?>
                    </ol>
                </nav>
                
                <!-- Сообщения -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['warning']; unset($_SESSION['warning']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>