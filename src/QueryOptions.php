<?php

    namespace Stelmet\SapRfc;

    use InvalidArgumentException;

    class QueryOptions {

        /** Equal operator: matches rows where field = value */
        public const string OP_EQ = "EQ";
        /** Not equal operator: matches rows where field != value */
        public const string OP_NE = "NE";
        /** Greater than operator: matches rows where field > value */
        public const string OP_GT = "GT";
        /** Less than operator: matches rows where field < value */
        public const string OP_LT = "LT";
        /** Greater or equal operator: matches rows where field >= value */
        public const string OP_GE = "GE";
        /** Less or equal operator: matches rows where field <= value */
        public const string OP_LE = "LE";
        /** Pattern match operator (like SQL LIKE) */
        public const string OP_CP = "CP";

        private array $lines = [];

        private array $conditions = [];

        /**
         * Add a condition for the SAP query.
         *
         * @param string $field Field name
         * @param string $operator Operator, must be one of the class OP_* constants
         * @param string|int|float $value Value to compare
         *
         * @return $this
         * @throws InvalidArgumentException If operator is invalid
         */
        public function addCondition(string $field, string $operator, string|int|float $value): self {

            $allowedOperators = [
                self::OP_EQ,
                self::OP_NE,
                self::OP_GT,
                self::OP_LT,
                self::OP_GE,
                self::OP_LE,
                self::OP_CP,
            ];

            if (!in_array($operator, $allowedOperators, true)) {
                throw new InvalidArgumentException("Invalid operator '$operator'. Allowed operators: " . implode(", ", $allowedOperators));
            }

            $this->conditions[] = [
                "field"    => $field,
                "operator" => $operator,
                "value"    => $value,
            ];

            return $this;
        }

        /**
         * Convenience method: Add an equal condition (field = value)
         *
         * @param string $field
         * @param string|int|float $value
         *
         * @return $this
         */
        public function addEqualCondition(string $field, string|int|float $value): self {

            return $this->addCondition($field, self::OP_EQ, $value);
        }

        /**
         * Convenience method: Add a not-equal condition (field != value)
         *
         * @param string $field
         * @param string|int|float $value
         *
         * @return $this
         */
        public function addNotEqualCondition(string $field, string|int|float $value): self {

            return $this->addCondition($field, self::OP_NE, $value);
        }

        /**
         * Convert the accumulated conditions into a SAP RFC_READ_TABLE OPTIONS array.
         *
         * SAP expects each line to be no longer than 72 characters, and multiple conditions
         * can be joined using "AND". This method automatically:
         *  - Quotes string values
         *  - Leaves numeric values unquoted
         *  - Splits conditions across multiple lines if needed
         *  - Prepends "AND" to subsequent conditions
         *
         * Example output:
         * [
         *     ["TEXT" => "BANKL NE " AND BANKN NE ""],
         *     ["TEXT" => "LIFNR EQ "00012345""]
         * ]
         *
         * @return array<int, array{TEXT: string}> Array of associative arrays, each with a "TEXT" key
         *                                        suitable for RFC_READ_TABLE OPTIONS parameter
         */
        public function toSapOptions(): array {

            $lines = [];
            $currentLine = "";

            foreach ($this->conditions as $i => $cond) {
                $value = $cond["value"];

                // Quote strings, leave numbers as-is
                if (is_string($value)) {
                    $value = "'" . $value . "'";
                }

                $expr = "{$cond["field"]} {$cond["operator"]} $value";

                // Add "AND" if not the first condition
                if ($i > 0) {
                    $expr = "AND " . $expr;
                }

                // Check if adding this expression exceeds 72 chars
                if (strlen($currentLine . " " . $expr) > 72) {
                    // Push current line and start new one
                    if ($currentLine !== "") {
                        $lines[] = ["TEXT" => trim($currentLine)];
                    }
                    $currentLine = $expr;
                } else {
                    $currentLine .= ($currentLine === "" ? "" : " ") . $expr;
                }
            }

            // Add remaining line
            if ($currentLine !== "") {
                $lines[] = ["TEXT" => trim($currentLine)];
            }

            return $lines;

        }

    }