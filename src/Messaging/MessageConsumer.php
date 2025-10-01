<?php

namespace Shared\Messaging;

interface MessageConsumer
{
    /**
     * Start consuming messages. Call $handler with the decoded payload array.
     */
    public function consume(callable $handler): void;
}


