<?php

namespace OldSound\RabbitMqBundle\Command;

use OldSound\RabbitMqBundle\Consumer\ConsumersRegistry;
use OldSound\RabbitMqBundle\Declarations\DeclarationsRegistry;
use OldSound\RabbitMqBundle\Declarations\Declarator;
use OldSound\RabbitMqBundle\Event\AfterProcessingMessageEvent;
use OldSound\RabbitMqBundle\EventListener\MemoryLimitListener;
use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConsumerCommand extends Command
{
    /** @var iterable|Consumer[] */
    protected $consumers;
    /** @var DeclarationsRegistry */
    protected $declarationsRegistry;
    
    public function __construct(
        iterable $consumers,
        DeclarationsRegistry $declarationsRegistry
    ) {
        $this->consumers = $consumers;
        $this->declarationsRegistry = $declarationsRegistry;
        parent::__construct();
    }
    
    protected function configure()
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Consumer Name')
            ->addOption('messages', 'm', InputOption::VALUE_OPTIONAL, 'Messages to consume', 0)
            ->addOption('memory-limit', 'l', InputOption::VALUE_OPTIONAL, 'Allowed memory for this process (MB)', null)
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable Debugging')
            // TODO ->addOption('declare', null, InputOption::VALUE_OPTIONAL, 'Ignore declarate', null)
            // TODO ->addOption('ignoreDeclare', null, InputOption::VALUE_OPTIONAL, 'Ignore declarate', null)
            ->addOption('without-signals', 'w', InputOption::VALUE_NONE, 'Disable catching of system signals')
        ;
        $this->setDescription('Executes a consumer');
        $this->setName('rabbitmq:consumer');
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return integer 0 if everything went fine, or an error code
     *
     * @throws \InvalidArgumentException When the number of messages to consume is less than 0
     * @throws \BadFunctionCallException When the pcntl is not installed and option -s is true
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        iterator_to_array($this->consumers);

        /** @var Consumer $consumer */
        $consumer = null;
        foreach ($this->consumers as $c) {
            if ($input->getArgument('name') === $c->name) {
                $consumer = $c;
            }
        }
        
        if (!$consumer) {
            $existNames = array_map(function ($c) {
                return $c->getName();
            }, iterator_to_array($this->consumers));

            throw new InvalidArgumentException(sprintf('Allow consumers %s', join(', ', $existNames)));
        }
        
        if (
            !is_null($input->getOption('memory-limit')) &&
            ctype_digit((string) $input->getOption('memory-limit')) &&
            $input->getOption('memory-limit') > 0
        ) {
            $consumer->getEventDispatcher()->addListener(
                AfterProcessingMessageEvent::NAME,
                new MemoryLimitListener($input->getOption('memory-limit'))
            );
        }
        
        if (defined('AMQP_WITHOUT_SIGNALS') === false) {
            define('AMQP_WITHOUT_SIGNALS', $input->getOption('without-signals'));
        }

        if (!AMQP_WITHOUT_SIGNALS && extension_loaded('pcntl')) {
            if (!function_exists('pcntl_signal')) {
                throw new \BadFunctionCallException("Function 'pcntl_signal' is referenced in the php.ini 'disable_functions' and can't be called.");
            }
            $this->initPcntlSignals($consumer);
        }

        if (defined('AMQP_DEBUG') === false) {
            define('AMQP_DEBUG', (bool) $input->getOption('debug'));
        }

        $this->amount = $input->getOption('messages');

        if (0 > (int) $this->amount) {
            throw new \InvalidArgumentException("The -m option should be null or greater than 0");
        }
        
        $declare = true; // TODO

        if ($declare) {
            $this->declareForConsumer($consumer);
        }

        return $consumer->consume($this->amount);
    }

    private function declareForConsumer(Consumer $consumer)
    {
        $declarator = new Declarator($consumer->getChannel());

        $consumingQueueNames = array_map(function ($queueConsuming) {
            return $queueConsuming->queueName;
        }, $consumer->getQueueConsumings());

        $consumerQueues = array_filter($this->declarationsRegistry->queues, function ($queue) use ($consumingQueueNames) {
            return in_array($queue->name, $consumingQueueNames, true);
        });
        foreach ($consumerQueues as $queue) {
            $declarator->declareForQueue($queue);
        }
    }
    
    private function initPcntlSignals(Consumer $consumer)
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            throw new \BadFunctionCallException("Function 'pcntl_signal_dispatch' is referenced in the php.ini 'disable_functions' and can't be called.");
        }

        $consumer->enablePcntlSignals();

        $stopConsumer = function () use ($consumer) {
            if ($consumer instanceof Consumer) {
                return;
            }
            // Process current message, then halt consumer
            $consumer->forceStopConsumer();

            // Halt consumer if waiting for a new message from the queue
            try {
                $consumer->stopConsuming();
            } catch (AMQPTimeoutException $e) {}
        };
        $restartConsumer = function () use($consumer) {
            if ($consumer instanceof Consumer) {
                return;
            }
            // TODO $consumer->restart();
        };

        pcntl_signal(SIGTERM, $stopConsumer);
        pcntl_signal(SIGINT, $stopConsumer);
        pcntl_signal(SIGHUP, $restartConsumer);
    }
}
