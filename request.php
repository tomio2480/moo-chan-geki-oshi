<?php
// index.html から MOO-Chan の出現状態を受け取り，send_serial.py で Arduino へ送る．
//
// 受け付ける入力は，Content-Type が application/json の POST で，
// 本文が JSON {"appearMOOChanState": 0 または 1} のものだけである．
// それ以外は send_serial.py を呼ばずに 405・415・400 のいずれかで断る．

// 許可する appearMOOChanState．index.html が送る 0（不在）と 1（出現）に限る．
const ALLOWED_STATES = [0, 1];

// 受け付ける本文の media type．text/plain などは他サイトのフォームから送れるため断る．
// application/json は，他サイトからだとブラウザーが事前確認（CORS preflight）を挟む．
// request.php は事前確認に許可を返さないので，他サイトのページからは送れない．
const ACCEPTED_MEDIA_TYPE = 'application/json';

function respond_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
}

// Content-Type から charset などのパラメーターを除き，小文字にした media type を返す．
function request_media_type(): string
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    return strtolower(trim(explode(';', $contentType, 2)[0]));
}

// 照合を外しても安全なように，値は文字列のまま受け取る．tests/request_test.php がメタ文字入りの値で直接呼ぶ．
function run_send_serial(string $argument): string
{
    // 配列で渡すと proc_open はシェルを介さずに起動する．値は引数 1 個のまま届き，コマンドとして解釈されない．
    $command = ['python3', __DIR__ . '/send_serial.py', $argument];
    $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return 'send_serial.py を起動できなかった';
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    return (string) $output;
}

function handle_request(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        respond_json(405, ['error' => 'POST only']);
        return;
    }

    if (request_media_type() !== ACCEPTED_MEDIA_TYPE) {
        respond_json(415, ['error' => 'Content-Type must be application/json']);
        return;
    }

    $raw = file_get_contents('php://input'); // POSTされた生のデータを受け取る
    $data = json_decode($raw); // json形式をphp変数に変換
    $state = (is_object($data) && isset($data->appearMOOChanState)) ? $data->appearMOOChanState : null;

    // 厳密比較で照合する．緩い比較では true や "1" も 1 と一致してしまう．
    if (!in_array($state, ALLOWED_STATES, true)) {
        respond_json(400, ['error' => 'appearMOOChanState must be 0 or 1']);
        return;
    }

    // php.ini を設定しておこう
    // log_errors=On
    // error_log="path/to/php_error_log"
    error_log("appearMOOChanState : " . $state);
    error_log(run_send_serial((string) $state));

    // 照合済みの値だけを返す．受け取った本文をそのまま返すと，余分なキーに仕込んだ HTML を反射してしまう．
    respond_json(200, ['appearMOOChanState' => $state]);
}

// tests/request_test.php は run_send_serial() だけを使うため，この定数を定義してから読み込む．
if (!defined('MOO_REQUEST_LIBRARY_ONLY')) {
    handle_request();
}
