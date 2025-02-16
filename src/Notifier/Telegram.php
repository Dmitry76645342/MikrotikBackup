<?php
namespace MikrotikBackup\Notifier;

class Telegram 
{
    private $botToken;
    private $chatId;
    private $logger;
    
    public function __construct(array $config, $logger) 
    {
        if (empty($config['telegram_bot_token'])) {
            throw new \RuntimeException('Не задан telegram_bot_token в конфигурации');
        }
        if (empty($config['telegram_chat_id'])) {
            throw new \RuntimeException('Не задан telegram_chat_id в конфигурации');
        }

        $this->botToken = $config['telegram_bot_token'];
        $this->chatId = $config['telegram_chat_id'];
        $this->logger = $logger;
        
        $this->logger->debug('telegram', sprintf(
            'Telegram инициализирован: bot_token="%s", chat_id="%s"',
            substr($this->botToken, 0, 10) . '...',
            $this->chatId
        ));
    }
    
    public function sendMessage(string $message): void 
    {
        try {
            $this->logger->debug('telegram', '🚀 Начинаем отправку сообщения в Telegram');
            
            // Проверяем параметры
            if (empty($this->botToken)) {
                throw new \RuntimeException('Не задан токен бота');
            }
            if (empty($this->chatId)) {
                throw new \RuntimeException('Не задан ID чата');
            }
            if (empty($message)) {
                throw new \RuntimeException('Пустое сообщение');
            }

            $this->logger->debug('telegram', sprintf(
                'Параметры отправки: bot_token="%s", chat_id="%s", message_length=%d',
                substr($this->botToken, 0, 10) . '...',
                $this->chatId,
                strlen($message)
            ));

            $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
            
            // Проверяем URL
            $this->logger->debug('telegram', 'URL запроса: ' . str_replace($this->botToken, '***', $url));
            
            $data = [
                'chat_id' => $this->chatId,
                'text' => $message,
                'parse_mode' => 'HTML'
            ];

            $this->logger->debug('telegram', 'Подготовлены данные для отправки');

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_VERBOSE => false,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADER => false
            ]);

            $this->logger->debug('telegram', '📤 Отправляем запрос в Telegram...');
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            $this->logger->debug('telegram', sprintf(
                'Ответ от Telegram: code=%d, response="%s", error="%s"',
                $httpCode,
                substr($response, 0, 100),
                $error
            ));

            if ($error) {
                throw new \RuntimeException('Ошибка CURL: ' . $error);
            }

            if ($httpCode !== 200) {
                throw new \RuntimeException(sprintf(
                    'Ошибка HTTP %d: %s', 
                    $httpCode, 
                    $response
                ));
            }

            $result = json_decode($response, true);
            if (!$result['ok']) {
                throw new \RuntimeException(sprintf(
                    'Ошибка API: %s', 
                    $result['description'] ?? 'неизвестная ошибка'
                ));
            }

            $this->logger->debug('telegram', '✅ Сообщение успешно отправлено');

        } catch (\Exception $e) {
            $this->logger->error('telegram', '❌ Ошибка отправки в Telegram: ' . $e->getMessage());
            throw $e;
        } finally {
            if (isset($ch)) {
                curl_close($ch);
            }
        }
    }

    public function sendBackupReport(string $backupPath): void 
    {
        $this->logger->debug('telegram', '🔍 Начинаем формирование отчета о бэкапах');
        $this->logger->debug('telegram', sprintf(
            'Путь к бэкапам: %s (exists=%s, is_readable=%s, is_dir=%s)',
            $backupPath,
            file_exists($backupPath) ? 'yes' : 'no',
            is_readable($backupPath) ? 'yes' : 'no',
            is_dir($backupPath) ? 'yes' : 'no'
        ));

        $devices = [];
        $directories = glob("{$backupPath}/*", GLOB_ONLYDIR);
        
        $this->logger->debug('telegram', sprintf(
            'Найдено директорий: %d (%s)',
            count($directories),
            implode(', ', array_map('basename', $directories))
        ));

        foreach ($directories as $deviceDir) {
            $this->logger->debug('telegram', sprintf(
                'Обрабатываем директорию: %s (exists=%s, is_readable=%s)',
                $deviceDir,
                is_dir($deviceDir) ? 'yes' : 'no',
                is_readable($deviceDir) ? 'yes' : 'no'
            ));
            
            $backupFiles = glob("$deviceDir/*.backup");
            $this->logger->debug('telegram', sprintf(
                'Найдено файлов бэкапов: %d (%s)',
                count($backupFiles),
                implode(', ', array_map('basename', $backupFiles))
            ));
            
            if (empty($backupFiles)) {
                $this->logger->debug('telegram', 'Пропускаем - нет файлов бэкапов');
                continue;
            }
            
            // Сортируем файлы по дате создания (новые первые)
            usort($backupFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            // Получаем имя устройства из последнего бэкапа
            $latestBackup = basename($backupFiles[0]);
            preg_match('/\d{4}-\d{2}-\d{2}_(.+)\.backup$/', $latestBackup, $matches);
            
            $deviceIp = basename($deviceDir);
            $devices[] = [
                'name' => $matches[1] ?? $deviceIp,
                'ip' => $deviceIp,
                'backups_count' => count($backupFiles),
                'last_backup' => date('Y-m-d H:i:s', filemtime($backupFiles[0]))
            ];
        }
        
        $this->logger->debug('telegram', sprintf(
            'Всего найдено устройств: %d (%s)',
            count($devices),
            implode(', ', array_column($devices, 'name'))
        ));
        
        // Формируем сообщение
        $message = "<b>📊 Отчет о резервных копиях MikroTik</b>\n\n";
        $message .= "<pre>"; // Используем моноширинный шрифт для таблицы
        
        // Заголовок таблицы
        $message .= sprintf(
            "%-20s %-15s %-7s %-19s\n",
            "Устройство", "IP", "Копий", "Последний бэкап"
        );
        
        // Разделитель
        $message .= str_repeat("-", 65) . "\n";
        
        // Данные
        foreach ($devices as $device) {
            $message .= sprintf(
                "%-20s %-15s %-7d %-19s\n",
                mb_strimwidth($device['name'], 0, 20, "..."),
                $device['ip'],
                $device['backups_count'],
                $device['last_backup']
            );
        }
        
        $message .= "</pre>\n\n";
        
        // Добавляем статистику
        $totalDevices = count($devices);
        $totalBackups = array_sum(array_column($devices, 'backups_count'));
        
        $message .= "📱 Всего устройств: $totalDevices\n";
        $message .= "💾 Всего резервных копий: $totalBackups\n";
        $message .= "📅 Отчет сгенерирован: " . date('Y-m-d H:i:s');
        
        $this->logger->debug('telegram', 'Сформировано сообщение длиной: ' . strlen($message));
        $this->logger->debug('telegram', 'Первые 100 символов сообщения: ' . substr($message, 0, 100));
        
        $this->sendMessage($message);
    }
} 