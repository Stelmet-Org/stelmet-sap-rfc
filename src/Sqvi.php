<?php

    namespace Stelmet\SapRfc;

    use SAPNWRFC\Connection;

    class Sqvi {

        /**
         * Calls the RSAQ_REMOTE_QUERY_CALL function module in SAP to execute a remote query.
         *
         * @param Connection $connection The SAP connection instance
         * @param string $queryName The name of the query to execute
         * @param string $userGroup The user group for the query (default: "SYSTQV000074")
         * @param string $variant The variant to use for the query (default: "")
         * @param string $dataToMemory Flag indicating whether to load data to memory (default: "X")
         * @param string $externalPresentation External presentation format (default: "Z")
         *
         * @return array The result data from the query execution
         */
        public static function call(
            Connection $connection,
            string $queryName,
            string $userGroup = "SYSTQV000074",
            string $variant = "",
            string $dataToMemory = "X",
            string $externalPresentation = "Z",
            bool $skipSelScreen = true
        ): array {

            $function = $connection->getFunction("RSAQ_REMOTE_QUERY_CALL");

            $params = [
                "QUERY" => $queryName,
                "USERGROUP" => $userGroup,
                "VARIANT" => $variant,
                "DATA_TO_MEMORY" => $dataToMemory,
                "EXTERNAL_PRESENTATION" => $externalPresentation,
                "SKIP_SELSCREEN" => $skipSelScreen ? "X" : " ",
            ];

            $result = $function->invoke($params);

            $data = $result["LDATA"];
            $meta = $result["LISTDESC"];

            return self::parseLengthPrefixedLines($data, $meta);

        }

        /**
         * Parse RSAQ LINE(s) encoded as length:value tokens.
         *
         * LDATA comes in a flat string format where each field is prefixed by its length:
         *   "010:1000000000,022:Dyr. Reg. Zielona Góra,..."
         *   - 010: means the next 10 characters are the field value
         *   - Fields are separated by ','
         *   - Rows are separated by ';'
         *   - '/' may indicate end of data
         *
         * Returns an array of rows. Each row is an indexed array of field values.
         *
         * @param array<int, array<string, string>> $ldata The LDATA metadata array
         * @param array<int, array<string, string>> $listdesc The LISTDESC metadata array
         *
         * @return array<int, array<int, string|null>> Rows of parsed values (null for empty)
         */
        public static function parseLengthPrefixedLines(array $ldata, array $listdesc): array {

            $fieldCounter = 0;

            $currentRow = [];
            $parsedData = [];

            foreach ($ldata as $row) {

                $lineData = $row["LINE"];

                while (trim($lineData) !== "") {

                    // End-of-data marker
                    if ($row === "/") {
                        $parsedData[] = $currentRow;
                        continue;
                    }

                    $value = self::takeField($lineData);

                    $fieldName = trim($listdesc[$fieldCounter]["FNAMENEW"]);

                    $value = DataUtils::castValue($value, $listdesc[$fieldCounter]["FTYP"]);

                    $separator = mb_substr($lineData, 0, 1, 'UTF-8') ?: ',';
                    $lineData = mb_substr($lineData, 1, null, 'UTF-8');

                    $currentRow[$fieldName] = $value;

                    if ($separator === ";") {

                        $parsedData[] = $currentRow;
                        $currentRow = [];
                        $fieldCounter = 0;

                    } else {

                        $fieldCounter++;

                    }

                }

            }

            return $parsedData;

        }

        /**
         * Extracts a single field value from a LINE string, considering multi-byte characters.
         *
         * @param string &$lineData Reference to the line string to parse. Modified in place.
         * @param int $lengthDigits Number of digits used to represent the field length prefix. Default 3.
         * @return string Extracted field value
         */
        protected static function takeField(string &$lineData, int $lengthDigits = 3): string {

            $length = (int)mb_substr($lineData, 0, $lengthDigits, 'UTF-8');
            $lineData = mb_substr($lineData, $lengthDigits + 1, null, 'UTF-8'); // skip ':'
            $value = mb_substr($lineData, 0, $length, 'UTF-8');
            $lineData = mb_substr($lineData, $length, null, 'UTF-8');

            return $value;

        }

    }