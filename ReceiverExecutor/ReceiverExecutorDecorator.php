<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\Declarations\BatchConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\ConsumerDef;
use OldSound\RabbitMqBundle\Declarations\RpcConsumeOptions;
use OldSound\RabbitMqBundle\Event\AfterProcessingMessagesEvent;
use OldSound\RabbitMqBundle\Event\BeforeProcessingMessagesEvent;
use OldSound\RabbitMqBundle\EventDispatcherAwareTrait;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\NullLogger;

class ReceiverExecutorDecorator implements ReceiverExecutorInterface
{
    use EventDispatcherAwareTrait;
    use LoggerAwareTrait;

    /** @var ConsumerDef */
    private $consumerDef;
    /** @var ConsumeOptions */
    private $options;

    /** @var ReceiverExecutorInterface */
    private $receiverExecutor;

    public function __construct(
        ReceiverExecutorInterface $receiverExecutor,
        ConsumerDef $consumerDef,
        ConsumeOptions $options
    )
    {
        $this->consumerDef = $consumerDef;
        $this->options = $options;
        $this->receiverExecutor = $receiverExecutor;
        $this->logger = new NullLogger();
    }

    /**
     * @param AMQPMessage[] $messages
     * @param callable $receiver
     * @return array
     * @throws \Throwable
     */
    public function execute(array $messages, callable $receiver): array
    {
        $event = new BeforeProcessingMessagesEvent($messages, $this->options);
        $this->dispatchEvent($event, BeforeProcessingMessagesEvent::NAME);
        if ($event->isForceStop()) {
            throw new StopConsumerException();
        }

        try {
            $flags = $this->receiverExecutor->execute($this->convertMessagesArgument($messages), $receiver);
        } catch (Exception\StopConsumerException $e) {
            $this->logger->info('Consumer requested stop', [
                'exception' => $e,
                'amqp' => $this->createLoggerExtraContext($messages)
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Throw exception while process messages', [
                'exception' => $e,
                'amqp' => $this->createLoggerExtraContext($messages)
            ]);
            throw $e;
        }


        $this->logger->info('Queue messages processed', ['amqp' => [...$this->createLoggerExtraContext($messages), 'flags' => $flags]]);
        $event = new AfterProcessingMessagesEvent($messages); // TODO add flag code
        $this->dispatchEvent($event, AfterProcessingMessagesEvent::NAME);
        if ($event->isForceStop()) {
            throw new StopConsumerException();
        }

        return $flags;
    }

    /**
     * @param AMQPMessage[] $messages
     */
    private function convertMessagesArgument(array $messages): array
    {
        // TODO

        return $messages;
    }

    private function createLoggerExtraContext(array $messages): array
    {
        return [
            'consumer' => $this->consumerDef->name,
            'queue' => $this->options->queue,
            'messages' => $messages
        ];
    }
}