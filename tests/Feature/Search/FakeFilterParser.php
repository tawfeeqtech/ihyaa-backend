<?php

namespace Tests\Feature\Search;

use RuntimeException;

/**
 * محلّل (Parser) مبسّط لقواعد فلترة Meilisearch التي ينتجها SearchQueryBuilder.
 *
 * يدعم: `AND`/`OR` + أقواس `(...)` + مقارنات `=  "..."` / `>= n` / `<= n`
 * / `= true|false` + `IN [...]`. كافٍ لاختبارات US-032/035.
 */
class FakeFilterParser
{
    /** @var list<array{type:string, value?:mixed}> */
    private array $tokens = [];

    private int $pos = 0;

    /** @param  array<string, mixed>  $doc */
    public function __construct(private readonly array $doc)
    {
    }

    public function parse(string $filter): bool
    {
        $this->tokens = $this->tokenize($filter);
        $this->pos = 0;

        if (empty($this->tokens)) {
            return true;
        }

        $result = $this->parseOr();

        return $result;
    }

    /**
     * @return list<array{type:string, value?:mixed}>
     */
    private function tokenize(string $s): array
    {
        $tokens = [];
        $len = strlen($s);
        $i = 0;

        while ($i < $len) {
            $c = $s[$i];

            if ($c === ' ' || $c === "\t") {
                $i++;
                continue;
            }

            if ($c === '(') {
                $tokens[] = ['type' => 'lparen'];
                $i++;
                continue;
            }

            if ($c === ')') {
                $tokens[] = ['type' => 'rparen'];
                $i++;
                continue;
            }

            foreach (['>=', '<=', '!=', '=='] as $op) {
                if (substr($s, $i, strlen($op)) === $op) {
                    $tokens[] = ['type' => 'op', 'value' => $op];
                    $i += strlen($op);
                    continue 2;
                }
            }

            if (substr($s, $i, 3) === 'AND' && $this->boundary($s, $i + 3)) {
                $tokens[] = ['type' => 'and'];
                $i += 3;
                continue;
            }

            if (substr($s, $i, 2) === 'OR' && $this->boundary($s, $i + 2)) {
                $tokens[] = ['type' => 'or'];
                $i += 2;
                continue;
            }

            if (substr($s, $i, 2) === 'IN' && $this->boundary($s, $i + 2)) {
                $tokens[] = ['type' => 'in'];
                $i += 2;
                continue;
            }

            if ($c === '>' || $c === '<' || $c === '=') {
                $tokens[] = ['type' => 'op', 'value' => $c];
                $i++;
                continue;
            }

            if ($c === '"') {
                $j = strpos($s, '"', $i + 1);

                if ($j === false) {
                    throw new RuntimeException('Unterminated string in filter: '.$s);
                }

                $tokens[] = ['type' => 'string', 'value' => substr($s, $i + 1, $j - $i - 1)];
                $i = $j + 1;
                continue;
            }

            if ($c === '[') {
                $i++;
                $values = [];

                while ($i < $len && $s[$i] !== ']') {
                    if ($s[$i] === '"') {
                        $j = strpos($s, '"', $i + 1);

                        if ($j === false) {
                            throw new RuntimeException('Unterminated list value in filter: '.$s);
                        }

                        $values[] = substr($s, $i + 1, $j - $i - 1);
                        $i = $j + 1;
                    } elseif ($s[$i] === ',') {
                        $i++;
                    } else {
                        $i++;
                    }
                }

                $i++; // skip ]
                $tokens[] = ['type' => 'list', 'value' => $values];
                continue;
            }

            // كلمة/معرف/رقم/قيمة منطقية
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*/', substr($s, $i), $m)) {
                $word = $m[0];

                if ($word === 'true') {
                    $tokens[] = ['type' => 'bool', 'value' => true];
                } elseif ($word === 'false') {
                    $tokens[] = ['type' => 'bool', 'value' => false];
                } else {
                    $tokens[] = ['type' => 'identifier', 'value' => $word];
                }

                $i += strlen($word);
                continue;
            }

            if (preg_match('/^-?\d+(?:\.\d+)?/', substr($s, $i), $m)) {
                $tokens[] = ['type' => 'number', 'value' => (float) $m[0]];
                $i += strlen($m[0]);
                continue;
            }

            $i++; // حرف غير معروف — نتجاوزه بأمان
        }

        return $tokens;
    }

    private function boundary(string $s, int $offset): bool
    {
        $c = $s[$offset] ?? '';

        return ! preg_match('/[A-Za-z0-9_]/', $c);
    }

    private function parseOr(): bool
    {
        $left = $this->parseAnd();

        while ($this->peek('or')) {
            $this->next();
            $right = $this->parseAnd();
            $left = $left || $right;
        }

        return $left;
    }

    private function parseAnd(): bool
    {
        $left = $this->parseAtom();

        while ($this->peek('and')) {
            $this->next();
            $right = $this->parseAtom();
            $left = $left && $right;
        }

        return $left;
    }

    private function parseAtom(): bool
    {
        $token = $this->next();

        if ($token === null) {
            return true;
        }

        if ($token['type'] === 'lparen') {
            $result = $this->parseOr();

            if (! $this->peek('rparen')) {
                throw new RuntimeException('Missing closing parenthesis in filter');
            }

            $this->next();

            return $result;
        }

        if ($token['type'] === 'identifier') {
            $field = $token['value'];
            $op = $this->next();

            if ($op['type'] === 'in') {
                $list = $this->next();

                if ($list['type'] !== 'list') {
                    throw new RuntimeException('Expected list after IN');
                }

                return $this->evalIn($field, $list['value']);
            }

            if ($op['type'] === 'op') {
                $value = $this->next();

                return $this->evalCompare($field, $op['value'], $value);
            }

            throw new RuntimeException('Unexpected token after identifier: '.$op['type']);
        }

        throw new RuntimeException('Unexpected token: '.$token['type']);
    }

    /** @param  list<string>  $values */
    private function evalIn(string $field, array $values): bool
    {
        $docValue = $this->doc[$field] ?? null;
        $docValues = is_array($docValue) ? $docValue : [$docValue];

        foreach ($docValues as $v) {
            if ($v !== null && in_array((string) $v, $values, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{type:string, value?:mixed}  $valueToken
     */
    private function evalCompare(string $field, string $op, array $valueToken): bool
    {
        $actual = $this->doc[$field] ?? null;

        $expected = match ($valueToken['type']) {
            'string' => (string) $valueToken['value'],
            'number' => (float) $valueToken['value'],
            'bool' => (bool) $valueToken['value'],
            default => null,
        };

        // true/false: الحقل `has_score` يُخزَّن boolean أو يُشتق
        if ($op === '=' && $valueToken['type'] === 'bool') {
            $actualBool = $actual === true
                || $actual === 1
                || $actual === '1'
                || $actual === 'true';

            return $actualBool === $expected;
        }

        if ($op === '=') {
            return (string) $actual === (string) $expected;
        }

        if ($op === '==') {
            return $actual == $expected;
        }

        if ($op === '!=') {
            return $actual != $expected;
        }

        // مقارنات رقمية
        $a = is_numeric($actual) ? (float) $actual : null;

        if ($a === null) {
            return false;
        }

        return match ($op) {
            '>=' => $a >= (float) $expected,
            '<=' => $a <= (float) $expected,
            '>' => $a > (float) $expected,
            '<' => $a < (float) $expected,
            default => false,
        };
    }

    /** @return array{type:string, value?:mixed}|null */
    private function peek(string $type): bool
    {
        return ($this->tokens[$this->pos]['type'] ?? null) === $type;
    }

    /** @return array{type:string, value?:mixed}|null */
    private function next(): ?array
    {
        return $this->tokens[$this->pos++] ?? null;
    }
}
