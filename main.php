<?php

    require_once __DIR__ .'/vendor/autoload.php';

    use Ifsnop\Mysqldump as IMysqldump;
    use Dotenv\Dotenv;

    function exportDatabase($db, $filename)
    {
        $sqlfile = sprintf(__DIR__ . "/dumps/%s/%s.sql", date('Ymd'), $filename);
        $directory = dirname($sqlfile);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $dump = new IMysqldump\Mysqldump(
            "mysql:host={$_ENV['DB_HOST']};dbname={$db}",
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD']
        );
        $dump->start($sqlfile);

        return $sqlfile;
    }

    function compressSqlFile($sqlfile)
    {
        $directory = dirname($sqlfile);
        preg_match('/^(?<filename>.+)\.sql$/', basename($sqlfile), $matches);
        $zipfile = rtrim($directory, '/') . "/{$matches['filename']}.zip";
        $zipArchive = new \ZipArchive();
        if (!$zipArchive->open($zipfile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException(basename($zipfile) ." open failed.");
        } elseif (!$zipArchive->addFile($sqlfile, basename($sqlfile))) {
            $zipArchive->close();
            unlink($zipfile);
            throw new RuntimeException(basename($sqlfile) ." compress failed.");
        }
        $zipArchive->close();
        unlink($sqlfile);

        return $zipfile;
    }

    function uploadBackupFile($zipfile)
    {
        $s3 = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => $_ENV['AWS_S3_REGION'],
            'credentials' => [
                'secret' => $_ENV['AWS_S3_SECRET'],
                'key' => $_ENV['AWS_S3_KEY']
            ],
            'suppress_php_deprecation_warning' => true,
        ]);
        $s3->putObject([
            'Bucket' => $_ENV['AWS_S3_BUCKET'],
            'Key'   => sprintf("mysqldumps/%s/%s", date('Ymd'), basename($zipfile)),
            'Body'  =>  fopen($zipfile, 'r')
        ]);
        if ($_ENV['KEEP_LOCAL_BACKUP'] !== 'true') {
            unlink($zipfile);
        }
    }

    function notify($domain)
    {
        // 初始化 cURL
        $ch = curl_init($_ENV['NOTIFY_URL']);

        // 設定選項
        $postFields = "domain={$domain}";
        curl_setopt($ch, CURLOPT_POST, true);                         // 使用 POST
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);            // POST 的 body
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);               // 回傳 response 而非直接輸出
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);               // 若有重導向則追蹤
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);                        // 超時（秒）
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);               // 驗證 SSL（真實環境建議開啟）
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($postFields)
        ]);

        // 執行並取得回應
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        // 錯誤處理
        if ($response === false || $httpCode != 200) {
            throw new \Exception('Unexpected response from sslcheck.');
        }
    }

    try {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
        $files = glob(__DIR__ . '/conf/*.json');
        $files = array_filter($files, function ($file) {
            return basename($file) !== 'example.json';
        });
        $configs = array_map(function ($file) {
            $config = json_decode(file_get_contents($file), true);
            if (is_null($config)) {
                throw new RuntimeException("{$file} 解析失敗。");
            }

            return $config;
        }, $files);
        foreach ($configs as $config) {
            try {
                echo "> Database `{$config['database']}` Export...";
                $sqlfile = exportDatabase($config['database'], $config['backup_name']);
                $zipfile = compressSqlFile($sqlfile);
                uploadBackupFile($zipfile);
                notify($config['notify_name']);
                echo "\033[32m[OK]\033[0m\r\n";
            } catch (Exception $e) {
                echo "\033[31m[ERR]{$e->getMessage()}\033[0m\r\n";
            }
        }
    } catch (Exception $e) {
        echo sprintf("Exception '%s' with message '%s' thrown from %s:%d", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    }

