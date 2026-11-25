# moo-chan-geki-oshi

MOO-Chan が映ったかを Web カメラで判定し，Arduino へ知らせる仕組みである．
本書には，`request.php` が受け付ける入力とテストの実行方法を記す．

## 📑 目次

- [📮 `request.php` の入力](#-requestphp-の入力)
- [🧪 入力検証のテスト](#-入力検証のテスト)

## 📮 `request.php` の入力

`index.html` は判定結果を `request.php` へ POST する．
`request.php` はその値を `send_serial.py` に渡し，シリアル通信で Arduino へ送る．

表 1. `request.php` への要求と応答

| 要求 | 状態コード | `send_serial.py` |
| --- | --- | --- |
| POST で本文が `{"appearMOOChanState": 0}` か `{"appearMOOChanState": 1}` | 200 | 呼ぶ |
| POST で本文が上記以外 | 400 | 呼ばない |
| POST 以外 | 405 | 呼ばない |

値は数値の 0 と 1 だけを許す．文字列 `"1"` や真偽値 `true` も 400 となる．
`send_serial.py` はシェルを介さず起動するので，値がコマンドとして解釈されない．

判定する状態を増やすときは，`request.php` 内の `ALLOWED_STATES` も直す．
Arduino が受け付ける値とも揃える．

## 🧪 入力検証のテスト

PHP 7.4 以降と `python3` があれば，次のコマンドで確かめられる．

```sh
php tests/request_test.php
```

テストでは，PHP 組み込みサーバー上の `request.php` へ HTTP 要求を送る．
`send_serial.py` を引数の記録だけ行う代役へ差し替えるので，Arduino なしで動く．
失敗が 1 件でもあれば終了コード 1 を返す．
