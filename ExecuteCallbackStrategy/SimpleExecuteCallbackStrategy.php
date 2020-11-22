<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class SimpleExecuteCallbackStrategy implements ExecuteCallbackStrategyInterface
{
    /** @var callable */
    private $proccessMessagesFn;

    public function setProccessMessagesFn(callable $proccessMessagesFn)
    {
        $this->proccessMessagesFn = $proccessMessagesFn;
    }

    public function canPrecessMultiMessages(): bool
    {
        return false;
    }

    /**
     * @param AMQPMessage[] $meesages
     */
    protected function proccessMessages(array $messages)
    {
        call_user_func($this->proccessMessagesFn, $messages);
    }

    public function consumeCallback(AMQPMessage $message)
    {
        $this->proccessMessages([$message]);
    }

    public function onMessageProcessed(AMQPMessage $message)
    {
    }

    public function onCatchTimeout(AMQPTimeoutException $e)
    {
    }

    public function onStopConsuming()
    {
    }
}