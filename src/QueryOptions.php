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

        /** Marker operator value used internally to denote an OR group condition */
        public const string OR_GROUP = "OR_GROUP";
        public const string AND_GROUP = "AND_GROUP";

        /**
         * @var array<int,string> Temporary holder for output lines (unused in current implementation,
         *                       kept for backward compatibility or future use)
         */
        private array $lines = [];

        /**
         * @var array<int,array<string,mixed>> Accumulated conditions.
         * Each element is an associative array containing:
         *  - field: string
         *  - operator: string (one of OP_* or OR_GROUP)
         *  - value: string|int|float|array (for OR_GROUP it's an array of values)
         *  - subOperator: string (only for OR_GROUP, the operator to apply to each value)
         */
        private array $conditions = [];

        /**
         * @var string[] List of allowed operators for simple comparisons.
         */
        private array $allowedOperators = [
            self::OP_EQ,
            self::OP_NE,
            self::OP_GT,
            self::OP_LT,
            self::OP_GE,
            self::OP_LE,
            self::OP_CP,
        ];

        /**
         * Validate whether an operator is allowed.
         *
         * The check is strict (type and value).
         *
         * @param string $operator Operator to validate
         *
         * @return bool True when $operator is one of the allowed operators
         */
        function operatorValid(string $operator): bool {

            return in_array($operator, $this->allowedOperators, true);
        }

        /**
         * Add a condition for the SAP query.
         *
         * Supports string, int and float values. Strings are quoted when converted to the
         * RFC format; numeric values are left unquoted.
         *
         * Example:
         *   addCondition('LIFNR', QueryOptions::OP_NE, '')
         *
         * @param string $field Field name
         * @param string $operator Operator, must be one of the class OP_* constants
         * @param string|int|float $value Value to compare
         *
         * @return $this
         * @throws InvalidArgumentException If operator is invalid
         */
        public function addCondition(string $field, string $operator, string|int|float $value): self {

            if (!$this->operatorValid($operator)) {
                throw new InvalidArgumentException("Invalid operator '$operator'. Allowed operators: " . implode(", ", $this->allowedOperators));
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
         * Add an OR group for a field.
         *
         * Example: addOrGroup('MTART', ['FERT', 'HAWA'])
         * Produces: "(MTART EQ 'FERT' OR MTART EQ 'HAWA')"
         *
         * Each value in $values will be paired with $field using $operator and combined
         * with "OR". The entire group is wrapped in parentheses when converted.
         *
         * @param string $field Field name to which the OR group applies
         * @param string[] $values List of values to OR together
         * @param string $operator Optional operator to apply to each value (defaults to EQ)
         *
         * @return $this
         * @throws InvalidArgumentException If provided operator is not allowed
         */
        public function addOrGroup(string $field, array $values, string $operator = self::OP_EQ): self {

            if (!$this->operatorValid($operator)) {
                throw new InvalidArgumentException("Invalid operator '$operator' for OR group");
            }

            if (empty($values)) {
                return $this;
            }

            $this->conditions[] = [
                "field"       => $field,
                "operator"    => self::OR_GROUP,
                "value"       => $values,
                "subOperator" => $operator,
            ];

            return $this;
        }

        /**
         * Add an AND group for a field.
         *
         * Example: addAndGroup('MTART', ['FERT', 'HAWA'])
         * Produces: "(MTART NE 'FERT' AND MTART NE 'HAWA')"
         *
         * Each value in $values will be paired with $field using $operator and combined
         * with "AND". The entire group is wrapped in parentheses when converted.
         *
         * @param string $field Field name to which the AND group applies
         * @param string[] $values List of values to ANDOR together
         * @param string $operator Optional operator to apply to each value (defaults to NE)
         *
         * @return $this
         * @throws InvalidArgumentException If provided operator is not allowed
         */
        public function addAndGroup(string $field, array $values, string $operator = self::OP_NE): self {

            if (!$this->operatorValid($operator)) {
                throw new InvalidArgumentException("Invalid operator '$operator' for AND group");
            }

            if (empty($values)) {
                return $this;
            }

            $this->conditions[] = [
                "field"       => $field,
                "operator"    => self::AND_GROUP,
                "value"       => $values,
                "subOperator" => $operator,
            ];

            return $this;
        }

        /**
         * Convert the accumulated conditions into a SAP RFC_READ_TABLE OPTIONS array.
         *
         * SAP expects each line to be no longer than 72 characters, and multiple conditions
         * can be joined using "AND". This method automatically:
         *  - Quotes string values (single quotes)
         *  - Leaves numeric values unquoted
         *  - Splits conditions across multiple lines if needed
         *  - Prepends "AND" to subsequent conditions
         *
         * Example output:
         * [
         *     ["TEXT" => "BANKL NE \"\" AND BANKN NE \"\""],
         *     ["TEXT" => "LIFNR EQ '00012345'"]
         * ]
         *
         * Notes / Edge cases:
         *  - OR groups are expanded into parenthesized sub-expressions joined with "OR".
         *  - Lines are split on word boundaries (spaces) to try to keep each line <= 72 chars.
         *  - The method returns an array of associative arrays with the key "TEXT" as expected by
         *    RFC_READ_TABLE's OPTIONS parameter.
         *
         * @return array<int, array{TEXT: string}> Array of associative arrays, each with a "TEXT" key
         *                                        suitable for RFC_READ_TABLE OPTIONS parameter
         */
        public function toSapOptions(): array {

            $lines = [];

            foreach ($this->conditions as $i => $cond) {

                $expr = trim($this->buildExpressionLine($cond));

                if ($i > 0) {
                    $expr = "AND " . $expr;
                }

                $split = $this->splitLongExpressionLine($expr);

                foreach ($split as $line) {
                    $lines[] = ["TEXT" => $line];
                }

            }

            return $lines;

        }

        /**
         * Build an expression string for a single condition entry.
         *
         * - For normal conditions this returns "FIELD OPERATOR VALUE" where:
         *   * string values are wrapped in single quotes
         *   * numeric values are left as-is
         * - For OR_GROUP entries this recursively creates sub-expressions for each value
         *   and joins them with " OR ", wrapping the joined sub-expressions in parentheses.
         * - For AND_GROUP entries this recursively creates sub-expressions for each value
         *  and joins them with " AND ", wrapping the joined sub-expressions in parentheses.
         *
         * @param array<string,mixed> $condition Condition entry from $this->conditions
         *
         * @return string Expression string (may contain parentheses for OR groups)
         */
        function buildExpressionLine(array $condition): string {

            if ($condition["operator"] === self::OR_GROUP) {

                $subExpressions = [];

                foreach ($condition["value"] as $val) {

                    $subExpressions[] = $this->buildExpressionLine(
                        [
                            "field"    => $condition["field"],
                            "operator" => $condition["subOperator"],
                            "value"    => $val,
                        ],
                    );

                }

                $joined = implode(" OR ", $subExpressions);

                return "($joined)";

            }

            if ($condition["operator"] === self::AND_GROUP) {

                $subExpressions = [];

                foreach ($condition["value"] as $val) {

                    $subExpressions[] = $this->buildExpressionLine(
                        [
                            "field"    => $condition["field"],
                            "operator" => $condition["subOperator"],
                            "value"    => $val,
                        ],
                    );

                }

                $joined = implode(" AND ", $subExpressions);

                return "($joined)";

            }

            $value = $condition["value"];

            if (is_string($value)) {
                $value = "'" . $value . "'";
            }

            return "{$condition["field"]} {$condition["operator"]} $value";

        }

        /**
         * Split a long expression into multiple lines without breaking words.
         *
         * SAP expects each OPTIONS line no longer than 72 characters. This method attempts
         * to split on spaces and returns an array of line strings, each <= 72 characters,
         * except in pathological cases where a single "word" is longer than 72 characters.
         *
         * @param string $expression Full expression to split
         *
         * @return string[] Array of lines
         */
        function splitLongExpressionLine(string $expression): array {

            $words = explode(" ", $expression);
            $lines = [];
            $currentLine = "";

            foreach ($words as $word) {
                if (strlen($currentLine . ($currentLine === "" ? "" : " ") . $word) > 72) {
                    if ($currentLine !== "") {
                        $lines[] = $currentLine;
                    }
                    $currentLine = $word;
                } else {
                    $currentLine .= ($currentLine === "" ? "" : " ") . $word;
                }
            }

            if ($currentLine !== "") {
                $lines[] = $currentLine;
            }

            return $lines;

        }

        /**
         * Return the raw conditions array (useful for tests or inspection).
         *
         * The structure is the internal representation used by this class; if you plan to
         * send conditions to SAP use toSapOptions() which transforms this structure into
         * the RFC-compatible "TEXT" lines.
         *
         * @return array<int,array<string,mixed>>
         */
        public function getConditions(): array {

            return $this->conditions;
        }

    }