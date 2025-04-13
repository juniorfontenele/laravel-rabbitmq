<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Messages;

use Illuminate\Support\Str;
use JuniorFontenele\LaravelRabbitMQ\Contracts\MessageInterface;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use PhpAmqpLib\Message\AMQPMessage;

class EventMessage implements MessageInterface
{
    /**
     * @param string $event
     * @param array<string, mixed> $data
     * @param string $routingKey
     * @param array<string, mixed> $options
     */
    final protected function __construct(
        protected string $event,
        protected array $data,
        protected string $routingKey = '',
        protected array $options = [],
    ) {
        $this->options['message_id'] = $this->data['message_id'];
        $this->options['correlation_id'] = $this->data['correlation_id'];
    }

    public static function tryFrom(AMQPMessage $message): static
    {
        $data = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (! isset($data['event'])) {
            throw new MessageException('Event not found in message data.');
        }

        $eventMessage = new static(
            event: $data['event'],
            data: $data,
            routingKey: $message->getRoutingKey() ?? '',
            options: $message->get_properties()
        );

        return $eventMessage;
    }

    /**
     * Create a new event message instance.
     *
     * @param string $event
     * @param array<string, mixed> $payload
     * @return static
     */
    public static function make(string $event, array $payload = [], string $correlationId = ''): static
    {
        $messageId = Str::uuid()->toString();
        $nonce = bin2hex(random_bytes(16));

        $data = [
            'timestamp' => now()->toIso8601ZuluString(),
            'app' => config('app.name'),
            'message_id' => $messageId,
            'correlation_id' => $correlationId,
            'nonce' => $nonce,
            'hostname' => gethostname(),
            'event' => $event,
            'payload' => $payload,
        ];

        if (static::signIsEnabled()) {
            $data['signature'] = static::generateSignature($data);
        }

        $eventMessage = new static($event, $data);

        return $eventMessage;
    }

    public function getTimestamp(): ?string
    {
        return $this->data['timestamp'] ?? null;
    }

    public function getNonce(): string
    {
        return $this->data['nonce'];
    }

    public function getSignature(): string
    {
        return $this->data['signature'] ?? '';
    }

    public function signatureIsValid(string $publicKey, string $algo = 'sha256'): bool
    {
        if (! static::signIsEnabled()) {
            return true;
        }

        $signature = base64_decode($this->getSignature());

        $data = $this->data;

        unset($data['signature']);

        ksort($data);

        $dataString = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return openssl_verify(
            $dataString,
            $signature,
            $publicKey,
            static::getAlgorithmFromString($algo)
        ) === 1;
    }

    public function routingKey(string $routingKey): static
    {
        $this->routingKey = $routingKey;

        return $this;
    }

    /** @param array<string, mixed> $payload */
    public function payload(array $payload): static
    {
        $this->data['payload'] = $payload;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->data['payload'];
    }

    /** @param array<string, mixed> $options */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getMessageId(): string
    {
        return $this->data['message_id'];
    }

    public function getCorrelationId(): string
    {
        return $this->data['correlation_id'];
    }

    protected static function signIsEnabled(): bool
    {
        return config('rabbitmq.sign_messages', false)
            && (! empty(config('rabbitmq.public_key')))
            && (! empty(config('rabbitmq.private_key')));
    }

    /** @param array<string, mixed> $data */
    protected static function generateSignature(array $data): string
    {
        if (! static::signIsEnabled()) {
            throw new MessageException('Trying to sign message but signing is disabled in rabbitmq.php config.');
        }

        $privateKey = config('rabbitmq.private_key');

        ksort($data);

        $dataString = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $algorithm = static::getAlgorithmFromString(config('rabbitmq.sign_algo', 'sha256'));

        openssl_sign(
            $dataString,
            $signature,
            $privateKey,
            $algorithm
        );

        $base64Signature = base64_encode($signature);

        return $base64Signature;
    }

    protected static function getAlgorithmFromString(string $algo): int
    {
        $contantName = 'OPENSSL_ALGO_' . strtoupper($algo);

        if (defined($contantName)) {
            return constant($contantName);
        }

        throw new MessageException('Invalid algorithm: ' . $algo);
    }
}
