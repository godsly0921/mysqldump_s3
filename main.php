<?php

    require_once __DIR__ .'/vendor/autoload.php';

    use Ifsnop\Mysqldump as IMysqldump;
    use Dotenv\Dotenv;

    function exportDatabase($db, $filename)
    {
        try {
            $sqlfile = sprintf(__DIR__ . "/dumps/%s/%s.sql", date('Ymd'), $filename);
            echo "> Export To .sql...";
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
            echo "\033[32m[OK]\033[0m\r\n";

            return $sqlfile;
        } catch (Throwable $e) {
            echo "\033[31m[ERR]{$e->getMessage()}\033[0m\r\n";
            return false;
        }
    }

    function compressSqlFile($sqlfile)
    {
        try {
            $directory = dirname($sqlfile);
            preg_match('/^(?<filename>.+)\.sql$/', basename($sqlfile), $matches);
            $zipfile = rtrim($directory, '/') . "/{$matches['filename']}.zip";
            echo "> Compress To .zip...";
            $zipArchive = new \ZipArchive();
            if (!$zipArchive->open($zipfile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                throw new RuntimeException("壓縮檔建立失敗");
            } elseif (!$zipArchive->addFile($sqlfile, basename($sqlfile))) {
                $zipArchive->close();
                unlink($zipfile);
                throw new RuntimeException("添加檔案失敗");
            }
            $zipArchive->close();
            unlink($sqlfile);
            echo "\033[32m[OK]\033[0m\r\n";

            return $zipfile;
        } catch (Throwable $e) {
            echo "\033[31m[ERR]{$e->getMessage()}\033[0m\r\n";
            return false;
        }
    }

    function uploadBackupFile($zipfile)
    {
        try {
            echo "> Upload To S3...";
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
            echo "\033[32m[OK]\033[0m\r\n";

            return true;
        } catch (Throwable $e) {
            echo "\033[31m[ERR]{$e->getMessage()}\033[0m\r\n";
            return false;
        }
    }

    function notify($domain)
    {
        try {
            echo "> Notify TO LuckyWave...";

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

            // 錯誤處理
            if ($response === false) {
                $err = curl_error($ch);
                $errno = curl_errno($ch);
                curl_close($ch);
                throw new \Exception($err);
            }

            // 取得 HTTP 狀態碼
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 顯示結果
            if ($httpCode !== 200) {
                throw new \Exception($response);
            }

            echo "\033[32m[OK]\033[0m\r\n";
        } catch (Throwable $e) {
            echo "\033[31m[ERR]{$e->getMessage()}\033[0m\r\n";
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
            echo "========== {$config['database']} ==========\r\n";
            // 匯出 .sql
            $sqlfile = exportDatabase($config['database'], $config['backup_name']);
            if ($sqlfile === false) continue;

            // 壓縮 .zip
            $zipfile = compressSqlFile($sqlfile);
            if ($zipfile === false) continue;


            # 上傳 zip 到 S3
            uploadBackupFile($zipfile);


            notify($config['notify_name']);
        }
    } catch (Throwable $e) {
        echo sprintf("Exception '%s' with message '%s' thrown from %s:%d", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    }

