# Laravel RabbitMQ Module

Módulo para integração com RabbitMQ providenciando suporte para múltiplas conexões, exchanges, filas e consumidores.

## Instalação

1. Publique o arquivo de configuração:
```bash
php artisan vendor:publish --tag=rabbitmq-config
```

2. Configure seu arquivo `.env`:
```env
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_SSL=false
```

## Estrutura de Configuração

### Conexões
Defina as conexões com servidores RabbitMQ:
```php
'connections' => [
    'default' => [
        'host' => env('RABBITMQ_HOST'),      // Host do servidor
        'port' => env('RABBITMQ_PORT'),      // Porta do servidor
        'user' => env('RABBITMQ_USER'),      // Usuário
        'password' => env('RABBITMQ_PASSWORD'), // Senha
        'vhost' => env('RABBITMQ_VHOST'),    // Virtual host
        'ssl' => [                           // Configurações SSL
            'enabled' => env('RABBITMQ_SSL'), // Habilitar SSL
            'cafile' => null,                // Arquivo CA
            'local_cert' => null,            // Certificado local
            'local_key' => null,             // Chave local
            'verify_peer' => true,           // Verificar certificado
        ],
    ],
]
```

### Exchanges
Configure suas exchanges:
```php
'exchanges' => [
    'notifications' => [
        'connection' => 'default',    // Conexão a ser utilizada
        'name' => 'app.notifications', // Nome da exchange
        'type' => 'fanout',          // Tipo: direct, topic, fanout, headers
        'passive' => false,          // Não criar, erro se não existir
        'durable' => true,           // Sobreviver reinicialização
        'auto_delete' => false,      // Deletar quando sem filas
        'internal' => false,         // Não permite publicação direta
        'arguments' => [],           // Argumentos adicionais
    ],
]
```

### Filas
Configure suas filas:
```php
'queues' => [
    'notifications' => [
        'exchange' => 'notifications', // Exchange vinculada
        'name' => 'notifications_queue', // Nome da fila
        'routing_key' => 'notifications.*', // Chave de roteamento
        'consumer_tag' => null,      // Identificador do consumidor
        'passive' => false,          // Não criar, erro se não existir
        'durable' => true,           // Sobreviver reinicialização
        'exclusive' => false,        // Única conexão pode consumir
        'auto_delete' => false,      // Deletar quando sem consumidores
        'arguments' => [],           // Argumentos adicionais
        'prefetch' => [
            'count' => 1,            // Mensagens para prefetch
            'size' => 0,             // Tamanho total em bytes
        ],
        'retry' => [
            'enabled' => true,       // Habilitar mecanismo de retry
            'max_attempts' => 3,     // Máximo de tentativas
            'delay' => 60000,        // Delay entre tentativas (ms)
        ],
    ],
]
```

## Uso

### Publicando Mensagens

```php
use JuniorFontenele\LaravelRabbitMQ\Facades\RabbitMQ;

// Publicação simples
RabbitMQ::publish('notifications', [
    'user_id' => 1,
    'message' => 'Hello!'
]);

// Com opções
RabbitMQ::publish('notifications', $data, [
    'message_id' => uniqid(),
    'correlation_id' => $correlationId,
    'headers' => ['priority' => 'high'],
    'routing_key' => 'notifications.email'
]);
```

### Criando um Consumidor

```php
namespace JuniorFontenele\LaravelRabbitMQ\Consumers;

use JuniorFontenele\LaravelRabbitMQ\Consumer;
use PhpAmqpLib\Message\AMQPMessage;

class NotificationsConsumer extends Consumer
{
    public function process(AMQPMessage $message): void
    {
        $data = json_decode($message->getBody(), true);
        
        // Processa a mensagem
        // ...
        
        // Confirma o processamento
        $message->ack();
    }
}
```

### Registrando Consumidor

```php
// Em um ServiceProvider
$manager = app(RabbitMQManager::class);
$manager->registerConsumer('notifications', NotificationsConsumer::class);
```

### Iniciando Worker

Via CLI:
```bash
# Worker básico
php artisan rabbitmq:work notifications

# Com opções
php artisan rabbitmq:work notifications \
    --memory=256 \
    --timeout=120 \
    --sleep=5 \
    --max-jobs=1000 \
    --tries=3
```

Via código:
```php
use JuniorFontenele\LaravelRabbitMQ\Worker;

$worker = app(Worker::class);
$worker->work('notifications', [
    'memory_limit' => 256,
    'timeout' => 120,
    'max_jobs' => 1000
]);
```

## Eventos

O módulo dispara os seguintes eventos:

- `rabbitmq.processing`: Antes do processamento
- `rabbitmq.processed`: Após processamento com sucesso
- `rabbitmq.failed`: Quando ocorre erro no processamento

```php
Event::listen('rabbitmq.failed', function($message, $queue, $exception) {
    Log::error("Falha no processamento", [
        'queue' => $queue,
        'error' => $exception->getMessage()
    ]);
});
```

## Melhores Práticas

1. Use exchanges e filas duráveis para mensagens importantes
2. Configure prefetch adequadamente para controlar uso de memória
3. Implemente tratamento de erros nos consumidores
4. Use routing keys significativas para melhor roteamento
5. Configure políticas de retry baseadas no seu caso de uso
6. Monitore uso de memória e configure limites apropriados
7. Use IDs de correlação para rastrear mensagens relacionadas
8. Implemente dead letter exchanges para mensagens com falha

## Tratamento de Erros

Mensagens que falham no processamento serão:
1. Novas tentativas conforme configuração de retry da fila
2. Disparam evento 'rabbitmq.failed'
3. Logadas com detalhes do erro
4. Rejeitadas (com requeue=false) após máximo de tentativas
