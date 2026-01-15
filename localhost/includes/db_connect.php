<?php
class Database {
    private static $connection = null;
    
    public static function getConnection() {
        if (self::$connection === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                self::$connection = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
                ]);
            } catch (PDOException $e) {
                die("Ошибка подключения к базе данных: " . $e->getMessage());
            }
        }
        return self::$connection;
    }
    
    public static function query($sql, $params = []) {
        $db = self::getConnection();
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            self::logError($e, $sql, $params);
            return false;
        }
    }
    
    public static function fetchAll($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }
    
    public static function fetchOne($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetch() : false;
    }
    
    public static function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        
        $db = self::getConnection();
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($data));
            return $db->lastInsertId();
        } catch (PDOException $e) {
            self::logError($e, $sql, array_values($data));
            return false;
        }
    }
    
    public static function update($table, $data, $where, $params = []) {
        $set = [];
        foreach ($data as $key => $value) {
            $set[] = "$key = ?";
        }
        $setClause = implode(', ', $set);
        $sql = "UPDATE $table SET $setClause WHERE $where";
        
        $db = self::getConnection();
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge(array_values($data), $params));
            return $stmt->rowCount();
        } catch (PDOException $e) {
            self::logError($e, $sql, array_merge(array_values($data), $params));
            return false;
        }
    }
    
    public static function delete($table, $where, $params = []) {
        $sql = "DELETE FROM $table WHERE $where";
        return self::query($sql, $params)->rowCount();
    }
    
    private static function logError($exception, $sql, $params) {
        if (DEBUG_MODE) {
            error_log("SQL Error: " . $exception->getMessage());
            error_log("SQL Query: " . $sql);
            error_log("SQL Params: " . print_r($params, true));
        }
    }
}

// Создаем короткие алиасы для удобства
function db_query($sql, $params = []) {
    return Database::query($sql, $params);
}

function db_fetch_all($sql, $params = []) {
    return Database::fetchAll($sql, $params);
}

function db_fetch_one($sql, $params = []) {
    return Database::fetchOne($sql, $params);
}

function db_insert($table, $data) {
    return Database::insert($table, $data);
}

function db_update($table, $data, $where, $params = []) {
    return Database::update($table, $data, $where, $params);
}

function db_delete($table, $where, $params = []) {
    return Database::delete($table, $where, $params);
}
?>