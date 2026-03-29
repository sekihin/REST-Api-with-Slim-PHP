<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Neuron\Agents\CheckoutAgent;
use App\Infrastructure\AI\Tools\ProcessOrderTool;
use App\Infrastructure\External\DeepSeekProvider; // 構築済みのLLMプロバイダ
use Psr\Log\NullLogger;

// 1. ツールの初期化
$processOrderTool = new ProcessOrderTool();

// 2. LLMプロバイダの初期化
$provider = new DeepSeekProvider(getenv('DEEPSEEK_API_KEY'), new NullLogger());

// 3. エージェントの初期化
$agent = new CheckoutAgent($provider, $processOrderTool);

// --- 会話のシミュレーション ---

echo "🤖 Agent: ご契約内容の確認です。契約ID「ORD-20260310-999」で確定してよろしいでしょうか？\n\n";

$userInput = "はい、確定でお願いします。";
echo "👤 User: {$userInput}\n\n";
echo "🔄 Agentが思考中...\n";

// エージェントにメッセージを送信（内部で思考し、ツールを呼び出し、結果を回答します）
// ※会話履歴を維持するために、システムプロンプトに契約IDをこっそり含ませるか、
// Userの入力にコンテキストとして付与する設計が一般的です。
$response = $agent->run("現在の契約IDは ORD-20260310-999 です。ユーザーの回答：「{$userInput}」");

echo "\n💬 Agentからの最終回答:\n";
echo "--------------------------------------------------\n";
echo $response['content'] ?? $response->getContent(); 
echo "\n--------------------------------------------------\n";