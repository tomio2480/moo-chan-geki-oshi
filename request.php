<?php
// index.html から MOO-Chan の出現状態を受け取り，send_serial.py で Arduino へ送る．
//
// 受け付ける入力は POST の JSON {"appearMOOChanState": 0 または 1} だけである．
// それ以外は send_serial.py を呼ばずに 405 または 400 で断る．

// 許可する appearMOOChanState．index.html が送る 0（不在）と 1（出現）に限る．
const ALLOWED_STATES = [0, 1];

function respond_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
}

function run_send_serial(int $state): string
{
    // 配列で渡すと proc_open はシェルを介さずに起動する．値は引数 1 個のまま届き，コマンドとして解釈されない．
    $command = ['python3', __DIR__ . '/send_serial.py', (string) $state];
    $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return 'send_serial.py を起動できなかった';
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    return (string) $output;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond_json(405, ['error' => 'POST only']);
    exit;
}

$raw = file_get_contents('php://input'); // POSTされた生のデータを受け取る
$data = json_decode($raw); // json形式をphp変数に変換
$state = (is_object($data) && isset($data->appearMOOChanState)) ? $data->appearMOOChanState : null;

// 厳密比較で照合する．緩い比較では true や "1" も 1 と一致してしまう．
if (!in_array($state, ALLOWED_STATES, true)) {
    respond_json(400, ['error' => 'appearMOOChanState must be 0 or 1']);
    exit;
}

// php.ini を設定しておこう
// log_errors=On
// error_log="path/to/php_error_log"
error_log("appearMOOChanState : " . $state);
error_log(run_send_serial($state));

// 照合済みの値だけを返す．受け取った本文をそのまま返すと，余分なキーに仕込んだ HTML を反射してしまう．
respond_json(200, ['appearMOOChanState' => $state]);
