<?php
declare(strict_types=1);

/**
 * Every pull request that moved shipped source in a release is cited in the
 * release's changelog section.
 *
 * WHY THIS EXISTS
 * ---------------
 * A `chore: cut X.Y.Z` commit writes the `## [X.Y.Z]` section and then
 * development simply carries on over it. Nobody reopens the section, and weeks
 * later the tag ships notes describing only the handful of changes that
 * existed on cut day. Measured across three engines in one night: this
 * repository's 0.1.10 section documented 1 of 22 merges, carve-rs 0.1.7
 * documented 3 of 24, and carve-js 0.1.8 documented 1 of 17. `[Unreleased]`
 * was empty in every case, so the work was recorded nowhere. A guard that asks
 * whether the section EXISTS and carries notes passes happily on a section
 * covering 3 of 24. Existence is not completeness, so this asks the other
 * question.
 *
 * WHY THE FILTER IS SHIPPED SOURCE, NOT THE COMMIT PREFIX
 * --------------------------------------------------------
 * A `fix:` prefix is a convention people drift from, and a `chore:`-prefixed
 * merge can still move the parser. Whether the diff touched SHIPPED is a fact
 * about the commit rather than a claim in its subject, so that is what decides.
 *
 * WHY IT ASKS GITHUB WHAT A PULL REQUEST CLOSES
 * ----------------------------------------------
 * The entries across these repositories cite the ISSUE a fix answers as often
 * as the pull request that carried it. A gate that demanded the pull request
 * number would fail correctly documented releases, and a check that cries wolf
 * is one somebody deletes. So a pull request counts as cited under its own
 * number or under any issue it closes.
 *
 *   php scripts/changelog-completeness.php [version] [options]
 *
 *     version          the release to check; defaults to LIB_VERSION
 *     --at REV         read history and CHANGELOG.md as of this revision;
 *                      defaults to the tag when it exists, otherwise HEAD
 *     --previous TAG   measure from this tag instead of the highest version
 *                      tag below `version`
 *     --section HEAD   the heading to read; defaults to `version`
 *     --repo O/N       this repository's slug; defaults to GITHUB_REPOSITORY,
 *                      then the origin remote
 *     --root DIR       the checkout to read; defaults to the working directory
 *
 * Needs `gh` authenticated (the workflow passes GITHUB_TOKEN). It refuses to
 * run without it rather than degrading to an answer it cannot back.
 *
 * Exit 0  every shipped-source pull request in range is cited or exempt.
 * Exit 1  at least one is not, and every one of them is named.
 */

/** Shipped source: the PSR-4 root the package autoloads. */
const SHIPPED = ['#^src/#'];

/**
 * A change that only moves a version constant ships nothing a reader needs.
 * LIB_VERSION lives inside CarveConverter, a real class, so this is a
 * LINE-level rule rather than an excluded path: the cut would otherwise put
 * its own pull request on the missing list at every single release, and a gate
 * that fires on every release is a gate somebody turns off.
 */
const VERSION_ONLY = '#^[+-]\s*(public\s+)?const\s+(LIB_|SPEC_)?VERSION\s*=#';

const EXEMPT_FILE = '.changelog-exempt';

/** A squash merge carries its pull request as a trailing `(#N)`. */
const PULL_REQUEST = '#\(\#(\d+)\)\s*$#';
/**
 * A qualified reference to ANOTHER repository is not a citation of this one.
 * This changelog carries 192 references to markup-carve/carve against 36 to
 * this repository, so a bare grep for `#N` would count spec tickets as local.
 */
const REFERENCE = '#(?:([A-Za-z0-9._-]+/[A-Za-z0-9._-]+))?\#(\d+)\b#';
const VERSION_TAG = '#^v?(\d+)\.(\d+)\.(\d+)$#';

exit(main($argv));

function main(array $argv): int
{
    [$positional, $flags] = parseArgs(array_slice($argv, 1));

    $root = realpath($flags['root'] ?? '.') ?: '.';
    $git = static function (string ...$args) use ($root): string {
        return run('git', array_merge(['-C', $root], $args));
    };

    $version = ltrim($positional[0] ?? libVersion($root), 'v');
    $section = $flags['section'] ?? $version;
    $at = $flags['at'] ?? (revExists($root, $version) ? $version : 'HEAD');

    $slug = $flags['repo'] ?? (getenv('GITHUB_REPOSITORY') ?: originSlug($git));
    [$owner, $name] = explode('/', $slug, 2);

    $previous = $flags['previous'] ?? highestBelow($git, $at, $version);
    $range = $previous !== null ? "$previous..$at" : $at;

    // What merged, and what of it moved shipped source.
    $shipping = [];
    $unattributed = [];
    foreach (explode("\n", $git('log', '--format=%H%x1f%s', $range)) as $line) {
        if ($line === '') {
            continue;
        }
        [$sha, $subject] = explode("\x1f", $line, 2);
        if (!movesShipped($git, $sha)) {
            continue;
        }
        if (!preg_match(PULL_REQUEST, $subject, $m)) {
            $unattributed[] = [substr($sha, 0, 9), $subject];
            continue;
        }
        $shipping[$m[1]] ??= trim(preg_replace(PULL_REQUEST, '', $subject));
    }

    $closes = closingIssues($owner, $name, array_keys($shipping), $shipping);
    if ($closes === null) {
        return 1;
    }

    $text = sectionOf(changelogAt($git, $root, $at), $section);
    if ($text === null) {
        printf("::error::CHANGELOG.md has no '## [%s]' section to check\n", $section);

        return 1;
    }

    $cited = [];
    preg_match_all(REFERENCE, $text, $refs, PREG_SET_ORDER);
    foreach ($refs as $ref) {
        if ($ref[1] !== '' && strcasecmp($ref[1], $slug) !== 0) {
            continue;
        }
        $cited[$ref[2]] = true;
    }

    [$exemptions, $malformed] = readExemptions($root);

    $missing = [];
    $skipped = [];
    $numbers = array_keys($shipping);
    sort($numbers, SORT_NUMERIC);
    foreach ($numbers as $number) {
        $number = (string)$number;
        if (isset($cited[$number])) {
            continue;
        }
        foreach ($closes[$number] ?? [] as $issue) {
            if (isset($cited[$issue])) {
                continue 2;
            }
        }
        if (isset($exemptions[$number])) {
            $skipped[] = [$number, $shipping[$number], $exemptions[$number]];
            continue;
        }
        $missing[] = [$number, $shipping[$number]];
    }

    foreach ($skipped as [$number, $title, $reason]) {
        printf("exempt: #%s %s\n        (%s: %s)\n", $number, $title, EXEMPT_FILE, $reason);
    }
    foreach ($unattributed as [$sha, $subject]) {
        printf("::notice::%s moved shipped source with no pull request to cite: %s\n", $sha, $subject);
    }

    $from = $previous !== null ? "since $previous" : 'so far';
    if ($malformed !== [] || $missing !== []) {
        foreach ($malformed as $problem) {
            printf("::error::%s\n", $problem);
        }
        foreach ($missing as [$number, $title]) {
            $also = $closes[$number] ?? [];
            $tail = $also !== []
                ? ' (closes ' . implode(', ', array_map(static fn ($i) => "#$i", $also)) . ')'
                : '';
            printf("::error::#%s%s is not cited in the %s section: %s\n", $number, $tail, $section, $title);
        }
        if ($missing !== []) {
            printf(
                "changelog-completeness: %d of %d pull request(s) %s moved shipped source and are cited "
                    . "nowhere in the %s section. Write them up, or exempt one with a reason in %s.\n",
                count($missing),
                count($shipping),
                $from,
                $section,
                EXEMPT_FILE
            );
        }

        return 1;
    }

    printf(
        "changelog-completeness: the %s section accounts for all %d shipped-source pull request(s) %s%s%s\n",
        $section,
        count($shipping) - count($skipped),
        $from,
        $skipped !== [] ? sprintf(', %d exempt', count($skipped)) : '',
        $unattributed !== [] ? sprintf(', %d commit(s) carrying no pull request', count($unattributed)) : ''
    );

    return 0;
}

/**
 * @return array{0: array<int, string>, 1: array<string, string>}
 */
function parseArgs(array $args): array
{
    $positional = [];
    $flags = [];
    for ($i = 0; $i < count($args); $i++) {
        if (str_starts_with($args[$i], '--')) {
            $flags[substr($args[$i], 2)] = $args[++$i] ?? '';
            continue;
        }
        $positional[] = $args[$i];
    }

    return [$positional, $flags];
}

/**
 * Runs a command. `$status` receives the exit code; when the caller passes it,
 * a failure returns stderr instead of throwing, so a gh error can be reported
 * rather than crashing the gate.
 */
function run(string $cmd, array $args, ?string $input = null, ?int &$status = null): string
{
    $capture = func_num_args() >= 4;
    $line = escapeshellcmd($cmd) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($line, $spec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("cannot run $cmd");
    }
    if ($input !== null) {
        fwrite($pipes[0], $input);
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status === 0) {
        return trim($out);
    }
    if ($capture) {
        return trim($err);
    }

    throw new RuntimeException("$cmd failed: " . trim($err));
}

function libVersion(string $root): string
{
    $text = file_get_contents($root . '/src/CarveConverter.php');
    if (!preg_match("#const\s+LIB_VERSION\s*=\s*'([^']+)'#", $text, $m)) {
        throw new RuntimeException('CarveConverter declares no LIB_VERSION');
    }

    return $m[1];
}

function revExists(string $root, string $rev): bool
{
    $status = 0;
    run('git', ['-C', $root, 'rev-parse', '--verify', '--quiet', $rev . '^{commit}'], null, $status);

    return $status === 0;
}

function originSlug(callable $git): string
{
    if (!preg_match('#[:/]([^/:]+/[^/]+?)(?:\.git)?$#', $git('remote', 'get-url', 'origin'), $m)) {
        throw new RuntimeException('cannot derive the repository slug; pass --repo owner/name');
    }

    return $m[1];
}

function versionKey(string $tag): array
{
    preg_match(VERSION_TAG, $tag, $m);

    return [(int)$m[1], (int)$m[2], (int)$m[3]];
}

function highestBelow(callable $git, string $at, string $version): ?string
{
    $target = preg_match(VERSION_TAG, $version) ? versionKey($version) : null;
    $below = [];
    foreach (explode("\n", $git('tag', '--merged', $at)) as $tag) {
        $tag = trim($tag);
        if ($tag === '' || !preg_match(VERSION_TAG, $tag)) {
            continue;
        }
        if ($target !== null && versionKey($tag) >= $target) {
            continue;
        }
        $below[] = $tag;
    }
    if ($below === []) {
        return null;
    }
    usort($below, static fn ($a, $b) => versionKey($a) <=> versionKey($b));

    return end($below);
}

/**
 * True when the commit changed a line under SHIPPED that is not merely a
 * version constant.
 */
function movesShipped(callable $git, string $sha): bool
{
    $diff = $git('diff-tree', '--no-commit-id', '-p', '-r', '-m', '--first-parent', '--root', $sha);
    $inShipped = false;
    foreach (explode("\n", $diff) as $line) {
        if (str_starts_with($line, 'diff --git ')) {
            $inShipped = preg_match('#^diff --git a/(\S+)#', $line, $m) === 1
                && isShipped($m[1]);
            continue;
        }
        if (!$inShipped || !preg_match('#^[+-]#', $line) || preg_match('#^(\+\+\+|---)#', $line)) {
            continue;
        }
        if (trim(substr($line, 1)) === '' || preg_match(VERSION_ONLY, $line)) {
            continue;
        }

        return true;
    }

    return false;
}

function isShipped(string $path): bool
{
    foreach (SHIPPED as $pattern) {
        if (preg_match($pattern, $path)) {
            return true;
        }
    }

    return false;
}

/**
 * What each pull request closes, batched by alias so this is a few requests
 * rather than one per pull request.
 *
 * @return array<string, array<int, string>>|null
 */
function closingIssues(string $owner, string $name, array $numbers, array &$titles): ?array
{
    $out = [];
    foreach (array_chunk($numbers, 50) as $chunk) {
        $fields = implode("\n", array_map(
            static fn ($n) => "p$n: pullRequest(number:$n)"
                . '{number title closingIssuesReferences(first:30){nodes{number}}}',
            $chunk
        ));
        $query = 'query($owner:String!,$name:String!){repository(owner:$owner,name:$name){' . $fields . '}}';
        $status = 0;
        $answer = run(
            'gh',
            ['api', 'graphql', '-F', "owner=$owner", '-F', "name=$name", '-F', 'query=@-'],
            $query,
            $status
        );
        if ($status !== 0) {
            echo "::error::could not ask GitHub what these pull requests close, so completeness "
                . "cannot be judged.\n";
            printf("::error::%s\n", explode("\n", $answer)[0] ?: 'gh failed');

            return null;
        }
        $repo = json_decode($answer, true)['data']['repository'] ?? [];
        foreach ($repo as $node) {
            if (!$node) {
                continue;
            }
            $number = (string)$node['number'];
            $out[$number] = array_map(
                static fn ($i) => (string)$i['number'],
                $node['closingIssuesReferences']['nodes']
            );
            if (!empty($node['title'])) {
                $titles[$number] = $node['title'];
            }
        }
    }

    return $out;
}

function changelogAt(callable $git, string $root, string $at): string
{
    try {
        return $git('show', "$at:CHANGELOG.md");
    } catch (RuntimeException) {
        return file_get_contents($root . '/CHANGELOG.md');
    }
}

function sectionOf(string $body, string $section): ?string
{
    $lines = explode("\n", $body);
    // `~` delimits this one: the pattern matches a literal `## ` heading, and
    // a `#` delimiter would end it at the heading's own hashes.
    $head = '~^## \[?' . preg_quote($section, '~') . '\]?(\s|\]|$)~';
    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match($head, $line)) {
            $start = $i;
            break;
        }
    }
    if ($start === null) {
        return null;
    }
    $out = [];
    foreach (array_slice($lines, $start + 1) as $line) {
        if (str_starts_with($line, '## ')) {
            break;
        }
        $out[] = $line;
    }

    return implode("\n", $out);
}

/**
 * Deliberate exclusions stay VISIBLE. An exemption with no reason is refused,
 * so the escape hatch cannot decay into a list of bare numbers nobody can
 * audit.
 *
 * @return array{0: array<string, string>, 1: array<int, string>}
 */
function readExemptions(string $root): array
{
    $path = $root . '/' . EXEMPT_FILE;
    if (!is_file($path)) {
        return [[], []];
    }
    $out = [];
    $malformed = [];
    foreach (explode("\n", file_get_contents($path)) as $i => $raw) {
        $line = trim($raw);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('#^\#?(\d+)\s*[:\s]\s*(\S.*)$#', $line, $m)) {
            $malformed[] = sprintf(
                "%s:%d: expected '<number>: <reason>', got '%s'",
                EXEMPT_FILE,
                $i + 1,
                $line
            );
            continue;
        }
        $out[$m[1]] = trim($m[2]);
    }

    return [$out, $malformed];
}
