            </div> <!-- Закрытие основного содержимого -->
        </div> <!-- Закрытие row -->
    </div> <!-- Закрытие container-fluid -->
    
    <!-- Футер -->
    <footer class="footer mt-auto py-3 bg-light border-top">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <span class="text-muted">
                        <?php echo SITE_NAME; ?> &copy; <?php echo date('Y'); ?> 
                        | Версия 1.0
                    </span>
                </div>
                <div class="col-md-6 text-end">
                    <span class="text-muted">
                        <?php echo $_SESSION['full_name'] ?? 'Гость'; ?> 
                        | <?php echo date('d.m.Y H:i:s'); ?>
                        <?php if (isset($_SESSION['login_time'])): ?>
                            | Время сессии: <?php echo gmdate("H:i:s", time() - $_SESSION['login_time']); ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Скрипты -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?php echo JS_PATH; ?>main.js"></script>
    
    <script>
    // Автоматическое скрытие алертов
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
    
    // Инициализация DataTables
    $(document).ready(function() {
        $('.data-table').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/ru.json'
            },
            pageLength: 25,
            responsive: true
        });
    });
    
    // Подтверждение удаления
    $(document).on('click', '.btn-delete', function(e) {
        if (!confirm('Вы уверены, что хотите удалить эту запись?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Подтверждение критических действий
    $(document).on('click', '.btn-critical', function(e) {
        if (!confirm('Это действие невозможно отменить. Продолжить?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Автообновление времени сессии
    function updateSessionTime() {
        if ($('#session-time').length) {
            const startTime = <?php echo $_SESSION['login_time'] ?? '0'; ?>;
            const currentTime = Math.floor(Date.now() / 1000);
            const diff = currentTime - startTime;
            
            const hours = Math.floor(diff / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;
            
            $('#session-time').text(
                hours.toString().padStart(2, '0') + ':' +
                minutes.toString().padStart(2, '0') + ':' +
                seconds.toString().padStart(2, '0')
            );
        }
    }
    
    // Обновляем каждую секунду
    setInterval(updateSessionTime, 1000);
    updateSessionTime();
    </script>
</body>
</html>
<?php
// Завершаем буферизацию и выводим все
ob_end_flush();
?>