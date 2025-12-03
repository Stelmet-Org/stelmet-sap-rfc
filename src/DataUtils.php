<?php

    namespace Stelmet\SapRfc;

    use DateTime;

    class DataUtils {

        /**
         * Converts a DateTime object to an inverted date string in SAP format.
         *
         * @param DateTime $date The DateTime object to convert
         *
         * @return string The inverted date string in SAP format
         */
        public static function getInvertedDate(DateTime $date): string {

            /**
             * SAP"s inverted date format is calculated as follows:
             * 99999999 - YYYYMMDD
             */
            $dateString = $date->format("Ymd");
            $invertedDate = 99999999 - intval($dateString);

            return str_pad(strval($invertedDate), 8, "0");

        }

        /**
         * Parses an inverted date string from SAP format back to a DateTime object or formatted string.
         *
         * @param string $invertedDate The inverted date string in SAP format
         * @param bool $toString If true, returns the date as a string in "Y-m-d" format
         *
         * @return DateTime|string Returns a DateTime object or a formatted date string
         */
        public static function parseInvertedDate(string $invertedDate, bool $toString = false): DateTime|string {

            $dateInt = 99999999 - intval($invertedDate);
            $dateString = str_pad(strval($dateInt), 8, "0");

            if ($toString) {
                return DateTime::createFromFormat("Ymd", $dateString)->format("Y-m-d");
            }

            return DateTime::createFromFormat("Ymd", $dateString);

        }

        /**
         * Cast RFC field value to proper type. Empty strings are treated as null.
         *
         * @param string $value The field value as string
         * @param array $typeData The field type data array containing "type" key
         * @param string $dateFormat The date format used in the SAP system (default "Ymd")
         * @param bool $castEmptyDecimalsToNull Whether to cast empty decimal fields to null (default true)
         *
         * @return string|int|float|null The cast value
         */
        public static function castRFCValue(
            string $value,
            array  $typeData,
            string $dateFormat = "Ymd",
            bool   $castEmptyDecimalsToNull = true,
        ): string|int|float|null {

            $type = $typeData["type"];
            $trimmed = trim($value);

            if ($trimmed === "") {

                if (in_array($type, ["RFCTYPE_NUM", "RFCTYPE_BCD", "RFCTYPE_FLOAT"], true)) {
                    return $castEmptyDecimalsToNull ? null : 0;
                }

                return null;
            }

            return match ($type) {
                "RFCTYPE_NUM"                  => (int)$trimmed,
                "RFCTYPE_BCD", "RFCTYPE_FLOAT" => (float)$trimmed,
                "RFCTYPE_DATE"                 => ($trimmed === "00000000") ? null : DateTime::createFromFormat($dateFormat, $trimmed)->format("Y-m-d"),
                default                        => $trimmed,
            };
        }

        /**
         * Cast SAP field value to proper type. Empty strings are treated as null.
         *
         * @param string $value The field value as string
         * @param string $type The field type code ("D" for date, "I" for integer, "F" for float, etc.)
         * @param string $dateFormat The date format used in the SAP system (default "Ymd")
         *
         * @return float|int|string|null The cast value
         */
        public static function castValue(string $value, string $type, string $dateFormat = "Ymd"): float|int|string|null {

            if ($value === "") {
                return null;
            }

            return match ($type) {

                "D"      => $value === "00000000"
                    ? null
                    : DateTime::createFromFormat($dateFormat, $value)->format("Y-m-d"),

                "T"      => strlen($value) === 6
                    ? substr($value, 0, 2) . ':' . substr($value, 2, 2) . ':' . substr($value, 4, 2)
                    : $value,

                // Numeric char fields → don't cast, they have semantic zeros
                "N"      => $value,

                "I", "B" => (int)$value,
                "F", "P" => (float)$value,
                "X"      => $value,
                default  => $value,
            };
        }

        /**
         * Compares two float values for equality up to a specified number of decimal places.
         *
         * @param float|null $a The first float value
         * @param float|null $b The second float value
         * @param int $decimalPlaced The number of decimal places to consider (default 5)
         * @param float $epsilon The tolerance for comparison (default 0.00001)
         *
         * @return bool True if the values are equal up to the specified decimal places, false otherwise
         */
        public static function floatEquals(?float $a, ?float $b, int $decimalPlaced = 5, float $epsilon = 0.00001): bool {

            if ($a === null || $b === null) {
                return $a === $b;
            }
            $factor = pow(10, $decimalPlaced);

            return abs(round($a * $factor) - round($b * $factor)) < $epsilon;
        }

        /**
         * Dumps data to a JSON file, ensuring unique filenames to avoid overwriting.
         *
         * @param mixed $data The data to be dumped to JSON
         * @param string $filePath The target file path for the JSON file
         *
         * @return bool True if the file was successfully written, false otherwise
         */
        public static function dumpToJson(array $data, string $filePath): bool {

            $targetDir = dirname($filePath);
            $fileName = basename($filePath);
            $fileNameIncrement = 1;

            if (!is_dir($targetDir) || !is_writable($targetDir)) {
                return false;
            }

            while (file_exists($targetDir . DIRECTORY_SEPARATOR . $fileName)) {
                $fileName = pathinfo($filePath, PATHINFO_FILENAME) . " (" . $fileNameIncrement . ").json";
                $fileNameIncrement++;
            }

            $fullPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
            file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT));

            return true;

        }

    }