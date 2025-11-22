<?php

    namespace Stelmet\SapRfc;

    use InvalidArgumentException;
    use Psr\Log\LoggerInterface;
    use RuntimeException;
    use SAPNWRFC\Connection;
    use SAPNWRFC\ConnectionException;

    class ConnectionFactory {

        private ?LoggerInterface $logger;

        public function __construct(?LoggerInterface $logger = null) {

            $this->logger = $logger;
        }


        /**
         * Create and return a new SAP RFC Connection instance.
         *
         * @param array $overrides Optional connection parameters to override environment variables
         *
         * @return Connection The SAP RFC Connection instance
         * @throws InvalidArgumentException If required connection parameters are missing
         * @throws RuntimeException If the connection fails
         */
        public function create(array $overrides = []): Connection {

            $client = array_merge(
                  [
                      "ashost" => $_ENV["SAP_RFC_ASHOST"] ?? null,
                      "sysnr"  => $_ENV["SAP_RFC_SYSNR"] ?? null,
                      "client" => $_ENV["SAP_RFC_CLIENT"] ?? null,
                      "user"   => $_ENV["SAP_RFC_USER"] ?? null,
                      "passwd" => $_ENV["SAP_RFC_PASSWD"] ?? null,
                  ]
                , $overrides);

            foreach (["ashost", "sysnr", "client", "user", "passwd"] as $key) {
                if (empty($client[$key])) {
                    $this->logger?->error("Missing SAP config key: $key");
                    throw new InvalidArgumentException("Missing SAP config key: $key");
                }
            }

            $this->logger?->info("Connecting to SAP system at {$client["ashost"]}");

            try {

                return new Connection($client);

            } catch (ConnectionException $e) {

                $errorInfo = $e->getErrorInfo();

                $this->logger?->error(
                    "SAP connection failed: {$errorInfo["key"]} ({$errorInfo["code"]}) - " . trim($errorInfo["message"]),
                );

                throw new RuntimeException(
                    "SAP connection failed: {$errorInfo["key"]} ({$errorInfo["code"]}) - " . trim($errorInfo["message"]),
                    0,
                    $e,
                );
            }

        }

    }