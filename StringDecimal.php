<?php
/* Licensed under the MIT license: http://www.opensource.org/licenses/mit-license.php
 * Copyright (c) 2010 Taavi Burns <taavi@taaviburns.ca>
 */

class StringDecimalException extends Exception {}

class StringDecimal {

	public $_precision = 30;

	function __construct($divide_precision = NULL) {
		if ($divide_precision !== NULL) {
			$this->_precision = intval($divide_precision);
		}
	}

	function _all_zero($a) {
		foreach ($a as $v) {
			if ($v !== 0) {
				return false;
			}
		}
		return true;
	}

	function _array_add($a, $b) {
		if (count($a) !== count($b)) {
			throw new StringDecimalException("Arrays of dissimilar length cannot be added");
		}
		$result = array();
		for ($i = 0; $i < count($a); $i++) {
			$result[$i] = $a[$i] + $b[$i];
		}
		return $result;
	}

	function _array_multiply($a, $n) {
		$result = array();
		for ($i = 0; $i < count($a); $i++) {
			$result[$i] = $a[$i] * $n;
		}
		return $result;
	}

	function _array_to_string($arr) {
		return join('', array_map('strval', $arr));
	}

	function _carry($arr) {
		$carry = 0;
		$result = array();
		for ($i = count($arr)-1; $i >= 0; $i--) {
			$current = $arr[$i] + $carry;
			$carry = (int)floor($current / 10);
			while ($current < 0) {
				$current += 10;
			}
			array_push($result, $current % 10);
		}
		if ($carry > 0) {
			array_push($result, $carry);
		}
		return array_reverse($result);
	}

	function _copy($sd) {
		return array(
			'sign' => $sd['sign'],
			'mantissa' => array_slice($sd['mantissa'], 0),
			'exponent' => $sd['exponent']
		);
	}

	function _match_exponents(&$a, &$b) {
		while ($a['exponent'] > $b['exponent']) {
			$b['exponent']++;
			array_push($b['mantissa'], 0);
		}
		while ($b['exponent'] > $a['exponent']) {
			$a['exponent']++;
			array_push($a['mantissa'], 0);
		}
	}

	function _match_leading(&$a, &$b) {
		if ($a['exponent'] !== $b['exponent']) {
			throw new StringDecimalException("Can't match leading with different exponents");
		}
		while (count($a['mantissa']) > count($b['mantissa'])) {
			array_unshift($b['mantissa'], 0);
		}
		while (count($b['mantissa']) > count($a['mantissa'])) {
			array_unshift($a['mantissa'], 0);
		}
	}

	function _string_to_array($str) {
		return array_map('intval', str_split($str));
	}

	function _strip_leading($sd) {
		while (count($sd['mantissa'])-1 > $sd['exponent'] && $sd['mantissa'][0] === 0) {
			array_shift($sd['mantissa']);
		}
		return $sd;
	}

	function _parse($str) {
		preg_match("/^(\+|-)?0*(\d+)(?:\.(\d+))?(?:e((?:\+|-)?\d+))?$/i", $str, $matches);
		$sign = $matches[1] ? $matches[1] : '+';
		$fractional = strlen($matches[3]) === 0 ? "" : $matches[3];
		$mantissa_as_a_string = $matches[2] . $fractional;
		$adjust = strlen($matches[4]) === 0 ? 0 : intval($matches[4]);
		$exponent = strlen($fractional);
		$value = array(
			'sign' => $sign,
			'mantissa' => $this->_string_to_array($mantissa_as_a_string),
			'exponent' => $exponent
		);
		if ($adjust > ($this->_precision * 2)) {
			throw new StringDecimalException("Number too big");
		}
		while ($adjust > 0) {
			if ($value['exponent'] > 0) {
				$value['exponent']--;
			} else {
				array_push($value['mantissa'], 0);
			}
			$adjust--;
		}
		while ($adjust < 0) {
			$value['exponent']++;
			if ($value['exponent'] >= count($value['mantissa'])) {
				array_unshift($value['mantissa'], 0);
			}
			$adjust++;
		}
		return $this->_strip_leading($value);
	}

	function _format($obj) {
		$sign = ($obj['sign'] === "-") ? "-" : "";
		$mantissa = $this->_array_to_string($obj['mantissa']);
		$decimal_point = ($obj['exponent'] > 0) ? "." : "";
		$decimal_point_offset = strlen($mantissa) - $obj['exponent'];
		return $sign .
			substr($mantissa, 0, $decimal_point_offset) .
			$decimal_point .
			substr($mantissa, $decimal_point_offset);
	}

	function add($raw_a, $raw_b) {
		$a = $this->_parse($raw_a);
		$b = $this->_parse($raw_b);
		$this->_match_exponents($a, $b);
		$this->_match_leading($a, $b);
		if ($a['sign'] === $b['sign']) {
			$sum = array(
				'sign' => $a['sign'],
				'mantissa' => $this->_array_add($a['mantissa'], $b['mantissa']),
				'exponent' => $a['exponent']
			);
		} else {
			if ($a['mantissa'] > $b['mantissa']) {
				$sum = array(
					'sign' => $a['sign'],
					'mantissa' => $this->_array_add($a['mantissa'], $this->_array_multiply($b['mantissa'], -1)),
					'exponent' => $a['exponent']
				);
			} elseif ($a['mantissa'] < $b['mantissa']) {
				$sum = array(
					'sign' => $b['sign'],
					'mantissa' => $this->_array_add($this->_array_multiply($a['mantissa'], -1), $b['mantissa']),
					'exponent' => $a['exponent']
				);
			} else {
				$sum = array(
					'sign' => '+',
					'mantissa' => array_fill(0, count($a['mantissa']), 0),
					'exponent' => $a['exponent']
				);
			}
		}
		$sum['mantissa'] = $this->_carry($sum['mantissa']);
		return $this->_format($sum);
	}

	function subtract($raw_a, $raw_b) {
		$b = $this->_parse($raw_b);
		$b['sign'] = ($b['sign'] === "-") ? "+" : "-";
		return $this->add($raw_a, $this->_format($b));
	}

	function multiply($raw_a, $raw_b) {
		$a = $this->_parse($raw_a);
		$b = $this->_parse($raw_b);
		$product = array();
		if ($a['sign'] === $b['sign']) {
			$product['sign'] = '+';
		} else {
			$product['sign'] = '-';
		}
		// This is just like in elementary school
		//   1 2
		// * 3 4
		// -----
		//   4 8
		// 3 6
		// -----
		// 4 0 8
		$addend_width = count($a['mantissa']) + count($b['mantissa']) - 1;
		$acc = array_fill(0, $addend_width, 0);
		for ($i = 0; $i < count($b['mantissa']); $i++) {
			for ($j = 0; $j < count($a['mantissa']); $j++) {
				$acc[$i+$j] += $a['mantissa'][$j] * $b['mantissa'][$i];
			}
		}
		// _carry() happily takes very large values to carry, and we shouldn't ever need more than the one
		// extra digit _carry() will give us at the end.
		$product['mantissa'] = $this->_carry($acc);
		$product['exponent'] = $a['exponent'] + $b['exponent'];
		return $this->_format($this->_strip_leading($product));
	}

	function divide($raw_a, $raw_b, $places) {
		// See http://en.wikipedia.org/wiki/Division_(digital)#Newton.E2.80.93Raphson_division
		$integer_places = intval($places, 10);
		if ($integer_places > $this->_precision) {
			// Doesn't make sense to let someone round to more places than our precision
			throw new StringDecimalException("Places too big");
		}
		$a = $this->_parse($raw_a);
		$b = $this->_parse($raw_b);

		if ($this->_all_zero($b['mantissa'])) {
			return "NaN";
		}
		if ($this->_all_zero($a['mantissa'])) {
			return $this->round($this->_format(array(
				'sign' => ($a['sign'] === $b['sign']) ? '+' : '-',
				'mantissa' => array(0),
				'exponent' => 0
			)), $integer_places);
		}

		$adjust = 0;
		while ($b['mantissa'][0] === 0) {
			$adjust++;
			$b['exponent']--;
			array_shift($b['mantissa']);
		}
		while (count($b['mantissa']) > $b['exponent']+1) {
			$adjust--;
			$b['exponent']++;
		}
		// Now the divisor is within [1,10]

		$factor_map = array(1, 5, 3, 2, 2, 1, 1, 1, 1, 1);
		$extra_factor = $factor_map[$b['mantissa'][0]];
		if ($b['sign'] === '-') {
			$extra_factor *= -1;
		}

		// Bring the divisor with [0,1]
		array_unshift($b['mantissa'], 0);
		$b['exponent']++;
		$adjust--;

		$new_b = $this->_format($b);
		// Bring the divisor within [0.5, 1]
		$new_b = $this->multiply($new_b, (string)$extra_factor);
		// Magic numbers!  See the wikipedia article
		$x = $this->add("2.9142", $this->multiply($new_b, "-2"));
		$old_x = "";
		$loop_limit = $this->_precision;
		while ($loop_limit && substr($old_x, 0, $this->_precision+2) !== substr($x, 0, $this->_precision+2)) {
			$old_x = $x;
			$x = $this->round(
				$this->multiply(
					$x,
					$this->subtract(
						"2",
						$this->multiply($new_b, $x)
					)
				),
				$this->_precision*2
			);
		}
		if ($loop_limit <= 0) {
			throw new StringDecimalException("Reciprocal failed to converge in {$this->_precision} iterations");
		}

		$new_a = $this->multiply($this->multiply($x, $raw_a), $extra_factor . "e" . $adjust);

		return $this->round($new_a, $integer_places);
	}

	function round($raw_a, $places) {
		$integer_places = intval($places, 10);
		if ($integer_places > $this->_precision*2) {
			// Need to clamp somewhere, and the divide routine
			// needs us to be able to round to _precision*2.
			throw new StringDecimalException("Places too big");
		}
		$a = $this->_parse($raw_a);
		if ($a['exponent'] > $integer_places) {
			while ($a['exponent'] > $integer_places+1) {
				$a['exponent']--;
				array_pop($a['mantissa']);
			}
			$a['exponent']--;
			$rounding_digit = array_pop($a['mantissa']);
			if ($rounding_digit >= 5) {
				$a['mantissa'][count($a['mantissa'])-1]++;
				$a['mantissa'] = $this->_carry($a['mantissa']);
			}
		} else {
			while ($a['exponent'] < $integer_places) {
				$a['exponent']++;
				array_push($a['mantissa'], 0);
			}
		}
		return $this->_format($a);
	}

}
