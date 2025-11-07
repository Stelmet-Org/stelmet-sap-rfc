<?php

    namespace Stelmet\SapRfc;

    use SAPNWRFC\Connection;

    class DataTables {

        /**
         * Fetch structured table data from SAP
         *
         * @param Connection $connection
         * @param string $tableName
         * @param array $fields Array of field names to fetch
         * @param int $rowCount Number of rows (0 = all)
         * @param string $delimiter
         * @param array $options Additional options for RFC_READ_TABLE
         *
         * @return array Parsed structured data
         */
        public static function getRows(
            Connection $connection,
            string $tableName,
            array $fields,
            int $rowCount = 0,
            string $delimiter = "|",
            array $options = [],
        ): array {

            $function = $connection->getFunction("RFC_READ_TABLE");

            $tableFields = array_map(fn($f) => ["FIELDNAME" => $f], $fields);

            $data = $function->invoke([
                "QUERY_TABLE" => $tableName,
                "ROWCOUNT"    => $rowCount,
                "FIELDS"      => $tableFields,
                "DELIMITER"   => $delimiter,
                "OPTIONS"     => $options,
            ]);

            return self::parseData($data["FIELDS"], $data["DATA"]);
        }

        /**
         * Fetch metadata (all fields) for a SAP table
         *
         * @param Connection $connection
         * @param string $tableName
         * @return array Array of field metadata
         */
        public static function getTableMeta(Connection $connection, string $tableName): array
        {
            $function = $connection->getFunction("RFC_READ_TABLE");

            // Fetch 1 row just to get all FIELDS info
            $data = $function->invoke([
                "QUERY_TABLE" => $tableName,
                "ROWCOUNT"    => 1,
            ]);

            return $data["FIELDS"];

        }

        /**
         * Parses SAP fixed-width table data into structured associative arrays.
         *
         * SAP RFC table rows are returned as a single string field "WA" containing
         * fixed-width concatenated fields. The SAP metadata ($fields) provides the
         * FIELDNAME, LENGTH, TYPE, and OFFSET for each field.
         *
         * This method handles multibyte (UTF-8) characters properly and normalizes
         * Unicode to ensure accurate string handling.
         *
         * @param array $fields Metadata describing each field (FIELDNAME, LENGTH, TYPE, OFFSET)
         * @param array $data Raw rows from SAP RFC (each row contains a "WA" string)
         * @return array Parsed rows as associative arrays keyed by FIELDNAME
         */
        public static function parseData(array $fields, array $data): array {


            $result = [];

            foreach ($data as $row) {

                $wa = $row["WA"];
                $rowData = [];

                foreach ($fields as $field) {

                    $len = (int)$field["LENGTH"];        // Length of field in characters
                    $name = trim($field["FIELDNAME"]);   // Field name for associative array
                    $offset = (int)$field["OFFSET"];     // Start position (0-based index) in WA string

                    // Extract the substring corresponding to this field
                    // Use mb_substr to handle multibyte UTF-8 characters correctly
                    $value = trim(mb_substr($wa, $offset, $len, 'UTF-8'));

                    // Normalize Unicode to composed form (NFC) to avoid mismatches
                    $value = Normalizer::normalize($value, Normalizer::FORM_C);

                    // Cast the value to the appropriate type based on SAP field TYPE
                    $rowData[$name] = DataUtils::castValue($value, $field["TYPE"]);
                }

                $result[] = $rowData;

            }

            return $result;

        }

    }