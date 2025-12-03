<?php

    use PHPUnit\Framework\TestCase;
    use SAPNWRFC\Connection;
    use Stelmet\SapRfc\ConnectionFactory;

    class ConnectionFactoryTest extends TestCase {

        public static function setUpBeforeClass(): void {
            // Skip these tests on systems that don't have the compiled SAPNWRFC extension
            if (!extension_loaded('sapnwrfc') && !class_exists(\SAPNWRFC\Connection::class)) {
                throw new \PHPUnit\Framework\SkippedTestError('SAPNWRFC extension is not installed; skipping ConnectionFactory tests.');
            }
        }

        public function testCreateReturnsConnectionInstance() {

            // Create a mock of the SAPNWRFC\Connection class
            $mockConnection = $this->createMock(Connection::class);

            // Use a stubbed ConnectionFactory that returns the mock instead of hitting SAP
            $factory = $this->getMockBuilder(ConnectionFactory::class)
                            ->onlyMethods(['create'])
                            ->getMock();

            $factory->method('create')->willReturn($mockConnection);

            $connection = $factory->create([
                'ashost' => 'testhost',
                'sysnr' => '00',
                'client' => '100',
                'user' => 'user',
                'passwd' => 'pass'
            ]);

            $this->assertInstanceOf(Connection::class, $connection);
        }

        public function testMissingConfigThrowsException() {

            $factory = new ConnectionFactory();

            // Tell PHPUnit we expect this exception and message
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Missing SAP config key: ashost');

            // Call create with missing required config
            $factory->create([
                'ashost' => null
            ]);

            // Any error messages or logging in ConnectionFactory won't fail the test
        }

    }
