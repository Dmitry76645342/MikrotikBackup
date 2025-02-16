<?php

namespace MikrotikBackup;

class Logger
{
    private $logFile;
    private static $instance;
    private $debugMode = false;
    private $debugModules = [];
    private $isDebug = false;
    
    // Иконки для разных типов сообщений
    private const ICONS = [
        'info' => '🔵',
        'error' => '🔴',
        'success' => '✅',
        'warning' => '⚠️',
        'debug' => '🔍',
        'backup' => '💾',
        'zabbix' => '📊',
        'cleanup' => '🗑️'
    ];

    private $lastMessage = '';
    private $lastTimestamp = 0;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
        
        // Проверяем аргументы для дебага
        foreach ($_SERVER['argv'] as $arg) {
            if (strpos($arg, '--debug=') === 0) {
                $this->isDebug = true;
                $modules = explode(',', substr($arg, 8));
                $this->debugModules = array_map('trim', $modules);
                $this->debug('system', '🔍 Включен дебаг для модулей: ' . implode(', ', $this->debugModules));
            }
        }
    }

    public static function getInstance(string $logFile): self
    {
        if (self::$instance === null) {
            self::$instance = new self($logFile);
        }
        return self::$instance;
    }

    /**
     * Записывает сообщение в лог
     * 
     * @param string $level Уровень сообщения
     * @param string $context Контекст (например, IP или модуль)
     * @param string $message Сообщение или ошибка
     * @return void
     */
    public function log(string $level, string $context, string $message = ''): void
    {
        // Устанавливаем московское время
        date_default_timezone_set('Europe/Moscow');
        $date = date('Y-m-d H:i:s');
        $icon = $this->getIcon($level, $message);
        
        $logMessage = sprintf("[%s] %s [%s] [%s] %s", 
            $date, 
            $icon,
            strtoupper($level), 
            $context, 
            $message
        );

        // Проверяем на дубликаты
        $currentTime = time();
        if ($this->lastMessage === $logMessage && ($currentTime - $this->lastTimestamp) < 2) {
            return;
        }

        $this->lastMessage = $logMessage;
        $this->lastTimestamp = $currentTime;

        if (!file_put_contents($this->logFile, $logMessage . "\n", FILE_APPEND)) {
            throw new \RuntimeException("Не удалось записать в лог-файл: {$this->logFile}");
        }
    }

    /**
     * Логирует успешную операцию
     */
    public function success(string $context, string $message = ''): void
    {
        $this->log('success', $context, $message);
    }

    /**
     * Логирует ошибку
     */
    public function error(string $context, string $message): void
    {
        $this->log('error', $context, $message);
    }

    /**
     * Логирует информационное сообщение
     */
    public function info(string $context, string $message, bool $forceLog = false): void
    {
        if (!$this->debugMode && !$forceLog && $this->isDebugMessage($message)) {
            return;
        }
        $this->log('info', $context, $message);
    }

    /**
     * Логирует отладочное сообщение
     */
    public function debug(string $context, string $message): void
    {
        // Проверяем нужно ли логировать
        if (!$this->isDebug || !$this->shouldLogDebug($context)) {
            return;
        }
        
        // Добавляем метку модуля в сообщение
        $moduleLabel = strtoupper(substr($context, 0, 3));
        $message = "[$moduleLabel] $message";
        
        $this->log('debug', $context, $message);
    }

    private function getIcon(string $level, string $message): string
    {
        if (stripos($message, 'backup') !== false || stripos($message, 'бэкап') !== false) {
            return self::ICONS['backup'];
        }
        if (stripos($message, 'zabbix') !== false) {
            return self::ICONS['zabbix'];
        }
        if (stripos($message, 'clean') !== false || stripos($message, 'очист') !== false) {
            return self::ICONS['cleanup'];
        }
        return self::ICONS[$level] ?? self::ICONS['info'];
    }

    private function isDebugMessage(string $message): bool
    {
        $debugPatterns = [
            'Zabbix API Request',
            'Zabbix API Response',
            'DEBUG',
            'Выполняем команду',
            'Проверяем существование',
            'Вывод команды'
        ];

        foreach ($debugPatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function shouldLogDebug(string $context): bool
    {
        // Если дебаг модули не указаны - логируем все
        if (empty($this->debugModules)) {
            return true;
        }
        
        // Проверяем соответствие контекста модулям
        foreach ($this->debugModules as $module) {
            $module = strtolower(trim($module));
            $context = strtolower($context);
            
            switch ($module) {
                case 'telegram':
                    if (strpos($context, 'telegram') !== false) {
                        return true;
                    }
                    break;
                case 'zabbix':
                    if (strpos($context, 'zabbix') !== false) {
                        return true;
                    }
                    break;
                case 'backup':
                    if (strpos($context, 'backup') !== false) {
                        return true;
                    }
                    break;
                case 'ssh':
                    if (strpos($context, 'ssh') !== false) {
                        return true;
                    }
                    break;
                case 'all':
                    return true;
            }
        }
        
        return false;
    }

    public function getDebugModules(): array
    {
        return $this->debugModules;
    }
}
