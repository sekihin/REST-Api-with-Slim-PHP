<?php

namespace App\Neuron\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Memory\ConversationMemory;
use Psr\Log\LoggerInterface;

/**
 * 一般的なチャットボット用エージェント
 * ・雑談・日常会話・質問回答が主目的
 * ・会話履歴を記憶（短期記憶 + 必要なら長期記憶）
 * ・DeepSeek-chat を使用（安くて速い・日本語も強い）
 */
class GeneralChatAgent extends Agent
{
    protected string $name = 'GeneralChatBot';
    protected string $description = '気軽に話しかけられるフレンドリーなチャットボットです';

    protected function provider(): AIProviderInterface
    {
        return new DeepSeekProvider(
            apiKey: env('DEEPSEEK_API_KEY'),
            logger: app(LoggerInterface::class),
            model: 'deepseek-chat',
            temperature: 0.75,          // 少し創造性を持たせて会話が自然に
            maxTokens: 4096,            // 十分な長さ
        );
    }

    protected function instructions(): string
    {
        return <<<EOD
あなたは「そんこと」という名前のフレンドリーで少しユーモアのある日本語チャットボットです。
以下のルールで会話してください：

・ユーザーの名前がわかったら積極的に使って親しみやすく
・カジュアルで自然な日本語（です・ます調とタメ口を状況で使い分ける）
・冗長にならず、1回の返信は2〜5文程度を目安に
・質問にはできるだけ正確に、わからないことは素直に「わからない」と言う
・ポジティブで励まし系・共感多め
・たまに軽いツッコミや冗談を入れて会話に変化を
・政治・宗教・過激な話題は避け、中立的に
・ユーザーが深刻そうなら優しく寄り添う

例：
ユーザー：今日疲れた〜
あなた：お疲れさま〜！何があったの？愚痴でも聞いてあげるよ😌

現在の日付は2026年3月です。よろしくね！
EOD;
    }

    protected function memory(): ConversationMemory
    {
        // 短期記憶（直近10ターン程度）を保持
        return new ConversationMemory(
            maxMessages: 12,           // 往復6ターン分
            summarizeAfter: 10         // 古い履歴は要約して記憶
        );
    }

    // 必要に応じてツールを追加（今回は一般会話なので最小限）
    protected function tools(): array
    {
        return [
            // 例: 天気ツール、検索ツールなどをここに追加可能
        ];
    }
}