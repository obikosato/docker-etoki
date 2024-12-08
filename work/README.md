# ウェブサービス開発環境の構築例（第6部）

## やりたいことの整理

- 共通
  - コンテナは停止時に自動で削除する
  - コンテナ間で通信できるようにする
  - JSTのタイムゾーンを設定する
  - バックグラウンド実行

- Appコンテナ...ウェブサーバー
  - ブラウザからアクセスできるようにする
  - DBコンテナにアクセスする
  - Mailコンテナにアクセスする

- DBコンテナ...MySQLサーバー
  - Appコンテナからアクセスされる
  - 登録ユーザーの情報を保持する（永続化する）
  - デバッグ用にホストマシンからもアクセスできるようにする
  - 初期データを作る

- Mailコンテナ...SMTPサーバー
  - ブラウザからアクセスできるようにする
  - Appコンテナからアクセスされる
  - 送信データを保持する（永続化する）

## コンテナを作る

### DBコンテナ用の材料

- [mysql - Official Image | Docker Hub](https://hub.docker.com/_/mysql)
  - mysql:9.1.0（2024/12/08時点のlatestと同じだった）

- 必要な環境変数
  - MYSQL_ROOT_PASSWORD
  - MYSQL_USER
  - MYSQL_PASSWORD
  - MYSQL_DATABASE
  - TZ

- 公開するポート
  - 3306:3306

以上より、コンテナ起動コマンドは、以下のようになる。

```sh
docker container run \
--name db \
--rm \
--detach \
--env MYSQL_ROOT_PASSWORD=root \
--env MYSQL_USER=app \
--env MYSQL_PASSWORD=pass1234 \
--env MYSQL_DATABASE=sample \
--env TZ=Asia/Tokyo \
--publish 3306:3306 \
mysql:9.1.0
```

- 確認事項
  - `mysql --host=127.0.0.1 --port=3306 --user=app --password=pass1234 sample`で接続できること（接続確認）
  - `select now();`で現在時刻（日本時間）が取得できること（タイムゾーン確認）

### Mailコンテナ用の材料

- [axllent/mailpit - Docker Image | Docker Hub](https://hub.docker.com/r/axllent/mailpit)
  - axllent/mailpit:v1.21.5（2024/12/08時点のlatestと同じだった）

- 必要な環境変数
  - TZ
  - MP_DATABASE
    - 書籍で使用されているバージョンでは、MP_DATA_FILEだったが、v1.16.0からMP_DATABASEに変更された（[axllent/mailpit v1.16.0 on GitHub](https://newreleases.io/project/github/axllent/mailpit/release/v1.16.0)）
- 公開するポート
  - 8025:8025
  - 1025は公開しない（Appコンテナからのみアクセスする）

以上より、コンテナ起動コマンドは、以下のようになる。

```sh
docker container run \
--name mail \
--rm \
--detach \
--env TZ=Asia/Tokyo \
# --env MP_DATABASE=/data/mailpit.db \ ...まだないので指定しない
--publish 8025:8025 \
axllent/mailpit:v1.21.5
```

- 確認事項
  - ブラウザで`http://localhost:8025`にアクセスできること（WebUI確認）

### Appコンテナ用の材料

- ベースイメージ: [php - Official Image | Docker Hub](https://hub.docker.com/_/php)
  - php:8.4.1（2024/12/08時点のlatestと同じだった）

- 公開するポート
  - 8000:8000

サーバを起動するコマンド。

- `--server 0.0.0.0:8000`
  - すべてのネットワークインターフェースからのアクセスを受け付ける
  - 8000番ポートで待ち受ける

サーバが起動することを確認する。

```sh
docker container run --rm --publish 8000:8000 php:8.4.1 --server 0.0.0.0:8000
```

phpのパスを調べる。

```sh
$ docker container run --rm php:8.4.1 which php
/usr/local/bin/php
```

- インストールが必要なもの
  - pdo_mysql...MySQLに接続するため
  - msmtp-mta...SMTPクライアント（メール送信用）

これらをインストールするためのDockerfileを作成し、ビルドしたイメージを使う。

```sh
docker image build --tag work-app:0.1.0 docker/app
```

以上より、コンテナ起動コマンドは、以下のようになる。

```sh
docker container run \
--name app \
--rm \
--detach \
--publish 8000:8000 \
# work-app:0.1.0 \ ...リモートと名前が被ってしまっているのかローカルのものが認識されないので、イメージIDで指定
c8e1a78c1a02 \
/usr/local/bin/php --server 0.0.0.0:8000 --docroot / #/my-workがまだないので/で代用
```

- 確認事項
  - ブラウザで`http://localhost:8000`にアクセスできること（WebUI確認）
  - `docker container exec -it app /bin/sh`でコンテナに入り
    - `php -m`で`pdo_mysql`が表示されること（インストール確認）
    - `dpkg -l | grep msmtp-mta`で`msmtp-mta`が表示されること（インストール確認）

## コンテナ外のリソースを用意

- ネットワークは3つのコンテナが同じネットワークに接続する
- DBコンテナ用のリソース
  - データの永続化
  - 初期データの作成
- Mailコンテナ用のリソース
  - データの永続化
- Appコンテナ用のリソース
  - ソースコードのバインド

### 共通のネットワーク

専用のネットワークを作成する。

```sh
docker network create work-network
```

### DBコンテナ用のリソース

#### データの永続化

MySQLのデータ保存場所を確認する。

```sh
$ docker container run --rm mysql:9.1.0 cat /etc/my.cnf | grep datadir
datadir=/var/lib/mysql
```

DBのデータの永続化のために、ボリュームを作成する。

```sh
docker volume create --name work-db-volume
```

- マウントする時の指定値
  - type: volume
  - source: work-db-volume
  - target: /var/lib/mysql

#### 初期データの作成

次に、初期データの定義を作成する。
work/docker/db/init/init-user.sqlに、userテーブルの作成と、レコードの挿入を記述する。

- マウントする時の指定値
  - type: bind
  - source: "$(pwd)"/docker/db/init
  - target: /docker-entrypoint-initdb.d

### Mailコンテナ用のリソース

#### データの永続化

ボリュームを作成する。

```sh
docker volume create --name work-mail-volume
```

- マウントする時の指定値
  - type: volume
  - source: work-mail-volume
  - target: /data ...MP_DATABASEの値

### Appコンテナ用のリソース

#### ソースコードのバインド

work/src/index.phpを作成する。

- マウントする時の指定値
  - type: bind
  - source: "$(pwd)"/src
  - target: /my-work ...--docrootの値

## 今のディレクトリ状態

```txt
work
├── README.md
├── docker
│   ├── app
│   │   ├── Dockerfile
│   │   └── msmtprc
│   ├── db
│   │   └── init
│   │       └── init-user.sql
│   └── mail
└── src
    └── index.php
```

## コンテナを起動する

### DBコンテナ

```sh
docker container run \
--name db \
--rm \
--detach \
--env MYSQL_ROOT_PASSWORD=secret \
--env MYSQL_USER=app \
--env MYSQL_PASSWORD=pass1234 \
--env MYSQL_DATABASE=sample \
--env TZ=Asia/Tokyo \
--publish 3306:3306 \
--mount type=volume,source=work-db-volume,target=/var/lib/mysql \
--mount type=bind,source="$(pwd)"/docker/db/init,target=/docker-entrypoint-initdb.d \
--network work-network \
mysql:9.1.0
```

### Mailコンテナ

```sh
docker container run \
--name mail \
--rm \
--detach \
--env MP_DATABASE=/data/mailpit.db \
--env TZ=Asia/Tokyo \
--publish 8025:8025 \
--mount type=volume,source=work-mail-volume,target=/data \
--network work-network \
axllent/mailpit:v1.21.5
```

### Appコンテナ

```sh
docker container run \
--name app \
--rm \
--detach \
--publish 8000:8000 \
--mount type=bind,source="$(pwd)"/src,target=/my-work \
--network work-network \
c8e1a78c1a02 \
/usr/local/bin/php --server 0.0.0.0:8000 --docroot /my-work
```

### 確認

- ブラウザで`http://localhost:8000`にアクセスして、JohnとJaneにメールが送信されていることを確認する
- ブラウザで`http://localhost:8025`にアクセスして、送信されたメールを確認する

## Docker Composeへの移行

Docker Composeとは、複数のコンテナをまとめて管理するためのツールである。
直前でやっていたような、コンテナの起動コマンドを一つのファイルにまとめて、それを実行するだけで複数のコンテナを起動できる。
ネットワークを作成しなくても、起動時に専用のブリッジネットワークが自動で作成される。

### Docker Composeの基本コマンド

- 参考: [docker compose | Docker Docs](https://docs.docker.com/reference/cli/docker/compose/)

基本この4つを使う。

- `docker compose up` ...ネットワークの作成、コンテナの**作成**、起動
  - `--detach` ...バックグラウンドで実行
  - `--build` ...イメージの再ビルド
  - `--force-recreate` ...コンテナの再作成
  - `--no-cache` ...キャッシュを使わない
- `docker compose down` ...コンテナの停止、**削除**、ネットワークの削除
  - `docker container run --rm`で起動し、停止時に削除するのと同じ  
  - `--volumes` ...ボリュームも削除される
  - `--rmi all` ...イメージも削除される
- `docker compose ps` ...コンテナ一覧、状態を確認
- `docker compose exec` ...起動中のコンテナでコマンドを実行

起動のみ、停止のみを行う場合は、以下のコマンドを使う。

- `docker compose start` ...コンテナの起動
- `docker compose stop` ...コンテナの停止

全起動、線削除まとめ。

- `docker compose up --detach --build`
- `docker compose down --volumes --rmi all`

起動中のサービスのコンテナに入る。

- `docker compose exec サービス名 /bin/sh` ...コンテナに入る
- `docker compose logs --follow` または `docker compose logs -n 10` ...ログの追跡

### Docker Composeファイルの作成

- 参考: [Compose file reference | Docker Docs](https://docs.docker.com/reference/compose-file/)
- 参考: [Compose ファイル リファレンス — Docker-docs-ja](https://docs.docker.jp/reference/compose-file/toc.html)

work/compose.yamlを作成する。

1. サービスの定義
    - `services:` ...サービスの定義を開始
      - サービス名をキーにして、サービスごとの設定を記述する
    - Docker Composeでは、コンテナ一つずつをそれぞれサービスとして定義し、扱う
    - コンテナ名ではなく、サービス名を定義し、サービス名で識別する
1. 環境変数の定義
    - サービスごとに環境変数を定義する
    - `environment:` ...環境変数の定義を開始（配列）
      - `key=value`の形式で列挙
1. 公開するポートの定義
    - サービスごとに公開するポートを定義する
    - `ports:` ...公開するポートの定義を開始（配列）
      - `host:container`の形式で列挙
1. ボリュームの定義
    - サービスの外に定義
    - `volumes:` ...ボリュームの定義を開始
      - ボリューム名をキーにして、ボリュームの設定を記述する
1. マウントの定義
    - サービスごとにマウントを定義する
    - `volumes:` ...マウントの定義を開始（配列）
      - `type: 指定値` & `source: 指定値` & `target: 指定値`の組を列挙
1. イメージの定義
    - サービスごとにイメージを定義する
    - `image:` ...イメージの定義を開始
      - レジストリ:タグの形式で指定（container run で指定するのと同じ）
1. イメージのビルドの定義
    - サービスごとにイメージのビルドを定義する
    - `build:` ...イメージのビルドの定義を開始
      - コンテキストのパスを指定

### volumesの書き方について

```yaml
    volumes:
      - type: volume
        source: compose-db-volume
        target: /var/lib/mysql
      - type: bind
        source: ./docker/db/init
        target: /docker-entrypoint-initdb.d
```

`container run`の`--mount`オプションの書き方では、以下のようになる。

```sh
--mount type=volume,source=compose-db-volume,target=/var/lib/mysql
--mount type=bind,source="$(pwd)"/docker/db/init,target=/docker-entrypoint-initdb.d
```

一方で、以下のような書き方もできる。

```yaml
    volumes:
      - compose-db-volume:/var/lib/mysql
      - ./docker/db/init:/docker-entrypoint-initdb.d
```

これは、`--volume`オプションの書き方に相当する。

```sh
--volume compose-db-volume:/var/lib/mysql
--volume "$(pwd)"/docker/db/init:/docker-entrypoint-initdb.d
```

詳細は、書籍の23章のコラムの「--mount」と「--volume」の違いについての説明にもあるとおり、明瞭で安全なのは、`--mount`オプションを使う方法である。

- 参考: [docker-composeのvolumesはlong syntaxで書くのがおすすめ](https://zenn.dev/daifukuninja/articles/5f0f4c99418a36)

### buildの書き方について

以下の三つの書き方がある。

```yaml
    build:
      context: ./docker/app
      dockerfile: Dockerfile
```

```yaml
    build:
      context: ./docker/app
```

```yaml
    build: ./docker/app
```

一つ目は、コンテキストのパスを指定して、Dockerfileを指定する方法。しかし、contextのdockerfileが、Dockerfileである場合は省略して二つ目の書き方ができる。
さらに、コンテキストのみの指定で良い場合は、三つ目の書き方ができる。
結果的に、dockerfileを指定する場合は一つ目の書き方、dockerfileを指定しない場合は三つ目の書き方が適していると言える。

### 環境変数の扱いについて

- 機密情報はベタ書きするのではなく、git管理されないものを指定する
- compose.yamlで`${変数名}`としてホストマシンの環境変数を参照できる
- 環境変数がない場合、.envで指定された値が使われる
- `env_file:`で指定したファイルから環境変数を読み込むこともできる
  - しかし、ファイル丸ごと読み込ませるのは、余計な変数までコンテナに渡してしまうため、セキュリティの観点から推奨されない
