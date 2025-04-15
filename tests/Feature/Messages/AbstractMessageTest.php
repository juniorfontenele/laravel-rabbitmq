<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests\Feature\Messages;

use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureExpiredException;
use JuniorFontenele\LaravelRabbitMQ\Messages\BasicMessage;
use JuniorFontenele\LaravelRabbitMQ\Tests\TestCase;
use PhpAmqpLib\Message\AMQPMessage;
use phpseclib3\Crypt\RSA;

class AbstractMessageTest extends TestCase
{
    private array $validMessageData;

    private BasicMessage $message;

    private string $privateKey;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validMessageData = [
            'timestamp' => now()->toIso8601ZuluString(),
            'nonce' => bin2hex(random_bytes(16)),
            'message_id' => '123e4567-e89b-12d3-a456-426614174000',
            'correlation_id' => '123e4567-e89b-12d3-a456-426614174001',
            'app' => 'test-app',
            'hostname' => 'test-host',
            'payload' => ['test' => 'data'],
        ];

        $this->message = BasicMessage::make(['test' => 'data']);

        // Generate RSA keys for signing tests
        $rsa = RSA::createKey(1024);
        $this->privateKey = $rsa->toString('PKCS1');
        $this->publicKey = $rsa->getPublicKey()->toString('PKCS1');
    }

    public function testValidateMessageDataWithValidData(): void
    {
        // This should not throw an exception
        BasicMessage::validateMessageData($this->validMessageData);
        $this->assertTrue(true); // Assert that we got here
    }

    public function testValidateMessageDataWithInvalidData(): void
    {
        $invalidData = $this->validMessageData;
        unset($invalidData['timestamp']);

        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Key "timestamp" not found in message data.');

        BasicMessage::validateMessageData($invalidData);
    }

    public function testRoutingKey(): void
    {
        $routingKey = 'test.routing.key';
        $this->message->routingKey($routingKey);

        $this->assertEquals($routingKey, $this->message->getRoutingKey());
    }

    public function testPayload(): void
    {
        $payload = ['foo' => 'bar'];
        $this->message->payload($payload);

        $this->assertEquals($payload, $this->message->getPayload());
    }

    public function testGetPayloadThrowsExceptionWhenNotSet(): void
    {
        // Create a new message without payload
        $message = BasicMessage::make([]);

        // Overwrite the payload with empty data
        $message->payload([]);

        // This should not throw an exception
        $this->assertEquals([], $message->getPayload());
    }

    public function testOptions(): void
    {
        $options = ['key' => 'value'];
        $this->message->options($options);

        $this->assertEquals($options, $this->message->getOptions());
    }

    public function testGetHeader(): void
    {
        $header = $this->message->getHeader();

        $this->assertArrayNotHasKey('payload', $header);
        $this->assertArrayHasKey('message_id', $header);
        $this->assertArrayHasKey('correlation_id', $header);
        $this->assertArrayHasKey('timestamp', $header);
        $this->assertArrayHasKey('nonce', $header);
    }

    public function testGetMessageId(): void
    {
        $messageId = $this->message->getMessageId();

        $this->assertNotEmpty($messageId);
        $this->assertIsString($messageId);
    }

    public function testGetCorrelationId(): void
    {
        $correlationId = $this->message->getCorrelationId();

        $this->assertIsString($correlationId);
    }

    public function testGetTimestamp(): void
    {
        $timestamp = $this->message->getTimestamp();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $timestamp);
    }

    public function testGetNonce(): void
    {
        $nonce = $this->message->getNonce();

        $this->assertNotEmpty($nonce);
        $this->assertIsString($nonce);
    }

    public function testAMQPMessageHandling(): void
    {
        $amqpMessage = new AMQPMessage(json_encode($this->validMessageData));
        $this->message->setAMQPMessageInstance($amqpMessage);

        $this->assertSame($amqpMessage, $this->message->getAMQPMessageInstance());
    }

    public function testGetAMQPMessageInstanceThrowsExceptionWhenNotSet(): void
    {
        $message = BasicMessage::make(['test' => 'data']);

        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('AMQPMessage instance is not set.');

        $message->getAMQPMessageInstance();
    }

    public function testGetData(): void
    {
        $data = $this->message->getData();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('payload', $data);
        $this->assertArrayHasKey('message_id', $data);
        $this->assertArrayHasKey('correlation_id', $data);
    }

    public function testValidateSignatureWhenSigningIsDisabled(): void
    {
        // Ensure signing is disabled
        config(['rabbitmq.message_signing.enabled' => false]);

        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Message signing is not enabled.');

        $this->message->validateSignature($this->publicKey);
    }

    public function testSignAndValidateSignature(): void
    {
        // Enable message signing and set keys
        config([
            'rabbitmq.message_signing.enabled' => true,
            'rabbitmq.message_signing.keys.private' => $this->privateKey,
            'rabbitmq.message_signing.verification_time_window' => 120,
        ]);

        // Create a new message with signing enabled
        $message = BasicMessage::make(['test' => 'signing']);

        // Use reflection to access protected method
        $reflectionClass = new \ReflectionClass($message);
        $signMethodReflection = $reflectionClass->getMethod('signMessage');
        $signMethodReflection->setAccessible(true);
        $signMethodReflection->invoke($message);

        // Assert signature exists
        $signature = $message->getSignature();
        $this->assertNotEmpty($signature);

        // Validate signature
        $message->validateSignature($this->publicKey);

        // Assert validation passes with isSignatureValid
        $this->assertTrue($message->isSignatureValid($this->publicKey));
    }

    public function testValidateSignatureWithInvalidKey(): void
    {
        // Enable message signing and set keys
        config([
            'rabbitmq.message_signing.enabled' => true,
            'rabbitmq.message_signing.keys.private' => $this->privateKey,
            'rabbitmq.message_signing.verification_time_window' => 120,
        ]);

        // Create a new message with signing enabled
        $message = BasicMessage::make(['test' => 'signing']);

        // Use reflection to access protected method
        $reflectionClass = new \ReflectionClass($message);
        $signMethodReflection = $reflectionClass->getMethod('signMessage');
        $signMethodReflection->setAccessible(true);
        $signMethodReflection->invoke($message);

        // Generate a different key pair
        $differentRsa = RSA::createKey(1024);
        $differentPublicKey = $differentRsa->getPublicKey()->toString('PKCS1');

        // Validate signature with wrong key should fail
        $this->expectException(MessageSignatureException::class);
        $this->expectExceptionMessage('Message signature is invalid.');

        $message->validateSignature($differentPublicKey);
    }

    public function testValidateSignatureWithExpiredTimestamp(): void
    {
        // Enable message signing and set keys
        config([
            'rabbitmq.message_signing.enabled' => true,
            'rabbitmq.message_signing.keys.private' => $this->privateKey,
            'rabbitmq.message_signing.verification_time_window' => 10, // 10 seconds window
        ]);

        // Create message with old timestamp
        $oldData = $this->validMessageData;
        $oldData['timestamp'] = now()->subSeconds(20)->toIso8601ZuluString(); // 20 seconds ago

        // Create message and sign it
        $message = BasicMessage::make(['test' => 'signing']);
        $reflectionClass = new \ReflectionClass($message);

        // Set the data property
        $dataProperty = $reflectionClass->getProperty('data');
        $dataProperty->setAccessible(true);
        $dataProperty->setValue($message, $oldData);

        // Sign the message
        $signMethodReflection = $reflectionClass->getMethod('signMessage');
        $signMethodReflection->setAccessible(true);
        $signMethodReflection->invoke($message);

        // Validation should fail with expired exception
        $this->expectException(MessageSignatureExpiredException::class);
        $this->expectExceptionMessage('Message signature is expired.');

        $message->validateSignature($this->publicKey);
    }

    public function testIsSignatureValidReturnsFalseWithInvalidKey(): void
    {
        // Enable message signing and set keys
        config([
            'rabbitmq.message_signing.enabled' => true,
            'rabbitmq.message_signing.keys.private' => $this->privateKey,
            'rabbitmq.message_signing.verification_time_window' => 120,
        ]);

        // Create a new message with signing enabled
        $message = BasicMessage::make(['test' => 'signing']);

        // Use reflection to access protected method
        $reflectionClass = new \ReflectionClass($message);
        $signMethodReflection = $reflectionClass->getMethod('signMessage');
        $signMethodReflection->setAccessible(true);
        $signMethodReflection->invoke($message);

        // Generate a different key pair
        $differentRsa = RSA::createKey(1024);
        $differentPublicKey = $differentRsa->getPublicKey()->toString('PKCS1');

        // isSignatureValid should return false with wrong key
        $this->assertFalse($message->isSignatureValid($differentPublicKey));
    }

    public function testIsSignatureValidReturnsFalseWithExpiredTimestamp(): void
    {
        // Enable message signing and set keys
        config([
            'rabbitmq.message_signing.enabled' => true,
            'rabbitmq.message_signing.keys.private' => $this->privateKey,
            'rabbitmq.message_signing.verification_time_window' => 10, // 10 seconds window
        ]);

        // Create message with old timestamp
        $oldData = $this->validMessageData;
        $oldData['timestamp'] = now()->subSeconds(20)->toIso8601ZuluString(); // 20 seconds ago

        // Create message and sign it
        $message = BasicMessage::make(['test' => 'signing']);
        $reflectionClass = new \ReflectionClass($message);

        // Set the data property
        $dataProperty = $reflectionClass->getProperty('data');
        $dataProperty->setAccessible(true);
        $dataProperty->setValue($message, $oldData);

        // Sign the message
        $signMethodReflection = $reflectionClass->getMethod('signMessage');
        $signMethodReflection->setAccessible(true);
        $signMethodReflection->invoke($message);

        // isSignatureValid should return false with expired timestamp
        $this->assertFalse($message->isSignatureValid($this->publicKey));
    }

    public function testValidateSignatureWithTamperedSignature(): void
    {
        // Enable message signing and set keys
        config([
            'rabbitmq.message_signing.enabled' => true,
            'rabbitmq.message_signing.keys.private' => $this->privateKey,
            'rabbitmq.message_signing.verification_time_window' => 120,
        ]);

        // Create a new message with signing enabled
        $message = BasicMessage::make(['test' => 'signing']);

        // Use reflection to access protected method
        $reflectionClass = new \ReflectionClass($message);
        $signMethodReflection = $reflectionClass->getMethod('signMessage');
        $signMethodReflection->setAccessible(true);
        $signMethodReflection->invoke($message);

        // Get original data
        $originalData = $message->getData();
        $validSignature = $originalData['signature'];

        // Tamper with the signature (modify it slightly)
        $dataProperty = $reflectionClass->getProperty('data');
        $dataProperty->setAccessible(true);

        // Create a tampered signature by changing a character
        $tamperedSignature = $this->corruptBase64String($validSignature);

        $tamperedData = $originalData;
        $tamperedData['signature'] = $tamperedSignature;
        $dataProperty->setValue($message, $tamperedData);

        // Validation should fail with invalid signature exception
        $this->expectException(MessageSignatureException::class);
        $this->expectExceptionMessage('Message signature is invalid.');

        $message->validateSignature($this->publicKey);
    }

    public function testValidateSignatureWithoutSignature(): void
    {
        // Enable message signing and set keys
        config([
            'rabbitmq.message_signing.enabled' => true,
            'rabbitmq.message_signing.keys.private' => $this->privateKey,
            'rabbitmq.message_signing.verification_time_window' => 120,
        ]);

        // Create a message without a signature
        $message = BasicMessage::make(['test' => 'no-signature']);

        // Use reflection to remove the signature (if it was added automatically)
        $reflectionClass = new \ReflectionClass($message);
        $dataProperty = $reflectionClass->getProperty('data');
        $dataProperty->setAccessible(true);

        $data = $message->getData();
        unset($data['signature']);
        $dataProperty->setValue($message, $data);

        // Validation should fail with message exception
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Message signature is not set.');

        $message->validateSignature($this->publicKey);
    }

    /**
     * Helper method to corrupt a base64 string by changing one character
     * while maintaining valid base64 format
     */
    private function corruptBase64String(string $base64String): string
    {
        $allowedChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

        if ($base64String === '') {
            return '';
        }

        // Select a random position to corrupt
        $position = rand(0, strlen($base64String) - 1);

        // Get the character at that position
        $currentChar = $base64String[$position];

        // Find a different character to replace it with
        do {
            $newChar = $allowedChars[rand(0, strlen($allowedChars) - 1)];
        } while ($newChar === $currentChar);

        // Replace the character
        return substr_replace($base64String, $newChar, $position, 1);
    }
}
