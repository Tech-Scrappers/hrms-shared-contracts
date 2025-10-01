<?php

namespace Shared\Messaging;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Illuminate\Support\Facades\Log;

class MqttConsumer implements MessageConsumer
{
    public function __construct(
        private string $host,
        private int $port,
        private string $clientId,
        private ?string $username = null,
        private ?string $password = null,
        private string $topic = 'hrms/employee/events/#',
    ) {}

    public function consume(callable $handler): void
    {
        $settings = (new ConnectionSettings())
            ->setUsername($this->username)
            ->setPassword($this->password)
            ->setUseTls(false)
            ->setReconnectAutomatically(true)
            ->setDelayBetweenReconnectAttempts(1000);

        $client = new MqttClient($this->host, $this->port, $this->clientId);
        // cleanSession=false required if automatic reconnects are enabled
        $client->connect($settings, false);

        $client->subscribe($this->topic, function (string $topic, string $message) use ($handler) {
            try {
                $payload = json_decode($message, true) ?: [];
                $handler($payload);
            } catch (\Throwable $e) {
                Log::error('MQTT message handling failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }, 1);

        $client->loop(true);
    }
}


