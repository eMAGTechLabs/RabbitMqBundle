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
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class ConsumerCommand extends Command
{
    use ContainerAwareTrait;
    /** @var iterable|Consumer[] */
    protected $consumers;
    
    protected function configure()
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Consumer Name')
            ->addOption('messages', 'm', InputOption::VALUE_OPTIONAL, 'Messages to consume', 0)
            ->addOption('memory-limit', 'l', InputOption::VALUE_OPTIONAL, 'Allowed memory for this process (MB)', null)
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable Debugging')
            ->addOption('skip-declare', null, InputOption::VALUE_NONE, 'Skip declare exhanges, queues and bindings')
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
        $consumerName = $input->getArgument('name');
        $alias = sprintf('old_sound_rabbit_mq.consumer.%s', $consumerName);
        if (!$this->container->has($alias)) {
            $containerNames = $this->container->getParameter('old_sound_rabbit_mq.allowed_consumer_names');
            throw new InvalidArgumentException(sprintf('Consumer %s is undefined. Allowed ones: %s', $consumerName, join(', ', $containerNames)));
        }

        /** @var Consumer $consumer */
        $consumer = $this->container->get($alias);
        
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

        if (!$input->getOption('skip-declare')) {
            $this->declareForConsumer($consumer, $output);
        }

        return $consumer->consume($this->amount);
    }

    private function declareForConsumer(Consumer $consumer, OutputInterface $output)
    {
        $declarator = new Declarator($consumer->getChannel());
        $declarator->setLogger(
            new ConsoleLogger($output)
        );
        $declarationRegistry = $this->container->get('old_sound_rabbit_mq.declaration_registry');
        foreach($consumer->getQueueConsumings() as $queueConsuming) {
            $declarator->declareForQueueDeclaration($queueConsuming->queueName, $declarationRegistry);
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
