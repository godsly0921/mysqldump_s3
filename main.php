<?php

    require_once __DIR__ .'/vendor/autoload.php';

    use Ifsnop\Mysqldump\Mysqldump;
    use Aws\S3\S3Client;

    class MysqldumpS3
    {
        private $config;
        /**
         * @var S3Client
         */
        private $awsClient;

        public function __construct()
        {
            $this->initConfig();
            $this->initAWSClient();
        }

        /**
         * @return void
         */
        private function initConfig()
        {
            $path = __DIR__ .'/config.php';
            if (!file_exists($path)) {
                throw new UnexpectedValueException('config.php not found.');
            }

            $this->config = require $path;
        }

        public function dump()
        {
            foreach ($this->config['databases'] as $domain => $database) {
                $sqlfile = $this->export($database);
                $zipfile = $this->compress($sqlfile);
                $this->upload($zipfile, $domain);
            }
        }

        /**
         * @param string $db
         * @return string
         * @throws Exception
         */
        private function export(string $db)
        {
            $filepath = sprintf(__DIR__ .'/dumps/%s/%s.sql', date('Ymd'), $db);
            $directory = dirname($filepath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            echo sprintf("> Export To {$filepath} ...\r\n");
            $dsn = "mysql:host={$this->config['host']};dbname={$db}";
            $username = $this->config['username'];
            $password = $this->config['password'];
            $dump = new Mysqldump($dsn, $username, $password);
            $dump->start($filepath);

            return $filepath;
        }

        /**
         * @param string $srcfile
         * @return array|string|string[]|null
         */
        private function compress(string $srcfile)
        {
            $destfile = preg_replace('/\.sql$/', '.zip', $srcfile);

            echo sprintf("> Compress As {$destfile} ...\r\n");
            $archive = new ZipArchive();
            if (!$archive->open($destfile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                throw new RuntimeException("{$destfile} open failed.");
            } elseif (!$archive->addFile($srcfile, basename($srcfile))) {
                $archive->close();
                unlink($destfile);
                throw new RuntimeException("{$srcfile} compress failed.");
            }

            $archive->close();
            unlink($srcfile);
            return $destfile;
        }

        private function upload($srcPath, $domain)
        {
            preg_match('/(?<ext>[^\.]+$)/', basename($srcPath), $matches);
            $destPath = sprintf("%s/%s.%s", $domain, date('YmdHis'), $matches['ext']);

            echo sprintf("> Upload To s3://{$this->config['aws_bucket']}/{$destPath} ...\r\n");
            $putObjectResult = $this->awsClient->putObject([
                'Bucket' => $this->config['aws_bucket'],
                'Key'   => $destPath,
                'Body'  =>  fopen($srcPath, 'r')
            ]);
        }

        private function initAWSClient()
        {
            $this->awsClient = new S3Client([
                'version' => 'latest',
                'region'  => $this->config['aws_region'],
                'credentials' => [
                    'secret' => $this->config['aws_secret'],
                    'key' => $this->config['aws_key']
                ],
                'suppress_php_deprecation_warning' => true,
            ]);
        }
    }

    (new MysqldumpS3())->dump();


