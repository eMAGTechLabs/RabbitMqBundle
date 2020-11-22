<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class BatchExecuteCallbackStrategy implements ExecuteCallbackStrategyInterface
{
    /** @var int */
    private $batchCount;
    /** @var callable */
    private $proccessMessagesFn;

    /** @var AMQPMessage[] */
    protected $messagesBatch = [];

    public function __construct(int $batchCount)
    {
        $this->batchCount = $batchCount;
    }

    public function setProccessMessagesFn(callable $proccessMessagesFn)
    {
        $this->proccessMessagesFn  = $proccessMessagesFn;
    }

    public function canPrecessMultiMessages(): bool
    {
        return true;
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
        $this->batch[$message->getDeliveryTag()] = $message;

        if ($this->isBatchCompleted()) {
            $this->proccessMessages($this->messagesBatch);
            // TODO $this->messagesBatch = [];
        }
    }

    public function onStopConsuming()
    {
        if (!$this->isEmptyBatch()) {
            $this->proccessMessages($this->messagesBatch);
        }
    }

    public function onCatchTimeout(AMQPTimeoutException $e)
    {
        if (!$this->isBatchEmpty()) {
            $this->proccessMessages($this->messagesBatch);
        }
    }


    protected function isBatchCompleted(): bool
    {
        return count($this->batch) === $this->batchCount;
    }

    /**
     * @return  bool
     */
    protected function isBatchEmpty()
    {
        return count($this->batch) === 0;
    }
}