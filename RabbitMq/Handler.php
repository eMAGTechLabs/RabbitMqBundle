<?php


namespace OldSound\RabbitMqBundle\RabbitMq;


use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class Handler implements ExecuteCallbackStrategyInterface
{
    /** @var QueueConsuming */
    private $queueConsuming;
    /** @var callable */
    private $proccessMessagesFn;

    public function __construct(QueueConsuming $queueConsuming, callable $proccessMessagesFn)
    {
        if ($queueConsuming->batchCount && $queueConsuming->batchCount > 1) {
            throw new \InvalidArgumentException('TODO');
        }
        $this->queueConsuming = $queueConsuming;
        $this->proccessMessagesFn = $proccessMessagesFn;
    }

    /**
     * @param AMQPMessage[] $meesages
     */
    protected function proccessMessages(array $messages)
    {
        call_user_func($this->proccessMessagesFn, $messages, $this->queueConsuming);
    }

    public function consumeCallback(AMQPMessage $message)
    {
        $this->proccessMessages([$message]);
    }

    public function onStopConsuming()
    {
    }

    public function onCatchTimeout(AMQPTimeoutException $e)
    {
    }
}