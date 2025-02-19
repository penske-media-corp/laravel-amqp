<?php

namespace Bschmitt\Amqp;

use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;

/**
 * @author Björn Schmitt <code@bjoern.io>
 */
class Request extends Context
{

    /**
     * @var AMQPStreamConnection
     */
    protected $connection;

    /**
     * @var AMQPChannel
     */
    protected $channel;

    /**
     * @var array
     */
    protected $queueInfo;

    /**
     *
     */
    public function connect()
    {
        $config = new AMQPConnectionConfig();

        $config->setHost($this->getProperty('host'));
        $config->setPort($this->getProperty('port'));
        $config->setUser($this->getProperty('username'));
        $config->setPassword($this->getProperty('password'));
        $config->setVhost($this->getProperty('vhost'));

        $config->setIoType($this->getConnectOption('io_type', AMQPConnectionConfig::IO_TYPE_STREAM));
        $config->setInsist($this->getConnectOption('insist', false));
        $config->setLoginMethod($this->getConnectOption('login_method', AMQPConnectionConfig::AUTH_AMQPPLAIN));
        $config->setLoginResponse($this->getConnectOption('login_response', null));
        $config->setLocale($this->getConnectOption('locale', 'en_US'));
        $config->setConnectionTimeout($this->getConnectOption('connection_timeout', 3.0));
        $config->setReadTimeout($this->getConnectOption('read_timeout', 3.0));
        $config->setWriteTimeout($this->getConnectOption('write_timeout', 3.0));
        $config->setKeepalive($this->getConnectOption('keepalive', false));
        $config->setHeartbeat($this->getConnectOption('heartbeat', 0));

        $config->setStreamContext($this->getConnectOption('context', null));
        $config->setIsSecure($this->getConnectOption('ssl', true));

        $this->connection = AMQPConnectionFactory::create($config);

        $this->channel = $this->connection->channel();
    }

    /**
     * @throws Exception\Configuration
     */
    public function setup()
    {
        $this->connect();

        $exchange = $this->getProperty('exchange');

        if (empty($exchange)) {
            throw new Exception\Configuration('Please check your settings, exchange is not defined.');
        }

        /*
            name: $exchange
            type: topic
            passive: false
            durable: true // the exchange will survive server restarts
            auto_delete: false //the exchange won't be deleted once the channel is closed.
        */
        $this->channel->exchange_declare(
            $exchange,
            $this->getProperty('exchange_type'),
            $this->getProperty('exchange_passive'),
            $this->getProperty('exchange_durable'),
            $this->getProperty('exchange_auto_delete'),
            $this->getProperty('exchange_internal'),
            $this->getProperty('exchange_nowait'),
            $this->getProperty('exchange_properties')
        );

        $queue = $this->getProperty('queue');

        if (!empty($queue) || $this->getProperty('queue_force_declare')) {
            /*
                name: $queue
                passive: false
                durable: true // the queue will survive server restarts
                exclusive: false // queue is deleted when connection closes
                auto_delete: false //the queue won't be deleted once the channel is closed.
                nowait: false // Doesn't wait on replies for certain things.
                parameters: array // Extra data, like high availability params
            */

            /** @var ['queue name', 'message count',] queueInfo */
            $this->queueInfo = $this->channel->queue_declare(
                $queue,
                $this->getProperty('queue_passive'),
                $this->getProperty('queue_durable'),
                $this->getProperty('queue_exclusive'),
                $this->getProperty('queue_auto_delete'),
                $this->getProperty('queue_nowait'),
                $this->getProperty('queue_properties')
            );

            foreach ((array) $this->getProperty('routing') as $routingKey) {
                $this->channel->queue_bind(
                    $queue ?: $this->queueInfo[0],
                    $exchange,
                    $routingKey
                );
            }
        }
        // clear at shutdown
        $this->connection->set_close_on_destruct(true);
    }

    /**
     * @return AMQPChannel
     */
    public function getChannel() : AMQPChannel
    {
        return $this->channel;
    }

    /**
     * @return AMQPStreamConnection
     */
    public function getConnection() : AMQPStreamConnection
    {
        return $this->connection;
    }

    /**
     * @return int
     */
    public function getQueueMessageCount() : int
    {
        if (is_array($this->queueInfo)) {
            return $this->queueInfo[1];
        }
        return 0;
    }

    /**
     * @param AMQPChannel $channel
     * @param AMQPStreamConnection $connection
     *
     * @throws \Exception
     */
    public static function shutdown(AMQPChannel $channel, AMQPStreamConnection $connection)
    {
        $channel->close();
        $connection->close();
    }
}
