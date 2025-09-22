### 1.建立 .env
```
$ cp .env.example .env
```

### 2.安裝套件
```
$ composer install
```

### 3.新增資料庫設定
```
$ cp conf/example.json conf/luckywave.json
$ cp conf/example.json conf/hhhdesignlab.json
$ cp conf/example.json conf/moldlink.json
... etc
```

### 4.執行備份
```
$ php main.php
```
