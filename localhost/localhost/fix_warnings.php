<?php
/**
 * Быстрый фикс для всех модулей
 */

// 1. Функция безопасного получения данных из массива
if (!function_exists('safe_array_get')) {
    function safe_array_get($array, $key, $default = null) {
        if (!is_array($array)) {
            return $default;
        }
        return isset($array[$key]) ? $array[$key] : $default;
    }
}

// 2. Обновленный форматтер с защитой
if (!function_exists('safe_format_number')) {
    function safe_format_number($number, $decimals = 3) {
        if ($number === null || $number === '' || !is_numeric($number)) {
            return '0';
        }
        
        $number = (float)$number;
        if ($number == 0) {
            return '0';
        }
        return number_format($number, $decimals, ',', ' ');
    }
}

// 3. Обновленная функция format_date
if (!function_exists('safe_format_date')) {
    function safe_format_date($date, $format = 'd.m.Y H:i') {
        if (empty($date) || $date == '0000-00-00 00:00:00' || $date == '0000-00-00' || !strtotime($date)) {
            return '';
        }
        return date($format, strtotime($date));
    }
}

// 4. Безопасное получение GET параметров
if (!function_exists('safe_get_param')) {
    function safe_get_param($param, $default = '') {
        return isset($_GET[$param]) ? clean_input($_GET[$param]) : $default;
    }
}

// 5. Безопасное получение POST параметров
if (!function_exists('safe_post_param')) {
    function safe_post_param($param, $default = '') {
        return isset($_POST[$param]) ? clean_input($_POST[$param]) : $default;
    }
}

// 6. Обновленная функция clean_input с защитой
if (!function_exists('safe_clean_input')) {
    function safe_clean_input($data) {
        if (is_array($data)) {
            return array_map('safe_clean_input', $data);
        }
        
        if ($data === null || $data === '' || !is_scalar($data)) {
            return '';
        }
        
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        
        return $data;
    }
}

// Переопределяем старые функции на безопасные
if (!function_exists('clean_input')) {
    function clean_input($data) {
        return safe_clean_input($data);
    }
}

if (!function_exists('format_number')) {
    function format_number($number, $decimals = 3) {
        return safe_format_number($number, $decimals);
    }
}

if (!function_exists('format_date')) {
    function format_date($date, $format = 'd.m.Y H:i') {
        return safe_format_date($date, $format);
    }
}