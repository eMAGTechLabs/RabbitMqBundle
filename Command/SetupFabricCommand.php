<?php

namespace OldSound\RabbitMqBundle\Command;

use OldSound\RabbitMqBundle\Declarations\DeclarationsRegistry;
use OldSound\RabbitMqBundle\Declarations\Declarator;
use OldSound\RabbitMqBundle\RabbitMq\DynamicConsumer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class SetupFabricCommand extends Command
{
    use ContainerAwareTrait;

    public function __construct(
        DeclarationsRegistry $declarationsRegistry
    ) {
        $this->declarationsRegistry = $declarationsRegistry;
    }

    protected function configure()
    {
        $this
            ->setName('rabbitmq:declare')
            ->addOption('connection', 'c', InputOption::VALUE_OPTIONAL, 'Rabbitmq connection name', 'default')
            ->setDescription('Sets up the Rabbit MQ fabric')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable Debugging')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (defined('AMQP_DEBUG') === false) {
            define('AMQP_DEBUG', (bool) $input->getOption('debug'));
        }

        $channelAlias = sprintf('old_sound_rabbit_mq.channel.%s', $input->getOption('connection'));


        // TODO $this->container->has($channelAlias)

        $channel = $this->container->get($channelAlias);

        // TODO $output->writeln('Setting up the Rabbit MQ fabric');

        $producers = [];
        $consumers = [];
        $exchanges = [];
        foreach ($producers as $producer) {
            // TODO $exchanges[] = $producer->exchange;
        }

        foreach ($consumers as $consumer) {
            // TODO $exchanges[] = $producer->exchange;
            //$bindings[] = $producer->exchange;
            //$queues[] = $producer->exchange;
        }

        $declarator = new Declarator($channel);
        // $declarator->declareExchanges($exchanges);
        foreach ($exchanges as $exchange) {
            $declarator->declareForExchange($exchange);
        }

        return 0;

    }
}
