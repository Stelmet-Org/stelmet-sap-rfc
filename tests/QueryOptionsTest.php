<?php

    use PHPUnit\Framework\TestCase;
    use Stelmet\SapRfc\QueryOptions;

    final class QueryOptionsTest extends TestCase {

        public function testSingleEqualCondition(): void {

            $opts = (new QueryOptions())->addEqualCondition('FIELD1', 'VALUE1');
            $expected = [
                ["TEXT" => "FIELD1 EQ 'VALUE1'"],
            ];
            $this->assertEquals($expected, $opts->toSapOptions());
        }

        public function testMultipleEqualConditions(): void {

            $opts = (new QueryOptions())
                ->addEqualCondition('FIELD1', 'VALUE1')
                ->addEqualCondition('FIELD2', 'VALUE2');
            $expected = [
                ["TEXT" => "FIELD1 EQ 'VALUE1'"],
                ["TEXT" => "AND FIELD2 EQ 'VALUE2'"],
            ];
            $this->assertEquals($expected, $opts->toSapOptions());
        }

        public function testOrGroupSingleLine(): void {

            $opts = (new QueryOptions())->addOrGroup('MTART', ['FERT', 'HAWA']);
            $expected = [
                ["TEXT" => "(MTART EQ 'FERT' OR MTART EQ 'HAWA')"],
            ];
            $this->assertEquals($expected, $opts->toSapOptions());
        }

        public function testOrGroupLongLineSplitting(): void {

            $values = ['MATIN120', 'MATPR120', 'MATUR150', 'WG00', 'WG01'];
            $opts = (new QueryOptions())->addOrGroup('MATKL', $values);
            $lines = $opts->toSapOptions();

            // Each line max 72 chars
            foreach ($lines as $line) {
                $this->assertLessThanOrEqual(72, strlen($line['TEXT']));
            }

            // Ensure "OR" appears in the second line (line splitting works)
            $this->assertStringContainsString('OR MATKL EQ', $lines[1]['TEXT']);
        }

        public function testMixedConditions(): void {

            $opts = (new QueryOptions())
                ->addEqualCondition('TST1', 'Value1')
                ->addEqualCondition('TST2', 'Value2')
                ->addOrGroup('MTART', ['FERT', 'HAWA'])
                ->addOrGroup('MATKL', ['MATIN120', 'MATPR120', 'MATUR150', 'WG00', 'WG01']);

            $lines = $opts->toSapOptions();

            $this->assertCount(5, $lines);  // matches your working output
            $this->assertEquals("TST1 EQ 'Value1'", $lines[0]['TEXT']);
            $this->assertEquals("AND TST2 EQ 'Value2'", $lines[1]['TEXT']);
            $this->assertStringContainsString("MTART EQ 'FERT'", $lines[2]['TEXT']);
            $this->assertStringContainsString("MATKL EQ 'MATIN120'", $lines[3]['TEXT']);
            $this->assertStringContainsString("MATKL EQ 'WG01'", $lines[4]['TEXT']);
        }

        // New tests for QOL features

        public function testAddRawPreservesText(): void {
            $raw = "FIELDX EQ 'A' OR FIELDY EQ 'B'";
            $opts = (new QueryOptions())->addRaw($raw);
            $this->assertEquals([['TEXT' => $raw]], $opts->toSapOptions());
        }

        public function testClearRemovesConditions(): void {
            $opts = (new QueryOptions())
                ->addEqualCondition('A', '1')
                ->addEqualCondition('B', '2');

            $this->assertNotEmpty($opts->getConditions());

            $opts->clear();

            $this->assertEmpty($opts->getConditions());
            $this->assertEquals([], $opts->toSapOptions());
        }

        public function testSetAndGetMaxLineLength(): void {
            $opts = new QueryOptions();
            $this->assertEquals(72, $opts->getMaxLineLength());

            $opts->setMaxLineLength(40);
            $this->assertEquals(40, $opts->getMaxLineLength());

            // small sanity check: low but allowed value splits long OR groups
            $values = ['ONE', 'TWO', 'THREE', 'FOUR', 'FIVE'];
            $lines = $opts->addOrGroup('FLD', $values)->toSapOptions();
            foreach ($lines as $line) {
                $this->assertLessThanOrEqual(40, strlen($line['TEXT']));
            }
        }

        public function testSingleQuoteEscaping(): void {
            $opts = (new QueryOptions())->addEqualCondition('NAME', "O'Reilly");
            $lines = $opts->toSapOptions();

            $this->assertCount(1, $lines);
            $this->assertEquals("NAME EQ 'O''Reilly'", $lines[0]['TEXT']);
        }

        public function testAddAndGroupProducesParenthesisedAndExpression(): void {
            $opts = (new QueryOptions())->addAndGroup('MTART', ['FERT', 'HAWA']);
            $lines = $opts->toSapOptions();

            $this->assertCount(1, $lines);
            $this->assertEquals("(MTART NE 'FERT' AND MTART NE 'HAWA')", $lines[0]['TEXT']);
        }

    }
