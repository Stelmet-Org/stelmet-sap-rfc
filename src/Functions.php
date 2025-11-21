<?php

    namespace Stelmet\SapRfc;

    use SAPNWRFC\Connection;
    use SAPNWRFC\FunctionCallException;

    class Functions {

        /**
         * Invoke an SAP RFC function with structured inputs and parse tables automatically
         *
         * @param Connection $connection
         *
         * @param string $functionName
         * @param array $parameters Input parameters (key => value)
         *
         * @return array Parsed RFC function result, tables as structured arrays
         */
        public static function call(
            Connection $connection,
            string $functionName,
            array $parameters = [],
            bool $throwOnError = true,
            bool $debug = false,
            string $dateFormat = "Ymd"
        ): array {

            $function = $connection->getFunction($functionName);

            try {

                $result = $function->invoke($parameters);
                $meta = $function->getFunctionDescription();

            } catch (FunctionCallException $e) {

                if (!$throwOnError) {
                    return [];
                }

                $errorInfo = $e->getErrorInfo();
                $key = $errorInfo['key'] ?? 'UNKNOWN';
                $code = $errorInfo['code'] ?? 0;
                $msg = trim($errorInfo['message'] ?? '');
                $abapMsg = '';

                // If ABAP messages exist, include them
                if (!empty($errorInfo['abapMsgClass'])) {
                    $abapMsg = sprintf(
                        "ABAP Msg: %s %s %s %s %s %s",
                        $errorInfo['abapMsgType'] ?? '',
                        $errorInfo['abapMsgClass'] ?? '',
                        $errorInfo['abapMsgNumber'] ?? '',
                        $errorInfo['abapMsgV1'] ?? '',
                        $errorInfo['abapMsgV2'] ?? '',
                        $errorInfo['abapMsgV3'] ?? ''
                    );
                }

                if ($debug) {
                    fwrite(STDERR, "[ERROR] SAP function call failed: $functionName\n");
                    fwrite(STDERR, "Error Key:     $key\n");
                    fwrite(STDERR, "Error Code:    $code\n");
                    fwrite(STDERR, "Error Message: $msg\n");
                    if ($abapMsg) {
                        fwrite(STDERR, "$abapMsg\n");
                    }
                    fwrite(STDERR, "Timestamp: " . date('Y-m-d H:i:s') . "\n\n");
                }

                throw new \RuntimeException("SAP function call failed: $functionName ($key)", 0, $e);

            }

            if (isset($parameters["I_TXTONLY"]) && $parameters["I_TXTONLY"] === 'X') {
                return self::parseTextOnlyData($result["RT_TEXTTAB"]);
            }

            $result = $result["ET_RESULT"] ?? $result["RT_RESULT"] ?? [];
            $meta = $meta["ET_RESULT"] ?? $meta["RT_RESULT"] ?? [];

            if (!isset($meta["typedef"])) {
                print("[WARN] No typedef metadata for RFC function table result: $functionName\n");
                return [];
            }

            foreach ($result as &$row) {

                $row = self::parseRow($row, $meta["typedef"], dateFormat: $dateFormat);

            }

            return $result;

        }

        /**
         * Parse an RFC row using typedef metadata
         */
        private static function parseRow(array $rowData, array $meta, string $dateFormat = "Ymd"): array {

            $result = [];

            foreach ($rowData as $fieldName => $fieldValue) {

                $typeData = $meta[$fieldName] ?? null;

                if ($typeData == null) {
                    $result[$fieldName] = trim($fieldValue);
                    continue;
                }

                $result[$fieldName] = DataUtils::castRFCValue($fieldValue, $typeData, $dateFormat);

            }

            return $result;

        }

        /**
         * Get metadata for an RFC function (parameters, tables)
         */
        public static function getFunctionMeta($connection, string $functionName): array {

            $function = $connection->getFunction($functionName);

            return $function->getFunctionDescription();
        }

        /**
         * Parse text-only RFC function result into structured array
         */
        private static function parseTextOnlyData(array $result): array {

            if (count($result) < 2) {
                return [];
            }

            $lines = [];
            $columnMap = [];
            $headerLine = $result[1]["LINE"];

            $currentPos = 0;

            while ($currentPos < strlen($headerLine)) {

                if ($headerLine[$currentPos] === '|') {

                    $nextPos = strpos($headerLine, '|', $currentPos + 1);
                    if ($nextPos === false) {
                        break;
                    }

                    $columnName = trim(substr($headerLine, $currentPos + 1, $nextPos - $currentPos - 1));
                    $columnMap[] = ['name' => $columnName, 'start' => $currentPos + 1, 'length' => $nextPos - $currentPos - 1];
                    $currentPos = $nextPos;

                } else {

                    $currentPos++;

                }

            }

            for ($i = 0; $i < count($result); $i++) {

                if ($result[$i]["LINE"] === $headerLine) {
                    continue;
                }

                if (trim($result[$i]["LINE"], " -") === "") {
                    continue;
                }

                $lineData = $result[$i]["LINE"];
                $lineEntry = [];

                foreach ($columnMap as $col) {
                    $lineEntry[$col['name']] = trim(substr($lineData, $col['start'], $col['length']));
                }

                $lines[] = $lineEntry;

            }

            return $lines;

        }

    }