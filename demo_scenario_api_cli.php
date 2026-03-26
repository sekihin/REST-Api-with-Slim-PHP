<?php
/**
 * HTTP POSTリクエストを送信し、Chat APIからレスポンスを取得するサンプルコード
 * 注文状況の問い合わせメッセージをAPIに送信するシナリオ
 */

// APIエンドポイントのURLを設定
$url = 'http://localhost:8080/api/chat';

// APIに送信するリクエストデータ（JSON形式に変換するための配列）
$data = [
    'message' => '昨日購入した商品がなぜ発送されていないのですか？', // ユーザーからの問い合わせメッセージ
];

// HTTPリクエストのオプション設定
$options = [
    'http' => [
        'method' => 'POST',          // リクエストメソッドをPOSTに設定
        'header' => 'Content-Type: application/json', // リクエストヘッダー（JSON形式を指定）
        'content' => json_encode($data), // 送信データをJSON文字列に変換
    ]
];

// ストリームコンテキストを作成（HTTPリクエストの設定を適用）
$context = stream_context_create($options);

// APIにリクエストを送信し、レスポンスを取得
// file_get_contentsでPOSTリクエストを実行（ストリームコンテキストを使用）
$result = file_get_contents($url, false, $context);

// リクエストの成否を判定
if ($result === false) {
    // リクエストが失敗した場合のエラーハンドリング
    $error = error_get_last(); // 最後に発生したエラー情報を取得
    echo "HTTPリクエストが失敗しました！\n";
    
    // エラーメッセージが存在する場合は表示
    if ($error && isset($error['message'])) {
        echo "エラーメッセージ: " . $error['message'] . "\n";
    }
    
    // HTTPレスポンスヘッダーが存在する場合は表示（デバッグ用）
    // $http_response_headerはfile_get_contents実行時に自動で設定される特殊な変数
    if (isset($http_response_header)) {
        echo "HTTPレスポンスヘッダー:\n";
        foreach ($http_response_header as $header) {
            echo $header . "\n";
        }
    }
} else {
    // リクエストが成功した場合はレスポンスデータを表示
    print_r($result);
}