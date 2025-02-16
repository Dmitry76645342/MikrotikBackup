<?php
namespace MikrotikBackup\Validator;

class BackupValidator 
{
    public function validateBackups(string $backupDir): array 
    {
        $results = [];
        foreach (glob("$backupDir/*/*.backup") as $file) {
            $results[$file] = $this->validateFile($file);
        }
        return $results;
    }
    
    public function validateFile(string $file): array 
    {
        if (!file_exists($file)) {
            return [
                'file' => basename($file),
                'valid' => false,
                'errors' => ['Файл не существует']
            ];
        }

        $result = [
            'file' => basename($file),
            'size' => filesize($file),
            'valid' => false,
            'errors' => []
        ];
        
        // Проверка размера
        if ($result['size'] < 1024) {
            $result['errors'][] = 'Файл слишком маленький';
        }
        
        // Проверка сигнатуры
        $handle = fopen($file, 'rb');
        if (!$handle) {
            $result['errors'][] = 'Не удалось открыть файл для проверки';
            return $result;
        }

        $header = fread($handle, 8);
        fclose($handle);
        
        if (strpos($header, 'BACKUP2') === 0 || strpos($header, hex2bin('88aca1b1')) === 0) {
            $result['valid'] = true;
        } else {
            $result['errors'][] = 'Неверная сигнатура файла';
            // Добавляем hex для отладки
            $result['debug'] = [
                'header_hex' => bin2hex($header)
            ];
        }
        
        return $result;
    }
} 