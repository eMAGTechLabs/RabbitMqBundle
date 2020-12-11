<?php

namespace OldSound\RabbitMqBundle\Command;

use OldSound\RabbitMqBundle\RabbitMq\BatchConsumer;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class BatchConsumerCommand extends BaseRabbitMqCommand
{
    /**
     * @var BatchConsumer
     */
    protected $consumer;

    public function stopConsumer(): void
    {
        if ($this->consumer instanceof BatchConsumer) {
            // Process current message, then halt consumer
            $this->consumer->forceStopConsumer();

            // Halt consumer if waiting for a new message from the queue
            try {
                $this->consumer->stopConsuming();
            } catch (AMQPTimeoutException $e) {}
        }
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rabbitmq:batch:consumer')
            ->addArgument('name', InputArgument::REQUIRED, 'Consumer Name')
            ->addOption('route', 'r', InputOption::VALUE_OPTIONAL, 'Routing Key', '')
            ->addOption('memory-limit', 'l', InputOption::VALUE_OPTIONAL, 'Allowed memory for this process')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable Debugging')
            ->addOption('without-signals', 'w', InputOption::VALUE_NONE, 'Disable catching of system signals')
            ->setDescription('Executes a Batch Consumer');
    }

    /**
     * Executes the current command.
     *
     * @throws  \InvalidArgumentException       When the number of messages to consume is less than 0
     * @throws  \BadFunctionCallException|\ErrorException       When the pcntl is not installed and option -s is true
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (defined('AMQP_WITHOUT_SIGNALS') === false) {
            define('AMQP_WITHOUT_SIGNALS', $input->getOption('without-signals'));
        }

        if (!AMQP_WITHOUT_SIGNALS && extension_loaded('pcntl')) {
            if (!function_exists('pcntl_signal')) {
                throw new \BadFunctionCallException("Function 'pcntl_signal' is referenced in the php.ini 'disable_functions' and can't be called.");
            }

            pcntl_signal(SIGTERM, array(&$this, 'stopConsumer'));
            pcntl_signal(SIGINT, array(&$this, 'stopConsumer'));
        }

        if (defined('AMQP_DEBUG') === false) {
            define('AMQP_DEBUG', (bool) $input->getOption('debug'));
        }

        $this->initConsumer($input);

        return $this->consumer->consume();
    }

    protected function initConsumer(InputInterface $input): void
    {
        $this->consumer = $this->getContainer()
            ->get(sprintf($this->getConsumerService(), $input->getArgument('name')));

        if (null !== $input->getOption('memory-limit') &&
            ctype_digit((string) $input->getOption('memory-limit')) &&
            (int) $input->getOption('memory-limit') > 0
        ) {
            $this->consumer->setMemoryLimit($input->getOption('memory-limit'));
        }
        $this->consumer->setRoutingKey($input->getOption('route'));
    }

    protected function getConsumerService(): string
    {
        return 'old_sound_rabbit_mq.%s_batch';
    }
}