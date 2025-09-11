<?php

    require_once __DIR__ .'/vendor/autoload.php';

    use Ifsnop\Mysqldump as IMysqldump;
    use Dotenv\Dotenv;

    function notify($domain)
    {
        $notifyURL = isset($_ENV['NOTIFY_URL']) ? $_ENV['NOTIFY_URL'] : null;
        if (empty($notifyURL)) {
            return;
        }        

        // 初始化 cURL
        $ch = curl_init($notifyURL);

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
    }

    # 載入 .env
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    $config = [
        'db_host' => isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : null,
        'db_port' => isset($_ENV['DB_PORT']) ? $_ENV['DB_PORT'] : null,
        'db_username' => isset($_ENV['DB_USERNAME']) ? $_ENV['DB_USERNAME'] : null,
        'db_password' => isset($_ENV['DB_PASSWORD']) ? $_ENV['DB_PASSWORD'] : null,
        'db_databases' => isset($_ENV['DB_DATABASES']) ? json_decode($_ENV['DB_DATABASES'], true) : [],
        'aws_key' => isset($_ENV['AWS_S3_KEY']) ? $_ENV['AWS_S3_KEY'] : null,
        'aws_secret' => isset($_ENV['AWS_S3_SECRET']) ? $_ENV['AWS_S3_SECRET'] : null,
        'aws_region' => isset($_ENV['AWS_S3_REGION']) ? $_ENV['AWS_S3_REGION'] : null,
        'aws_bucket' => isset($_ENV['AWS_S3_BUCKET']) ? $_ENV['AWS_S3_BUCKET'] : null
    ];

    #
    foreach ($config['db_databases'] as $key => $value) {
        try {
            echo "> Export {$value}...";

            // 匯出 .sql
            $sqlfile = sprintf(__DIR__ ."/dumps/%s/%s.sql", date('Ymd'), $value);
            $dir    = dirname($sqlfile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $dump = new IMysqldump\Mysqldump(
                "mysql:host={$config['db_host']};dbname={$value}",
                $config['db_username'],
                $config['db_password']
            );
            $dump->start($sqlfile);

            // 壓縮 .zip
            $zipfile = sprintf('%s/%s.zip', rtrim($dir), $value);
            $zipArchive = new \ZipArchive();
            if (!$zipArchive->open($zipfile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                throw new RuntimeException("{$zipfile} open failed.");
            } elseif (!$zipArchive->addFile($sqlfile, basename($sqlfile))) {
                throw new RuntimeException("{$sqlfile} add failed.");
            }
            $zipArchive->close();
	        unlink($sqlfile);

            # 上傳 zip 到 S3
            $s3 = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => $config['aws_region'],
                'credentials' => [
                    'secret' => $config['aws_secret'],
                    'key' => $config['aws_key']
                ],
                'suppress_php_deprecation_warning' => true,
            ]);
            $s3->putObject([
                'Bucket' => $config['aws_bucket'],
                'Key'   => sprintf("%s/%s/%s", $config['db_host'], date('Ymd'), basename($zipfile)),
                'Body'  =>  fopen($zipfile, 'r')
            ]);
            unlink($zipfile);

            #
            notify($key);
            echo "\033[32m[OK]\033[0m\r\n";
        } catch (\Exception $e) {
            echo "\033[31m[ERR]\033[0m\r\n";
        }
    }

