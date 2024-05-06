(プログラム⑧)

#include <DHT.h> // DHTセンサーライブラリをインクルード
#include <TimeLib.h> // 時刻管理ライブラリをインクルード
#include <Wire.h> // I2C通信用のワイヤーライブラリをインクルード
#include <DS1307RTC.h> // DS1307 RTCライブラリをインクルード

const int TACT_PIN = A1; // タクトスイッチのピン番号を定義
const int RELAY_PIN = A2; // リレーの制御ピン番号を定義
const int DHT_PIN = A3; // DHTセンサーのデータピン番号を定義

// 7セグメントのピン設定
const int SEG_A = 11;
const int SEG_B = 13;
const int SEG_C = 10;
const int SEG_D = 3;
const int SEG_E = 5;
const int SEG_F = 9;
const int SEG_G = 12;
const int SEG_DP = 7;

// 7セグメントの桁のピン設定
const int DIGIT_1 = 8;
const int DIGIT_2 = 6;
const int DIGIT_3 = 2;
const int DIGIT_4 = 1;

int previousTactState = LOW; // 前回のタクトスイッチの状態を格納する変数を初期化
unsigned long previousTactMillis = 0; // 前回のタクトスイッチの状態を記録した時間を初期化
const unsigned long debounceDelay = 50; // デバウンス用の遅延時間を設定
int relayState = LOW; // リレーの状態を初期化
boolean sensorActive = true; // センサーの活性状態を初期化
DHT dht(DHT_PIN, DHT11); // DHTセンサーオブジェクトを作成
unsigned long previousMillis = 0; // 前回のイベント発生時刻を初期化
unsigned long previousTempHumidityMillis = 0; // 前回の温度・湿度の読み取り時刻を初期化
const long interval = 2000; // 温度・湿度を読み取る間隔を設定
boolean countingDown = false; // カウントダウン中かどうかを初期化
int countdown = 10; // カウントダウンの初期値を設定
const float humidityOffThreshold = 50.0; // リレーをオフにする湿度閾値を設定

// 修正①: 湿度設定を変数で管理
const float humidityDisplayThreshold = 33.0; // 湿度表示の閾値を設定
const float humidityResetThreshold = 50.0; // 湿度リセットの閾値を設定

// 時間イベントの構造体を定義
struct TimeEvent {
  int hour; // 時を格納する変数
  int minute; // 分を格納する変数
  bool relayState; // リレーの状態を格納する変数
};

// 時間イベントの配列を定義
TimeEvent events[] = {
  {21, 25, true}, // 21:25にリレーON
  {21, 26, false} // 21:26にリレーオフ
};
const int eventsCount = sizeof(events) / sizeof(events[0]); // 時間イベントの数を計算

void setup() {
  Serial.begin(9600); // シリアル通信を開始
  pinMode(TACT_PIN, INPUT); // タクトスイッチのピンを入力モードに設定
  pinMode(RELAY_PIN, OUTPUT); // リレーのピンを出力モードに設定
  pinMode(SEG_A, OUTPUT); // 7セグメントの各セグメントピンを出力モードに設定
  pinMode(SEG_B, OUTPUT);
  pinMode(SEG_C, OUTPUT);
  pinMode(SEG_D, OUTPUT);
  pinMode(SEG_E, OUTPUT);
  pinMode(SEG_F, OUTPUT);
  pinMode(SEG_G, OUTPUT);
  pinMode(SEG_DP, OUTPUT);
  pinMode(DIGIT_1, OUTPUT); // 7セグメントの各桁ピンを出力モードに設定
  pinMode(DIGIT_2, OUTPUT);
  pinMode(DIGIT_3, OUTPUT);
  pinMode(DIGIT_4, OUTPUT);
  dht.begin(); // DHTセンサーの初期化

  setSyncProvider(RTC.get); // RTCモジュールを同期プロバイダに設定
  if (timeStatus() != timeSet) { // 時間が設定されているかどうかを確認
    Serial.println("RTCモジュールの同期に失敗しました。"); // 同期に失敗した場合のメッセージを出力
  } else {
    Serial.println("RTCモジュールは正常に動作しています。"); // 正常に動作している場合のメッセージを出力
  }
}

void displayCountdown() {
  Serial.print("カウントダウン: "); // カウントダウンのメッセージを出力
  Serial.println(countdown); // カウントダウンの値を出力
}

void loop() {
  unsigned long currentMillis = millis(); // 現在の時間を取得
  int currentTactState = digitalRead(TACT_PIN); // タクトスイッチの状態を取得

  if (currentTactState != previousTactState) { // タクトスイッチの状態が変化した場合
    if (currentMillis - previousTactMillis >= debounceDelay) { // デバウンス用の遅延時間経過後に処理
      previousTactMillis = currentMillis; // タイムスタンプを更新
      if (currentTactState == HIGH) { // タクトスイッチが押された場合
        relayState = !relayState; // リレーの状態をトグル
        digitalWrite(RELAY_PIN, relayState); // リレーを制御
        sensorActive = true; // センサーを活性化
        Serial.print("リレーモジュール: "); // リレーモジュールの状態を出力
        Serial.println(relayState == HIGH ? "ON" : "OFF"); // リレーの状態を出力
        countingDown = true; // カウントダウンを開始
        countdown = 10; // カウントダウンの初期値を設定
        sensorActive = false; // センサーを非活性化
      }
    }
  }
  previousTactState = currentTactState; // タクトスイッチの状態を更新

  manageTimeEvents(); // 時間イベントの管理

  if (countingDown) { // カウントダウン中の場合
    if (currentMillis - previousMillis >= 1000) { // 1秒経過した場合
      previousMillis = currentMillis; // タイムスタンプを更新
      countdown--; // カウントダウンをデクリメント
      displayCountdown(); // カウントダウンの状態を表示
      if (countdown <= 0) { // カウントダウンが終了した場合
        countingDown = false; // カウントダウンを停止
        displayCountdownStatus(); // カウントダウンの終了状態を表示
        sensorActive = true; // センサーを活性化
      }
    }
  } else if (sensorActive) { // センサーが活性化している場合
    if (currentMillis - previousTempHumidityMillis >= interval) { // 温度・湿度の読み取り間隔が経過した場合
      previousTempHumidityMillis = currentMillis; // タイムスタンプを更新
      float humidity = dht.readHumidity(); // 湿度を読み取る
      float temperature = dht.readTemperature(); // 温度を読み取る

      if (!isnan(humidity) && !isnan(temperature)) { // 温度と湿度が有効な値である場合
        manageHumidityControl(humidity); // 湿度制御を行う
        displayTemperatureAndHumidity(humidity, temperature); // 温度と湿度を表示
        displayHumidityOnSevenSegment(humidity); // 湿度を7セグメントに表示
      } else {
        Serial.println("湿度または温度の読み取りに失敗しました。"); // 湿度または温度の読み取りに失敗した場合のメッセージを出力
      }
    }
  }
}

void manageTimeEvents() {
  static int lastHour = -1; // 前回の時間を初期化
  static int lastMinute = -1; // 前回の分を初期化
  int currentHour = hour(); // 現在の時間を取得
  int currentMinute = minute(); // 現在の分を取得

  if (currentHour != lastHour || currentMinute != lastMinute) { // 時刻が変化した場合
    lastHour = currentHour; // 前回の時間を更新
    lastMinute = currentMinute; // 前回の分を更新

    for (int i = 0; i < eventsCount; i++) { // 全ての時間イベントに対して
      if (currentHour == events[i].hour && currentMinute == events[i].minute) { // 時刻が一致する場合
        if (events[i].relayState) { // リレーをオンにする場合
          float currentHumidity = dht.readHumidity(); // 現在の湿度を取得
          if (!isnan(currentHumidity) && currentHumidity <= humidityOffThreshold) { // 有効な湿度値かつ指定の湿度閾値以下の場合
            relayState = HIGH; // リレーをオンに設定
            digitalWrite(RELAY_PIN, relayState); // リレーを制御
            sensorActive = true; // センサーを活性化
            Serial.println("湿度低下によりリレーON (時刻指示)"); // リレーのONメッセージを出力
          } else {
            Serial.println("高湿度のため時刻指示によるリレーONをスキップ"); // 高湿度のためリレーONをスキップした場合のメッセージを出力
          }
        } else {
          relayState = LOW; // リレーをオフに設定
          digitalWrite(RELAY_PIN, relayState); // リレーを制御
          sensorActive = false; // センサーを非活性化
          Serial.println("時刻指示によりリレーOFF"); // リレーのOFFメッセージを出力
        }
      }
    }
  }
}

void manageHumidityControl(float humidity) {
  // 修正①: 湿度設定を変数で管理
  if (humidity <= humidityDisplayThreshold && relayState == LOW) { // 湿度が表示閾値以下でリレーがオフの場合
    relayState = HIGH; // リレーをオンに設定
    digitalWrite(RELAY_PIN, relayState); // リレーを制御
    Serial.println("湿度が指定値以下のためリレーをONにしました"); // リレーのONメッセージを出力
  } else if (humidity >= humidityResetThreshold && relayState == HIGH) { // 湿度がリセット閾値以上でリレーがオンの場合
    relayState = LOW; // リレーをオフに設定
    digitalWrite(RELAY_PIN, relayState); // リレーを制御
    Serial.println("湿度が指定値以上になったためリレーをOFFにしました"); // リレーのOFFメッセージを出力
  }
}

void displayTemperatureAndHumidity(float humidity, float temperature) {
  Serial.print("湿度: "); // 湿度のラベルを出力
  Serial.print(humidity); // 湿度を出力
  Serial.print("%\t温度: "); // 温度のラベルを出力
  Serial.print(temperature); // 温度を出力
  Serial.println("°C"); // 摂氏の単位を出力
}

void displayCountdownStatus() {
  Serial.print("カウントダウン停止。現在のリレー状態: "); // カウントダウン停止メッセージを出力
  Serial.println(relayState == HIGH ? "ON" : "OFF"); // 現在のリレー状態を出力
}

void displayHumidityOnSevenSegment(float humidity) {
  // 湿度を整数化
  int humidityInt = int(humidity);
  
  // 4桁分の湿度を表示するために、0〜999の範囲に制限
  if (humidityInt < 0) {
    humidityInt = 0;
  } else if (humidityInt > 999) {
    humidityInt = 999;
  }

  // 各桁の数字を取得
  int digit1 = humidityInt % 10; // 1の位
  int digit2 = (humidityInt / 10) % 10; // 10の位
  int digit3 = (humidityInt / 100) % 10; // 100の位

  // 各桁を7セグメントに表示
  digitalWrite(DIGIT_1, HIGH);
  digitalWrite(DIGIT_2, LOW);
  digitalWrite(DIGIT_3, LOW);
  digitalWrite(DIGIT_4, LOW);
  displayDigit(digit1);

  digitalWrite(DIGIT_1, LOW);
  digitalWrite(DIGIT_2, HIGH);
  digitalWrite(DIGIT_3, LOW);
  digitalWrite(DIGIT_4, LOW);
  displayDigit(digit2);

  digitalWrite(DIGIT_1, LOW);
  digitalWrite(DIGIT_2, LOW);
  digitalWrite(DIGIT_3, HIGH);
  digitalWrite(DIGIT_4, LOW);
  displayDigit(digit3);
}

void displayDigit(int digit) {
  // 各数字に対応するセグメントの点灯パターンを定義
  switch (digit) {
    case 0:
      displaySegment(true, true, true, true, true, true, false); // 0
      break;
    case 1:
      displaySegment(false, true, true, false, false, false, false); // 1
      break;
    case 2:
      displaySegment(true, true, false, true, true, false, true); // 2
      break;
    case 3:
      displaySegment(true, true, true, true, false, false, true); // 3
      break;
    case 4:
      displaySegment(false, true, true, false, false, true, true); // 4
      break;
    case 5:
      displaySegment(true, false, true, true, false, true, true); // 5
      break;
    case 6:
      displaySegment(true, false, true, true, true, true, true); // 6
      break;
    case 7:
      displaySegment(true, true, true, false, false, false, false); // 7
      break;
    case 8:
      displaySegment(true, true, true, true, true, true, true); // 8
      break;
    case 9:
      displaySegment(true, true, true, true, false, true, true); // 9
      break;
    default:
      break;
  }
}

void displaySegment(boolean a, boolean b, boolean c, boolean d, boolean e, boolean f, boolean g) {
  // セグメントの点灯方法を修正
  digitalWrite(SEG_A, !a); // 点灯させるために否定演算子を追加
  digitalWrite(SEG_B, !b);
  digitalWrite(SEG_C, !c);
  digitalWrite(SEG_D, !d);
  digitalWrite(SEG_E, !e);
  digitalWrite(SEG_F, !f);
  digitalWrite(SEG_G, !g);
  digitalWrite(SEG_DP, false); // セグメントDPは使用しないので常にOFF
}