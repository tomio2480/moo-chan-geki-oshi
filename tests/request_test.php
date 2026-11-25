<?php
// request.php の入力検証を，PHP の組み込みサーバー越しに HTTP で確かめる．
//
// 実行: php tests/request_test.php
//
// request.php を一時ディレクトリへ複写し，send_serial.py は引数を記録するだけの代役に差し替える．
// このためシリアルポートや pyserial が無い環境でも動く．代役は request.php と同じく python3 で起動される．
// 失敗が 1 件でもあれば終了コード 1 を返す．

declare(strict_types=1);

// リポジトリごと Web サーバーの公開ディレクトリに置くと，本ファイルも URL で開ける．
// プロセスの起動や一時ファイルの作成を外から引き起こさせないため，CLI 以外では何もしない．
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const HOST = '127.0.0.1';
const VALUE_KEY = 'appearMOOChanState';

// 受け取った引数を calls.jsonl へ 1 行ずつ追記する send_serial.py の代役．
const STUB_SOURCE = <<<'PY'
import json
import os
import sys

log_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'calls.jsonl')
with open(log_path, 'a', encoding='utf-8') as log:
    log.write(json.dumps(sys.argv[1:]) + '\n')
print('stub received ' + json.dumps(sys.argv[1:]))
PY;

function make_work_dir(): string
{
    $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moo-request-test-' . bin2hex(random_bytes(6));
    if (!mkdir($workDir . DIRECTORY_SEPARATOR . 'public', 0700, true)) {
        throw new RuntimeException("作業ディレクトリを作れない: {$workDir}");
    }
    return $workDir;
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function find_free_port(): int
{
    $probe = stream_socket_server('tcp://' . HOST . ':0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("空きポートを得られない: {$errstr}");
    }
    $name = stream_socket_get_name($probe, false);
    fclose($probe);
    return (int) substr((string) strrchr((string) $name, ':'), 1);
}

/** @return resource */
function start_server(string $workDir, string $docRoot, int $port)
{
    $command = [PHP_BINARY, '-S', HOST . ':' . $port, '-t', $docRoot];
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptors = [
        0 => ['file', $nullDevice, 'r'],
        1 => ['file', $nullDevice, 'w'],
        2 => ['file', $workDir . DIRECTORY_SEPARATOR . 'server.log', 'w'],
    ];
    // 作業ディレクトリを公開ディレクトリに固定し，注入されたコマンドの書き込み先を予測できるようにする．
    $server = proc_open($command, $descriptors, $pipes, $docRoot);
    if (!is_resource($server)) {
        throw new RuntimeException('php -S を起動できない');
    }
    return $server;
}

function wait_for_server(int $port): void
{
    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen(HOST, $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('php -S が待ち受けを始めない');
}

/**
 * HTTP/1.0 で 1 件送り，状態コード・ヘッダー・本文を返す．
 * HTTP/1.0 にするのは，チャンク転送を避けて応答をそのまま読むためである．
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function send_request(int $port, string $method, ?string $body, string $contentType = 'application/json'): array
{
    $socket = fsockopen(HOST, $port, $errno, $errstr, 5.0);
    if ($socket === false) {
        throw new RuntimeException("接続できない: {$errstr}");
    }
    $request = "{$method} /request.php HTTP/1.0\r\nHost: " . HOST . ":{$port}\r\n";
    if ($body !== null) {
        $request .= "Content-Type: {$contentType}\r\nContent-Length: " . strlen($body) . "\r\n";
    }
    $request .= "Connection: close\r\n\r\n" . ($body ?? '');
    fwrite($socket, $request);
    stream_set_timeout($socket, 30);
    $response = (string) stream_get_contents($socket);
    fclose($socket);

    $parts = explode("\r\n\r\n", $response, 2);
    $lines = explode("\r\n", $parts[0]);
    $status = preg_match('#^HTTP/\d\.\d (\d{3})#', $lines[0], $match) === 1 ? (int) $match[1] : 0;
    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        $pair = explode(':', $line, 2);
        if (count($pair) === 2) {
            $headers[strtolower(trim($pair[0]))] = trim($pair[1]);
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $parts[1] ?? ''];
}

/** @return list<list<string>> 代役が受け取った引数の一覧（呼ばれた順） */
function stub_calls(string $docRoot): array
{
    $logPath = $docRoot . DIRECTORY_SEPARATOR . 'calls.jsonl';
    if (!is_file($logPath)) {
        return [];
    }
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_map(static function (string $line): array {
        return json_decode($line, true);
    }, $lines === false ? [] : $lines);
}

$repoRoot = dirname(__DIR__);
$workDir = make_work_dir();
$docRoot = $workDir . DIRECTORY_SEPARATOR . 'public';
copy($repoRoot . DIRECTORY_SEPARATOR . 'request.php', $docRoot . DIRECTORY_SEPARATOR . 'request.php');
file_put_contents($docRoot . DIRECTORY_SEPARATOR . 'send_serial.py', STUB_SOURCE);
$port = find_free_port();

$total = 0;
$failures = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$total, &$failures): void {
    $total++;
    if (!$ok) {
        $failures++;
    }
    $line = ($ok ? 'ok' : 'not ok') . " {$total} - {$name}";
    echo $line . ($ok || $detail === '' ? '' : " # {$detail}") . PHP_EOL;
};

// 1 件送り，その間に代役が受け取った引数の一覧を添えて返す．
$call = static function (string $method, ?string $body, string $contentType = 'application/json') use ($port, $docRoot): array {
    $before = count(stub_calls($docRoot));
    $response = send_request($port, $method, $body, $contentType);
    $response['calls'] = array_slice(stub_calls($docRoot), $before);
    return $response;
};

$server = start_server($workDir, $docRoot, $port);
try {
    wait_for_server($port);

    // 正常値: index.html が送る 0 と 1 は受け付け，そのまま 1 個の引数として渡す．
    foreach ([1, 0] as $value) {
        $res = $call('POST', json_encode([VALUE_KEY => $value]));
        $expectedBody = json_encode([VALUE_KEY => $value]);
        $check("POST {$value} は 200 を返す", $res['status'] === 200, "status={$res['status']}");
        $check("POST {$value} は JSON で応答する", strpos($res['headers']['content-type'] ?? '', 'application/json') === 0, 'content-type=' . ($res['headers']['content-type'] ?? ''));
        $check("POST {$value} は値をそのまま返す", $res['body'] === $expectedBody, 'body=' . $res['body']);
        $check("POST {$value} は send_serial.py へ \"{$value}\" だけを渡す", $res['calls'] === [[(string) $value]], 'calls=' . json_encode($res['calls']));
    }

    // 応答へ入力を反射しない．text/html で返すと他サイトのフォームから XSS を起こせる．
    $res = $call('POST', json_encode([VALUE_KEY => 1, 'note' => '<script>alert(1)</script>']));
    $check('余分なキーを応答へ反射しない', $res['body'] === json_encode([VALUE_KEY => 1]), 'body=' . $res['body']);

    // POST 以外は 405 で断り，send_serial.py を呼ばない．
    foreach (['GET' => null, 'PUT' => json_encode([VALUE_KEY => 1])] as $method => $body) {
        $res = $call($method, $body);
        $check("{$method} は 405 を返す", $res['status'] === 405, "status={$res['status']}");
        $check("{$method} は Allow: POST を返す", ($res['headers']['allow'] ?? '') === 'POST', 'allow=' . ($res['headers']['allow'] ?? ''));
        $check("{$method} は send_serial.py を呼ばない", $res['calls'] === [], 'calls=' . json_encode($res['calls']));
    }

    // 不正値: 400 で断り，send_serial.py を呼ばない．
    $invalidBodies = [
        '許可値でない数値 2' => json_encode([VALUE_KEY => 2]),
        '文字列の "1"' => json_encode([VALUE_KEY => '1']),
        '真偽値 true' => json_encode([VALUE_KEY => true]),
        'null' => json_encode([VALUE_KEY => null]),
        'キーの欠落' => '{}',
        'JSON でない本文' => 'not json',
        'オブジェクトでない JSON' => '[1]',
    ];
    foreach ($invalidBodies as $label => $body) {
        $res = $call('POST', $body);
        $check("{$label} は 400 を返す", $res['status'] === 400, "status={$res['status']}");
        $check("{$label} では send_serial.py を呼ばない", $res['calls'] === [], 'calls=' . json_encode($res['calls']));
    }

    // メタ文字入りの値: & と | は sh と cmd.exe の両方で後続コマンドを実行させる．
    // 注入が通れば公開ディレクトリへ目印のファイルができる．text/plain は他サイトのフォームから送れる形である．
    $injections = [
        '& による連結' => ['1 & echo INJECTED> injected_amp.txt', 'injected_amp.txt', 'application/json'],
        '| による連結' => ['1 | echo INJECTED> injected_pipe.txt', 'injected_pipe.txt', 'application/json'],
        'text/plain で送る & による連結' => ['1 & echo INJECTED> injected_plain.txt', 'injected_plain.txt', 'text/plain'],
    ];
    foreach ($injections as $label => [$payload, $marker, $contentType]) {
        $res = $call('POST', json_encode([VALUE_KEY => $payload]), $contentType);
        $markerPath = $docRoot . DIRECTORY_SEPARATOR . $marker;
        $check("{$label} は 400 を返す", $res['status'] === 400, "status={$res['status']}");
        $check("{$label} でコマンドが実行されない", !file_exists($markerPath), "{$marker} が作られた");
        $check("{$label} では send_serial.py を呼ばない", $res['calls'] === [], 'calls=' . json_encode($res['calls']));
    }
} finally {
    proc_terminate($server);
    proc_close($server);
    if ($failures > 0) {
        echo PHP_EOL . '--- php -S のログ ---' . PHP_EOL;
        echo (string) @file_get_contents($workDir . DIRECTORY_SEPARATOR . 'server.log');
    }
    remove_dir($workDir);
}

echo PHP_EOL . "{$total} 件中 " . ($total - $failures) . " 件成功，{$failures} 件失敗" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
