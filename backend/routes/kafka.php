<?php

use App\Services\KafkaNotificationReceiver;
use Illuminate\Support\Facades\Artisan;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;

Artisan::command('events:consume {--once}', function (KafkaNotificationReceiver $receiver) {
    $config = new Conf;
    $config->set('metadata.broker.list', config('kafka.brokers'));
    $config->set('group.id', config('kafka.group'));
    $config->set('enable.auto.commit', 'false');
    $config->set('enable.auto.offset.store', 'false');
    $config->set('auto.offset.reset', 'earliest');
    $consumer = new KafkaConsumer($config);
    $consumer->subscribe([config('kafka.topic')]);
    while (true) {
        $message = $consumer->consume(1000);
        if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            // Any database/transport error exits without acknowledging this message.
            $receiver->receive($message->payload, $message->topic_name, $message->partition, $message->offset);
            $consumer->commit($message);
            if ($this->option('once')) {
                return;
            }
        } elseif (! in_array($message->err, [RD_KAFKA_RESP_ERR__PARTITION_EOF, RD_KAFKA_RESP_ERR__TIMED_OUT], true)) {
            throw new RuntimeException($message->errstr());
        }
    }
});
