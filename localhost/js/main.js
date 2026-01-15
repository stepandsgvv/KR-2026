// Основные функции JavaScript для системы "СкладPRO"

// Глобальные переменные
const appConfig = {
    siteName: 'СкладPRO',
    apiUrl: '/api/',
    sessionTimeout: 3600000, // 1 час в миллисекундах
    autoSaveInterval: 30000, // 30 секунд
    dateFormat: 'DD.MM.YYYY',
    timeFormat: 'HH:mm:ss'
};

// Инициализация при загрузке страницы
$(document).ready(function() {
    initApplication();
    setupEventListeners();
    startAutoSave();
    startSessionTimer();
});

// Инициализация приложения
function initApplication() {
    // Инициализация DataTables
    $('.data-table').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/ru.json'
        },
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        responsive: true,
        order: [],
        dom: '<"row"<"col-md-6"l><"col-md-6"f>>rt<"row"<"col-md-6"i><"col-md-6"p>>',
        stateSave: true
    });
    
    // Инициализация тултипов
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Инициализация попапов
    $('[data-bs-toggle="popover"]').popover();
    
    // Настройка выпадающих календарей
    $('.datepicker').datepicker({
        format: 'dd.mm.yyyy',
        language: 'ru',
        autoclose: true,
        todayHighlight: true
    });
    
    // Маска для телефона
    $('.phone-mask').mask('+7 (999) 999-99-99');
    
    // Маска для чисел
    $('.number-mask').inputmask('numeric', {
        radixPoint: ",",
        groupSeparator: " ",
        digits: 3,
        autoGroup: true,
        prefix: '',
        rightAlign: false,
        removeMaskOnSubmit: true
    });
}

// Настройка обработчиков событий
function setupEventListeners() {
    // Подтверждение удаления
    $(document).on('click', '.confirm-delete', function(e) {
        e.preventDefault();
        const message = $(this).data('confirm') || 'Вы уверены, что хотите удалить эту запись?';
        const url = $(this).attr('href');
        
        if (confirm(message)) {
            window.location = url;
        }
    });
    
    // Подтверждение критических действий
    $(document).on('click', '.confirm-action', function(e) {
        e.preventDefault();
        const message = $(this).data('confirm') || 'Это действие невозможно отменить. Продолжить?';
        const url = $(this).attr('href');
        
        if (confirm(message)) {
            window.location = url;
        }
    });
    
    // Быстрый поиск
    $('#quick-search').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('.searchable-row').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(searchTerm));
        });
    });
    
    // Копирование в буфер обмена
    $(document).on('click', '.copy-to-clipboard', function() {
        const text = $(this).data('copy');
        navigator.clipboard.writeText(text).then(function() {
            showToast('Скопировано в буфер обмена', 'success');
        });
    });
    
    // Показать/скрыть пароль
    $(document).on('click', '.toggle-password', function() {
        const input = $(this).closest('.input-group').find('input');
        const icon = $(this).find('i');
        
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('bi-eye').addClass('bi-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('bi-eye-slash').addClass('bi-eye');
        }
    });
    
    // Динамическая загрузка форм
    $(document).on('click', '.load-form', function(e) {
        e.preventDefault();
        const url = $(this).data('url');
        const target = $(this).data('target') || '#modal-content';
        
        $.get(url, function(data) {
            $(target).html(data);
            $('.modal').modal('show');
        });
    });
    
    // Автоматическое сохранение формы
    let autoSaveTimer;
    $(document).on('input', '.autosave-form :input', function() {
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(saveForm, appConfig.autoSaveInterval);
    });
}

// Функция автозапуска сохранения
function startAutoSave() {
    if ($('.autosave-form').length > 0) {
        setInterval(saveForm, appConfig.autoSaveInterval * 2);
    }
}

// Сохранение формы
function saveForm() {
    const form = $('.autosave-form');
    if (form.length === 0) return;
    
    const formData = new FormData(form[0]);
    formData.append('autosave', '1');
    
    $.ajax({
        url: form.attr('action') || window.location.href,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        beforeSend: function() {
            $('.autosave-status').html('<span class="text-warning">Сохранение...</span>');
        },
        success: function(response) {
            $('.autosave-status').html('<span class="text-success">Автосохранено ' + new Date().toLocaleTimeString() + '</span>');
        },
        error: function() {
            $('.autosave-status').html('<span class="text-danger">Ошибка сохранения</span>');
        }
    });
}

// Таймер сессии
function startSessionTimer() {
    let lastActivity = Date.now();
    
    // Обновление времени активности
    $(document).on('mousemove keydown click scroll', function() {
        lastActivity = Date.now();
    });
    
    // Проверка неактивности
    setInterval(function() {
        const idleTime = Date.now() - lastActivity;
        
        if (idleTime > appConfig.sessionTimeout) {
            showSessionWarning();
        }
        
        // Обновление отображения времени сессии
        if ($('#session-timer').length) {
            const sessionStart = $('#session-timer').data('start');
            const elapsed = Math.floor((Date.now() - sessionStart) / 1000);
            const hours = Math.floor(elapsed / 3600);
            const minutes = Math.floor((elapsed % 3600) / 60);
            const seconds = elapsed % 60;
            
            $('#session-timer').text(
                hours.toString().padStart(2, '0') + ':' +
                minutes.toString().padStart(2, '0') + ':' +
                seconds.toString().padStart(2, '0')
            );
        }
    }, 1000);
}

// Предупреждение о сессии
function showSessionWarning() {
    if (!$('#session-warning-modal').length) {
        const modalHtml = `
            <div class="modal fade" id="session-warning-modal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-warning">
                            <h5 class="modal-title">Предупреждение о сессии</h5>
                        </div>
                        <div class="modal-body">
                            <p>Ваша сессия скоро закончится из-за неактивности.</p>
                            <p>Продолжить работу?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Продолжить</button>
                            <a href="logout.php" class="btn btn-primary">Выйти</a>
                        </div>
                    </div>
                </div>
            </div>
        `;
        $('body').append(modalHtml);
    }
    
    $('#session-warning-modal').modal('show');
}

// Показать уведомление
function showToast(message, type = 'info') {
    if (!$('#toast-container').length) {
        $('body').append('<div id="toast-container" class="toast-container position-fixed top-0 end-0 p-3"></div>');
    }
    
    const toastId = 'toast-' + Date.now();
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    
    $('#toast-container').append(toastHtml);
    const toast = new bootstrap.Toast(document.getElementById(toastId));
    toast.show();
    
    // Удаление после скрытия
    document.getElementById(toastId).addEventListener('hidden.bs.toast', function() {
        $(this).remove();
    });
}

// Валидация форм
function validateForm(form) {
    let isValid = true;
    const errors = [];
    
    $(form).find('[required]').each(function() {
        if (!$(this).val().trim()) {
            isValid = false;
            $(this).addClass('is-invalid');
            errors.push(`Поле "${$(this).attr('placeholder') || $(this).attr('name')}" обязательно для заполнения`);
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    
    // Валидация email
    $(form).find('[type="email"]').each(function() {
        const email = $(this).val();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        if (email && !emailRegex.test(email)) {
            isValid = false;
            $(this).addClass('is-invalid');
            errors.push('Неверный формат email');
        }
    });
    
    // Валидация чисел
    $(form).find('[type="number"]').each(function() {
        const min = $(this).attr('min');
        const max = $(this).attr('max');
        const value = parseFloat($(this).val());
        
        if (min !== undefined && value < parseFloat(min)) {
            isValid = false;
            $(this).addClass('is-invalid');
            errors.push(`Значение должно быть не менее ${min}`);
        }
        
        if (max !== undefined && value > parseFloat(max)) {
            isValid = false;
            $(this).addClass('is-invalid');
            errors.push(`Значение должно быть не более ${max}`);
        }
    });
    
    if (!isValid) {
        showToast(errors.join('<br>'), 'danger');
    }
    
    return isValid;
}

// AJAX запросы
function ajaxRequest(url, data, method = 'POST') {
    return new Promise((resolve, reject) => {
        $.ajax({
            url: url,
            method: method,
            data: data,
            dataType: 'json',
            success: function(response) {
                resolve(response);
            },
            error: function(xhr, status, error) {
                reject(error);
            }
        });
    });
}

// Форматирование даты
function formatDate(date, format = appConfig.dateFormat) {
    const d = new Date(date);
    const day = d.getDate().toString().padStart(2, '0');
    const month = (d.getMonth() + 1).toString().padStart(2, '0');
    const year = d.getFullYear();
    const hours = d.getHours().toString().padStart(2, '0');
    const minutes = d.getMinutes().toString().padStart(2, '0');
    const seconds = d.getSeconds().toString().padStart(2, '0');
    
    return format
        .replace('DD', day)
        .replace('MM', month)
        .replace('YYYY', year)
        .replace('HH', hours)
        .replace('mm', minutes)
        .replace('ss', seconds);
}

// Форматирование чисел
function formatNumber(number, decimals = 2) {
    return new Intl.NumberFormat('ru-RU', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    }).format(number);
}

// Расчет остатков
function calculateStock(productId, callback) {
    $.getJSON(appConfig.apiUrl + 'stock/' + productId, function(data) {
        if (callback) callback(data);
    });
}

// Поиск товаров
function searchProducts(query, callback) {
    $.getJSON(appConfig.apiUrl + 'products/search', { q: query }, function(data) {
        if (callback) callback(data);
    });
}

// Экспорт данных
function exportData(type, params = {}) {
    const queryString = new URLSearchParams(params).toString();
    window.open(appConfig.apiUrl + 'export/' + type + '?' + queryString, '_blank');
}

// Печать отчета
function printReport(elementId) {
    const printContent = document.getElementById(elementId).innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = printContent;
    window.print();
    document.body.innerHTML = originalContent;
    window.location.reload();
}

// Глобальные обработчики ошибок
window.onerror = function(message, source, lineno, colno, error) {
    console.error('Ошибка:', message, 'в', source, 'строка', lineno);
    showToast('Произошла ошибка. Проверьте консоль для подробностей.', 'danger');
    return true;
};

// Обработка обещаний
window.addEventListener('unhandledrejection', function(event) {
    console.error('Необработанное обещание:', event.reason);
    showToast('Ошибка выполнения операции', 'danger');
});

// Глобальные функции для использования в других модулях
window.app = {
    showToast,
    validateForm,
    formatDate,
    formatNumber,
    exportData,
    printReport
};