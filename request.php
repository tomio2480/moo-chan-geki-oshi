<?php
    $raw = file_get_contents('php://input'); // POSTされた生のデータを受け取る
    $data = json_decode($raw); // json形式をphp変数に変換

    // php.ini を設定しておこう
    // log_errors=On
    // error_log="path/to/php_error_log"
    error_log("appearMOOChanState : " . $data->appearMOOChanState);
    error_log(shell_exec("python3 send_serial.py " . $data->appearMOOChanState));

    // echoすると返せる
    $res = $data;
    echo json_encode($res); // json形式にして返す
?>