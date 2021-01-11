<?php


namespace OldSound\RabbitMqBundle\Receiver;


use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;

interface ArgumentResolverInterface
{
    /**
     * @param \AMQPMessage[] $messages
     * @param callable $receiver
     * @param ConsumeOptions $options
     * @return mixed
     */
    public function getArguments(array $messages, callable $receiver, ConsumeOptions $options);
}