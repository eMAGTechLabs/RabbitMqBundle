<?php


namespace OldSound\RabbitMqBundle\RabbitMq;


use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class BatchHandler implements ExecuteCallbackStrategyInterface
{
    /** @var QueueConsuming */
    private $queueConsuming;
    /** @var callable */
    private $proccessMessagesFn;

    /** @var AMQPMessage[] */
    protected $messagesBatch = [];

    public function __construct(QueueConsuming $queueConsuming, callable $proccessMessagesFn)
    {
        if (!$queueConsuming->batchCount || $queueConsuming->batchCount < 2) {
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
        return count($this->batch) === $this->queueConsuming->batchCount;
    }

    /**
     * @return  bool
     */
    protected function isBatchEmpty()
    {
        return count($this->batch) === 0;
    }
}