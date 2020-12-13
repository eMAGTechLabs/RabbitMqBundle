<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use http\Exception\InvalidArgumentException;
use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use OldSound\RabbitMqBundle\Event\AfterProcessingMessageEvent;
use OldSound\RabbitMqBundle\Event\AfterProcessingMessagesEvent;
use OldSound\RabbitMqBundle\Event\AMQPEvent;
use OldSound\RabbitMqBundle\Event\BeforeProcessingMessageEvent;
use OldSound\RabbitMqBundle\Event\BeforeProcessingMessagesEvent;
use OldSound\RabbitMqBundle\Event\OnConsumeEvent;
use OldSound\RabbitMqBundle\Event\OnIdleEvent;
use OldSound\RabbitMqBundle\EventDispatcherAwareTrait;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\BatchExecuteReceiverStrategy;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\ExecuteReceiverStrategyInterface;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\FnMessagesProcessor;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\MessagesProcessorInterface;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\SimpleExecuteReceiverStrategy;
use OldSound\RabbitMqBundle\MemoryChecker\MemoryConsumptionChecker;
use OldSound\RabbitMqBundle\MemoryChecker\NativeMemoryUsageProvider;
use OldSound\RabbitMqBundle\Producer\ProducerInterface;
use OldSound\RabbitMqBundle\Receiver\NotReadyReceiveException;
use OldSound\RabbitMqBundle\Receiver\ReceiverException;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;
use OldSound\RabbitMqBundle\Receiver\ReplyReceiverInterface;
use OldSound\RabbitMqBundle\Serializer\JsonMessageBodySerializer;
use OldSound\RabbitMqBundle\Serializer\MessageBodySerializerInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\ExecuteReceiverStrategyInterface;
use Symfony\Component\Serializer\SerializerInterface;

class Consumer
{
    use LoggerAwareTrait;
    use EventDispatcherAwareTrait;

    /** @var AMQPChannel */
    protected $channel;
    /** @var QueueConsuming[] */
    protected $queueConsumings = [];
    /** @var ExecuteReceiverStrategyInterface[] */
    protected $executeReceiverStrategies = [];
    /** @var MessageBodySerializerInterface */
    protected $serializer;

    /** @var string[] */
    protected $consumerTags = [];
    /** @var array */
    protected $basicProperties = [
        'content_type' => 'text/plain',
        'delivery_mode' => ProducerInterface::DELIVERY_MODE_PERSISTENT
    ];
    /** @var int|null */
    protected $target;
    /** @var int */
    protected $consumed = 0;
    /** @var bool */
    protected $forceStop = false;
    /**
     * Importrant! If true - then channel can not be used from somewhere else
     * @var bool
     */
    public $multiAck = false;
    /**
     * @var \DateTime|null DateTime after which the consumer will gracefully exit. "Gracefully" means, that
     *      any currently running consumption will not be interrupted.
     */
    public $gracefulMaxExecutionDateTime;
    /** @var int Exit code used, when consumer is closed by the Graceful Max Execution Timeout feature. */
    public $gracefulMaxExecutionTimeoutExitCode = 0;
    /** @var int|null */
    public $timeoutWait;
    /** @var int */
    public $idleTimeout = 0;
    /** @var int */
    public $idleTimeoutExitCode;
    /** @var \DateTime|null */
    public $lastActivityDateTime;

    public function __construct(AMQPChannel $channel)
    {
        $this->channel = $channel;
        $this->logger = new NullLogger();
        $this->serializer = new JsonMessageBodySerializer();
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    public function setSerializer(MessageBodySerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    protected function setup(): Consumer
    {
        foreach($this->queueConsumings as $index => $queueConsuming) {
            $this->channel->basic_qos($queueConsuming->qosPrefetchSize, $queueConsuming->qosPrefetchCount, false);

            $consumerTag = $this->channel->basic_consume(
                $queueConsuming->queueName,
                $queueConsuming->consumerTag ?
                    $queueConsuming->consumerTag :
                    sprintf("PHPPROCESS_%s_%s_%s", gethostname(), getmypid(), $index),
                $queueConsuming->noLocal,
                $queueConsuming->noAck,
                $queueConsuming->exclusive,
                $queueConsuming->nowait,
                function (AMQPMessage $message) use ($queueConsuming) {
                    $this->getExecuteReceiverStrategy($queueConsuming)->consumeCallback($message);
                });

            //$queueConsuming->consumerTag = $consumerTag;
            $this->consumerTags[] = $consumerTag;
        }

        return $this;
    }

    /**
     * @param iterable|QueueConsuming[] $queueConsumings
     */
    public function consumeQueues(iterable $queueConsumings)
    {
        foreach ($queueConsumings as $queueConsuming) {
            $this->consumeQueue($queueConsuming);
        }
    }

    public function consumeQueue(QueueConsuming $queueConsuming, ExecuteReceiverStrategyInterface $executeReceiverStrategy = null): Consumer
    {
        $this->queueConsumings[] = $queueConsuming;
        if (null === $executeReceiverStrategy) {
            $executeReceiverStrategy = null === $queueConsuming->batchCount ?
                new SimpleExecuteReceiverStrategy() :
                new BatchExecuteReceiverStrategy($queueConsuming->batchCount);
        }

        $executeReceiverStrategy->setMessagesProccessor(new FnMessagesProcessor(
            (function (array $messages) use ($queueConsuming) {
                $logAmqpContext = ['queue' => $queueConsuming->queueName];
                if ($this->getExecuteReceiverStrategy($queueConsuming)->canPrecessMultiMessages()) {
                    $logAmqpContext['messages'] = $messages;
                } else {
                    $logAmqpContext['message'] = $messages[0];
                }

                $this->dispatchEvent(BeforeProcessingMessagesEvent::NAME,
                    new BeforeProcessingMessagesEvent($this, $messages, $queueConsuming)
                );

                try {
                    $this->processMessages($messages, $queueConsuming);
                } catch (Exception\StopConsumerException $e) {
                    $this->logger->info('Consumer requested stop', [
                        'amqp' => $logAmqpContext,
                        'exception' => $e
                    ]);

                    $this->stopConsuming(true);
                    return;
                } catch (\Throwable $e) {
                    $this->logger->error('Throw exception while process messages', [
                        'amqp' => $logAmqpContext,
                        'exception' => $e
                    ]);
                    throw $e;
                }

                $this->logger->info('Queue messages processed', ['amqp' => $logAmqpContext]); // TODO add flag code
                $this->dispatchEvent(
                    AfterProcessingMessagesEvent::NAME,
                    new AfterProcessingMessagesEvent($this, $messages) // TODO add flag code
                );

                $this->maybeStopConsumer();
            })->bindTo($this)
        ));

        $canPrecessMultiMessages = $executeReceiverStrategy->canPrecessMultiMessages();
        if ($canPrecessMultiMessages) {
            if (!$queueConsuming->receiver instanceof BatchReceiverInterface) {
                throw new \InvalidArgumentException('TODO '. $queueConsuming->queueName);
            }
        } else {
            if (!$queueConsuming->receiver instanceof ReceiverInterface) {
                throw new \InvalidArgumentException('TODO '. $queueConsuming->queueName);
            }
        }

        $this->executeReceiverStrategies[] = $executeReceiverStrategy;

        return $this;
    }

    private function getExecuteReceiverStrategy(QueueConsuming $queueConsuming): ExecuteReceiverStrategyInterface
    {
        return $this->executeReceiverStrategies[array_search($queueConsuming, $this->queueConsumings, true)];
    }

    /**
     * @return QueueConsuming[]
     */
    public function getQueueConsumings(): array
    {
        return $this->queueConsumings;
    }

    /**
     * Consume the message
     * @param   int     $msgAmount
     * @return  int
     *
     * @throws  AMQPTimeoutException
     */
    public function consume(int $msgAmount = null)
    {
        $this->target = $msgAmount;
        $this->consumed = 0;

        $this->setup();

        $this->lastActivityDateTime = new \DateTime();
        while ($this->channel->is_consuming()) {
            $this->dispatchEvent(OnConsumeEvent::NAME, new OnConsumeEvent($this));
            $this->maybeStopConsumer();

            if ($this->forceStop) {
                break;
            }
            /*
             * Be careful not to trigger ::wait() with 0 or less seconds, when
             * graceful max execution timeout is being used.
             */
            $waitTimeout = $this->chooseWaitTimeout();
            if ($this->gracefulMaxExecutionDateTime && $waitTimeout < 1) {
                return $this->gracefulMaxExecutionTimeoutExitCode;
            }

            try {
                $this->channel->wait(null, false, $waitTimeout);
                $this->lastActivityDateTime = new \DateTime();
                if ($this->forceStop) {
                    break;
                }
            } catch (AMQPTimeoutException $e) {
                foreach($this->executeReceiverStrategies as $executeReceiverStrategy) {
                    $executeReceiverStrategy->onCatchTimeout($e);
                }
                $now = new \DateTime();
                if ($this->gracefulMaxExecutionDateTime && $this->gracefulMaxExecutionDateTime <= $now) {
                    return $this->gracefulMaxExecutionTimeoutExitCode;
                }

                if ($this->idleTimeout && ($this->lastActivityDateTime->getTimestamp() + $this->idleTimeout <= $now->getTimestamp())) {
                    $idleEvent = new OnIdleEvent($this);
                    $this->dispatchEvent(OnIdleEvent::NAME, $idleEvent);

                    if ($idleEvent->isForceStop()) {
                        if (null !== $this->idleTimeoutExitCode) {
                            return $this->idleTimeoutExitCode;
                        } else {
                            throw $e;
                        }
                    }
                }
            }
        }

        return 0;
    }

    /**
     * @param AMQPMessage[] $messages
     * @param QueueConsuming $queueConsuming
     */
    protected function processMessages(array $messages, QueueConsuming $queueConsuming)
    {
        if (count($messages) === 0) {
            throw new \InvalidArgumentException('Messages can not be empty');
        }

        $canPrecessMultiMessages = $this->getExecuteReceiverStrategy($queueConsuming)->canPrecessMultiMessages();
        if (!$canPrecessMultiMessages && count($messages) !== 1) {
            throw new \InvalidArgumentException('Strategy is not supported process of multi messages');
        }

        /** @var int[]|int $flags */
        $flags = [];
        try {
            if ($queueConsuming->receiver instanceof ReceiverInterface) {
                $flags = $queueConsuming->receiver->execute($messages[0]);
            } else if ($queueConsuming->receiver instanceof BatchReceiverInterface) {
                $flags = $queueConsuming->receiver->batchExecute($messages);
            } else if ($queueConsuming->receiver instanceof ReplyReceiverInterface) {
                $reply = $queueConsuming->receiver->execute($messages[0]);
                $isRpcCall = $messages[0]->has('reply_to') && $messages[0]->has('correlation_id');
                if ($isRpcCall) {
                    $this->sendRpcReply($messages[0], $reply);
                    $flags = ReceiverInterface::MSG_ACK;
                } else {
                    $flags = ReceiverInterface::MSG_REJECT;
                    // logging
                }
            } else {
                throw new \InvalidArgumentException('TODO');
            }
        } catch (ReceiverException $exception) {
            $flags = $exception->getCode();
        } catch (NotReadyReceiveException $exception) {
            // TODO
            $this->forceStop = true;
            return;
        }

        if (!is_array($flags)) { // spread handle flag for each delivery tag
            $flag = $flags;
            $flags = [];
            foreach ($messages as $message) {
                $flags[$message->getDeliveryTag()] = $flag;
            }
        } else if (count($flags) !== count($messages)) {
            throw new AMQPRuntimeException(
                'Method batchExecute() should return an array with elements equal with the number of messages processed'
            );
        }

        if (!$queueConsuming->noAck) {
            $messages = array_combine(
                array_map(fn ($message) => $message->getDeliveryTag(), $messages),
                $messages
            );

            $this->handleProcessMessages($messages, $flags, $queueConsuming);
        }
    }

    /**
     * @param AMQPMessage[] $messages
     * @param int[]|RpcReponse[]|RpcResponseException[]|bool[] $replies
     */
    private function handleProcessMessages($messages, array $replies, QueueConsuming $queueConsuming)
    {
        $executeReceiverStrategy = $this->getExecuteReceiverStrategy($queueConsuming);

        $ack = !array_search(fn ($reply) => $reply !== ReceiverInterface::MSG_ACK, $replies, true);
        if ($this->multiAck && count($messages) > 1 && $ack) {
            $channels = array_map(fn ($message) => $message->getChannel(), $messages);
            if (count($channels) !== array_unique($channels)) { // all messages have same channel
                throw new InvalidArgumentException('Messages can not be processed as multi ack with different channels');
            }

            $lastDeliveryTag = array_key_last($replies);

            $this->channel->basic_ack($lastDeliveryTag, true);
            $this->consumed = $this->consumed + count($messages);
            foreach ($messages as $message) {
                $executeReceiverStrategy->onMessageProcessed($message);
            }
        } else {
            foreach ($replies as $deliveryTag => $reply) {
                $message = $messages[$deliveryTag] ?? null;
                if (null === $message) {
                    throw new AMQPRuntimeException(sprintf('Unknown delivery_tag %d!', $deliveryTag));
                }

                $channel = $message->getChannel();
                $processFlag = $reply;
                if ($processFlag === ReceiverInterface::MSG_REJECT_REQUEUE || false === $processFlag) {
                    $channel->basic_reject($deliveryTag, true); // Reject and requeue message to RabbitMQ
                } else if ($processFlag === ReceiverInterface::MSG_SINGLE_NACK_REQUEUE) {
                    $channel->basic_nack($deliveryTag, false, true); // NACK and requeue message to RabbitMQ
                } else if ($processFlag === ReceiverInterface::MSG_REJECT) {
                    $channel->basic_reject($deliveryTag, false); // Reject and drop
                } else if ($processFlag !== ReceiverInterface::MSG_ACK_SENT) {
                    $channel->basic_ack($deliveryTag); // Remove message from queue only if callback return not false
                }

                $this->consumed++;

                $executeReceiverStrategy->onMessageProcessed($message);
            }
        }
    }

    protected function sendRpcReply(AMQPMessage $message, $result)
    {
        if ($result instanceof RpcReponse || $result instanceof RpcResponseException) {
            $body = $this->serializer->serialize($result);
            $replayMessage = new AMQPMessage($body, [
                'content_type' => 'text/plain',
                'correlation_id' => $message->get('correlation_id'),
            ]);
            $message->getChannel()->basic_publish($replayMessage , '', $message->get('reply_to'));
        } else {
            $this->logger->error('Rpc call send msg to queue which have not rpc reponse', [
                'amqp' => ['message' => $message]
            ]);
        }
    }

    protected function maybeStopConsumer()
    {
        if ($this->forceStop || ($this->target && $this->consumed == $this->target)) {
            $this->stopConsuming();
        }
    }

    public function forceStopConsumer()
    {
        $this->forceStop = true;
    }

    public function stopConsuming($immedietly = false)
    {
        if (false === $immedietly) {
            foreach ($this->executeReceiverStrategies as $executeReceiverStrategy) {
                $executeReceiverStrategy->onStopConsuming();
            }
        }

        foreach ($this->consumerTags as $consumerTag) {
            $this->channel->basic_cancel($consumerTag, false, true);
        }

        $this->consumerTags = [];
    }

    /**
     * @param int $secondsInTheFuture
     */
    public function setGracefulMaxExecutionDateTimeFromSecondsInTheFuture($secondsInTheFuture)
    {
        $this->gracefulMaxExecutionDateTime = new \DateTime("+{$secondsInTheFuture} seconds");
    }

    /**
     * Choose the timeout wait (in seconds) to use for the $this->getChannel()->wait() method.
     */
    private function chooseWaitTimeout(): int
    {
        if ($this->gracefulMaxExecutionDateTime) {
            $allowedExecutionSeconds = $this->gracefulMaxExecutionDateTime->getTimestamp() - time();

            /*
             * Respect the idle timeout if it's set and if it's less than
             * the remaining allowed execution.
             */
            $waitTimeout = $this->idleTimeout && $this->idleTimeout < $allowedExecutionSeconds
                ? $this->idleTimeout
                : $allowedExecutionSeconds;
        } else {
            $waitTimeout = $this->idleTimeout;
        }

        if (!is_null($this->timeoutWait) && $this->timeoutWait > 0) {
            $waitTimeout = min($waitTimeout, $this->timeoutWait);
        }
        return $waitTimeout;
    }
}
