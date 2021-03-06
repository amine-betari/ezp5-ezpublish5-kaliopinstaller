<?php

namespace Kaliop\eZP5UI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Kaliop\eZP5UI\Common\MemcacheHandler;

class PurgeMemcache extends Command
{
    protected function configure()
    {
        $this
            ->setName('memcache:purge')
            ->setDescription('Purges Memcache sending a curl request')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The yaml config key storing the address of the server(s)', 'stash.caches.default.Memcache.servers')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'The config file storing the address of the server(s)', 'ezpublish/config/ezpublish_{ENV}.yml')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (null == ($env = $this->getApplication()->getEnv())) {
            throw new \Exception('Can purge memcache: unknown environment!');
        }

        $handler = new MemcacheHandler($output);
        $handler->purge($env, $input->getOption('key'), $input->getOption('file'));
    }
}
