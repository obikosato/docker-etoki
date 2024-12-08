<?php

$users = [];

// データベースに接続
$dsn = 'mysql:host=db;port=3306;dbname=sample';
$username = 'app';
$password = 'pass1234';

try {
    $pdo = new PDO($dsn, $username, $password);

    // userテーブルの中身を取得
    $sql = 'SELECT * FROM user';
    $stmt = $pdo->query($sql);
    $stmt->execute();
    while ($row = $stmt->fetch()) {
        $users[] = $row;
    }

    // データベース接続を切断
    $pdo = null;
} catch (PDOException $e) {
    echo 'データベースに接続できませんでした' . PHP_EOL;
}

// ユーザー一覧を表示
foreach ($users as $user) {
    echo '<p>id: ' . $user['id'] . ', name: ' . $user['name'] . '</p>';
}

// メールを送信
$subject = 'テストメールです';
$body = 'Docker Hubはこちら → https://hub.docker.com/';
foreach ($users as $user) {
    $success = mb_send_mail($user['email'], $subject, $body);
    if ($success) {
        echo '<p>' . $user['name'] . 'さんにメールを送信しました</p>';
    } else {
        echo '<p>' . $user['name'] . 'さんにメールを送信できませんでした</p>';
    }
}
