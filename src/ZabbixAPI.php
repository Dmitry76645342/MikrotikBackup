<?php

namespace MikrotikBackup;

class ZabbixAPI
{
    private $apiUrl;
    private $authToken;
    private $logger;
    private $config;

    public function __construct(array $config, Logger $logger)
    {
        $this->apiUrl = $config['zabbix_url'];
        $this->logger = $logger;
        $this->config = $config;
        $this->authenticate($config['zabbix_user'], $config['zabbix_pass']);
    }

    /**
     * Аутентификация в Zabbix API
     */
    private function authenticate(string $user, string $password): void
    {
        // Если уже есть токен, не делаем повторную аутентификацию
        if ($this->authToken) {
            return;
        }

        $params = [
            'user' => $user,
            'password' => $password
        ];

        $response = $this->apiRequest('user.login', $params);
        if (!isset($response['result'])) {
            throw new \RuntimeException('Ошибка аутентификации в Zabbix API');
        }

        $this->authToken = $response['result'];
        $this->logger->info('system', 'Успешная аутентификация в Zabbix API');
    }

    /**
     * Получение списка устройств MikroTik из группы
     */
    public function getMikrotikDevices(string $groupId): array
    {
        $params = [
            'output' => ['hostid', 'host', 'name', 'interfaces'],
            'groupids' => [$groupId],
            'selectMacros' => ['macro', 'value'],
            'selectInterfaces' => ['ip']
        ];

        $response = $this->apiRequest('host.get', $params);
        if (!isset($response['result'])) {
            throw new \RuntimeException('Ошибка получения списка устройств');
        }

        $devices = [];

        foreach ($response['result'] as $host) {
            // Создаем item для мониторинга для всех устройств
            $this->ensureBackupItem(
                $host['hostid'],
                $host['host'],
                $host['interfaces'][0]['ip'] ?? $host['host']
            );

            $credentials = $this->extractCredentials($host['macros']);
            
            // Если нет учетных данных, отмечаем устройство как проблемное
            if (!$credentials) {
                $this->logger->error('system', "Отсутствуют макросы для устройства {$host['name']}");
                $this->sendBackupStatus($host['hostid'], 1);
                continue;
            }

            $devices[] = [
                'hostid' => $host['hostid'],
                'name' => $host['name'],
                'host' => $host['host'],
                'ip' => $host['interfaces'][0]['ip'] ?? $host['host'],
                'username' => $credentials['username'],
                'password' => $credentials['password']
            ];
        }

        $this->logger->info('system', sprintf('Получено %d устройств MikroTik', count($devices)));
        return $devices;
    }

    /**
     * Отправка статуса бэкапа в Zabbix
     */
    public function sendBackupStatus(string $hostid, int $status, string $key = null): void
    {
        $host = $this->config['zabbix_monitor_host'];
        $key = $key ?? $this->config['zabbix_backup_status_key'] . "[{$hostid}]";
        $server = $this->config['zabbix_server'];
        $port = $this->config['zabbix_port'];

        // Проверяем существует ли item
        $this->logger->info('system', "Проверяем существование item $key на хосте $host");
        $params = [
            'output' => ['itemid'],
            'host' => $host,
            'search' => [
                'key_' => $key
            ]
        ];
        
        try {
            $response = $this->apiRequest('item.get', $params);
            if (empty($response['result'])) {
                // Если это main.error, создаем item для общего статуса
                if ($key === 'main.error') {
                    $this->createMainErrorItem($host);
                } else {
                    throw new \RuntimeException("Item $key не найден на хосте $host");
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('system', "Ошибка проверки item: " . $e->getMessage());
        }
        
        // Отправляем значение через zabbix_sender
        $command = sprintf(
            'zabbix_sender -z %s -p %d -s %s -k %s -o %d -vv',
            $server,
            $port,
            escapeshellarg($host),
            escapeshellarg($key),
            $status
        );

        $this->logger->info('system', "Выполняем команду: $command");
        exec($command . " 2>&1", $output, $returnVar);
        
        $outputStr = implode("\n", $output);
        $this->logger->info('system', "Вывод команды: $outputStr");
        
        if ($returnVar !== 0 || strpos($outputStr, 'failed: 1') !== false) {
            $this->logger->error('system', "Ошибка zabbix_sender: $outputStr");
            throw new \RuntimeException("Ошибка отправки значения через zabbix_sender: $outputStr");
        }

        $this->logger->info($hostid, sprintf(
            "Статус бэкапа отправлен в Zabbix: %d (результат: %s)",
            $status,
            $outputStr
        ));
    }

    /**
     * Создание item для общего статуса бэкапа
     */
    private function createMainErrorItem(string $host): void
    {
        // Получаем ID хоста мониторинга
        $params = [
            'output' => ['hostid'],
            'filter' => ['host' => $host]
        ];
        
        $response = $this->apiRequest('host.get', $params);
        if (empty($response['result'])) {
            throw new \RuntimeException("Хост $host не найден");
        }
        
        $monitorHostId = $response['result'][0]['hostid'];
        
        // Создаем item
        $params = [
            'name' => "Backup process status",
            'key_' => 'main.error',
            'hostid' => $monitorHostId,
            'type' => 2, // Zabbix trapper
            'value_type' => 3, // Numeric unsigned
            'delay' => '30s',
            'history' => '90d',
            'trends' => '365d',
            'description' => "Overall status of MikroTik backup process (0 - success, 1 - error)",
            'status' => 0, // Enabled
            'allowed_hosts' => '127.0.0.1,localhost'
        ];
        
        $this->apiRequest('item.create', $params);
        $this->logger->info('system', "Создан item main.error для общего статуса бэкапа");
        
        // Создаем триггер для main.error
        $this->createMainErrorTrigger($monitorHostId);
    }

    /**
     * Создание триггера для общего статуса
     */
    private function createMainErrorTrigger(string $hostId): void
    {
        $trigger = $this->apiRequest('trigger.create', [
            'description' => "MikroTik backup process failed",
            'expression' => "last(/backup.mikrotiks/main.error)=1",
            'priority' => 4,
            'status' => 0,
            'type' => 0,
            'manual_close' => 1
        ]);
        
        $this->logger->info('system', "Создан триггер для общего статуса бэкапа");
    }

    /**
     * Создание или обновление хоста мониторинга
     */
    private function ensureMonitorHost(): void
    {
        $host = $this->config['zabbix_monitor_host'];
        $templateId = $this->getTemplateId('Template Mikrotik Backup Monitor');
        
        // Проверяем существует ли хост
        $params = [
            'output' => ['hostid'],
            'filter' => ['host' => $host]
        ];
        
        $response = $this->apiRequest('host.get', $params);
        
        if (empty($response['result'])) {
            // Создаем хост
            $params = [
                'host' => $host,
                'name' => 'Mikrotik Backup Monitor',
                'interfaces' => [
                    [
                        'type' => 1,
                        'main' => 1,
                        'useip' => 1,
                        'ip' => '127.0.0.1',
                        'dns' => '',
                        'port' => '10050'
                    ]
                ],
                'groups' => [
                    ['groupid' => $this->getGroupId('Zabbix servers')]
                ],
                'templates' => [
                    ['templateid' => $templateId]
                ],
                'status' => 0
            ];
            
            $this->apiRequest('host.create', $params);
            $this->logger->info('system', "Создан хост мониторинга: $host");
        } else {
            // Обновляем шаблоны хоста
            $hostId = $response['result'][0]['hostid'];
            $params = [
                'hostid' => $hostId,
                'templates' => [
                    ['templateid' => $templateId]
                ]
            ];
            
            $this->apiRequest('host.update', $params);
            $this->logger->info('system', "Обновлен хост мониторинга: $host");
        }
    }

    /**
     * Получение ID шаблона по имени
     */
    private function getTemplateId(string $name): string
    {
        $params = [
            'output' => ['templateid'],
            'filter' => ['host' => $name]
        ];

        $response = $this->apiRequest('template.get', $params);
        if (!isset($response['result'][0]['templateid'])) {
            throw new \RuntimeException("Шаблон '$name' не найден");
        }

        return $response['result'][0]['templateid'];
    }

    /**
     * Получение ID группы по имени
     */
    private function getGroupId(string $name): string
    {
        $params = [
            'output' => ['groupid'],
            'filter' => ['name' => $name]
        ];

        $response = $this->apiRequest('hostgroup.get', $params);
        if (!isset($response['result'][0]['groupid'])) {
            throw new \RuntimeException("Группа '$name' не найдена");
        }

        return $response['result'][0]['groupid'];
    }

    /**
     * Извлечение учетных данных из макросов хоста
     */
    private function extractCredentials(array $macros): ?array
    {
        $username = null;
        $password = null;

        foreach ($macros as $macro) {
            if ($macro['macro'] === '{$MIKROTIK_USER}') {
                $username = $macro['value'];
            }
            if ($macro['macro'] === '{$MIKROTIK_PASS}') {
                $password = $macro['value'];
            }
        }

        if ($username && $password) {
            return ['username' => $username, 'password' => $password];
        }

        return null;
    }

    /**
     * Получение ID элемента по хосту и ключу
     */
    private function getItemId(string $hostid, string $key): string
    {
        $params = [
            'output' => ['itemid'],
            'hostids' => [$hostid],
            'search' => [
                'key_' => $key
            ]
        ];

        $response = $this->apiRequest('item.get', $params);
        if (!isset($response['result'][0]['itemid'])) {
            throw new \RuntimeException("Item с ключом $key не найден для хоста $hostid");
        }

        return $response['result'][0]['itemid'];
    }

    /**
     * Создание item для мониторинга бэкапа
     */
    private function ensureBackupItem(string $hostid, string $hostname, string $ip): void
    {
        $host = $this->config['zabbix_monitor_host'];
        $key = $this->config['zabbix_backup_status_key'] . "[{$hostid}]";

        // Проверяем существует ли item
        $params = [
            'output' => ['itemid'],
            'host' => $host,
            'search' => [
                'key_' => $key
            ]
        ];
        
        $response = $this->apiRequest('item.get', $params);
        
        if (empty($response['result'])) {
            // Получаем ID хоста мониторинга
            $params = [
                'output' => ['hostid'],
                'filter' => ['host' => $host]
            ];
            
            $response = $this->apiRequest('host.get', $params);
            if (empty($response['result'])) {
                throw new \RuntimeException("Хост $host не найден");
            }
            
            $monitorHostId = $response['result'][0]['hostid'];
            
            // Создаем item
            $params = [
                'name' => "Backup status for $hostname",
                'key_' => $key,
                'hostid' => $monitorHostId,
                'type' => 2, // Zabbix trapper
                'value_type' => 3, // Numeric unsigned
                'delay' => '30s',
                'history' => '90d',
                'trends' => '365d',
                'description' => "Status of backup for MikroTik device $hostname ($ip)",
                'status' => 0, // Enabled
                'allowed_hosts' => '127.0.0.1,localhost'
            ];
            
            $response = $this->apiRequest('item.create', $params);
            $this->logger->info('system', "Создан item для устройства $hostname");
        }

        // Всегда проверяем и создаем триггер, независимо от того, существует item или нет
        $this->createBackupTrigger($hostid, $response['result'][0]['itemid'] ?? null, $hostname);
    }

    /**
     * Создание триггера для мониторинга бэкапа
     */
    public function createBackupTrigger($hostId, $itemId, $deviceName) {
        // Получаем ID хоста мониторинга
        $monitorHost = $this->apiRequest('host.get', [
            'output' => ['hostid'],
            'filter' => ['host' => 'backup.mikrotiks']
        ]);

        if (empty($monitorHost['result'])) {
            throw new \RuntimeException("Хост мониторинга backup.mikrotiks не найден");
        }

        $monitorHostId = $monitorHost['result'][0]['hostid'];

        // Проверяем существование триггера
        $existingTrigger = $this->apiRequest('trigger.get', [
            'output' => 'extend',
            'hostids' => $monitorHostId,
            'filter' => [
                'description' => "Backup failed for {$deviceName}"
            ]
        ]);

        if (empty($existingTrigger['result'])) {
            // Создаем триггер если его нет
            $trigger = $this->apiRequest('trigger.create', [
                'description' => "Backup failed for {$deviceName}",
                'expression' => "last(/backup.mikrotiks/trap.status[{$hostId}])=1",
                'priority' => 4,
                'status' => 0,
                'type' => 0,
                'manual_close' => 1
            ]);
            
            $this->logger->info('system', "Создан триггер для устройства $deviceName");
            return $trigger;
        }
        
        return $existingTrigger;
    }

    /**
     * Выполнение запроса к Zabbix API
     */
    private function apiRequest(string $method, array $params): array
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => time(),
            'auth' => $method === 'user.login' ? null : $this->authToken
        ];

        // Логируем исходящий запрос
        $this->logger->debug('zabbix', sprintf(
            "Zabbix API Request:\nMethod: %s\nParams: %s",
            $method,
            json_encode($params, JSON_PRETTY_PRINT)
        ));

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc']
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Логируем ответ
        $this->logger->debug('zabbix', sprintf(
            "Zabbix API Response:\nHTTP Code: %d\nResponse: %s\nCURL Error: %s",
            $httpCode,
            $response,
            $error ?: 'None'
        ));

        if ($error) {
            throw new \RuntimeException("Ошибка CURL: $error");
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['error'])) {
            $errorMsg = sprintf(
                "Ошибка Zabbix API: %s (код: %d)\nДетали: %s",
                $decoded['error']['message'],
                $decoded['error']['code'],
                json_encode($decoded['error']['data'], JSON_PRETTY_PRINT)
            );
            throw new \RuntimeException($errorMsg);
        }

        return $decoded;
    }
} 