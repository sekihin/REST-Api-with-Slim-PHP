<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use App\Common\PerfTrace;
use App\Common\Tracing\TracerInterface;
use Generator;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\OpenAILike;
use Psr\Http\Message\ResponseInterface;

/**
 * NeuronAI OpenAILike（豆包 Ark）に LLM.DoubaoChat パフォーマンス計測を付与する。
 */
final class TracedDoubaoProvider extends OpenAILike
{
    private readonly LastResponseHeaders $lastHeaders;

    /** @var array<string, mixed>|null 直近の chat/completions リクエスト body */
    private ?array $lastChatPayload = null;

    public function __construct(
        string $baseUri,
        string $key,
        string $model,
        private readonly TracerInterface $tracer,
        array $parameters = [],
        bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->lastHeaders = new LastResponseHeaders();

        if ($httpClient === null) {
            $capture = $this->lastHeaders;
            $stack   = HandlerStack::create();
            $stack->push(Middleware::mapResponse(
                static function (ResponseInterface $response) use ($capture): ResponseInterface {
                    $capture->set($response->getHeaders());

                    return $response;
                }
            ));
            $httpClient = new GuzzleHttpClient(handler: $stack);
        }

        parent::__construct($baseUri, $key, $model, $parameters, $strict_response, $httpClient);
    }

    protected function createChatHttpRequest(array $payload): HttpRequest
    {
        $this->lastChatPayload = $payload;

        return parent::createChatHttpRequest($payload);
    }

    public function chat(Message ...$messages): Message
    {
        $t = $this->tracer->now();
        if (!is_float($t)) {
            $t = microtime(true);
        }

        try {
            $result = parent::chat(...$messages);
            $usage  = $result->getUsage();
            $this->logSuccess(
                $t,
                $messages,
                'sync',
                $usage?->inputTokens ?? 0,
                $usage?->outputTokens ?? 0,
                $this->resolveRequestId(),
            );

            return $result;
        } catch (\Throwable $e) {
            $this->logError($t, $e, $this->resolveRequestId());

            throw $e;
        }
    }

    public function stream(Message ...$messages): Generator
    {
        $t = $this->tracer->now();
        if (!is_float($t)) {
            $t = microtime(true);
        }

        try {
            $inner = parent::stream(...$messages);
            $final = yield from $inner;

            $usage = isset($this->streamState) ? $this->streamState->getUsage() : null;
            $this->logSuccess(
                $t,
                $messages,
                'stream',
                $usage?->inputTokens ?? 0,
                $usage?->outputTokens ?? 0,
                $this->resolveRequestId(),
            );

            return $final;
        } catch (\Throwable $e) {
            $this->logError($t, $e, $this->resolveRequestId());

            throw $e;
        }
    }

    private function resolveRequestId(): string
    {
        $fromHeaders = DoubaoRequestId::fromHeaders($this->lastHeaders->get());
        if ($fromHeaders !== 'unknown') {
            return $fromHeaders;
        }

        if (isset($this->streamState)) {
            $streamId = $this->streamState->messageId();
            if ($streamId !== '') {
                return $streamId;
            }
        }

        return 'unknown';
    }

    /**
     * @param Message[] $messages
     */
    private function logSuccess(
        float $t,
        array $messages,
        string $mode,
        int $promptTokens,
        int $completionTokens,
        string $requestId,
    ): void {
        $this->tracer->log('LLM.DoubaoChat', $t, [
            'model'             => $this->model,
            'mode'              => $mode,
            'msg_count'         => count($messages),
            'has_tools'         => !empty($this->tools) ? 'yes' : 'no',
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'request_id'        => $requestId,
            ...PerfTrace::requestPayloadFields($this->encodeLastChatPayload()),
        ]);
    }

    private function logError(float $t, \Throwable $e, string $requestId): void
    {
        $this->tracer->log('LLM.DoubaoChat', $t, [
            'status'     => 'error',
            'error'      => $e->getMessage(),
            'request_id' => $requestId,
            ...PerfTrace::requestPayloadFields($this->encodeLastChatPayload()),
        ]);
    }

    private function encodeLastChatPayload(): string
    {
        if ($this->lastChatPayload === null) {
            return '';
        }

        $json = json_encode($this->lastChatPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '';
    }
}
