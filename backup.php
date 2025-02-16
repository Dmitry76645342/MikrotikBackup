<?php

namespace MikrotikBackup;

require_once __DIR__ . '/vendor/autoload.php';

use MikrotikBackup\MikrotikBackup;
use MikrotikBackup\Logger;

/**
 * Основной скрипт для создания бэкапов MikroTik
 * 
 * Использование:
 * php backup.php             - бэкап всех устройств
 * php backup.php --device=IP - бэкап конкретного устройства
 */

// Проверяем наличие флага --help
if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo <<<HELP
Скрипт резервного копирования MikroTik

Использование:
    php backup.php [опции]

Опции:
    --help, -h              Показать эту справку
    --device=DEVICE         Создать бэкап только для указанного устройства
                           DEVICE может быть именем хоста, IP или названием из Zabbix
                           Пример: --device="Mikrotik 333 - RB3011UiAS"
                           
    --report-telegram       Отправить отчет в Telegram после создания бэкапов
                           Отчет также отправляется автоматически по субботам
                           
    --debug=CATEGORY        Включить отладочные сообщения для категории
                           Категории: telegram, system, all
                           Пример: --debug="telegram"

    --async                Использовать асинхронный режим для параллельного
                          создания бэкапов (ускоряет работу при большом
                          количестве устройств)

Примеры:
    php backup.php                                          # Бэкап всех устройств
    php backup.php --device="192.168.1.1"                  # Бэкап конкретного IP
    php backup.php --report-telegram                       # Бэкап + отчет в Telegram
    php backup.php --device="Office Router" --debug="all"  # Бэкап с отладкой

HELP;
    exit(0);
}

try {
    // Загружаем конфигурацию
    $config = require __DIR__ . '/config/config.php';
    
    // Инициализируем логгер
    $logger = new Logger($config['log_file']);
    
    // Отладка аргументов в лог
    $logger->debug('system', '=== Аргументы скрипта ===');
    $logger->debug('system', print_r($argv, true));
    $logger->debug('system', '========================');
    
    // Создаем и запускаем бэкап
    $backup = new MikrotikBackup($config, $logger);
    $backup->run();
    
} catch (\Exception $e) {
    if (isset($logger)) {
        $logger->error('system', 'Критическая ошибка: ' . $e->getMessage());
    } else {
        echo "Критическая ошибка: " . $e->getMessage() . "\n";
    }
    exit(1);
} 