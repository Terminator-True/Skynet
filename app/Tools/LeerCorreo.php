<?php

namespace App\Tools;

use App\Services\Gmail\GmailMessagesReader;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleTokenRefreshException;
use App\Tools\Contracts\Tool;
use InvalidArgumentException;

/**
 * Gmail read tool (spec contract {message_id}): returns the decoded body of a
 * single message via the GmailMessagesReader seam. The model never passes
 * body text — it passes only the opaque id obtained from buscar_correos.
 */
class LeerCorreo implements Tool
{
    public function __construct(private readonly GmailMessagesReader $reader) {}

    public function name(): string
    {
        return 'leer_correo';
    }

    public function description(): string
    {
        return 'Reads the decoded text body of ONE Gmail message by its id. Call buscar_correos first to obtain a message id, then read ONE message and summarize it. Do not read many emails in a row — read one, answer, and let the user ask for more.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message_id' => [
                    'type' => 'string',
                    'description' => 'Opaque Gmail message id returned by buscar_correos',
                ],
            ],
            'required' => ['message_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{body?: string, error?: string}
     */
    public function execute(array $args): array
    {
        if (! isset($args['message_id']) || ! is_string($args['message_id']) || trim($args['message_id']) === '') {
            throw new InvalidArgumentException('leer_correo requires a non-empty string message_id argument.');
        }

        try {
            $message = $this->reader->get($args['message_id']);
        } catch (GoogleTokenRefreshException) {
            return ['error' => 'google_not_connected'];
        } catch (GoogleApiException $e) {
            return ['error' => 'google_api_error', 'detail' => mb_substr($e->getMessage(), 0, 300)];
        }

        $cap = (int) config('ollama.tool_result_char_cap', 6000);
        $body = $message['body'];

        if (mb_strlen($body) > $cap) {
            $body = mb_substr($body, 0, $cap)."\n...\u{2026} [body truncated]";
        }

        return ['body' => $body];
    }
}
