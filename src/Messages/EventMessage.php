<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Messages;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JuniorFontenele\LaravelRabbitMQ\Contracts\MessageInterface;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureExpiredException;
use PhpAmqpLib\Message\AMQPMessage;
use phpseclib3\Crypt\PublicKeyLoader;

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

        if ($this->signingIsEnabled()) {
            $this->signMessage();
        }
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

        $eventMessage = new static($event, $data);

        return $eventMessage;
    }

    public function getTimestamp(): Carbon
    {
        if (! isset($this->data['timestamp'])) {
            throw new MessageException('Message timestamp is not set.');
        }

        return Carbon::parse($this->data['timestamp']);
    }

    public function getNonce(): string
    {
        return $this->data['nonce'];
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

    /** @return array<string, mixed> */
    public function getHeader(): array
    {
        $data = $this->data;
        unset($data['payload']);

        return $data;
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

    protected function signingIsEnabled(): bool
    {
        return config('rabbitmq.message_signing.enabled', false)
            && (! empty(config('rabbitmq.message_signing.keys.private')));
    }

    protected function signMessage(): void
    {
        if (! $this->signingIsEnabled()) {
            throw new MessageException('Trying to sign message but signing is disabled in rabbitmq.php config.');
        }

        $privateKey = PublicKeyLoader::loadPrivateKey(config('rabbitmq.message_signing.keys.private'));

        $dataToSign = array_filter($this->data, function ($key) {
            return $key !== 'signature';
        }, ARRAY_FILTER_USE_KEY);

        ksort($dataToSign);

        $dataString = json_encode($dataToSign, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $signature = $privateKey->sign($dataString);

        $base64Signature = base64_encode($signature);

        $this->data['signature'] = $base64Signature;
    }

    public function getSignature(): string
    {
        if ($this->signingIsEnabled() && ! isset($this->data['signature'])) {
            throw new MessageException('Message signature is not set.');
        }

        return $this->data['signature'] ?? '';
    }

    /**
     * @param string $publicKey
     * @throws MessageSignatureException
     * @throws MessageSignatureExpiredException
     * @throws MessageException
     * @return void
     */
    public function validateSignature(string $publicKey): void
    {
        if (! $this->signingIsEnabled()) {
            throw new MessageException('Message signing is not enabled.');
        }

        $signature = base64_decode($this->getSignature());

        $receivedData = $this->data;

        $dataToVerify = array_filter($receivedData, function ($key) {
            return $key !== 'signature';
        }, ARRAY_FILTER_USE_KEY);

        $messageTimestamp = $this->getTimestamp();
        $diff = $messageTimestamp->diffInSeconds(now());

        $signTimeWindow = config('rabbitmq.message_signing.verification_time_window', 120);

        if ($diff > $signTimeWindow) {
            throw new MessageSignatureExpiredException('Message signature is expired.');
        }

        if ($diff < -$signTimeWindow) {
            throw new MessageSignatureExpiredException('Message signature is not yet valid.');
        }

        ksort($dataToVerify);

        $dataString = json_encode($dataToVerify, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $publicKey = PublicKeyLoader::loadPublicKey($publicKey);

        $signatureVerified = $publicKey->verify($dataString, $signature);

        if (! $signatureVerified) {
            throw new MessageSignatureException('Message signature is invalid.');
        }
    }

    public function isSignatureValid(string $publicKey): bool
    {
        try {
            $this->validateSignature($publicKey);

            return true;
        } catch (MessageSignatureException) {
            return false;
        } catch (MessageSignatureExpiredException) {
            return false;
        } catch (MessageException) {
            return false;
        }
    }
}
