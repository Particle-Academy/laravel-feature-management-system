<?php

declare(strict_types=1);

namespace ParticleAcademy\Fms\Tests\Support;

use ParticleAcademy\Conformance\Conformance;
use RuntimeException;

/**
 * Locate and run a `fancy-conformance` suite against THIS package.
 *
 * ## Why this is not just `Conformance::runTable()`
 *
 * `runTable()` reads the fixtures from wherever the installed package puts
 * them, and `shared/feature-entitlement` is newer than the released fixture
 * package. Until `fancy-conformance` cuts the release carrying it, the rows have
 * to come from a checkout — so this resolves a root explicitly and iterates,
 * while still loading through `Conformance::cases()` so the package's own
 * load-time guards (duplicate ids, an empty skip reason, a malformed table) are
 * the ones doing the checking. A test that re-implemented those guards would be
 * asserting a copy of itself, which is the exact failure the conformance
 * repository exists to stop.
 *
 * **Delete this class and call `Conformance::runTable()` directly** once the
 * installed fixture package carries the suite.
 *
 * ## A missing toolchain is a FAILURE, not a skip
 *
 * `root()` throws rather than returning null. `runners/README.md` names
 * `skipIf(!HAS_X)` as the mechanism that hid two-way drift for months: a suite
 * that silently does not run reads exactly like full coverage.
 */
final class SharedSuites
{
    /**
     * The fixture root — the directory holding `suites/`.
     *
     * 1. `FANCY_CONFORMANCE_ROOT`, which is how CI points at its checkout.
     * 2. A checkout beside this one: `../fancy-conformance`, or
     *    `<envelope>/repos/fancy-conformance`, found by walking up.
     * 3. The installed package's own fixtures — the resting state, once the
     *    suite is in a release.
     *
     * Never a fixed `../..`: the two parity harnesses `fancy-conformance`
     * replaced both hard-coded a relative path to a sibling checkout, so they
     * ran in exactly one directory layout and silently no-opped everywhere else.
     */
    public static function root(string $suite): string
    {
        foreach (self::candidates() as $candidate) {
            if ($candidate !== null && is_file($candidate.'/suites/'.$suite.'/manifest.json')) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "fancy-conformance: could not find the '{$suite}' fixtures.\n"
            ."Set FANCY_CONFORMANCE_ROOT to a checkout of Particle-Academy/fancy-conformance, "
            ."or install a release of particle-academy/fancy-conformance that carries the suite.\n"
            .'This is a failure and not a skip on purpose: a conformance suite that quietly '
            .'does not run reads exactly like full coverage.'
        );
    }

    /** @return list<string|null> */
    private static function candidates(): array
    {
        $found = [];

        $env = getenv('FANCY_CONFORMANCE_ROOT');
        if (is_string($env) && $env !== '') {
            $found[] = rtrim(str_replace('\\', '/', $env), '/');
        }

        $dir = str_replace('\\', '/', \dirname(__DIR__, 2));
        for ($i = 0; $i < 6; $i++) {
            $found[] = $dir.'/../fancy-conformance';
            $found[] = $dir.'/repos/fancy-conformance';
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        try {
            $found[] = Conformance::root();
        } catch (\Throwable) {
            // The installed package could not find its own fixtures. Not fatal
            // here — one of the candidates above may still resolve, and if none
            // does, root() reports all of it in one message.
        }

        return $found;
    }

    /**
     * Run one table suite against `$impl`, in the same shape `runTable()` uses.
     *
     * @param  callable(array<string,mixed>): mixed  $impl
     * @return array{suite:string,language:string,suiteVersion:string,passed:int,failed:int,skipped:int,results:list<array<string,mixed>>,ok:bool}
     */
    public static function runTable(string $suite, callable $impl, string $language = 'php'): array
    {
        $root = self::root($suite);
        $results = [];

        foreach (Conformance::cases($suite, $root) as $case) {
            $reason = $case['skip'][$language] ?? null;

            if ($reason !== null) {
                $results[] = ['id' => $case['id'], 'title' => $case['title'], 'status' => 'skip', 'reason' => $reason];

                continue;
            }

            try {
                $actual = $impl($case);
            } catch (\Throwable $e) {
                $results[] = [
                    'id' => $case['id'],
                    'title' => $case['title'],
                    'status' => 'fail',
                    'expected' => $case['expected'],
                    'actual' => 'threw: '.$e->getMessage(),
                ];

                continue;
            }

            $results[] = Conformance::equals($actual, $case['expected'])
                ? ['id' => $case['id'], 'title' => $case['title'], 'status' => 'pass']
                : [
                    'id' => $case['id'],
                    'title' => $case['title'],
                    'status' => 'fail',
                    'expected' => $case['expected'],
                    'actual' => $actual,
                ];
        }

        $count = fn (string $status): int => \count(array_filter($results, fn ($r): bool => $r['status'] === $status));
        $failed = $count('fail');

        return [
            'suite' => $suite,
            'language' => $language,
            'suiteVersion' => trim((string) @file_get_contents($root.'/VERSION')),
            'passed' => $count('pass'),
            'failed' => $failed,
            'skipped' => $count('skip'),
            'results' => $results,
            'ok' => $failed === 0,
        ];
    }
}
