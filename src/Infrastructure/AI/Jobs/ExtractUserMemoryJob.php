<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use NeuronAI\Providers\AIProviderInterface;
use App\Domain\Memory\ElasticsearchMemoryService;
use Illuminate\Support\Facades\Log;

class ExtractUserMemoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $userId,
        private string $userMessage,
        private string $assistantReply
    ) {}

    /**
     * ジョブの実行ロジック
     */
    public function handle(AIProviderInterface $llm, ElasticsearchMemoryService $memoryService): void
    {
        $systemPrompt = <<<PROMPT
あなたはユーザーの会話から「長期的に記憶すべき事実（Facts）」を抽出する専門アシスタントです。
以下のユーザーの発言とAIの回答を分析し、ユーザーに関する事実（名前、所属、契約内容、好みなど）が明言されている場合のみ、それを抽出してください。

【出力フォーマット】
以下のJSON配列形式のみを出力してください。Markdownのコードブロックは含めないでください。記憶すべき事実がない場合は空の配列 `[]` を出力してください。
[
  "事実1",
  "事実2"
]
PROMPT;

        $userContext = "ユーザーの発言: {$this->userMessage}\nAIの回答: {$this->assistantReply}";

        try {
            // 1. LLMによる事実の抽出
            $response = $llm->generate([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContext]
            ]);

            $jsonString = trim($response);
            $extractedFacts = json_decode($jsonString, true);

            // 2. 抽出結果が存在すれば、Chroma DB に保存する
            if (is_array($extractedFacts) && count($extractedFacts) > 0) {
                foreach ($extractedFacts as $fact) {
                    // ここでエンベディング生成とAPI送信が走る
                    $memoryService->storeMemory($this->userId, $fact);
                    
                    Log::info("Memory extracted and saved for {$this->userId}: {$fact}");
                }
            }

        } catch (\Throwable $e) {
            Log::error("Memory extraction failed for user {$this->userId}: " . $e->getMessage());
        }
    }
}