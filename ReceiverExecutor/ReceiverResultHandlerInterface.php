<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

interface ReceiverResultHandlerInterface
{
    /**
     * @param mixed $result
     * @param array $messages
     */
    public function handle($result, array $messages): void;
}