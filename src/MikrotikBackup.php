<?php

namespace MikrotikBackup;

use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

class MikrotikBackup
{
    private $logger;
    private $backupPath;
    private $enableEncryption;
    private $gpgRecipient;
    private $config;
    private $zabbix;
    private $validator;
    private $telegram;
    private $args = [];

    public function __construct(array $config, Logger $logger)
    {
        $this->logger = $logger;
        $this->zabbix = new ZabbixAPI($config, $this->logger);
        $this->backupPath = rtrim($config['backup_path'], '/');
        $this->enableEncryption = $config['enable_encryption'] ?? false;
        $this->gpgRecipient = $config['gpg_recipient'] ?? null;
        $this->config = $config;
        $this->validator = new Validator\BackupValidator();
        
        // Сохраняем аргументы
        $this->args = $_SERVER['argv'];
        
        // Проверяем настройки Telegram
        $this->logger->debug('telegram', sprintf(
            'Проверка конфигурации Telegram: token=%s, chat_id=%s',
            !empty($config['telegram_bot_token']) ? 'задан' : 'не задан',
            !empty($config['telegram_chat_id']) ? 'задан' : 'не задан'
        ));
        
        if (!empty($config['telegram_bot_token']) && !empty($config['telegram_chat_id'])) {
            $this->telegram = new Notifier\Telegram($config, $this->logger);
            $this->logger->debug('telegram', '✅ Telegram успешно инициализирован');
        } else {
            $this->logger->debug('telegram', '⚠️ Telegram не инициализирован - отсутствуют настройки');
        }
    }

    public function run(): void
    {
        $startTime = microtime(true);
        
        try {
            $hasErrors = false;
            
            // Проверяем аргументы
            $reportToTelegram = in_array('--report-telegram', $this->args);
            
            // Проверяем день недели для автоматической отправки отчета
            $currentDay = date('N'); // 1 (пн) - 7 (вс)
            $isSaturday = ($currentDay == 6);
            
            if ($isSaturday) {
                $reportToTelegram = true;
                $this->logger->debug('telegram', '📅 Сегодня суббота - включаем отправку отчета');
            }
            
            $singleDevice = null;
            foreach ($this->args as $arg) {
                if (strpos($arg, '--device=') === 0) {
                    $singleDevice = substr($arg, 9);
                    break;
                }
            }
            
            $this->logger->debug('telegram', sprintf(
                'Параметры запуска: device="%s", report_telegram=%s, is_saturday=%s',
                $singleDevice ?? 'все',
                $reportToTelegram ? 'ДА' : 'НЕТ',
                $isSaturday ? 'ДА' : 'НЕТ'
            ));

            $this->logger->debug('telegram', '=== СТАРТ СКРИПТА ===');
            $this->logger->debug('telegram', sprintf(
                'Аргументы командной строки (всего %d):', 
                count($this->args)
            ));
            
            foreach ($this->args as $index => $arg) {
                $this->logger->debug('telegram', sprintf(
                    'Аргумент %d: "%s"',
                    $index,
                    $arg
                ));
            }

            // Проверяем настройки Telegram
            if ($reportToTelegram) {
                if (!isset($this->telegram)) {
                    $this->logger->error('telegram', '❌ Telegram не инициализирован');
                    return;
                }
                $this->logger->debug('telegram', '✅ Telegram готов к отправке отчета');
            }

            $this->logger->info('system', '🚀 Начало процесса резервного копирования');

            // Получаем список устройств
            $devices = $this->zabbix->getMikrotikDevices($this->config['zabbix_group_id']);

            // Фильтруем устройства если указано конкретное
            if ($singleDevice) {
                $devices = array_filter($devices, function($device) use ($singleDevice) {
                    return $device['host'] === $singleDevice || 
                           $device['name'] === $singleDevice || 
                           $device['ip'] === $singleDevice;
                });
            }

            if (empty($devices)) {
                $this->logger->warning('system', 'Не найдено устройств для резервного копирования');
                $this->zabbix->sendBackupStatus('main', 1, 'main.error');
                exit(1);
            }

            // Проверяем режим работы
            $asyncMode = in_array('--async', $this->args);
            $this->logger->debug('system', sprintf(
                'Режим работы: %s',
                $asyncMode ? 'асинхронный' : 'последовательный'
            ));

            if ($asyncMode) {
                // Асинхронный режим
                $async = new AsyncBackup($this->config, $this->logger);
                $results = $async->runAsync($devices);
                
                // Обновляем статусы в Zabbix
                foreach ($results as $result) {
                    $device = $result['device'];
                    $this->zabbix->sendBackupStatus(
                        $device['hostid'],
                        $result['success'] ? 0 : 1
                    );
                }
                
                // Проверяем общий результат
                $hasErrors = count(array_filter($results, function($r) {
                    return !$r['success'];
                })) > 0;
                
            } else {
                // Последовательный режим
                foreach ($devices as $device) {
                    try {
                        $this->processDevice($device);
                        $this->zabbix->sendBackupStatus($device['hostid'], 0);
                    } catch (\Exception $e) {
                        $hasErrors = true;
                        $this->logger->error($device['ip'], $e->getMessage());
                        $this->zabbix->sendBackupStatus($device['hostid'], 1);
                    }
                }
            }

            if ($hasErrors) {
                $this->logger->error('system', "❌ Процесс резервного копирования завершен с ошибками");
                exit(1);
            }

            // Отправляем отчет если это суббота или указан флаг
            if ($reportToTelegram) {
                $this->logger->debug('telegram', sprintf(
                    'Отправка отчета: по флагу=%s, по субботе=%s',
                    in_array('--report-telegram', $this->args) ? 'ДА' : 'НЕТ',
                    $isSaturday ? 'ДА' : 'НЕТ'
                ));

                try {
                    // Проверяем путь к бэкапам
                    if (!is_dir($this->backupPath)) {
                        throw new \RuntimeException("Директория бэкапов не существует: {$this->backupPath}");
                    }

                    $this->logger->debug('telegram', sprintf(
                        'Параметры отправки отчета: path=%s, exists=%s, is_readable=%s',
                        $this->backupPath,
                        is_dir($this->backupPath) ? 'yes' : 'no',
                        is_readable($this->backupPath) ? 'yes' : 'no'
                    ));

                    $this->telegram->sendBackupReport($this->backupPath);
                    $this->logger->debug('telegram', '✅ Отчет успешно отправлен');
                } catch (\Exception $e) {
                    $this->logger->error('telegram', sprintf(
                        '❌ Ошибка отправки отчета: %s (trace: %s)',
                        $e->getMessage(),
                        $e->getTraceAsString()
                    ));
                }
            } else {
                $this->logger->debug('telegram', sprintf(
                    '❌ Пропускаем отправку отчета - флаг не установлен (args: %s)',
                    implode(' ', $this->args)
                ));
            }

            // Запускаем очистку старых бэкапов
            try {
                $this->cleanupOldBackups($this->config['retention_days']);
            } catch (\Exception $e) {
                $this->logger->error('system', 'Ошибка при очистке старых бэкапов: ' . $e->getMessage());
            }

            $this->logger->success('system', "✅ Процесс резервного копирования завершен успешно");
            $this->zabbix->sendBackupStatus('main', 0, 'main.error');

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            $this->logger->info('system', sprintf(
                '⏱️ Время выполнения: %s сек.',
                $duration
            ));

        } catch (\Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            $this->logger->error('system', sprintf(
                '❌ Критическая ошибка после %s сек.: %s',
                $duration,
                $e->getMessage()
            ));
            $this->zabbix->sendBackupStatus('main', 1, 'main.error');
            exit(1);
        }
    }

    private function processDevice(array $device): void
    {
        $this->logger->info($device['ip'], 'Обработка устройства ' . $device['name']);
        
        try {
            // Создаем бэкап
            $backupResult = $this->createBackup($device);
            
            if (!$backupResult['success']) {
                // Отправляем детальную информацию об ошибке в Zabbix
                $this->zabbix->sendBackupStatus($device['hostid'], 1);
                
                // Если есть информация о валидации, добавляем её в лог
                if (isset($backupResult['validation'])) {
                    $this->logger->error($device['ip'], sprintf(
                        "Ошибки валидации: %s", 
                        implode(', ', $backupResult['validation']['errors'])
                    ));
                }
                
                throw new \RuntimeException($backupResult['error'] ?? 'Ошибка создания бэкапа');
            }
            
            $this->logger->success(
                $device['ip'], 
                sprintf(
                    'Бэкап успешно создан и валидирован (размер: %s)', 
                    $this->formatSize($backupResult['size'])
                )
            );
            
            // Отправляем успешный статус в Zabbix
            $this->zabbix->sendBackupStatus($device['hostid'], 0);
            
        } catch (\Exception $e) {
            $this->logger->error($device['ip'], 'Ошибка при создании бэкапа: ' . $e->getMessage());
            $this->zabbix->sendBackupStatus($device['hostid'], 1);
            throw $e;
        }
    }

    /**
     * Создание бэкапа для устройства
     */
    public function createBackup(array $device): array
    {
        try {
            $this->logger->info($device['ip'], 'Начинаем процесс бэкапа');
            
            // Подключаемся по SSH
            $this->logger->info($device['ip'], 'Подключение по SSH...');
            $ssh = $this->connect($device);
            $version = $this->getRouterOSVersion($ssh);
            $this->logger->info($device['ip'], "Версия RouterOS: $version");
            $this->logger->info($device['ip'], 'SSH подключение установлено');
            
            // Создаем имя файла для бэкапа
            $backupName = date('Y-m-d') . "_{$device['ip']}";
            $devicePath = "{$this->backupPath}/{$device['ip']}";
            $localBackupFile = "$devicePath/$backupName.backup";
            
            $this->logger->debug($device['ip'], "Параметры бэкапа:");
            $this->logger->debug($device['ip'], "- Имя файла: $backupName");
            $this->logger->debug($device['ip'], "- Путь: $devicePath");
            $this->logger->debug($device['ip'], "- Полный путь: $localBackupFile");
            
            // Проверяем и создаем директорию
            $this->logger->info($device['ip'], "Создаем директорию: $devicePath");
            if (!is_dir($devicePath)) {
                if (!mkdir($devicePath, 0755, true)) {
                    throw new \RuntimeException("Не удалось создать директорию: $devicePath");
                }
            }

            // Проверяем права на директорию
            $perms = substr(sprintf('%o', fileperms($devicePath)), -4);
            $this->logger->debug($device['ip'], "Права на директорию: $perms");
            
            // Проверяем возможность записи
            if (!is_writable($devicePath)) {
                throw new \RuntimeException("Нет прав на запись в директорию: $devicePath");
            }

            // Выполняем команду бэкапа
            $this->logger->info($device['ip'], "Выполняем команду бэкапа: /system backup save name=$backupName");
            $result = $ssh->exec("/system backup save name=$backupName");
            
            if (stripos($result, 'failure') !== false || stripos($result, 'error') !== false) {
                throw new \RuntimeException("Ошибка создания бэкапа на устройстве: $result");
            }
            
            $this->logger->info($device['ip'], "Результат команды бэкапа: $result");
            
            // Ждем создания файла и проверяем его
            $maxAttempts = 10;
            $attempt = 0;
            $fileFound = false;
            $backupFileName = $backupName . '.backup';
            
            while ($attempt < $maxAttempts && !$fileFound) {
                sleep(2);
                $files = $ssh->exec("/file print terse");
                $this->logger->debug($device['ip'], "Попытка $attempt, список файлов:\n$files");
                
                if (strpos($files, $backupFileName) !== false) {
                    $fileFound = true;
                    break;
                }
                $attempt++;
            }
            
            if (!$fileFound) {
                throw new \RuntimeException('Файл бэкапа не создан на устройстве после нескольких попыток');
            }

            // Скачиваем файл через SFTP
            $this->logger->info($device['ip'], "Скачиваем файл бэкапа через SFTP...");
            $sftp = new SFTP($device['ip'], $this->config['ssh_port'] ?? 22);
            if (!$sftp->login($device['username'], $device['password'])) {
                throw new \RuntimeException('Ошибка подключения по SFTP');
            }

            // Скачиваем файл
            if (!$sftp->get($backupFileName, $localBackupFile)) {
                $error = error_get_last();
                throw new \RuntimeException('Ошибка при скачивании файла бэкапа: ' . ($error['message'] ?? 'неизвестная ошибка'));
            }

            // Проверяем локальный файл
            if (!file_exists($localBackupFile)) {
                throw new \RuntimeException("Файл бэкапа не найден локально: $localBackupFile");
            }

            $localFileSize = filesize($localBackupFile);
            if ($localFileSize === 0) {
                throw new \RuntimeException("Файл бэкапа пустой: $localBackupFile");
            }

            // Проверяем формат файла
            if (!$this->checkBackupFormat($localBackupFile, $device)) {
                throw new \RuntimeException("Файл бэкапа поврежден или имеет неверный формат");
            }

            // После успешного скачивания файла добавляем валидацию
            $validationResult = $this->validator->validateFile($localBackupFile);
            
            if (!$validationResult['valid']) {
                $errorMsg = sprintf(
                    "Ошибка валидации бэкапа: %s", 
                    implode(', ', $validationResult['errors'])
                );
                $this->logger->error($device['ip'], $errorMsg);
                
                // Удаляем невалидный файл
                unlink($localBackupFile);
                
                return [
                    'success' => false,
                    'error' => $errorMsg
                ];
            }

            $this->logger->info($device['ip'], sprintf(
                "Файл успешно скачан, проверен и валидирован (размер: %s)",
                $this->formatSize($validationResult['size'])
            ));

            // Теперь можно удалить файл с устройства
            $ssh->exec("/file remove \"$backupFileName\"");

            // Проверяем что файл удален
            $filesAfterRemove = $ssh->exec("/file print");
            if (strpos($filesAfterRemove, $backupFileName) !== false) {
                $this->logger->warning($device['ip'], "⚠️ Файл бэкапа не удален с устройства, пробуем еще раз");
                
                // Пробуем удалить через другую команду для ROS6
                $ssh->exec("/file remove [find name=\"$backupFileName\"]");
                
                // Проверяем еще раз
                $finalCheck = $ssh->exec("/file print");
                if (strpos($finalCheck, $backupFileName) !== false) {
                    $this->logger->error($device['ip'], "❌ Не удалось удалить файл бэкапа с устройства");
                } else {
                    $this->logger->info($device['ip'], "✅ Файл бэкапа успешно удален с устройства (2-я попытка)");
                }
            } else {
                $this->logger->info($device['ip'], "✅ Файл бэкапа успешно удален с устройства");
            }

            return [
                'success' => true,
                'size' => $validationResult['size'],
                'path' => $localBackupFile,
                'validation' => $validationResult
            ];

        } catch (\Exception $e) {
            // Если файл создался локально, но что-то пошло не так - удаляем его
            if (isset($localBackupFile) && file_exists($localBackupFile)) {
                unlink($localBackupFile);
                $this->logger->info($device['ip'], "Удален неполный локальный файл бэкапа");
            }
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Очистка старых бэкапов
     */
    public function cleanupOldBackups(int $retentionDays): void
    {
        $this->logger->info('system', 'Начало очистки старых бэкапов');
        
        $cutoffDate = strtotime("-$retentionDays days");
        $today = date('Y-m-d');
        
        foreach (glob("{$this->backupPath}/*", GLOB_ONLYDIR) as $deviceDir) {
            // Получаем список всех бэкапов для устройства
            $backupFiles = glob("$deviceDir/*.backup*");
            if (empty($backupFiles)) {
                continue; // Пропускаем если нет бэкапов
            }

            // Сортируем файлы по дате создания (новые первые)
            usort($backupFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            // Получаем дату последнего бэкапа
            $latestBackup = $backupFiles[0];
            $latestBackupDate = date('Y-m-d', filemtime($latestBackup));

            // Если сегодня не было успешного бэкапа, сохраняем все файлы
            if ($latestBackupDate !== $today) {
                $this->logger->info('system', "Пропускаем очистку для " . basename($deviceDir) . " - нет свежего бэкапа");
                continue;
            }

            // Удаляем старые файлы, оставляя как минимум один
            $filesCount = count($backupFiles);
            foreach ($backupFiles as $index => $backupFile) {
                // Всегда оставляем последний бэкап
                if ($index === 0) {
                    continue;
                }

                // Проверяем дату файла
                if (filemtime($backupFile) < $cutoffDate) {
                    unlink($backupFile);
                    $this->logger->info('system', sprintf(
                        "Удален старый бэкап: %s (возраст: %d дней)",
                        basename($backupFile),
                        floor((time() - filemtime($backupFile)) / 86400)
                    ));
                }
            }
        }
        
        $this->logger->info('system', 'Очистка старых бэкапов завершена');
    }

    /**
     * Подключение к устройству по SSH
     */
    private function connect(array $device): SSH2
    {
        $ip = $device['ip'];
        $port = $this->config['ssh_port'] ?? 22;

        // Проверяем доступность порта
        $socket = @fsockopen($ip, $port, $errno, $errstr, 5);
        if (!$socket) {
            throw new \RuntimeException(
                sprintf("Порт %d на %s недоступен: %s", $port, $ip, $errstr)
            );
        }
        fclose($socket);

        $this->logger->info($device['ip'], sprintf(
            "Подключение к %s (%s:%d)...", 
            $device['name'], 
            $ip,
            $port
        ));
        
        $ssh = new SSH2($ip, $port);
        
        if (!$ssh->login($device['username'], $device['password'])) {
            throw new \RuntimeException('Ошибка аутентификации SSH');
        }

        return $ssh;
    }

    /**
     * Шифрование файла бэкапа
     */
    private function encryptBackup(string $file): void
    {
        $command = sprintf(
            'gpg --encrypt --recipient "%s" --trust-model always --output "%s.gpg" "%s"',
            escapeshellarg($this->gpgRecipient),
            escapeshellarg($file),
            escapeshellarg($file)
        );

        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \RuntimeException('Ошибка при шифровании файла');
        }

        // Удаляем незашифрованный файл
        unlink($file);
    }

    private function formatSize(int $bytes): string 
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    private function checkBackupFormat(string $localBackupFile, array $device): bool
    {
        $fileHandle = fopen($localBackupFile, 'rb');
        if (!$fileHandle) {
            throw new \RuntimeException("Не удалось открыть файл для проверки: $localBackupFile");
        }

        $header = fread($fileHandle, 8);
        fclose($fileHandle);
        
        // Выводим первые байты для отладки
        $this->logger->debug($device['ip'], "Первые байты файла: " . bin2hex($header));
        
        // Проверяем сигнатуры для разных версий ROS
        $validSignatures = [
            'BACKUP2',  // ROS 7
            hex2bin('88aca1b1') // ROS 6
        ];
        
        foreach ($validSignatures as $signature) {
            if (strpos($header, $signature) === 0) {
                $this->logger->debug($device['ip'], "Обнаружена валидная сигнатура бэкапа");
                return true;
            }
        }
        
        return false;
    }

    private function getRouterOSVersion(SSH2 $ssh): string
    {
        $result = $ssh->exec('/system resource print');
        if (preg_match('/version: ([0-9.]+)/', $result, $matches)) {
            return $matches[1];
        }
        return 'unknown';
    }
} 