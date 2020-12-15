<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\Event\AfterProcessingMessagesEvent;
use OldSound\RabbitMqBundle\Event\BeforeProcessingMessagesEvent;
use OldSound\RabbitMqBundle\Logger\ExtraContextLogger;
use OldSound\RabbitMqBundle\RabbitMq\Consuming;

class ReceiverExecutorDecorator implements ReceiverExecutorInterface
{
    /** @var Consuming */
    private $consuming;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(Consuming $consuming, LoggerInterface $logger)
    {
        $this->consuming = $consuming;
        $this->logger = $logger;
    }

    private function createLoggerExtraContext(array $messages)
    {
        $amqpContext = ['queue' => $this->consuming->options->queue];
        if ($this->consuming->executeReceiverStrategy->canPrecessMultiMessages()) {
            $amqpContext['messages'] = $messages;
        } else {
            if (count($messages) > 1) {
                // TODO
            }
            $amqpContext['message'] = $messages[0];
        }
        return $amqpContext;
    }

    public function execute(array $messages, $receiver): array
    {
        $logger = new ExtraContextLogger($this->logger, ['amqp' => $this->createLoggerExtraContext($messages)]);

        $event = new BeforeProcessingMessagesEvent($messages, $this->consuming->options);
        $this->dispatchEvent(BeforeProcessingMessagesEvent::NAME, $event);
        if ($event->isStoppingConsumer()) {
            throw new StopConsumerException();
        }

        try {
            $flags = $this->consuming->receiverExecutor->execute($messages, $receiver);
        } catch (Exception\StopConsumerException $e) {
            $logger->info('Consumer requested stop', [
                'exception' => $e
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $logger->error('Throw exception while process messages', [
                'exception' => $e
            ]);
            throw $e;
        }


        $logger->info('Queue messages processed'); // TODO add flag code
        $event = new AfterProcessingMessagesEvent($this, $messages); // TODO add flag code
        $this->dispatchEvent(AfterProcessingMessagesEvent::NAME, $event);
        if ($event->isStoppingConsumer()) {
            throw new StopConsumerException();
        }

        return $flags;
    }

    public function support($receiver): bool
    {
        return $this->consuming->receiverExecutor->support($receiver);
    }
}