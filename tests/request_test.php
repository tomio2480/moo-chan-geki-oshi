<?php
// request.php の入力検証と send_serial.py の起動を確かめる．
//
// 実行: php tests/request_test.php
//
// request.php を一時ディレクトリへ複写し，send_serial.py は引数を記録するだけの代役に差し替える．
// このためシリアルポートや pyserial が無い環境でも動く．代役は request.php と同じく python3 で起動される．
// 確かめる内容は次の 3 つである．
// - PHP の組み込みサーバー越しに HTTP で送ったときの応答と，代役の呼ばれ方．
// - 起動部 run_send_serial() を直接呼び，メタ文字を含む値がシェルを経ずに引数 1 個で届くこと．
// - request.php がシェルを介する関数とバッククォート演算子を使っていないこと（字句解析による静的検査）．
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

// 置くと代役がその値を終了コードとして終わるファイル．STUB_SOURCE と名前を揃える．
const EXIT_CODE_FILE = 'exit_code.txt';
// 代役が 0 以外で終わる前に標準出力へ書く文言．STUB_SOURCE と揃える．500 の応答へ漏れないかを確かめる．
const STUB_FAILURE_DETAIL = 'STUB-FAILURE-DETAIL';

// シェルを介してコマンドを実行する関数．request.php では使わない．
const SHELL_FUNCTIONS = ['shell_exec', 'exec', 'system', 'passthru', 'popen'];

// 受け取った引数を calls.jsonl へ 1 行ずつ追記する send_serial.py の代役．
const STUB_SOURCE = <<<'PY'
import json
import os
import sys

here = os.path.dirname(os.path.abspath(__file__))
with open(os.path.join(here, 'calls.jsonl'), 'a', encoding='utf-8') as log:
    log.write(json.dumps(sys.argv[1:]) + '\n')
print('stub received ' + json.dumps(sys.argv[1:]))

exit_code_path = os.path.join(here, 'exit_code.txt')
if os.path.exists(exit_code_path):
    with open(exit_code_path, encoding='utf-8') as source:
        exit_code = int(source.read().strip())
    print('STUB-FAILURE-DETAIL: serial port is not available')
    sys.exit(exit_code)
PY;

// request.php の run_send_serial() を直接呼ぶ補助スクリプト．
// 要求処理を走らせない定数を定義してから読み込む．別プロセスで動かし，読み込みの副作用をテスト本体へ及ぼさない．
// 引数は stdin の JSON 配列で受け取り，各呼び出しの戻り値を JSON 配列で stdout へ書く．
const DIRECT_CALL_SOURCE = <<<'PHP'
<?php
declare(strict_types=1);

define('MOO_REQUEST_LIBRARY_ONLY', true);
require $argv[1] . DIRECTORY_SEPARATOR . 'request.php';

$results = [];
foreach (json_decode((string) stream_get_contents(STDIN), true) as $argument) {
    $results[] = run_send_serial($argument);
}
echo json_encode($results);
PHP;

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

/** @return array<string, string> 現在の環境変数のうち PATH だけを $pathDir へ差し替えたもの */
function env_with_path(string $pathDir): array
{
    $env = [];
    foreach (getenv() as $name => $value) {
        // Windows の環境変数名は大文字小文字を区別しないため，Path なども除く．
        if (strcasecmp((string) $name, 'PATH') !== 0) {
            $env[(string) $name] = $value;
        }
    }
    $env['PATH'] = $pathDir;
    return $env;
}

/**
 * @param array<string, string>|null $env null なら現在の環境変数を引き継ぐ
 * @return resource
 */
function start_server(string $docRoot, int $port, string $logPath, ?array $env = null)
{
    // display_errors を有効にする．警告が応答の本文へ混ざると，本文の照合で検出できる．
    $command = [
        PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1',
        '-S', HOST . ':' . $port, '-t', $docRoot,
    ];
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptors = [
        0 => ['file', $nullDevice, 'r'],
        1 => ['file', $nullDevice, 'w'],
        2 => ['file', $logPath, 'w'],
    ];
    // 作業ディレクトリを公開ディレクトリに固定し，注入されたコマンドの書き込み先を予測できるようにする．
    $server = proc_open($command, $descriptors, $pipes, $docRoot, $env);
    if (!is_resource($server)) {
        throw new RuntimeException('php -S を起動できない');
    }
    return $server;
}

/** @param resource $server */
function assert_server_running($server): void
{
    $status = proc_get_status($server);
    if (!$status['running']) {
        throw new RuntimeException("php -S が終了している（終了コード {$status['exitcode']}）");
    }
}

/** @param resource $server */
function wait_for_server($server, int $port): void
{
    for ($i = 0; $i < 100; $i++) {
        assert_server_running($server);
        $socket = @fsockopen(HOST, $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);
            // ポートを別のプロセスに取られて php -S が終わった場合，接続先は別物である．接続後にも確かめる．
            assert_server_running($server);
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('php -S が待ち受けを始めない');
}

/**
 * HTTP/1.0 で 1 件送り，状態コード・ヘッダー・本文を返す．
 * HTTP/1.0 にするのは，チャンク転送を避けて応答をそのまま読むためである．
 * $contentType が null なら Content-Type を付けない．
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function send_request(int $port, string $method, ?string $body, ?string $contentType = 'application/json'): array
{
    $socket = fsockopen(HOST, $port, $errno, $errstr, 5.0);
    if ($socket === false) {
        throw new RuntimeException("接続できない: {$errstr}");
    }
    $request = "{$method} /request.php HTTP/1.0\r\nHost: " . HOST . ":{$port}\r\n";
    if ($body !== null) {
        if ($contentType !== null) {
            $request .= "Content-Type: {$contentType}\r\n";
        }
        $request .= 'Content-Length: ' . strlen($body) . "\r\n";
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

/**
 * 代役が受け取った引数の一覧を呼ばれた順に返す．
 * JSON として読めない行は ['壊れた行' => 行] で表し，照合を失敗させる．例外では止めない．
 *
 * @return list<array<int|string, mixed>>
 */
function stub_calls(string $docRoot): array
{
    $logPath = $docRoot . DIRECTORY_SEPARATOR . 'calls.jsonl';
    if (!is_file($logPath)) {
        return [];
    }
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_map(static function (string $line): array {
        $decoded = json_decode($line, true);
        return is_array($decoded) ? $decoded : ['壊れた行' => $line];
    }, $lines === false ? [] : $lines);
}

/**
 * 補助スクリプトで run_send_serial() を直接呼ぶ．作業ディレクトリは公開ディレクトリとする．
 *
 * @param list<string> $arguments
 * @return array{exit: int, output: string, results: mixed}
 */
function call_run_send_serial(string $workDir, string $docRoot, array $arguments): array
{
    $helper = $workDir . DIRECTORY_SEPARATOR . 'direct_call.php';
    file_put_contents($helper, DIRECT_CALL_SOURCE);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', $workDir . DIRECTORY_SEPARATOR . 'direct-call.log', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $helper, $docRoot], $descriptors, $pipes, $docRoot);
    if (!is_resource($process)) {
        throw new RuntimeException('補助スクリプトを起動できない');
    }
    fwrite($pipes[0], (string) json_encode($arguments));
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'output' => $output, 'results' => json_decode($output, true)];
}

/** @return list<string> PHP のソースのうち，シェルを介する関数名とバッククォート演算子の出現 */
function shell_calls_in(string $source): array
{
    $nameTokens = [T_STRING];
    if (defined('T_NAME_FULLY_QUALIFIED')) {
        $nameTokens[] = constant('T_NAME_FULLY_QUALIFIED');
    }
    $found = [];
    foreach (token_get_all($source) as $token) {
        if ($token === '`') {
            $found[] = '`';
            continue;
        }
        if (!is_array($token)) {
            continue;
        }
        [$id, $text] = $token;
        if (in_array($id, $nameTokens, true)) {
            $name = ltrim($text, '\\');
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
            // call_user_func('system', ...) のように文字列で渡す形も拾う．
            $name = trim($text, '\'"');
        } else {
            continue;
        }
        if (in_array(strtolower($name), SHELL_FUNCTIONS, true)) {
            $found[] = $text;
        }
    }
    return $found;
}

/** 本文が {"error": 文字列} だけの JSON かを返す．警告などが混ざれば JSON として読めない． */
function is_error_only_json(string $body): bool
{
    $decoded = json_decode($body, true);
    return is_array($decoded) && array_keys($decoded) === ['error'] && is_string($decoded['error']);
}

/** @param array{headers: array<string, string>} $response */
function is_json_response(array $response): bool
{
    return strpos($response['headers']['content-type'] ?? '', 'application/json') === 0;
}

$repoRoot = dirname(__DIR__);
$requestSource = (string) file_get_contents($repoRoot . DIRECTORY_SEPARATOR . 'request.php');
$workDir = make_work_dir();
$docRoot = $workDir . DIRECTORY_SEPARATOR . 'public';
file_put_contents($docRoot . DIRECTORY_SEPARATOR . 'request.php', $requestSource);
file_put_contents($docRoot . DIRECTORY_SEPARATOR . 'send_serial.py', STUB_SOURCE);

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

/** @var list<resource> $servers */
$servers = [];
try {
    // テスト補助の自己点検: 静的検査が検出すべき形を検出し，コメントは無視することを確かめる．
    $detectorSamples = [
        'shell_exec の呼び出し' => ['<?php shell_exec($c);', true],
        '名前空間を明示した exec' => ['<?php \\exec($c);', true],
        '大文字で書いた system' => ['<?php SYSTEM($c);', true],
        '文字列で渡す passthru' => ['<?php call_user_func(\'passthru\', $c);', true],
        'popen の呼び出し' => ['<?php popen($c, \'r\');', true],
        'バッククォート演算子' => ['<?php $out = `ls`;', true],
        'コメント中の exec' => ['<?php // exec は使わない' . "\n", false],
        '配列を渡す proc_open' => ['<?php proc_open([\'python3\'], [], $pipes);', false],
    ];
    foreach ($detectorSamples as $label => [$sample, $expected]) {
        $found = shell_calls_in($sample);
        $check('静的検査は ' . $label . ($expected ? ' を検出する' : ' を検出しない'), ($found !== []) === $expected, 'found=' . json_encode($found));
    }

    // テスト補助の自己点検: calls.jsonl に壊れた行があっても例外で止まらず，照合を失敗させる．
    $brokenDir = $workDir . DIRECTORY_SEPARATOR . 'broken-log';
    mkdir($brokenDir);
    file_put_contents($brokenDir . DIRECTORY_SEPARATOR . 'calls.jsonl', "[\"1\"]\n{broken\n");
    $brokenCalls = stub_calls($brokenDir);
    $check('calls.jsonl の壊れた行を照合に失敗する値として読む', $brokenCalls === [['1'], ['壊れた行' => '{broken']], 'calls=' . json_encode($brokenCalls));

    // 第 2 段の静的な確認: request.php はシェルを介してコマンドを実行しない．
    $shellCalls = shell_calls_in($requestSource);
    $check('request.php はシェルを介する関数とバッククォート演算子を使わない', $shellCalls === [], 'found=' . json_encode($shellCalls));

    $port = find_free_port();
    $server = start_server($docRoot, $port, $workDir . DIRECTORY_SEPARATOR . 'server.log');
    $servers[] = $server;
    wait_for_server($server, $port);

    // 1 件送り，その間に代役が受け取った引数の一覧を添えて返す．送る前に php -S が動いていることを確かめる．
    $call = static function (string $method, ?string $body, ?string $contentType = 'application/json') use ($server, $port, $docRoot): array {
        assert_server_running($server);
        $before = count(stub_calls($docRoot));
        $response = send_request($port, $method, $body, $contentType);
        $response['calls'] = array_slice(stub_calls($docRoot), $before);
        return $response;
    };

    // 正常値: index.html が送る 0 と 1 は受け付け，そのまま 1 個の引数として渡す．
    foreach ([1, 0] as $value) {
        $res = $call('POST', json_encode([VALUE_KEY => $value]));
        $expectedBody = json_encode([VALUE_KEY => $value]);
        $check("POST {$value} は 200 を返す", $res['status'] === 200, "status={$res['status']}");
        $check("POST {$value} は JSON で応答する", is_json_response($res), 'content-type=' . ($res['headers']['content-type'] ?? ''));
        $check("POST {$value} は値をそのまま返す", $res['body'] === $expectedBody, 'body=' . $res['body']);
        $check("POST {$value} は send_serial.py へ \"{$value}\" だけを渡す", $res['calls'] === [[(string) $value]], 'calls=' . json_encode($res['calls']));
    }

    // README に書いた細かな扱い: -0 は整数 0 になり，重複したキーは後の値で判定する．
    $acceptedBodies = [
        '-0' => ['{"' . VALUE_KEY . '":-0}', 0],
        '重複キーの後の値 1' => ['{"' . VALUE_KEY . '":"1 & echo x","' . VALUE_KEY . '":1}', 1],
    ];
    foreach ($acceptedBodies as $label => [$body, $value]) {
        $res = $call('POST', $body);
        $check("{$label} は 200 を返す", $res['status'] === 200, "status={$res['status']}");
        $check("{$label} は {$value} として send_serial.py へ渡す", $res['calls'] === [[(string) $value]], 'calls=' . json_encode($res['calls']));
    }

    // media type の大文字小文字と，charset などのパラメーターは問わない．
    foreach (['application/json; charset=utf-8', 'Application/JSON'] as $contentType) {
        $res = $call('POST', json_encode([VALUE_KEY => 1]), $contentType);
        $check("Content-Type: {$contentType} は 200 を返す", $res['status'] === 200, "status={$res['status']}");
        $check("Content-Type: {$contentType} は send_serial.py を呼ぶ", $res['calls'] === [['1']], 'calls=' . json_encode($res['calls']));
    }

    // 応答へ入力を反射しない．text/html で返すと他サイトのフォームから XSS を起こせる．
    $res = $call('POST', json_encode([VALUE_KEY => 1, 'note' => '<script>alert(1)</script>']));
    $check('余分なキーを応答へ反射しない', $res['body'] === json_encode([VALUE_KEY => 1]), 'body=' . $res['body']);

    // POST 以外は 405 で断り，send_serial.py を呼ばない．
    foreach (['GET' => null, 'PUT' => json_encode([VALUE_KEY => 1])] as $method => $body) {
        $res = $call($method, $body);
        $check("{$method} は 405 を返す", $res['status'] === 405, "status={$res['status']}");
        $check("{$method} は Allow: POST を返す", ($res['headers']['allow'] ?? '') === 'POST', 'allow=' . ($res['headers']['allow'] ?? ''));
        $check("{$method} は JSON で応答する", is_json_response($res), 'content-type=' . ($res['headers']['content-type'] ?? ''));
        $check("{$method} は send_serial.py を呼ばない", $res['calls'] === [], 'calls=' . json_encode($res['calls']));
    }

    // CSRF 対策: application/json 以外は 415 で断る．以下は他サイトのページから事前確認なしで送れる形である．
    // 本文が正常値でも send_serial.py を呼ばない．
    $rejectedTypes = [
        'text/plain' => 'text/plain',
        'application/x-www-form-urlencoded' => 'application/x-www-form-urlencoded',
        'Content-Type なし' => null,
    ];
    foreach ($rejectedTypes as $label => $contentType) {
        $res = $call('POST', json_encode([VALUE_KEY => 1]), $contentType);
        $check("{$label} で送った正常値は 415 を返す", $res['status'] === 415, "status={$res['status']}");
        $check("{$label} で送った正常値は JSON で応答する", is_json_response($res), 'content-type=' . ($res['headers']['content-type'] ?? ''));
        $check("{$label} で送った正常値では send_serial.py を呼ばない", $res['calls'] === [], 'calls=' . json_encode($res['calls']));
    }

    // 不正値: 400 で断り，send_serial.py を呼ばない．
    $invalidBodies = [
        '許可値でない数値 2' => json_encode([VALUE_KEY => 2]),
        '文字列の "1"' => json_encode([VALUE_KEY => '1']),
        '真偽値 true' => json_encode([VALUE_KEY => true]),
        '小数表記の 1.0' => '{"' . VALUE_KEY . '":1.0}',
        'null' => json_encode([VALUE_KEY => null]),
        '重複キーの後の値が文字列' => '{"' . VALUE_KEY . '":1,"' . VALUE_KEY . '":"1"}',
        'キーの欠落' => '{}',
        'JSON でない本文' => 'not json',
        'オブジェクトでない JSON' => '[1]',
    ];
    foreach ($invalidBodies as $label => $body) {
        $res = $call('POST', $body);
        $check("{$label} は 400 を返す", $res['status'] === 400, "status={$res['status']}");
        $check("{$label} は JSON で応答する", is_json_response($res), 'content-type=' . ($res['headers']['content-type'] ?? ''));
        $check("{$label} では send_serial.py を呼ばない", $res['calls'] === [], 'calls=' . json_encode($res['calls']));
    }

    // メタ文字入りの値: & と | は sh と cmd.exe の両方で後続コマンドを実行させる．
    // 注入が通れば公開ディレクトリへ目印のファイルができる．text/plain は 415 の段階で断る．
    $injections = [
        '& による連結' => ['1 & echo INJECTED> injected_amp.txt', 'injected_amp.txt', 'application/json', 400],
        '| による連結' => ['1 | echo INJECTED> injected_pipe.txt', 'injected_pipe.txt', 'application/json', 400],
        'text/plain で送る & による連結' => ['1 & echo INJECTED> injected_plain.txt', 'injected_plain.txt', 'text/plain', 415],
    ];
    foreach ($injections as $label => [$payload, $marker, $contentType, $expectedStatus]) {
        $res = $call('POST', json_encode([VALUE_KEY => $payload]), $contentType);
        $markerPath = $docRoot . DIRECTORY_SEPARATOR . $marker;
        $check("{$label} は {$expectedStatus} を返す", $res['status'] === $expectedStatus, "status={$res['status']}");
        $check("{$label} でコマンドが実行されない", !file_exists($markerPath), "{$marker} が作られた");
        $check("{$label} では send_serial.py を呼ばない", $res['calls'] === [], 'calls=' . json_encode($res['calls']));
    }

    // send_serial.py が 0 以外で終わったら 500 を返す．代役の出力などの詳細は応答へ含めない．
    $exitCodePath = $docRoot . DIRECTORY_SEPARATOR . EXIT_CODE_FILE;
    file_put_contents($exitCodePath, '3');
    try {
        $failed = $call('POST', json_encode([VALUE_KEY => 1]));
    } finally {
        unlink($exitCodePath);
    }
    $check('send_serial.py が 0 以外で終わると 500 を返す', $failed['status'] === 500, "status={$failed['status']}");
    $check('send_serial.py が 0 以外で終わると JSON で応答する', is_json_response($failed), 'content-type=' . ($failed['headers']['content-type'] ?? ''));
    $check('send_serial.py が 0 以外で終わると error だけの JSON を返す', is_error_only_json($failed['body']), 'body=' . $failed['body']);
    $check('send_serial.py が 0 以外で終わっても出力を応答へ含めない', strpos($failed['body'], STUB_FAILURE_DETAIL) === false && strpos($failed['body'], 'stub received') === false, 'body=' . $failed['body']);
    $check('send_serial.py が 0 以外で終わる要求でも値は 1 個の引数で渡す', $failed['calls'] === [['1']], 'calls=' . json_encode($failed['calls']));

    // 第 2 段の確認: 照合を外したときに届きうる文字列も，起動部はシェルを介さず引数 1 個のまま渡す．
    // 起動部が文字列のコマンドへ戻ると，sh か cmd.exe がメタ文字を解釈する．目印のファイルができるか，引数が割れる．
    $directArguments = [
        '& による連結' => ['1 & echo INJECTED> direct_amp.txt', 'direct_amp.txt'],
        '| による連結' => ['1 | echo INJECTED> direct_pipe.txt', 'direct_pipe.txt'],
        '; による連結' => ['1; echo INJECTED> direct_semicolon.txt', 'direct_semicolon.txt'],
        '$() によるコマンド置換' => ['$(echo INJECTED> direct_subst.txt)', 'direct_subst.txt'],
        '二重引用符による脱出' => ['1" & echo INJECTED> direct_quote.txt & "', 'direct_quote.txt'],
    ];
    $arguments = array_column(array_values($directArguments), 0);
    $before = count(stub_calls($docRoot));
    $direct = call_run_send_serial($workDir, $docRoot, $arguments);
    $directCalls = array_slice(stub_calls($docRoot), $before);
    $check('起動部の直接呼び出しはすべて true を返す', $direct['exit'] === 0 && $direct['results'] === array_fill(0, count($arguments), true), "exit={$direct['exit']} output={$direct['output']}");
    $check('起動部は呼び出し 1 回につき send_serial.py を 1 回起動する', count($directCalls) === count($arguments), 'calls=' . json_encode($directCalls));
    $index = 0;
    foreach ($directArguments as $label => [$argument, $marker]) {
        $received = $directCalls[$index] ?? null;
        $check("起動部へ直接渡した {$label} は同じ文字列のまま引数 1 個で届く", $received === [$argument], 'received=' . json_encode($received));
        $check("起動部へ直接渡した {$label} でコマンドが実行されない", !file_exists($docRoot . DIRECTORY_SEPARATOR . $marker), "{$marker} が作られた");
        $index++;
    }

    // python3 を起動できないときも 500 を返す．PATH を空のディレクトリだけにした php -S へ送る．
    // Windows では proc_open が警告を出して false を返す．POSIX では子プロセスが 0 以外で終わる．
    // display_errors を有効にしてあるので，警告が本文へ混ざれば JSON として読めなくなる．
    $emptyDir = $workDir . DIRECTORY_SEPARATOR . 'empty-path';
    mkdir($emptyDir);
    $noPythonPort = find_free_port();
    $noPythonServer = start_server($docRoot, $noPythonPort, $workDir . DIRECTORY_SEPARATOR . 'server-no-python.log', env_with_path($emptyDir));
    $servers[] = $noPythonServer;
    wait_for_server($noPythonServer, $noPythonPort);
    $before = count(stub_calls($docRoot));
    $res = send_request($noPythonPort, 'POST', json_encode([VALUE_KEY => 1]));
    $noPythonCalls = array_slice(stub_calls($docRoot), $before);
    $check('python3 を起動できないと 500 を返す', $res['status'] === 500, "status={$res['status']}");
    $check('python3 を起動できないと JSON で応答する', is_json_response($res), 'content-type=' . ($res['headers']['content-type'] ?? ''));
    $check('python3 を起動できないと error だけの JSON を返す', is_error_only_json($res['body']), 'body=' . $res['body']);
    $check('500 の本文は失敗の理由によらず同じである', $res['body'] === $failed['body'], 'body=' . $res['body'] . ' / ' . $failed['body']);
    $check('python3 を起動できないと send_serial.py は動かない', $noPythonCalls === [], 'calls=' . json_encode($noPythonCalls));
} catch (Throwable $e) {
    $check('テストを最後まで実行する', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($servers as $server) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($failures > 0) {
        foreach (glob($workDir . DIRECTORY_SEPARATOR . '*.log') ?: [] as $logPath) {
            echo PHP_EOL . '--- ' . basename($logPath) . ' ---' . PHP_EOL;
            echo (string) file_get_contents($logPath);
        }
    }
    remove_dir($workDir);
}

echo PHP_EOL . "{$total} 件中 " . ($total - $failures) . " 件成功，{$failures} 件失敗" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
