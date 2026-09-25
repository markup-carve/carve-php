<?php
declare(strict_types=1);

use MarkupCarve\Carve\Test\TestCase\ProseMirror\SchemaMapProvenance;

/**
 * The vendored ProseMirror map still agrees with carve-grammars.
 *
 * `resources/prosemirror-schema-map.json` is a copy of carve-grammars
 * `tiptap/schema-map.json` and records the commit it came from. Nothing measured
 * that commit, so the copy drifted in silence: six decisions existed only here,
 * and `_provenance.commit` named a file containing none of them
 * (carve-php#2326). A refresh - which `_provenance.refresh` describes as a copy
 * plus a commit bump - would have deleted all six.
 *
 *   php scripts/check-schema-map.php --grammars <carve-grammars checkout>
 *
 *     --grammars DIR   a carve-grammars checkout, full history
 *     --branch NAME    the branch the pin must sit on; defaults to main
 *     --map PATH       the copy to check; defaults to the vendored one
 *     --github         emit GitHub Actions annotations
 *
 * WHY THE HAS-A-DECISION TEST CANNOT DO THIS. It asserts that every AST type
 * this engine produces has a mapped-or-unmapped decision, and a locally added
 * entry satisfies that by construction - so the test FORCES a local entry
 * whenever a type becomes producible and nothing forces the upstream one. Every
 * new type widened the gap.
 *
 * WHY DECISIONS AND NOT THE COMMIT DISTANCE. carve-grammars merges
 * continuously, so a gate on distance would be red from any open pull request
 * over there. The distance is reported as a number instead.
 *
 * Exit 0 every assertion holds, 1 an assertion failed, 2 usage error.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

/**
 * @param array<string> $argv
 *
 * @return array<string, string>|null
 */
function parseOptions(array $argv): ?array
{
    $options = ['branch' => 'main', 'map' => '', 'grammars' => '', 'github' => ''];
    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $argument = $argv[$i];
        if ($argument === '--github') {
            $options['github'] = '1';

            continue;
        }
        $name = ltrim($argument, '-');
        if (!str_starts_with($argument, '--') || !array_key_exists($name, $options) || !isset($argv[$i + 1])) {
            return null;
        }
        $options[$name] = $argv[++$i];
    }

    return $options['grammars'] === '' ? null : $options;
}

/**
 * @return array{out: string, ok: bool}
 */
function git(string $repo, string ...$arguments): array
{
    $command = 'git -C ' . escapeshellarg($repo);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);

    return ['out' => trim(implode("\n", $output)), 'ok' => $status === 0];
}

function annotate(string $message, bool $github): void
{
    fwrite(STDERR, ($github ? '::error::' : 'error: ') . $message . "\n");
}

$options = parseOptions($argv);
if ($options === null) {
    fwrite(STDERR, "usage: php scripts/check-schema-map.php --grammars DIR [--branch NAME] [--map PATH] [--github]\n");

    exit(2);
}

$github = $options['github'] === '1';
$grammars = $options['grammars'];
$mapPath = $options['map'] !== '' ? $options['map'] : $root . '/resources/prosemirror-schema-map.json';

if (!is_dir($grammars . '/.git')) {
    fwrite(STDERR, sprintf("check-schema-map: %s is not a git checkout\n", $grammars));

    exit(2);
}
$contents = is_file($mapPath) ? file_get_contents($mapPath) : false;
if ($contents === false) {
    fwrite(STDERR, sprintf("check-schema-map: %s is not readable\n", $mapPath));

    exit(2);
}
try {
    /** @var array<string, mixed> $local */
    $local = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, sprintf("check-schema-map: %s is not valid JSON: %s\n", $mapPath, $exception->getMessage()));

    exit(2);
}

/**
 * Reads the map out of the checkout at one revision.
 *
 * @return array<string, mixed>|string the document, or why it could not be read
 */
function upstreamAt(string $grammars, string $revision, string $path): array|string
{
    $shown = git($grammars, 'show', $revision . ':' . $path);
    if (!$shown['ok']) {
        return sprintf('carve-grammars %s has no %s: %s', $revision, $path, $shown['out']);
    }
    try {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($shown['out'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return sprintf('carve-grammars %s:%s is not valid JSON: %s', $revision, $path, $exception->getMessage());
    }

    return $decoded;
}

$provenance = SchemaMapProvenance::provenance($local);
/** @var array<array{check: string, message: string}> $failures */
$failures = $provenance['failures'];
$commit = $provenance['commit'];
$path = $provenance['path'];
$divergences = $provenance['divergences'];
$branch = $options['branch'];
$reference = null;

if ($failures === [] && $commit !== null && $path !== null) {
    $reference = null;
    foreach (['origin/' . $branch, $branch] as $candidate) {
        if (git($grammars, 'rev-parse', '--verify', '--quiet', $candidate . '^{commit}')['ok']) {
            $reference = $candidate;

            break;
        }
    }

    if (!git($grammars, 'cat-file', '-e', $commit . '^{commit}')['ok']) {
        $failures[] = [
            'check' => 'commit_exists',
            'message' => sprintf('carve-grammars has no commit %s', $commit),
        ];
    } elseif ($reference === null) {
        $failures[] = [
            'check' => 'pin_is_current',
            'message' => sprintf(
                'neither origin/%s nor %s exists in %s; check it out with fetch-depth: 0',
                $branch,
                $branch,
                $grammars,
            ),
        ];
    } elseif (!git($grammars, 'merge-base', '--is-ancestor', $commit, $reference)['ok']) {
        $failures[] = [
            'check' => 'commit_on_branch',
            'message' => sprintf(
                'carve-grammars %s is not an ancestor of %s, so this copy came from an '
                    . 'unmerged or rewritten branch',
                $commit,
                $reference,
            ),
        ];
    }

    if ($failures === [] && $reference !== null) {
        $touched = git($grammars, 'log', '-1', '--format=%H', $commit, '--', $path)['out'];
        if ($touched !== $commit) {
            $failures[] = [
                'check' => 'commit_touched_source',
                'message' => sprintf(
                    'carve-grammars %s does not change %s; record the commit the copy was taken '
                        . 'from, which is %s',
                    $commit,
                    $path,
                    $touched !== '' ? $touched : 'none in its history',
                ),
            ];
        }
    }

    if ($failures === [] && $reference !== null) {
        $pinned = upstreamAt($grammars, $commit, $path);
        $head = upstreamAt($grammars, $reference, $path);
        if (is_string($pinned)) {
            $failures[] = ['check' => 'source_readable', 'message' => $pinned];
        } elseif (is_string($head)) {
            $failures[] = ['check' => 'pin_is_current', 'message' => $head];
        } else {
            $ours = SchemaMapProvenance::decisions($local);
            $atPin = SchemaMapProvenance::decisions($pinned);
            $atHead = SchemaMapProvenance::decisions($head);

            $againstPin = SchemaMapProvenance::compare($ours, $atPin, $divergences, 'the pin');
            if ($againstPin['undeclared'] !== []) {
                $failures[] = [
                    'check' => 'decisions_match_pin',
                    'message' => sprintf(
                        '%d decision(s) differ from the pin with nothing in '
                            . '`_provenance.divergences` saying why: %s',
                        count($againstPin['undeclared']),
                        implode('; ', $againstPin['undeclared']),
                    ),
                ];
            }
            $againstHead = SchemaMapProvenance::compare($atPin, $atHead, $divergences, $branch);
            if ($againstHead['undeclared'] !== []) {
                $failures[] = [
                    'check' => 'pin_is_current',
                    'message' => sprintf(
                        '%d decision(s) differ from %s with nothing in '
                            . '`_provenance.divergences` saying why: %s',
                        count($againstHead['undeclared']),
                        $branch,
                        implode('; ', $againstHead['undeclared']),
                    ),
                ];
            }

            $used = array_merge($againstPin['used'], $againstHead['used']);
            $stale = SchemaMapProvenance::staleDivergences($divergences, $used);
            if ($stale !== []) {
                $failures[] = [
                    'check' => 'divergences_are_live',
                    'message' => sprintf(
                        '`_provenance.divergences` names %s, with no difference from upstream left '
                            . 'to explain; drop the entry rather than let the list only grow',
                        implode(', ', $stale),
                    ),
                ];
            }

            if ($failures === []) {
                $behind = git($grammars, 'rev-list', '--count', $commit . '..' . $reference)['out'];
                $touching = git($grammars, 'rev-list', '--count', $commit . '..' . $reference, '--', $path)['out'];
                printf("recorded carve-grammars commit: %s\n", $commit);
                printf(
                    "%s is %s commit(s) ahead of it, %s of them touching the map\n",
                    $branch,
                    $behind !== '' ? $behind : '?',
                    $touching !== '' ? $touching : '?',
                );
                ksort($divergences);
                foreach ($divergences as $name => $why) {
                    printf("declared divergence: %s - %s\n", $name, $why);
                }
                // Not a decision, so not a gate - but a copy that differs only in
                // prose is still not a copy, and printing it is what keeps the
                // difference from being invisible.
                $prose = SchemaMapProvenance::prose($local, $pinned);
                printf(
                    "%d entries match the pin's decision but not its prose%s\n",
                    count($prose),
                    $prose === [] ? '' : ': ' . implode(', ', $prose),
                );
            }
        }
    }
}

foreach ($failures as $failure) {
    annotate($failure['check'] . ': ' . $failure['message'], $github);
}
if ($failures !== []) {
    exit(1);
}
echo "check-schema-map: every assertion holds.\n";

exit(0);
