<?php

    namespace Stelmet\SapRfc;

    use RuntimeException;
    use SAPNWRFC\Connection;
    use SAPNWRFC\FunctionCallException;

    class Functions {

        /**
         * Helper utilities for invoking SAP RFC functions and parsing results.
         *
         * This class centralises common RFC call patterns used by the project:
         * - invoking RFC functions via a SAPNWRFC\Connection,
         * - optionally dumping raw results for debugging,
         * - converting RFC table rows to PHP types based on typedef metadata,
         * - special parsing for text-only (fixed-width) table output.
         */

        /**
         * Invoke an SAP RFC function with structured inputs and parse tables automatically.
         *
         * The method will call the specified RFC function using the provided
         * SAPNWRFC connection, optionally write raw result and metadata JSON
         * files, and convert any table rows using typedef metadata returned by
         * the function description.
         *
         * If the function returns a "text-only" result (indicated by
         * I_TXTONLY = 'X') this method will forward the output to
         * parseTextOnlyData() and return a structured array of rows.
         *
         * Example usage with a custom caster:
         *
         * $custom = [
         *     'MY_COLUMN' => function($raw, $type = null, $dateFormat = null, $castEmptyDecimals = null) {
         *         // Return a transformed value; for example convert empty strings to null
         *         $v = trim($raw);
         *         return $v === '' ? null : $v;
         *     }
         * ];
         * $rows = Functions::call($conn, 'Z_MY_RFC', ['I_TXTONLY' => 'X'], true, false, 'Ymd', null, true, $custom);
         *
         * @param Connection $connection Active SAPNWRFC connection object.
         * @param string $functionName The RFC function name to invoke (e.g. 'Z_MY_RFC').
         * @param array $parameters RFC input parameters (associative array of name => value).
         * @param bool $throwOnError If true (default) rethrows exceptions as RuntimeException;
         *                           if false it will return an empty array on error.
         * @param bool $debug Enable debug output to STDERR when an RFC call fails.
         * @param string $dateFormat The date format used by DataUtils::castRFCValue (default: 'Ymd').
         * @param string|null $rawDataToDir Optional directory path where raw JSON of result and
         *                                   metadata will be written. Directory must exist.
         * @param bool $castEmptyDecimalsToNull When true, empty numeric/decimal fields are cast to null
         *                                       by DataUtils::castRFCValue; when false they remain as empty strings.
         * @param array|null $customCastMap Optional map for text-only parsing where keys are column names
         *                                  and values are callables that receive the raw string and
         *                                  return a cast value. Callables will be invoked with the
         *                                  signature: ($rawValue)
         * @param string|array $resultKey The key in the RFC result array that contains the main table data, or an array of keys if multiple tables are expected. Default is "RT_RESULT".
         *
         * @return array Parsed result: for normal table-based RFCs an array of row arrays; for
         *               text-only results an array of parsed rows as determined by parseTextOnlyData().
         *
         * @throws RuntimeException When the underlying RFC call fails and $throwOnError is true.
         */
        public static function call(
            Connection   $connection,
            string       $functionName,
            array        $parameters = [],
            bool         $throwOnError = true,
            bool         $debug = false,
            string       $dateFormat = "Ymd",
            ?string      $rawDataToDir = null,
            bool         $castEmptyDecimalsToNull = true,
            ?array       $customCastMap = null,
            string|array $resultKey = "RT_RESULT",
        ): array {

            $function = $connection->getFunction($functionName);

            try {

                $result = $function->invoke($parameters);
                $meta = $function->getFunctionDescription();

                if (is_string($rawDataToDir) && is_dir($rawDataToDir)) {

                    if (!str_ends_with($rawDataToDir, DIRECTORY_SEPARATOR)) {
                        $rawDataToDir .= DIRECTORY_SEPARATOR;
                    }
                    DataUtils::dumpToJson($result, "{$rawDataToDir}{$functionName}_result.json");
                    DataUtils::dumpToJson($meta, "{$rawDataToDir}{$functionName}_meta.json");

                }

            } catch (FunctionCallException $e) {

                if (!$throwOnError) {
                    return [];
                }

                $errorInfo = $e->getErrorInfo();
                $key = $errorInfo["key"] ?? "UNKNOWN";
                $code = $errorInfo["code"] ?? 0;
                $msg = trim($errorInfo["message"] ?? "");
                $abapMsg = "";

                // If ABAP messages exist, include them
                if (!empty($errorInfo["abapMsgClass"])) {
                    $abapMsg = sprintf(
                        "ABAP Msg: %s %s %s %s %s %s",
                        $errorInfo["abapMsgType"] ?? "",
                        $errorInfo["abapMsgClass"] ?? "",
                        $errorInfo["abapMsgNumber"] ?? "",
                        $errorInfo["abapMsgV1"] ?? "",
                        $errorInfo["abapMsgV2"] ?? "",
                        $errorInfo["abapMsgV3"] ?? "",
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
                    fwrite(STDERR, "Timestamp: " . date("Y-m-d H:i:s") . "\n\n");
                }

                throw new RuntimeException("SAP function call failed: $functionName ($key)", 0, $e);

            }

            if (isset($parameters["I_TXTONLY"]) && $parameters["I_TXTONLY"] === "X") {
                return self::parseTextOnlyData($result["RT_TEXTTAB"], $customCastMap);
            }

            if (is_array($resultKey)) {

                $output = [];

                foreach ($resultKey as $rk) {

                    $output[$rk] = $result[$rk] ?? [];
                    $metaMeta = $meta[$rk] ?? [];

                    if (!is_array($output[$rk])) {
                        $output[$rk] = DataUtils::castRFCValue($output[$rk], $metaMeta, $dateFormat, $castEmptyDecimalsToNull);
                        continue;
                    }

                    foreach ($output[$rk] as &$row) {

                        if (!is_array($row)) {
                            continue;
                        }

                        $row = self::parseRow($row, $metaMeta["typedef"] ?? [], $dateFormat, $castEmptyDecimalsToNull, $customCastMap);

                    }

                }

                return $output;

            }

            $result = $result[$resultKey] ?? [];
            $meta = $meta[$resultKey] ?? [];

            if (!isset($meta["typedef"])) {
                print("[WARN] No typedef metadata for RFC function table result: $functionName\n");

                return $result;
            }

            foreach ($result as &$row) {

                $row = self::parseRow($row, $meta["typedef"], $dateFormat, $castEmptyDecimalsToNull, $customCastMap);

            }

            return $result;

        }

        /**
         * Parse an RFC row using typedef metadata.
         *
         * Converts each field in the provided row using DataUtils::castRFCValue
         * and the associated typedef metadata. Unknown fields (no metadata)
         * are trimmed and preserved as strings.
         *
         * @param array $rowData Associative array of field-name => raw value as returned by the RFC.
         * @param array $meta Typedef metadata array for the row (field-name => typedef info).
         * @param string $dateFormat Date format passed down to DataUtils::castRFCValue.
         * @param bool $castEmptyDecimalsToNull See call() documentation; passed to DataUtils::castRFCValue.
         * @param array|null $customCastMap Optional map for text-only parsing where keys are column names
         *                                  and values are callables that will receive the raw field value
         *                                  and may return a custom cast value.
         *
         * @return array Parsed associative row with proper PHP types (strings, ints, floats, null, DateTime strings, etc.).
         */
        private static function parseRow(array $rowData, array $meta, string $dateFormat, bool $castEmptyDecimalsToNull, ?array $customCastMap = null): array {

            $result = [];

            foreach ($rowData as $fieldName => $fieldValue) {

                /*
                 * If a custom caster exists for this field, use it. The callable is given the raw field value
                 */
                if ($customCastMap && isset($customCastMap[$fieldName]) && is_callable($customCastMap[$fieldName])) {
                    $result[$fieldName] = $customCastMap[$fieldName]($fieldValue);
                    continue;
                }

                $typeData = $meta[$fieldName] ?? null;

                if ($typeData == null) {
                    $result[$fieldName] = trim($fieldValue);
                    continue;
                }

                $result[$fieldName] = DataUtils::castRFCValue($fieldValue, $typeData, $dateFormat, $castEmptyDecimalsToNull);

            }

            return $result;

        }

        /**
         * Get metadata for an RFC function (parameters, tables).
         *
         * Convenience wrapper around the underlying SAPNWRFC Function object.
         *
         * @param Connection $connection Active SAPNWRFC connection instance.
         * @param string $functionName The RFC function name to inspect.
         *
         * @return array Function description metadata as returned by getFunctionDescription().
         */
        public static function getFunctionMeta(Connection $connection, string $functionName): array {

            $function = $connection->getFunction($functionName);

            return $function->getFunctionDescription();
        }

        /**
         * Parse text-only RFC function result into a structured array.
         *
         * Some RFCs return a single table of lines where the second row contains
         * a header line that defines fixed-width columns. This helper will
         * convert that format into an array of associative rows by slicing each
         * data line according to the header positions.
         *
         * Expected input structure: an array of rows where each row is an array
         * containing a 'LINE' key with the raw line string. The header is taken
         * from the second entry (index 1) of $result.
         *
         * @param array $result Raw RFC table result where each entry has a 'LINE' key.
         * @param array|null $customCastMap Optional associative map of columnName => callable
         *                                  used to transform each extracted string value.
         *
         * @return array Array of associative rows (columnName => value). Returns an empty array
         *               if the input doesn't contain enough lines to parse a header and data.
         */
        private static function parseTextOnlyData(array $result, ?array $customCastMap = null): array {

            if (count($result) < 2) {
                return [];
            }

            /**
             * If only 5 lines and the number of | in the header row does not match the number of columns
             * in the 4th row. This is due to SAP returning no data rows, only header and footer.
             * In this case, return an empty array.
             */
            if (count($result) === 5) {
                $headerLine = $result[1]["LINE"];
                $dataLine = $result[3]["LINE"];
                $numHeaderCols = substr_count($headerLine, "|");
                $numDataCols = substr_count($dataLine, " ") + 1; // Approximate column count by spaces

                if ($numHeaderCols !== $numDataCols) {
                    return [];
                }
            }

            $headerLine = $result[1]["LINE"];
            $columnMap = self::parseTextOnlyHeader($headerLine);

            $lines = [];

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

                    $value = trim(mb_substr($lineData, $col["start"], $col["length"]));

                    if ($customCastMap && isset($customCastMap[$col["name"]]) && is_callable($customCastMap[$col["name"]])) {
                        $value = $customCastMap[$col["name"]]($value);
                    }

                    $lineEntry[$col["name"]] = $value;

                }

                $lines[] = $lineEntry;

            }

            return $lines;

        }

        /**
         * Build a column map for a fixed-width header line.
         *
         * Parses a header line where column names are separated by pipe characters ("|")
         * but the underlying output is fixed-width (each column has a start and length).
         * This function returns an ordered list of column descriptor arrays:
         *  [ ['name' => string, 'start' => int, 'length' => int], ... ]
         *
         * @param string $headerLine The header line containing pipe-separated column names.
         *
         * @return array Ordered list of column descriptors with keys: name, start, length.
         */
        private static function parseTextOnlyHeader(string $headerLine): array {

            $columnMap = [];
            $seenNames = [];

            $columnNames = explode("|", $headerLine);

            foreach ($columnNames as $colName) {

                if (trim($colName) === "") {
                    continue;
                }

                $pos = mb_strpos($headerLine, $colName);
                if ($pos === false) {
                    continue;
                }

                $colLength = mb_strlen($colName);
                $baseName = trim($colName);
                $colName = trim($colName);
                $suffix = 1;

                while (in_array($colName, $seenNames, true)) {
                    $colName = $baseName . "_" . $suffix;
                    $suffix++;
                }

                $seenNames[] = $colName;

                $columnMap[] = [
                    "name"   => $colName,
                    "start"  => $pos,
                    "length" => $colLength,
                ];

            }

            return $columnMap;

        }

    }