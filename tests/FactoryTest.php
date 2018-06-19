<?php

namespace React\Tests\MySQL;

use React\MySQL\ConnectionInterface;
use React\MySQL\Factory;
use React\Socket\Server;

class FactoryTest extends BaseTestCase
{
    public function testConnectWillUseHostAndDefaultPort()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $pending = $this->getMockBuilder('React\Promise\PromiseInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('127.0.0.1:3306')->willReturn($pending);

        $factory = new Factory($loop, $connector);
        $factory->createConnection('127.0.0.1');
    }

    public function testConnectWillUseGivenHostAndGivenPort()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $pending = $this->getMockBuilder('React\Promise\PromiseInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('127.0.0.1:1234')->willReturn($pending);

        $factory = new Factory($loop, $connector);
        $factory->createConnection('127.0.0.1:1234');
    }

    public function testConnectWithInvalidUriWillRejectWithoutConnecting()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->never())->method('connect');

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('///');

        $this->assertInstanceof('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWithInvalidHostRejectsWithConnectionError()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString(array('host' => 'example.invalid'));
        $promise = $factory->createConnection($uri);

        $promise->then(null, $this->expectCallableOnce());

        $loop->run();
    }

    public function testConnectWithInvalidPassRejectsWithAuthenticationError()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString(array('passwd' => 'invalidpass'));
        $promise = $factory->createConnection($uri);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('Exception'),
                $this->callback(function (\Exception $e) {
                    return !!preg_match("/^Access denied for user '.*?'@'.*?' \(using password: YES\)$/", $e->getMessage());
                })
            )
        ));

        $loop->run();
    }

    public function testConnectWillRejectWhenServerClosesConnection()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $server = new Server(0, $loop);
        $server->on('connection', function ($connection) use ($server) {
            $server->close();
            $connection->close();
        });

        $parts = parse_url($server->getAddress());
        $uri = $this->getConnectionString(array('host' => $parts['host'], 'port' => $parts['port']));

        $promise = $factory->createConnection($uri);
        $promise->then(null, $this->expectCallableOnce());

        $loop->run();
    }

    public function testConnectWithValidAuthWillRunUtilClose()
    {
        $this->expectOutputString('connected.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->close(function ($e) {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        $loop->run();
    }

    public function testConnectWithValidAuthCanPingAndClose()
    {
        $this->expectOutputString('connected.ping.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->ping()->then(function () {
                echo 'ping.';
            });
            $connection->close(function ($e) {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        $loop->run();
    }
}
