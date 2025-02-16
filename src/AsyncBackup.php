<?php
namespace MikrotikBackup;

use Spatie\Async\Pool;

class AsyncBackup
{
    private $logger;
    private $config;
    private $maxConcurrent;
    
    public function __construct(array $config, Logger $logger, int $maxConcurrent = 5)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->maxConcurrent = $maxConcurrent;
    }
    
    public function runAsync(array $devices): array
    {
        $startTime = microtime(true);
        
        $this->logger->info('system', sprintf(
            '🚀 Запуск асинхронного бэкапа для %d устройств (макс. параллельных: %d)',
            count($devices),
            $this->maxConcurrent
        ));
        
        $pool = Pool::create();
        $pool->concurrency($this->maxConcurrent);
        
        $results = [];
        
        foreach ($devices as $device) {
            $pool->add(function() use ($device) {
                try {
                    $backup = new MikrotikBackup($this->config, $this->logger);
                    $result = $backup->createBackup($device);
                    
                    return [
                        'device' => $device,
                        'success' => $result['success'],
                        'data' => $result
                    ];
                } catch (\Exception $e) {
                    return [
                        'device' => $device,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            })->then(function ($output) use (&$results) {
                $results[] = $output;
                
                $device = $output['device'];
                if ($output['success']) {
                    $this->logger->success(
                        $device['ip'],
                        sprintf(
                            'Асинхронный бэкап успешно создан (размер: %s)',
                            $this->formatSize($output['data']['size'])
                        )
                    );
                } else {
                    $this->logger->error(
                        $device['ip'],
                        sprintf(
                            'Ошибка асинхронного бэкапа: %s',
                            $output['error'] ?? 'неизвестная ошибка'
                        )
                    );
                }
            })->catch(function (\Exception $e) use ($device, &$results) {
                $results[] = [
                    'device' => $device,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                
                $this->logger->error(
                    $device['ip'],
                    sprintf(
                        'Критическая ошибка асинхронного бэкапа: %s',
                        $e->getMessage()
                    )
                );
            });
        }
        
        $pool->wait();
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        // Подсчитываем статистику
        $total = count($results);
        $success = count(array_filter($results, function($r) {
            return $r['success'];
        }));
        $failed = $total - $success;
        
        $this->logger->info('system', sprintf(
            '📊 Результаты асинхронного бэкапа: всего=%d, успешно=%d, ошибок=%d, время=%s сек.',
            $total,
            $success,
            $failed,
            $duration
        ));
        
        return $results;
    }
    
    private function formatSize(int $bytes): string 
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }
} 