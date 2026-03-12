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
        /** Between operator: matches rows where field is between two values (inclusive) */
        public const string OP_BT = "BT";

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
            self::OP_BT,
        ];

        /**
         * Maximum allowed length for each OPTIONS line. Default 72 as required by SAP.
         * @var int
         */
        private int $maxLineLength = 72;

        /**
         * Validate whether an operator is allowed.
         *
         * The check is strict (type and value).
         *
         * @param string $operator Operator to validate
         *
         * @return bool True when $operator is one of the allowed operators
         */
        protected function operatorValid(string $operator): bool {

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
         * Add a raw OPTIONS line. Useful for advanced/edge cases where you need to pass
         * a preformatted condition directly to SAP. The provided text will be used as-is
         * (no automatic quoting or splitting beyond the normal max line length).
         *
         * @param string $text Raw condition text
         * @return $this
         */
        public function addRaw(string $text): self {
            if ($text === "") {
                return $this;
            }

            $this->conditions[] = [
                "raw" => $text,
            ];

            return $this;
        }

        /**
         * Clear all accumulated conditions.
         *
         * @return $this
         */
        public function clear(): self {
            $this->conditions = [];
            return $this;
        }

        /**
         * Configure the maximum length for OPTIONS lines (default 72).
         *
         * @param int $length
         * @return $this
         */
        public function setMaxLineLength(int $length): self {
            if ($length < 10) {
                throw new InvalidArgumentException("maxLineLength must be at least 10");
            }
            $this->maxLineLength = $length;
            return $this;
        }

        /**
         * Get the configured max line length.
         *
         * @return int
         */
        public function getMaxLineLength(): int {
            return $this->maxLineLength;
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
         * Add a RANGE table condition (for SAP SELECT-OPTION / RANGE parameters).
         *
         * Unlike toSapOptions() which builds WHERE-clause style TEXT lines, range conditions
         * are passed as structured table rows with SIGN, OPTION, LOW, HIGH fields.
         *
         * @param string $field     The RANGE table parameter name (must be uppercase, e.g. 'T_S0BUKRS')
         * @param string $sign      'I' (include) or 'E' (exclude)
         * @param string $operator  One of the OP_* constants (EQ, NE, BT, GT, LT, GE, LE, CP)
         * @param string|int|float $low   Lower value (or the only value for EQ etc.)
         * @param string|int|float $high  Upper value (only used for BT ranges, otherwise '')
         *
         * @return $this
         */
        public function addRangeCondition(
            string           $field,
            string           $sign,
            string|int|float $low,
            string|int|float $high = "",
        ): self {

            $sign = strtoupper($sign);

            if (!in_array($sign, ["I", "E"], true)) {
                throw new InvalidArgumentException("Invalid SIGN '$sign'. Must be 'I' (include) or 'E' (exclude).");
            }

            $this->conditions[] = [
                "field"    => strtoupper($field),
                "operator" => "RANGE",
                "sign"     => $sign,
                "option"   => self::OP_BT,
                "low"      => (string)$low,
                "high"     => (string)$high,
            ];

            return $this;
        }

        /**
         * Convenience method: add an inclusive RANGE EQ condition (the most common case).
         *
         * Equivalent to: SIGN=I, OPTION=EQ, LOW=$value
         *
         * @param string $field
         * @param string|int|float $value
         * @return $this
         */
        public function addRangeEqual(string $field, string|int|float $value): self {
            return $this->addRangeCondition($field, "I", self::OP_EQ, $value);
        }

        /**
         * Convenience method: add an inclusive RANGE BT (between) condition.
         *
         * Equivalent to: SIGN=I, OPTION=BT, LOW=$from, HIGH=$to
         *
         * @param string $field
         * @param string|int|float $from
         * @param string|int|float $to
         * @return $this
         */
        public function addRangeBetween(string $field, string|int|float $from, string|int|float $to): self {
            return $this->addRangeCondition($field, "I", "BT", $from, $to);
        }

        /**
         * Convert RANGE-type conditions into a structured array suitable for passing
         * as SAP RFC TABLES parameters (e.g. T_S0BUKRS, T_S0DODT).
         *
         * Each field becomes a key in the output array, with its value being an array
         * of rows containing SIGN, OPTION, LOW, HIGH — exactly as SAP expects.
         *
         * Example output:
         * [
         *     'T_S0BUKRS' => [
         *         ['SIGN' => 'I', 'OPTION' => 'EQ', 'LOW' => '1000', 'HIGH' => ''],
         *         ['SIGN' => 'I', 'OPTION' => 'EQ', 'LOW' => '4000', 'HIGH' => ''],
         *     ],
         *     'T_S0DODT' => [
         *         ['SIGN' => 'I', 'OPTION' => 'EQ', 'LOW' => '20260311', 'HIGH' => ''],
         *     ],
         * ]
         *
         * Non-RANGE conditions (added via addCondition/addEqualCondition etc.) are ignored here.
         * Use toSapOptions() for those.
         *
         * @return array<string, array<int, array{SIGN: string, OPTION: string, LOW: string, HIGH: string}>>
         */
        public function toRangeTables(): array {

            $tables = [];

            foreach ($this->conditions as $cond) {

                if (($cond["operator"] ?? null) !== "RANGE") {
                    continue;
                }

                $field = $cond["field"];

                $tables[$field][] = [
                    "SIGN"   => $cond["sign"],
                    "OPTION" => $cond["option"],
                    "LOW"    => $cond["low"],
                    "HIGH"   => $cond["high"],
                ];

            }

            return $tables;

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
        protected function buildExpressionLine(array $condition): string {

            if (array_key_exists("raw", $condition)) {
                return (string)$condition["raw"];
            }

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
                // Escape single quotes by doubling them (common SAP/SQL-style escaping)
                $escaped = str_replace("'", "''", $value);
                $value = "'" . $escaped . "'";
            }

            return "{$condition["field"]} {$condition["operator"]} $value";

        }

        /**
         * Split a long expression into multiple lines without breaking words.
         *
         * SAP expects each OPTIONS line no longer than 72 characters. This method attempts
         * to split on spaces and returns an array of line strings, each <= 72 characters,
         * except in pathological cases where a single "word" is longer than the max length.
         * In that case the long "word" will be chunked.
         *
         * @param string $expression Full expression to split
         *
         * @return string[] Array of lines
         */
        protected function splitLongExpressionLine(string $expression): array {

            $max = $this->maxLineLength;

            $words = explode(" ", $expression);
            $lines = [];
            $currentLine = "";

            foreach ($words as $word) {
                // If a single word is longer than the max, chunk it to avoid infinite loops
                if (strlen($word) > $max) {
                    // flush current line first
                    if ($currentLine !== "") {
                        $lines[] = $currentLine;
                        $currentLine = "";
                    }

                    // chunk the long word
                    $offset = 0;
                    $len = strlen($word);
                    while ($offset < $len) {
                        $chunk = substr($word, $offset, $max);
                        $lines[] = $chunk;
                        $offset += $max;
                    }

                    continue;
                }

                $candidate = $currentLine . ($currentLine === "" ? "" : " ") . $word;

                if (strlen($candidate) > $max) {
                    if ($currentLine !== "") {
                        $lines[] = $currentLine;
                    }
                    $currentLine = $word;
                } else {
                    $currentLine = $candidate;
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