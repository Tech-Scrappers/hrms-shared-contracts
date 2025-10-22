<?php

namespace Shared\Messaging;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// Include the logger
require_once __DIR__ . '/MqttLogger.php';

class MqttPublisher
{
    public function __construct(
        private string $host,
        private int $port,
        private string $clientId,
        private ?string $username = null,
        private ?string $password = null,
    ) {}

    /**
     * Publish a message to MQTT broker
     */
    public function publish(string $topic, array $payload, int $qos = 1, bool $retain = false): bool
    {
        try {
            $settings = (new ConnectionSettings())
                ->setUsername($this->username)
                ->setPassword($this->password)
                ->setUseTls(false)
                ->setReconnectAutomatically(false);

            $client = new MqttClient($this->host, $this->port, $this->clientId);
            $client->connect($settings, true);

            $message = json_encode($payload);
            $client->publish($topic, $message, $qos, $retain);
            $client->disconnect();

            \Shared\Messaging\mqtt_log_info('Message published to MQTT', [
                'topic' => $topic,
                'client_id' => $this->clientId,
                'qos' => $qos,
                'retain' => $retain,
            ]);

            return true;

        } catch (\Throwable $e) {
            \Shared\Messaging\mqtt_log_error('Failed to publish MQTT message', [
                'topic' => $topic,
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publish an event with standard HRMS format
     */
    public function publishEvent(string $eventType, array $data, array $meta = [], string $topicPrefix = 'hrms'): bool
    {
        $topic = "{$topicPrefix}/{$eventType}";
        
        $payload = [
            'event' => $eventType,
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => now()->toISOString(),
                'event_id' => uniqid('evt_', true),
            ], $meta),
        ];

        return $this->publish($topic, $payload);
    }
}
