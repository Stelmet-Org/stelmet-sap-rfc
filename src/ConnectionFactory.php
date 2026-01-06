<?php

    namespace Stelmet\SapRfc;

    use InvalidArgumentException;
    use Psr\Log\LoggerInterface;
    use RuntimeException;
    use SAPNWRFC\Connection;
    use SAPNWRFC\ConnectionException;
    use Throwable;

    class ConnectionFactory {

        private ?LoggerInterface $logger;

        /**
         * Keys required for a connection. Keep lowercase for normalization.
         * @var string[]
         */
        private array $requiredKeys = ["ashost", "sysnr", "client", "user", "passwd"];

        public function __construct(?LoggerInterface $logger = null) {

            $this->logger = $logger;
        }

        /**
         * Create a factory pre-configured to read from environment.
         */
        public static function fromEnv(?LoggerInterface $logger = null): self {

            return new self($logger);
        }

        /**
         * Chainable setter for a logger (useful in tests / builders).
         */
        public function withLogger(LoggerInterface $logger): self {

            $this->logger = $logger;

            return $this;
        }

        /**
         * Read connection defaults from available environment sources.
         * Supports: $_ENV, getenv(), and $_SERVER.
         *
         * @return array<string,mixed>
         */
        protected function loadEnvConfig(): array {

            $map = [
                "ashost" => "SAP_RFC_ASHOST",
                "sysnr"  => "SAP_RFC_SYSNR",
                "client" => "SAP_RFC_CLIENT",
                "user"   => "SAP_RFC_USER",
                "passwd" => "SAP_RFC_PASSWD",
            ];

            $out = [];
            foreach ($map as $key => $envName) {
                $value = null;

                if (array_key_exists($envName, $_ENV) && $_ENV[$envName] !== null) {
                    $value = $_ENV[$envName];
                } elseif (getenv($envName) !== false) {
                    $value = getenv($envName);
                } elseif (array_key_exists($envName, $_SERVER) && $_SERVER[$envName] !== null) {
                    $value = $_SERVER[$envName];
                }

                $out[$key] = $value;
            }

            return $out;
        }


        /**
         * Create and return a new SAP RFC Connection instance.
         *
         * Accepts an overrides array; keys are normalized to lowercase.
         *
         * @param array $overrides Optional connection parameters to override environment variables
         *
         * @return Connection The SAP RFC Connection instance
         * @throws InvalidArgumentException If required connection parameters are missing
         * @throws RuntimeException If the connection fails
         */
        public function create(array $overrides = []): Connection {

            // Normalize override keys to lowercase strings
            $normalizedOverrides = [];
            foreach ($overrides as $k => $v) {
                $normalizedOverrides[strtolower((string)$k)] = $v;
            }

            $defaults = $this->loadEnvConfig();

            $client = array_merge($defaults, $normalizedOverrides);

            // Validate required keys and collect missing ones for clearer errors
            $missing = [];
            foreach ($this->requiredKeys as $key) {
                if (empty($client[$key])) {
                    $missing[] = $key;
                }
            }

            if ($missing) {
                $msg = "Missing SAP config keys: " . implode(", ", $missing);
                $this->logger?->error($msg);
                throw new InvalidArgumentException($msg);
            }

            // Mask sensitive fields for logs
            $loggedClient = $client;
            if (array_key_exists("passwd", $loggedClient)) {
                $loggedClient["passwd"] = "****";
            }

            $this->logger?->info("Connecting to SAP system at {$client['ashost']}", $loggedClient);

            try {

                return new Connection($client);

            } catch (ConnectionException $e) {

                // ConnectionException provides getErrorInfo() in this runtime.
                $errorInfo = [];
                if (method_exists($e, "getErrorInfo")) {
                    $errorInfo = $e->getErrorInfo();
                }

                $key = $errorInfo["key"] ?? "UNKNOWN";
                $code = $errorInfo["code"] ?? 0;
                $message = trim((string)($errorInfo["message"] ?? $e->getMessage()));

                $logMsg = "SAP connection failed: {$key} ({$code}) - {$message}";

                $this->logger?->error($logMsg, ["exception" => $e]);

                throw new RuntimeException($logMsg, 0, $e);
            } catch (Throwable $e) {
                // Be defensive: wrap any other exception type
                $this->logger?->error("Unexpected error while connecting to SAP", ["exception" => $e]);
                throw new RuntimeException("Unexpected error while connecting to SAP: " . $e->getMessage(), 0, $e);
            }

        }

    }