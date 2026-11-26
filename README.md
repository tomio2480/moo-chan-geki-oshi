# moo-chan-geki-oshi

MOO-Chan が映ったかを Web カメラで判定し，Arduino へ知らせる仕組みである．
本書には，`request.php` が受け付ける入力とテストの実行方法を記す．

## 📑 目次

- [📮 `request.php` の入力](#-requestphp-の入力)
- [🧪 入力検証のテスト](#-入力検証のテスト)

## 📮 `request.php` の入力

`index.html` は判定結果を `request.php` へ POST する．
`request.php` はその値を `send_serial.py` に渡し，シリアル通信で Arduino へ送る．
Web サーバーの公開ディレクトリには `index.html`・`request.php`・`send_serial.py` だけを置く．

`request.php` が受け付ける要求は，次の 4 条件をすべて満たすものである．

- メソッドが POST である．
- `Content-Type` の media type が `application/json` である．
  `charset` などのパラメーターと大文字小文字は問わない．
- 本文が JSON オブジェクトである．
- 本文中の `appearMOOChanState` が，JSON で書いた整数 0 か 1 である．
  他のキーは無視する．

<!-- 表のキャプションは体言止めで句点を付けない．この行だけ句点の検査を外す． -->
<!-- textlint-disable ja-technical-writing/ja-no-mixed-period -->
表 1. `request.php` への要求と応答
<!-- textlint-enable ja-technical-writing/ja-no-mixed-period -->

| 要求 | 状態コード | `send_serial.py` |
| --- | --- | --- |
| 4 条件を満たし，`send_serial.py` が終了コード 0 で終わる | 200 | 呼ぶ |
| 4 条件を満たすが，`send_serial.py` を起動できないか 0 以外で終わる | 500 | 起動を試みる |
| POST だが，media type が `application/json` でない | 415 | 呼ばない |
| POST で `application/json` だが，本文が条件に合わない | 400 | 呼ばない |
| POST 以外 | 405 | 呼ばない |

本文は PHP の `json_decode()` で読む．このため，値の扱いは次のとおりとなる．

- `1.0` などの小数表記，文字列 `"1"`，真偽値 `true` は 400 となる．
- `-0` は整数 0 として受け付ける．
- `appearMOOChanState` が複数あるときは，最後の値で判定する．

200 の応答は，照合した `appearMOOChanState` だけを返す．
500 では固定の文言だけを返す．詳細な失敗理由は，PHP のエラーログへ書く．
`send_serial.py` はシェルを介さず起動するので，値がコマンドとして解釈されない．

415 で断るのは，他サイトのページから送らせないためである．
`text/plain` などは，他サイトのフォームからも送れる．
他サイトが `application/json` で送ると，ブラウザーは事前確認（CORS preflight）を挟む．
`request.php` は事前確認に許可を返さない．このため，通常は他サイトのページから送れない．

ただし，DNS rebinding によって同一オリジンとなった要求は防げない．
DNS rebinding では，攻撃者が管理するドメインの名前解決先を途中から切り替える．
切り替え先は `request.php` を置いたサーバーである．
するとブラウザーは，攻撃者のページが送る要求を同一オリジンとして扱う．

`request.php` は `Host` ヘッダーを検査しないため，その要求を受け付ける．
送れる値は 0 か 1 だけである．値の照合とシェルを介さない起動により，コマンドは注入できない．
ただし回数の制限は無い．要求ごとに `send_serial.py` が終わるまで待つため，連続して送れば Web サーバーのワーカーを占有しうる．
`Host` の許可リストは入れていない．運用するホスト名が本リポジトリから定まらないためである．

判定する状態を増やすときは，`request.php` 内の `ALLOWED_STATES` も直す．
Arduino が受け付ける値とも揃える．

## 🧪 入力検証のテスト

PHP（`tokenizer` 拡張を含む）と `python3` があれば，次のコマンドで確かめられる．
動作は PHP 8.5 で確かめた．構文は PHP 7.4 以降を想定している．7.4 での実行は確かめていない．

```sh
php tests/request_test.php
```

テストでは，PHP 組み込みサーバー上の `request.php` へ HTTP 要求を送る．
`send_serial.py` を引数の記録だけ行う代役へ差し替えるので，Arduino なしで動く．
500 は，代役を 0 以外で終わらせて確かめる．`python3` を見つけられない場合も確かめる．

あわせて，起動部の `run_send_serial()` を直接呼ぶ．
メタ文字入りの値が引数 1 個のまま届くか，確かめる．
字句解析により，`request.php` がシェル経由の関数やバッククォート演算子を使っていないかも調べる．
失敗が 1 件でもあれば終了コード 1 を返す．
