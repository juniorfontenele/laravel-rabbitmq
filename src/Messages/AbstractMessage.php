<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Messages;

use Illuminate\Support\Carbon;
use JuniorFontenele\LaravelRabbitMQ\Contracts\MessageInterface;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureExpiredException;
use PhpAmqpLib\Message\AMQPMessage;
use phpseclib3\Crypt\PublicKeyLoader;

abstract class AbstractMessage implements MessageInterface
{
    /** @var array<string, mixed> $data */
    protected array $data = [];

    protected string $routingKey = '';

    /** @var array<string, mixed> $options */
    protected array $options = [];

    protected AMQPMessage $AMQPMessage;

    /**
     * @throws MessageException
     */
    abstract public static function tryFrom(AMQPMessage $AMQPMessage): MessageInterface;

    /**
     * @param array<string, mixed> $data
     * @throws MessageException
     */
    public static function validateMessageData(array $data): void
    {
        $requiredKeys = [
            'timestamp',
            'nonce',
            'message_id',
            'correlation_id',
            'payload',
        ];

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $data)) {
                throw new MessageException(sprintf('Key "%s" not found in message data.', $key));
            }
        }
    }

    public function getAMQPMessageInstance(): AMQPMessage
    {
        if (! isset($this->AMQPMessage)) {
            throw new MessageException('AMQPMessage instance is not set.');
        }

        return $this->AMQPMessage;
    }

    public function setAMQPMessageInstance(AMQPMessage $AMQPMessage): MessageInterface
    {
        $this->AMQPMessage = $AMQPMessage;

        return $this;
    }

    public function ack(bool $multiple = false): bool
    {
        if (! isset($this->AMQPMessage)) {
            return false;
        }

        $this->AMQPMessage->ack($multiple);

        return true;
    }

    public function nack(bool $multiple = false, bool $requeue = true): bool
    {
        if (! isset($this->AMQPMessage)) {
            return false;
        }

        $this->AMQPMessage->nack($multiple, $requeue);

        return true;
    }

    public function reject(bool $requeue = true): bool
    {
        if (! isset($this->AMQPMessage)) {
            return false;
        }

        $this->AMQPMessage->reject($requeue);

        return true;
    }

    public function isRedelivered(): ?bool
    {
        if (! isset($this->AMQPMessage)) {
            throw new MessageException('AMQPMessage instance is not set.');
        }

        return $this->AMQPMessage->isRedelivered();
    }

    public function getConsumerTag(): ?string
    {
        if (! isset($this->AMQPMessage)) {
            throw new MessageException('AMQPMessage instance is not set.');
        }

        return $this->AMQPMessage->getConsumerTag();
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
        if (! isset($this->data['nonce'])) {
            throw new MessageException('Message nonce is not set.');
        }

        return $this->data['nonce'];
    }

    /**
     * Set the routing key for the message.
     *
     * @param string $routingKey The routing key to set
     * @return $this
     */
    public function routingKey(string $routingKey): MessageInterface
    {
        $this->routingKey = $routingKey;

        return $this;
    }

    /**
     * Set the payload for the message.
     *
     * @param array<string, mixed> $payload The payload data
     * @return $this
     */
    public function payload(array $payload): MessageInterface
    {
        $this->data['payload'] = $payload;

        return $this;
    }

    /**
     * Get the payload from the message.
     *
     * @throws MessageException When payload is not set
     * @return array<string, mixed> The message payload
     */
    public function getPayload(): array
    {
        if (! isset($this->data['payload'])) {
            throw new MessageException('Message payload is not set.');
        }

        return $this->data['payload'];
    }

    /**
     * Get the message header data.
     *
     * @return array<string, mixed> The header data
     */
    public function getHeader(): array
    {
        $data = $this->data;
        unset($data['payload']);

        return $data;
    }

    /** @param array<string, mixed> $options */
    public function options(array $options): MessageInterface
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

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getSenderId(): string
    {
        if (! isset($this->data['sender_id'])) {
            throw new MessageException('Message sender ID is not set.');
        }

        return $this->data['sender_id'];
    }

    public function senderId(string $senderId): MessageInterface
    {
        $this->data['sender_id'] = $senderId;

        return $this;
    }

    public function getMessageId(): string
    {
        if (! isset($this->data['message_id'])) {
            throw new MessageException('Message ID is not set.');
        }

        return $this->data['message_id'];
    }

    public function getCorrelationId(): string
    {
        if (! isset($this->data['correlation_id'])) {
            throw new MessageException('Message correlation ID is not set.');
        }

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
