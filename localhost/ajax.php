<?php
// Начинаем буферизацию сразу
ob_start();

require_once 'config.php';

// Устанавливаем заголовок JSON
header('Content-Type: application/json');

// Проверка авторизации
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit();
}

// Получение действия
$action = clean_input($_GET['action'] ?? $_POST['action'] ?? '');

// Очищаем буфер на случай случайных выводов
ob_clean();

// Обработка различных действий
switch ($action) {
    
    // Получение информации о товаре
    case 'get_product_info':
        $product_id = (int)($_GET['product_id'] ?? 0);
        if ($product_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверный ID товара']);
            break;
        }
        
        try {
            $product = db_fetch_one("
                SELECT p.*, c.name as category_name,
                       COALESCE(SUM(sb.quantity), 0) as total_stock
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN stock_balances sb ON p.id = sb.product_id
                WHERE p.id = ? AND p.is_active = 1
                GROUP BY p.id
            ", [$product_id]);
            
            if ($product) {
                echo json_encode([
                    'success' => true,
                    'product' => $product,
                    'unit' => $product['unit'],
                    'stock' => $product['total_stock']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Товар не найден']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        break;
    
    // Поиск товаров
    case 'search_products':
        $query = clean_input($_GET['q'] ?? '');
        $limit = (int)($_GET['limit'] ?? 10);
        
        try {
            $products = db_fetch_all("
                SELECT p.id, p.article, p.name, p.unit,
                       COALESCE(SUM(sb.quantity), 0) as available
                FROM products p
                LEFT JOIN stock_balances sb ON p.id = sb.product_id
                WHERE p.is_active = 1 AND p.is_deleted = 0
                AND (p.article LIKE ? OR p.name LIKE ? OR p.barcode LIKE ?)
                GROUP BY p.id
                ORDER BY p.article
                LIMIT ?
            ", ["%{$query}%", "%{$query}%", "%{$query}%", $limit]);
            
            echo json_encode(['success' => true, 'products' => $products]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        break;
    
    // Получение партий товара
    case 'get_batches':
        $product_id = (int)($_GET['product_id'] ?? 0);
        if ($product_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверный ID товара']);
            break;
        }
        
        try {
            $batches = db_fetch_all("
                SELECT b.id, b.batch_number, b.current_quantity,
                       b.expiry_date, b.supplier
                FROM batches b
                WHERE b.product_id = ? AND b.current_quantity > 0
                ORDER BY b.expiry_date, b.batch_number
            ", [$product_id]);
            
            echo json_encode(['success' => true, 'batches' => $batches]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        break;
    
    // Проверка остатков
    case 'check_stock':
        $product_id = (int)($_GET['product_id'] ?? 0);
        $location_id = (int)($_GET['location_id'] ?? 0);
        
        if ($product_id <= 0 || $location_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверные параметры']);
            break;
        }
        
        try {
            $stock = db_fetch_one("
                SELECT COALESCE(SUM(quantity), 0) as available
                FROM stock_balances
                WHERE product_id = ? AND location_id = ?
            ", [$product_id, $location_id]);
            
            echo json_encode(['success' => true, 'available' => $stock['available'] ?? 0]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        break;
    
    // Быстрая приемка
    case 'quick_receipt':
        // Проверяем CSRF только если не отключено
        if (!defined('DISABLE_CSRF')) {
            check_csrf();
        }
        
        $product_id = (int)($_POST['product_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);
        $location_id = (int)($_POST['location_id'] ?? 0);
        $supplier = clean_input($_POST['supplier'] ?? '');
        
        if ($product_id <= 0 || $quantity <= 0 || $location_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверные параметры']);
            break;
        }
        
        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            
            // Генерация номера документа
            $document_number = 'БЫСТР-' . date('Ymd-His');
            
            // Получаем информацию о товаре
            $product = db_fetch_one("SELECT article, name FROM products WHERE id = ?", [$product_id]);
            if (!$product) {
                throw new Exception('Товар не найден');
            }
            
            // Создаем транзакцию (упрощенный вариант без документа)
            $transaction_id = db_insert('inventory_transactions', [
                'product_id' => $product_id,
                'location_id_to' => $location_id,
                'quantity' => $quantity,
                'operation_type' => 'receipt',
                'document_number' => $document_number,
                'document_date' => date('Y-m-d'),
                'user_id' => $_SESSION['user_id'],
                'comments' => 'Быстрая приемка от: ' . $supplier,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Обновление остатков
            $existing = db_fetch_one("
                SELECT id, quantity 
                FROM stock_balances 
                WHERE product_id = ? AND location_id = ? AND batch_id IS NULL
            ", [$product_id, $location_id]);
            
            if ($existing) {
                db_query("
                    UPDATE stock_balances 
                    SET quantity = quantity + ? 
                    WHERE id = ?
                ", [$quantity, $existing['id']]);
            } else {
                db_insert('stock_balances', [
                    'product_id' => $product_id,
                    'location_id' => $location_id,
                    'quantity' => $quantity
                ]);
            }
            
            $db->commit();
            
            // Логирование
            log_action('QUICK_RECEIPT', "Быстрая приемка: {$quantity} ед. товара {$product['article']}");
            
            echo json_encode([
                'success' => true,
                'message' => 'Приемка успешно выполнена',
                'document_number' => $document_number
            ]);
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        break;
    
    // Быстрая отгрузка
    case 'quick_shipment':
        if (!defined('DISABLE_CSRF')) {
            check_csrf();
        }
        
        $product_id = (int)($_POST['product_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);
        $location_id = (int)($_POST['location_id'] ?? 0);
        $customer = clean_input($_POST['customer'] ?? '');
        
        if ($product_id <= 0 || $quantity <= 0 || $location_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверные параметры']);
            break;
        }
        
        try {
            // Проверка наличия
            $available = db_fetch_one("
                SELECT COALESCE(SUM(quantity), 0) as available
                FROM stock_balances 
                WHERE product_id = ? AND location_id = ?
            ", [$product_id, $location_id]);
            
            if ($available['available'] < $quantity) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Недостаточно товара. Доступно: ' . $available['available']
                ]);
                break;
            }
            
            $db = Database::getConnection();
            $db->beginTransaction();
            
            // Генерация номера документа
            $document_number = 'БЫСТР-ОТГР-' . date('Ymd-His');
            
            // Получаем информацию о товаре
            $product = db_fetch_one("SELECT article, name FROM products WHERE id = ?", [$product_id]);
            
            // Создаем транзакцию
            $transaction_id = db_insert('inventory_transactions', [
                'product_id' => $product_id,
                'location_id_from' => $location_id,
                'quantity' => -$quantity,
                'operation_type' => 'shipment',
                'document_number' => $document_number,
                'document_date' => date('Y-m-d'),
                'user_id' => $_SESSION['user_id'],
                'comments' => 'Быстрая отгрузка для: ' . $customer,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Списание остатков
            db_query("
                UPDATE stock_balances 
                SET quantity = quantity - ? 
                WHERE product_id = ? AND location_id = ? AND quantity >= ?
                ORDER BY created_at
                LIMIT 1
            ", [$quantity, $product_id, $location_id, $quantity]);
            
            $db->commit();
            
            log_action('QUICK_SHIPMENT', "Быстрая отгрузка: {$quantity} ед. товара {$product['article']}");
            
            echo json_encode([
                'success' => true,
                'message' => 'Отгрузка успешно выполнена',
                'document_number' => $document_number
            ]);
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        break;
    
    // Поиск мест хранения
    case 'search_locations':
        $query = clean_input($_GET['q'] ?? '');
        $zone = clean_input($_GET['zone'] ?? '');
        $limit = (int)($_GET['limit'] ?? 10);
        
        try {
            $where = ['is_active = 1'];
            $params = [];
            
            if ($query) {
                $where[] = '(code LIKE ? OR name LIKE ?)';
                $search_term = "%{$query}%";
                array_push($params, $search_term, $search_term);
            }
            
            if ($zone) {
                $where[] = 'zone = ?';
                $params[] = $zone;
            }
            
            $where_clause = 'WHERE ' . implode(' AND ', $where);
            $params[] = $limit;
            
            $locations = db_fetch_all("
                SELECT id, code, name, zone
                FROM storage_locations
                {$where_clause}
                ORDER BY zone, code
                LIMIT ?
            ", $params);
            
            echo json_encode(['success' => true, 'locations' => $locations]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        break;
    
    // Автодополнение
    case 'autocomplete':
        $type = clean_input($_GET['type'] ?? '');
        $query = clean_input($_GET['q'] ?? '');
        
        if (empty($type) || empty($query)) {
            echo json_encode(['success' => false, 'message' => 'Неверные параметры']);
            break;
        }
        
        try {
            switch ($type) {
                case 'products':
                    $results = db_fetch_all("
                        SELECT id, CONCAT(article, ' - ', name) as text, article, name 
                        FROM products 
                        WHERE is_active = 1 AND is_deleted = 0
                        AND (article LIKE ? OR name LIKE ?)
                        ORDER BY article
                        LIMIT 10
                    ", ["%{$query}%", "%{$query}%"]);
                    break;
                    
                case 'suppliers':
                    $results = db_fetch_all("
                        SELECT DISTINCT supplier as text
                        FROM batches 
                        WHERE supplier LIKE ?
                        ORDER BY supplier
                        LIMIT 10
                    ", ["%{$query}%"]);
                    break;
                    
                case 'customers':
                    $results = db_fetch_all("
                        SELECT DISTINCT counterparty as text
                        FROM documents 
                        WHERE counterparty LIKE ?
                        ORDER BY counterparty
                        LIMIT 10
                    ", ["%{$query}%"]);
                    break;
                    
                default:
                    $results = [];
            }
            
            echo json_encode(['success' => true, 'results' => $results]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        break;
    
    // Тестовое действие для проверки AJAX
    case 'test':
        echo json_encode([
            'success' => true,
            'message' => 'AJAX работает!',
            'timestamp' => date('Y-m-d H:i:s'),
            'user' => $_SESSION['username'] ?? 'Гость'
        ]);
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'Неизвестное действие: ' . $action]);
}

// Очищаем буфер
ob_end_flush();
exit();