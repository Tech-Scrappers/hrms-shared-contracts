<?php

namespace Shared\Messaging;

// Simple logging functions for MQTT components
if (!function_exists('Shared\Messaging\mqtt_log_info')) {
    function mqtt_log_info($message, $context = []) {
        error_log("MQTT INFO: $message " . json_encode($context));
    }
}

if (!function_exists('Shared\Messaging\mqtt_log_error')) {
    function mqtt_log_error($message, $context = []) {
        error_log("MQTT ERROR: $message " . json_encode($context));
    }
}
