<?php

namespace App\Messaging;

use App\Contracts\EventPublisher;
use RdKafka\Conf;
use RdKafka\Producer;
use RuntimeException;

final class KafkaEventPublisher implements EventPublisher
{
    public function publish(array $payload): void
    {
        if (! extension_loaded('rdkafka')) {
            throw new RuntimeException('Kafka publisher requires php-rdkafka.');
        }
        $error = null;
        $delivered = false;
        $conf = new Conf;
        $conf->set('metadata.broker.list', config('kafka.brokers', 'kafka:9092'));
        $conf->set('enable.idempotence', 'true');
        $conf->set('acks', 'all');
        $conf->set('message.timeout.ms', '10000');
        $conf->setDrMsgCb(function ($producer, $message) use (&$error, &$delivered) {
            if ($message->err) {
                $error = $message->errstr();
            } else {
                $delivered = true;
            }
        });
        $producer = new Producer($conf);
        $topic = $producer->newTopic(config('kafka.topic', 'mdm-device-events'));
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode($payload, JSON_THROW_ON_ERROR), 'jamf|'.$payload['provider_device_id']);
        $producer->poll(0);
        $result = $producer->flush(15000);
        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR || ! $delivered || $error !== null) {
            throw new RuntimeException('Kafka publish was not acknowledged: '.($error ?? (string) $result));
        }
    }
}
