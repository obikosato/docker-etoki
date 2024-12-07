<?php

// データベースに接続
$dsn = 'mysql:host=db-v2;port=3306;dbname=sample';
$username = 'root';
$password = 'secret';
$pdo = new PDO($dsn, $username, $password);

// userテーブルの中身を全出力
$sql = 'SELECT * FROM user';
$stmt = $pdo->query($sql);
$stmt->execute();
while ($row = $stmt->fetch()) {
    echo '- id: ' . $row['id'] . ', name: ' . $row['name'] . PHP_EOL; 
}

// データベース接続を切断
$pdo = null;
