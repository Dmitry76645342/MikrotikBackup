<?php

namespace MikrotikBackup;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $config = require __DIR__ . '/../config/config.php';
    $logger = Logger::getInstance($config['log_file']);
    
    // Очистка старых бэкапов
    $backup = new MikrotikBackup($config, $logger);
    $backup->cleanupOldBackups($config['retention_days']);
    
    // Очистка логов если они больше 10MB
    $logFile = $config['log_file'];
    $maxSize = 10 * 1024 * 1024; // 10MB в байтах
    
    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        $logger->info('system', 'Начало очистки лог файла (превышен размер 10MB)');
        
        // Сохраняем последние 1000 строк
        $lines = file($logFile);
        $linesToKeep = array_slice($lines, -1000);
        
        // Перезаписываем файл
        file_put_contents($logFile, implode('', $linesToKeep));
        
        $logger->info('system', 'Лог файл очищен, сохранены последние 1000 строк');
    }
    
    $logger->info('system', 'Очистка старых бэкапов и логов выполнена успешно');
    exit(0);

} catch (\Exception $e) {
    $logger->error('system', 'Ошибка при очистке: ' . $e->getMessage());
    exit(1);
} 