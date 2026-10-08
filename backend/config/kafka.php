<?php

return [
    'brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
    'topic' => env('KAFKA_TOPIC', 'jamf.devices.v1'),
    'group' => env('KAFKA_GROUP', 'inventory-v1'),
];
