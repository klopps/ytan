<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * Harmonic tide prediction from a gauge's constants (TICON-4: amplitude +
 * Greenwich phase lag per constituent, phases in UTC):
 *
 *   h(t) = sum f_i * A_i * cos(V_i(t) + u_i - g_i)
 *
 * V from the Doodson numbers of TICON-4's Table 1 (tau = mean lunar time,
 * s, h, p, N', p1) plus the usual phase offset of the constituent
 * (Doodson-Warburg: -90 for the lunar/solar diurnals O1, Q1, P1 ..., +90 for
 * K1, J1, OO1, 180 for L2, LAMBDA2, R2); f/u = the nodal corrections of the
 * simplified Schureman/Foreman formulas for the basic constituents, compound
 * ones (M4, MS4, 2MK5 ...) from their parts. Astronomical arguments as Meeus
 * polynomials in Julian centuries since J2000 (UTC taken as TT - 69 s are
 * well below the minute this is used for). Pure math, no I/O.
 */
final class HarmonicTide
{
    /**
     * name => [Doodson digits a..f (already minus 5), phase offset in degrees, nodal parts [base => multiplier]]
     * Doodson numbers exactly as TICON-4's Table 1 lists them.
     */
    private const CONSTITUENTS = [
        'SA' => [[0, 0, 1, 0, 0, -1], 0, []],
        'SSA' => [[0, 0, 2, 0, 0, 0], 0, []],
        'MM' => [[0, 1, 0, -1, 0, 0], 0, ['MM' => 1]],
        'MSF' => [[0, 2, -2, 0, 0, 0], 0, ['M2' => -1]],
        'MF' => [[0, 2, 0, 0, 0, 0], 0, ['MF' => 1]],
        'MTM' => [[0, 3, 0, -1, 0, 0], 0, ['MF' => 1]],
        'MSQM' => [[0, 4, -2, 0, 0, 0], 0, ['MF' => 1]],
        '2Q1' => [[1, -3, 0, 2, 0, 0], 90, ['O1' => 1]],
        'SIGMA1' => [[1, -3, 2, 0, 0, 0], 90, ['O1' => 1]],
        'Q1' => [[1, -2, 0, 1, 0, 0], 90, ['O1' => 1]],
        'RHO1' => [[1, -2, 2, -1, 0, 0], 90, ['O1' => 1]],
        'O1' => [[1, -1, 0, 0, 0, 0], 90, ['O1' => 1]],
        'M1' => [[1, 0, 0, 1, 0, 0], 90, []],
        'P1' => [[1, 1, -2, 0, 0, 0], 90, []],
        'S1' => [[1, 1, -1, 0, 0, 0], 180, []],
        'K1' => [[1, 1, 0, 0, 0, 0], -90, ['K1' => 1]],
        'J1' => [[1, 2, 0, -1, 0, 0], -90, ['J1' => 1]],
        'OO1' => [[1, 3, 0, 0, 0, 0], -90, ['OO1' => 1]],
        'EPS2' => [[2, -3, 2, 1, 0, 0], 0, ['M2' => 1]],
        '2N2' => [[2, -2, 0, 2, 0, 0], 0, ['M2' => 1]],
        'MU2' => [[2, -2, 2, 0, 0, 0], 0, ['M2' => 1]],
        'MKS2' => [[2, -1, 0, 0, 0, 0], 0, ['M2' => 1]],
        'N2' => [[2, -1, 0, 1, 0, 0], 0, ['M2' => 1]],
        'NU2' => [[2, -1, 2, -1, 0, 0], 0, ['M2' => 1]],
        'MA2' => [[2, 0, -1, 0, 0, 0], 0, ['M2' => 1]],
        'M2' => [[2, 0, 0, 0, 0, 0], 0, ['M2' => 1]],
        'MB2' => [[2, 0, 1, 0, 0, 0], 0, ['M2' => 1]],
        '3N2' => [[2, 0, 2, 0, 0, 0], 0, ['M2' => 1]],
        'LAMBDA2' => [[2, 1, -2, 1, 0, 0], 180, ['M2' => 1]],
        'L2' => [[2, 1, 0, -1, 0, 0], 180, ['M2' => 1]],
        '3L2' => [[2, 1, 0, 0, 0, 0], 180, ['M2' => 1]],
        'T2' => [[2, 2, -3, 0, 0, 1], 0, []],
        'S2' => [[2, 2, -2, 0, 0, 0], 0, []],
        'R2' => [[2, 2, -1, 0, 0, -1], 180, []],
        'K2' => [[2, 2, 0, 0, 0, 0], 0, ['K2' => 1]],
        '2SM2' => [[2, 4, -4, 0, 0, 0], 0, ['M2' => -1]],
        'M3' => [[3, 0, 0, 0, 0, 0], 0, ['M2' => 1.5]],
        'T3' => [[3, 3, -4, 0, 0, 0], 0, []],
        'S3' => [[3, 3, -3, 0, 0, 0], 0, []],
        'R3' => [[3, 3, -2, 0, 0, 0], 0, []],
        'N4' => [[4, -2, 0, 2, 0, 0], 0, ['M2' => 2]],
        'MN4' => [[4, -1, 0, 1, 0, 0], 0, ['M2' => 2]],
        'M4' => [[4, 0, 0, 0, 0, 0], 0, ['M2' => 2]],
        'MS4' => [[4, 2, -2, 0, 0, 0], 0, ['M2' => 1]],
        'S4' => [[4, 4, -4, 0, 0, 0], 0, []],
        '2MO5' => [[5, -1, 0, 0, 0, 0], -90, ['M2' => 2, 'O1' => 1]],
        '2MK5' => [[5, 1, 0, 0, 0, 0], 90, ['M2' => 2, 'K1' => 1]],
        'M6' => [[6, 0, 0, 0, 0, 0], 0, ['M2' => 3]],
        '2MS6' => [[6, 2, -2, 0, 0, 0], 0, ['M2' => 2]],
        'M8' => [[8, 0, 0, 0, 0, 0], 0, ['M2' => 4]],
    ];

    /** rates of tau, s, h, p, N', p1 in degrees per hour */
    private const RATES = [14.4920521070, 0.5490165202, 0.0410686387, 0.0046418347, 0.0022064173, 0.0000019610];

    /** @return list<string> the constituent names this class knows */
    public static function names(): array
    {
        return array_keys(self::CONSTITUENTS);
    }

    /** Angular speed of a constituent in degrees per hour (M2 = 28.984...), null = unknown name. */
    public static function speed(string $name): ?float
    {
        $digits = self::CONSTITUENTS[$name][0] ?? null;
        if ($digits === null) {
            return null;
        }
        $speed = 0.0;
        foreach ($digits as $i => $digit) {
            $speed += $digit * self::RATES[$i];
        }

        return $speed;
    }

    /**
     * Prepared terms for a time range around $reference: amplitude * f,
     * angular speed (radians per second) and the phase V + u - g at
     * $reference (radians) - V grows linearly with time, so h(t) is then
     * sum a * cos(speed * (t - reference) + phase) without the astronomy per
     * sample. The nodal factors change by well under 1 % per month, so they
     * are taken once at $reference too.
     *
     * @param array<string, array{0: float, 1: float}> $constants name => [amplitude in m, Greenwich phase lag in degrees]
     * @return array{reference: int, terms: list<array{0: float, 1: float, 2: float}>}
     */
    public static function terms(array $constants, int $reference): array
    {
        $arguments = self::arguments($reference);
        $nodal = self::nodal($arguments[4]);
        $terms = [];
        foreach ($constants as $name => [$amplitude, $phase]) {
            $definition = self::CONSTITUENTS[$name] ?? null;
            if ($definition === null || $amplitude == 0.0) {
                continue;
            }
            [$d, $offset, $parts] = $definition;
            $f = 1.0;
            $u = 0.0;
            foreach ($parts as $base => $multiplier) {
                $f *= $nodal[$base][0] ** abs($multiplier);
                $u += $nodal[$base][1] * $multiplier;
            }
            $v = $d[0] * $arguments[0] + $d[1] * $arguments[1] + $d[2] * $arguments[2] + $d[3] * $arguments[3]
                - $d[4] * $arguments[4] + $d[5] * $arguments[5];
            $terms[] = [$amplitude * $f, deg2rad((float) self::speed($name)) / 3600, deg2rad(fmod($v + $offset + $u - $phase, 360.0))];
        }

        return ['reference' => $reference, 'terms' => $terms];
    }

    /** Height (m, relative to the mean) at a unix time. @param array{reference: int, terms: list<array{0: float, 1: float, 2: float}>} $prepared */
    public static function height(array $prepared, int $time): float
    {
        $dt = $time - $prepared['reference'];
        $sum = 0.0;
        foreach ($prepared['terms'] as [$amplitude, $speed, $phase]) {
            $sum += $amplitude * cos($speed * $dt + $phase);
        }

        return $sum;
    }

    /**
     * High and low waters between $from and $to: sampled every 20 minutes,
     * each local extreme refined by a parabola through its neighbours and
     * once more by a parabola at 2-minute spacing around that; then wiggles
     * smaller than 15 % of the typical rise/fall (a stand of the tide, double
     * high water) are dropped pairwise and same-type neighbours merged
     * (UkhoTideSource::merged()).
     *
     * @param array<string, array{0: float, 1: float}> $constants
     * @return list<array{time: int, type: 'HW'|'NW', height_m: float}>
     */
    public static function extremes(array $constants, int $from, int $to): array
    {
        $step = 1200;
        $prepared = self::terms($constants, intdiv($from + $to, 2));
        if ($prepared['terms'] === []) {
            return [];
        }
        $events = [];
        $previous2 = self::height($prepared, $from - 2 * $step);
        $previous = self::height($prepared, $from - $step);
        for ($t = $from; $t <= $to + $step; $t += $step) {
            $current = self::height($prepared, $t);
            $isMax = $previous > $previous2 && $previous >= $current;
            $isMin = $previous < $previous2 && $previous <= $current;
            if ($isMax || $isMin) {
                $time = self::vertex($t - $step, $step, $previous2, $previous, $current);
                $time = self::vertex($time, 120, self::height($prepared, $time - 120), self::height($prepared, $time), self::height($prepared, $time + 120));
                if ($time >= $from && $time <= $to) {
                    $events[] = ['time' => $time, 'type' => $isMax ? 'HW' : 'NW', 'height_m' => round(self::height($prepared, $time), 2)];
                }
            }
            $previous2 = $previous;
            $previous = $current;
        }

        return UkhoTideSource::merged(self::withoutWiggles($events));
    }

    /** Time of the vertex of the parabola through (centre - step, a), (centre, b), (centre + step, c), kept within one step. */
    private static function vertex(int $centre, int $step, float $a, float $b, float $c): int
    {
        $denominator = $a - 2 * $b + $c;
        $shift = $denominator == 0.0 ? 0.0 : max(-1.0, min(1.0, 0.5 * ($a - $c) / $denominator));

        return (int) round($centre + $shift * $step);
    }

    /**
     * @param list<array{time: int, type: 'HW'|'NW', height_m: float}> $events
     * @return list<array{time: int, type: 'HW'|'NW', height_m: float}>
     */
    private static function withoutWiggles(array $events): array
    {
        $differences = [];
        for ($i = 1, $n = count($events); $i < $n; $i++) {
            $differences[] = abs($events[$i]['height_m'] - $events[$i - 1]['height_m']);
        }
        if ($differences === []) {
            return $events;
        }
        sort($differences);
        // the 75th percentile is a real rise/fall even when the wiggles make up half of all differences
        $threshold = max(0.01, 0.15 * $differences[intdiv(3 * count($differences), 4)]);
        do {
            $removed = false;
            $smallest = null;
            for ($i = 1, $n = count($events); $i < $n; $i++) {
                $difference = abs($events[$i]['height_m'] - $events[$i - 1]['height_m']);
                if ($difference < $threshold && ($smallest === null || $difference < $smallest[1])) {
                    $smallest = [$i, $difference];
                }
            }
            if ($smallest !== null) {
                array_splice($events, $smallest[0] - 1, 2);
                $removed = true;
            }
        } while ($removed && count($events) > 1);

        return $events;
    }

    /**
     * Astronomical arguments in degrees: tau (mean lunar time), s (moon), h
     * (sun), p (lunar perigee), N (lunar node - Doodson's N' is -N), p1
     * (solar perigee).
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    public static function arguments(int $time): array
    {
        $centuries = ($time - 946728000) / 3155760000;
        $s = 218.3164477 + 481267.88123421 * $centuries;
        $h = 280.46646 + 36000.76983 * $centuries;
        $p = 83.3532465 + 4069.0137287 * $centuries;
        $n = 125.04452 - 1934.136261 * $centuries;
        $p1 = 282.93768 + 1.71946 * $centuries;
        // solar hour angle T = 15 deg per hour of UT + 180, tau = T + h - s
        $hours = fmod($time / 3600, 24.0);
        if ($hours < 0) {
            $hours += 24.0;
        }
        $tau = 15.0 * $hours + 180.0 + $h - $s;

        return [fmod($tau, 360.0), fmod($s, 360.0), fmod($h, 360.0), fmod($p, 360.0), fmod($n, 360.0), fmod($p1, 360.0)];
    }

    /**
     * Nodal factor f and angle u (degrees) of the basic constituents for the
     * lunar node N.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private static function nodal(float $node): array
    {
        $n = deg2rad($node);
        $c1 = cos($n);
        $c2 = cos(2 * $n);
        $c3 = cos(3 * $n);
        $s1 = sin($n);
        $s2 = sin(2 * $n);
        $s3 = sin(3 * $n);

        return [
            'MM' => [1.0 - 0.1300 * $c1 + 0.0013 * $c2, 0.0],
            'MF' => [1.0429 + 0.4135 * $c1 - 0.004 * $c2, -23.74 * $s1 + 2.68 * $s2 - 0.38 * $s3],
            'O1' => [1.0089 + 0.1871 * $c1 - 0.0147 * $c2 + 0.0014 * $c3, 10.80 * $s1 - 1.34 * $s2 + 0.19 * $s3],
            'K1' => [1.0060 + 0.1150 * $c1 - 0.0088 * $c2 + 0.0006 * $c3, -8.86 * $s1 + 0.68 * $s2 - 0.07 * $s3],
            'J1' => [1.0129 + 0.1676 * $c1 - 0.0170 * $c2 + 0.0016 * $c3, -12.94 * $s1 + 1.34 * $s2 - 0.19 * $s3],
            'OO1' => [1.1027 + 0.6504 * $c1 + 0.0317 * $c2 - 0.0014 * $c3, -36.68 * $s1 + 4.02 * $s2 - 0.57 * $s3],
            'M2' => [1.0007 - 0.0373 * $c1 + 0.0002 * $c2, -2.14 * $s1],
            'K2' => [1.0241 + 0.2863 * $c1 + 0.0083 * $c2 - 0.0015 * $c3, -17.74 * $s1 + 0.68 * $s2 - 0.04 * $s3],
        ];
    }
}
