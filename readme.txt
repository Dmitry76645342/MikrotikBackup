# Система резервного копирования MikroTik

Система автоматического резервного копирования устройств MikroTik с интеграцией в Zabbix.

## Требования

- PHP 7.2 или выше
- Composer
- Zabbix 5.4 или выше
- FTP-сервер для получения бэкапов
- GPG (опционально, для шифрования)

## Установка

1. Клонируем репозиторий:
git clone https://github.com/your-repo/mikrotik-backup.git
cd mikrotik-backup

2. Устанавливаем зависимости:
composer require phpseclib/phpseclib:~3.0

3. Создаем директории для бэкапов:
mkdir -p /home/dmitry/.backup/mikrotiks
chown dmitry:dmitry /home/dmitry/.backup/mikrotiks

4. Настраиваем права доступа:
chmod +x backup.php
chmod +x cron/cleanup.php
sudo touch /var/log/mikrotik_backup.log
sudo chown dmitry:dmitry /var/log/mikrotik_backup.log
chmod 644 /var/log/mikrotik_backup.log

## Настройка

### 1. Конфигурация

Копируем и редактируем конфигурационный файл:
cp config/config.php.example config/config.php

Настраиваем параметры в config/config.php:
return [
    // Zabbix configuration
    'zabbix_url' => 'http://localhost/api_jsonrpc.php',
    'zabbix_user' => 'MikrotikBackupUser',
    'zabbix_pass' => 'your_password',
    'zabbix_group_id' => '37', // ID группы устройств в Zabbix
    
    // Backup configuration
    'backup_path' => '/home/dmitry/.backup/mikrotiks',
    'retention_days' => 60,
    
    // Logging
    'log_file' => '/var/log/mikrotik_backup.log',
    
    // Encryption (optional)
    'enable_encryption' => false,
    'gpg_recipient' => '', // GPG recipient key if encryption is enabled
];

### 2. Настройка Zabbix

1. Импортируем шаблон zabbix_templates/mikrotik_backup_template.xml
2. Создаем группу устройств MikroTik в Zabbix (ID: 37)
3. Для каждого устройства MikroTik добавляем макросы:
   - {$MIKROTIK_USER} - имя пользователя для SSH
   - {$MIKROTIK_PASS} - пароль для SSH
4. Добавляем устройства в группу
5. Создаем хост для мониторинга бэкапов:
   - Имя: MikrotikBackup
   - IP: 127.0.0.1
   - Группа: Backup Systems
   - Шаблоны: Template Mikrotik Backup Monitor
   - Макросы:
     {$BACKUP_PATH} = /home/dmitry/.backup/mikrotiks
     {$RETENTION_DAYS} = 60

### 3. Настройка FTP

На сервере с системой бэкапа должен быть установлен и настроен FTP-сервер для получения бэкапов с устройств MikroTik.

### 4. Настройка CRON

Добавляем задачи в crontab:

# Ежедневное резервное копирование в 2 часа ночи
0 2 * * * /usr/bin/php /home/dmitry/.scripts/BackupMikrotik/backup.php >> /var/log/mikrotik_backup.log 2>&1

# Очистка старых бэкапов каждый день в 4 часа утра
0 4 * * * /usr/bin/php /home/dmitry/.scripts/BackupMikrotik/cron/cleanup.php >> /var/log/mikrotik_backup.log 2>&1

## Использование

### Создание бэкапа всех устройств:
php backup.php

### Создание бэкапа конкретного устройства:
php backup.php --device="Mikrotik Osipenko 2c1 - main"

## Мониторинг

1. В Zabbix все устройства будут автоматически обнаружены через LLD
2. Для каждого устройства создается элемент данных со статусом бэкапа:
   - 0 - бэкап успешен
   - 1 - ошибка бэкапа
3. При ошибке бэкапа срабатывает триггер HIGH приоритета

## Логирование

Все операции логируются в /var/log/mikrotik_backup.log в формате:
[YYYY-MM-DD HH:MM:SS] [IP] [STATUS] [MESSAGE]

## Структура бэкапов

Бэкапы сохраняются в следующей структуре:
/home/dmitry/.backup/mikrotiks/
└── ip_address/
    └── YYYY-MM-DD_ip_address.backup

## Шифрование (опционально)

Для включения шифрования бэкапов:
1. Установите GPG
2. Создайте или импортируйте ключ получателя
3. В конфиге укажите:
'enable_encryption' => true,
'gpg_recipient' => 'your-key-id@example.com'

## Поддержка

При возникновении проблем проверьте:
1. Логи в /var/log/mikrotik_backup.log
2. Доступность Zabbix API
3. Правильность учетных данных в макросах
4. Доступность устройств по SSH
5. Права доступа к директориям и файлам

## Автор

Diman

## Лицензия

MIT 
# Система резервного копирования MikroTik
